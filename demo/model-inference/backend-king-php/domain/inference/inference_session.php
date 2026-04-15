<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/llama_cpp_worker.php';

/**
 * Per-process inference session: owns the llama.cpp worker cache + is
 * the boundary between the dispatcher and the real subordinate process.
 *
 * Cache policy (intentionally narrow for the M-10 single-node demo):
 *  - At most one live worker per backend process.
 *  - Cache key is the registry model_id. A request for the same model_id
 *    as the live worker reuses that worker; a different model drains the
 *    old worker and spawns a new one.
 *  - No LRU, no N-way cache, no eviction. Multi-model hot-swapping is a
 *    future hardening leaf; fencing it out here keeps the honest contract
 *    narrow.
 *
 * GGUF delivery: on first spawn for a given model_id, the session streams
 * the artifact out of the King object store into a local cache directory
 * (one file per model_id). llama.cpp then mmaps the on-disk file the way
 * it would in production. Calling the object-store abstraction here
 * keeps the storage-backend choice swappable (cloud primaries work
 * without code changes).
 *
 * Worker state is reconciled lazily: diagnostics() fires a fresh health
 * probe through the King HTTP/1 client and promotes STARTING → READY as
 * soon as llama.cpp's /health returns 200.
 */
final class InferenceSession
{
    /** @var array<string, LlamaCppWorker> */
    private array $workersByModelId = [];

    /** @var array<string, string> cached local GGUF paths per model_id */
    private array $ggufPathsByModelId = [];

    public function __construct(
        private string $llamaBinaryPath,
        private string $llamaLibraryPath,
        private string $ggufCacheRoot,
        private int $workerReadyTimeoutMs = 60_000,
        private int $workerHealthPollMs = 250,
        private int $completionTimeoutMs = 60_000
    ) {
        if ($ggufCacheRoot === '') {
            throw new InvalidArgumentException('ggufCacheRoot must be non-empty');
        }
        if (!is_dir($ggufCacheRoot) && !mkdir($ggufCacheRoot, 0775, true) && !is_dir($ggufCacheRoot)) {
            throw new RuntimeException("unable to create GGUF cache root: {$ggufCacheRoot}");
        }
    }

    /**
     * Return a READY worker for this model_id, spawning one if needed.
     * Evicts any prior worker for a different model before spawning.
     */
    public function workerFor(string $modelId, string $objectStoreKey, int $contextTokens = 1024, int $maxNewTokens = 256): LlamaCppWorker
    {
        if (isset($this->workersByModelId[$modelId])) {
            $worker = $this->workersByModelId[$modelId];
            $state = $worker->state();
            if ($state === LlamaCppWorker::STATE_READY || $state === LlamaCppWorker::STATE_STARTING) {
                if ($state === LlamaCppWorker::STATE_STARTING) {
                    $worker->waitForReady($this->workerReadyTimeoutMs, $this->workerHealthPollMs);
                }
                return $worker;
            }
            $worker->stop();
            unset($this->workersByModelId[$modelId]);
        }

        // A worker for a different model is live — drain it first (one-active
        // worker policy).
        foreach ($this->workersByModelId as $existingModelId => $existingWorker) {
            $existingWorker->stop();
            unset($this->workersByModelId[$existingModelId]);
        }

        $ggufPath = $this->materializeGguf($modelId, $objectStoreKey);
        $worker = new LlamaCppWorker($this->llamaBinaryPath, $this->llamaLibraryPath);
        $port = $this->allocateEphemeralPort();
        $logPath = $this->ggufCacheRoot . '/worker-' . $modelId . '.log';
        $worker->start($ggufPath, $port, [
            'context_tokens' => $contextTokens,
            'max_new_tokens' => $maxNewTokens,
            'log_path' => $logPath,
        ]);
        $worker->waitForReady($this->workerReadyTimeoutMs, $this->workerHealthPollMs);
        $this->workersByModelId[$modelId] = $worker;
        return $worker;
    }

    /**
     * Run a non-streaming completion against the worker.
     * Accepts the normalized infer-request envelope from
     * model_inference_validate_infer_request() and returns the normalized
     * response shape {content, tokens_in, tokens_out, ttft_ms, duration_ms}.
     *
     * @param array<string, mixed> $validatedEnvelope
     * @return array<string, mixed>
     */
    public function completeNonStreaming(LlamaCppWorker $worker, array $validatedEnvelope, ?int $effectiveMaxTokens = null): array
    {
        $sampling = (array) $validatedEnvelope['sampling'];
        $maxTokens = $effectiveMaxTokens ?? (int) $sampling['max_tokens'];

        $prompt = (string) $validatedEnvelope['prompt'];
        if (isset($validatedEnvelope['system']) && is_string($validatedEnvelope['system']) && $validatedEnvelope['system'] !== '') {
            $prompt = $validatedEnvelope['system'] . "\n\n" . $prompt;
        }

        $body = [
            'prompt' => $prompt,
            'n_predict' => $maxTokens,
            'temperature' => (float) $sampling['temperature'],
            'top_p' => (float) $sampling['top_p'],
            'top_k' => (int) $sampling['top_k'],
            'stream' => false,
            'cache_prompt' => false,
        ];
        if (isset($sampling['seed']) && is_int($sampling['seed'])) {
            $body['seed'] = (int) $sampling['seed'];
        }
        $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($jsonBody)) {
            throw new RuntimeException('failed to encode completion request body');
        }

        $url = sprintf('http://127.0.0.1:%d/completion', $worker->port());
        $t0 = microtime(true);

        $rawResponse = $this->postCompletion($url, $jsonBody, $this->completionTimeoutMs);

        $elapsedMs = (int) round((microtime(true) - $t0) * 1000);

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('llama.cpp /completion returned non-JSON: ' . substr($rawResponse, 0, 200));
        }

        $timings = is_array($decoded['timings'] ?? null) ? $decoded['timings'] : [];
        $tokensIn = (int) ($decoded['tokens_evaluated'] ?? ($timings['prompt_n'] ?? 0));
        $tokensOut = (int) ($decoded['tokens_predicted'] ?? ($timings['predicted_n'] ?? 0));
        $promptMs = (float) ($timings['prompt_ms'] ?? 0.0);
        $predictedMs = (float) ($timings['predicted_ms'] ?? 0.0);
        $serverDurationMs = (int) round($promptMs + $predictedMs);
        $ttftMs = (int) round($promptMs);

        return [
            'content' => (string) ($decoded['content'] ?? ''),
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'ttft_ms' => $ttftMs,
            'duration_ms' => $serverDurationMs,
            'request_wall_ms' => $elapsedMs,
            'stop' => [
                'type' => (string) ($decoded['stop_type'] ?? ''),
                'word' => (string) ($decoded['stopping_word'] ?? ''),
                'truncated' => (bool) ($decoded['truncated'] ?? false),
            ],
            'worker' => [
                'pid' => $worker->pid(),
                'port' => $worker->port(),
                'gguf_path' => $worker->ggufPath(),
            ],
        ];
    }

    /**
     * Drain every cached worker. Safe to call in shutdown handlers.
     */
    public function drainAll(): void
    {
        foreach ($this->workersByModelId as $modelId => $worker) {
            $worker->stop();
            unset($this->workersByModelId[$modelId]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function diagnostics(): array
    {
        $rows = [];
        foreach ($this->workersByModelId as $modelId => $worker) {
            $rows[] = [
                'model_id' => $modelId,
                'worker' => $worker->diagnostics(),
            ];
        }
        return $rows;
    }

    private function materializeGguf(string $modelId, string $objectStoreKey): string
    {
        if (isset($this->ggufPathsByModelId[$modelId]) && is_file($this->ggufPathsByModelId[$modelId])) {
            return $this->ggufPathsByModelId[$modelId];
        }
        $target = $this->ggufCacheRoot . '/' . $modelId . '.gguf';
        if (!is_file($target) || filesize($target) === 0) {
            $tmp = $target . '.partial';
            $stream = @fopen($tmp, 'wb');
            if (!is_resource($stream)) {
                throw new RuntimeException("failed to open GGUF cache sink: {$tmp}");
            }
            try {
                $ok = king_object_store_get_to_stream($objectStoreKey, $stream);
                if ($ok !== true) {
                    throw new RuntimeException("king_object_store_get_to_stream failed for key '{$objectStoreKey}'");
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if (!rename($tmp, $target)) {
                @unlink($tmp);
                throw new RuntimeException("failed to finalize GGUF cache at {$target}");
            }
        }
        $this->ggufPathsByModelId[$modelId] = $target;
        return $target;
    }

    private function allocateEphemeralPort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($socket)) {
            throw new RuntimeException("failed to allocate ephemeral port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($name === false) {
            throw new RuntimeException('failed to resolve ephemeral port');
        }
        $pos = strrpos($name, ':');
        return (int) substr($name, $pos + 1);
    }

    /**
     * POST a JSON body to a loopback llama.cpp /completion. Prefers the
     * King HTTP/1 client (dogfoods the native transport); falls back to a
     * PHP stream when the extension is absent (unit-test use).
     */
    private function postCompletion(string $url, string $jsonBody, int $timeoutMs): string
    {
        if (function_exists('king_http1_request_send')) {
            try {
                $response = king_http1_request_send(
                    $url,
                    'POST',
                    ['Content-Type' => 'application/json'],
                    $jsonBody,
                    ['timeout_ms' => $timeoutMs]
                );
                if (is_array($response) && isset($response['body']) && is_string($response['body'])) {
                    $status = (int) ($response['status'] ?? $response['status_code'] ?? 0);
                    if ($status < 200 || $status >= 300) {
                        throw new RuntimeException("llama.cpp /completion returned HTTP {$status}: " . substr($response['body'], 0, 200));
                    }
                    return $response['body'];
                }
            } catch (RuntimeException $rethrow) {
                throw $rethrow;
            } catch (Throwable $ignored) {
                // Fall back to the stream probe.
            }
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $jsonBody,
                'timeout' => $timeoutMs / 1000.0,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                    $status = (int) $m[1];
                    break;
                }
            }
        }
        if (!is_string($body)) {
            throw new RuntimeException('llama.cpp /completion produced no response body');
        }
        if ($status !== 0 && ($status < 200 || $status >= 300)) {
            throw new RuntimeException("llama.cpp /completion returned HTTP {$status}: " . substr($body, 0, 200));
        }
        return $body;
    }

    public function __destruct()
    {
        $this->drainAll();
    }
}
