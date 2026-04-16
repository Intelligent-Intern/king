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
     *
     * Uses llama.cpp server's OpenAI-compatible /v1/chat/completions
     * endpoint so the chat template baked into the GGUF (ChatML for
     * SmolLM2, Llama-2 chat format for Llama-2, etc.) is applied
     * automatically. Raw /completion against a chat-tuned model would
     * emit EOS immediately because the model sees the prompt as
     * already-finished context.
     *
     * @param array<string, mixed> $validatedEnvelope
     * @return array<string, mixed>
     */
    public function completeNonStreaming(LlamaCppWorker $worker, array $validatedEnvelope, ?int $effectiveMaxTokens = null): array
    {
        $sampling = (array) $validatedEnvelope['sampling'];
        $maxTokens = $effectiveMaxTokens ?? (int) $sampling['max_tokens'];

        $body = [
            'messages' => $this->buildMessages($validatedEnvelope),
            'max_tokens' => $maxTokens,
            'temperature' => (float) $sampling['temperature'],
            'top_p' => (float) $sampling['top_p'],
            'top_k' => (int) $sampling['top_k'],
            'stream' => false,
        ];
        if (isset($sampling['seed']) && is_int($sampling['seed'])) {
            $body['seed'] = (int) $sampling['seed'];
        }
        $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($jsonBody)) {
            throw new RuntimeException('failed to encode chat completion request body');
        }

        $url = sprintf('http://127.0.0.1:%d/v1/chat/completions', $worker->port());
        $t0 = microtime(true);

        $rawResponse = $this->postCompletion($url, $jsonBody, $this->completionTimeoutMs);

        $elapsedMs = (int) round((microtime(true) - $t0) * 1000);

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('llama.cpp /v1/chat/completions returned non-JSON: ' . substr($rawResponse, 0, 200));
        }

        $choice = (is_array($decoded['choices'] ?? null) && isset($decoded['choices'][0]) && is_array($decoded['choices'][0]))
            ? $decoded['choices'][0]
            : [];
        $content = (string) (($choice['message'] ?? [])['content'] ?? '');
        $finishReason = (string) ($choice['finish_reason'] ?? '');

        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $timings = is_array($decoded['timings'] ?? null) ? $decoded['timings'] : [];
        $tokensIn = (int) ($usage['prompt_tokens'] ?? ($timings['prompt_n'] ?? 0));
        $tokensOut = (int) ($usage['completion_tokens'] ?? ($timings['predicted_n'] ?? 0));
        $promptMs = (float) ($timings['prompt_ms'] ?? 0.0);
        $predictedMs = (float) ($timings['predicted_ms'] ?? 0.0);
        $serverDurationMs = (int) round($promptMs + $predictedMs);
        $ttftMs = (int) round($promptMs);

        return [
            'content' => $content,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'ttft_ms' => $ttftMs,
            'duration_ms' => $serverDurationMs,
            'request_wall_ms' => $elapsedMs,
            'stop' => [
                'type' => $finishReason,
                'word' => '',
                'truncated' => $finishReason === 'length',
            ],
            'worker' => [
                'pid' => $worker->pid(),
                'port' => $worker->port(),
                'gguf_path' => $worker->ggufPath(),
            ],
        ];
    }

    /**
     * Build the OpenAI-style messages array from the validated envelope.
     * Always shapes as {system?, user}. Future roles (assistant, tool)
     * belong to a multi-turn leaf.
     *
     * @param array<string, mixed> $validatedEnvelope
     * @return array<int, array{role: string, content: string}>
     */
    public function buildMessages(array $validatedEnvelope): array
    {
        $messages = [];
        if (isset($validatedEnvelope['system']) && is_string($validatedEnvelope['system']) && $validatedEnvelope['system'] !== '') {
            $messages[] = ['role' => 'system', 'content' => $validatedEnvelope['system']];
        }
        $messages[] = ['role' => 'user', 'content' => (string) ($validatedEnvelope['prompt'] ?? '')];
        return $messages;
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
