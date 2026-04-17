<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/llama_cpp_worker.php';

final class EmbeddingSession
{
    /** @var array<string, LlamaCppWorker> */
    private array $workersByModelId = [];

    /** @var array<string, string> */
    private array $ggufPathsByModelId = [];

    public function __construct(
        private string $llamaBinaryPath,
        private string $llamaLibraryPath,
        private string $ggufCacheRoot,
        private int $workerReadyTimeoutMs = 60_000,
        private int $workerHealthPollMs = 250,
        private int $requestTimeoutMs = 30_000
    ) {
        if ($ggufCacheRoot === '') {
            throw new InvalidArgumentException('ggufCacheRoot must be non-empty');
        }
        if (!is_dir($ggufCacheRoot) && !mkdir($ggufCacheRoot, 0775, true) && !is_dir($ggufCacheRoot)) {
            throw new RuntimeException("unable to create GGUF cache root: {$ggufCacheRoot}");
        }
    }

    public function workerFor(string $modelId, string $objectStoreKey, int $contextTokens = 2048): LlamaCppWorker
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

        foreach ($this->workersByModelId as $existingModelId => $existingWorker) {
            $existingWorker->stop();
            unset($this->workersByModelId[$existingModelId]);
        }

        $ggufPath = $this->materializeGguf($modelId, $objectStoreKey);
        $worker = new LlamaCppWorker($this->llamaBinaryPath, $this->llamaLibraryPath);
        $port = $this->allocateEphemeralPort();
        $logPath = $this->ggufCacheRoot . '/embed-worker-' . $modelId . '.log';
        $worker->start($ggufPath, $port, [
            'context_tokens' => $contextTokens,
            'log_path' => $logPath,
            'extra_argv' => ['--embedding'],
        ]);
        $worker->waitForReady($this->workerReadyTimeoutMs, $this->workerHealthPollMs);
        $this->workersByModelId[$modelId] = $worker;
        return $worker;
    }

    /**
     * @param array<int, string> $texts
     * @return array{embeddings: array<int, array<int, float>>, dimensions: int, tokens_used: int, duration_ms: int}
     */
    public function embed(LlamaCppWorker $worker, array $texts, bool $normalize = true): array
    {
        $body = json_encode([
            'input' => $texts,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            throw new RuntimeException('failed to encode embedding request body');
        }

        $url = sprintf('http://127.0.0.1:%d/v1/embeddings', $worker->port());
        $t0 = microtime(true);

        $rawResponse = $this->postEmbedding($url, $body, $this->requestTimeoutMs);

        $elapsedMs = (int) round((microtime(true) - $t0) * 1000);

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('llama.cpp /v1/embeddings returned non-JSON: ' . substr($rawResponse, 0, 200));
        }

        $data = (array) ($decoded['data'] ?? []);
        $embeddings = [];
        foreach ($data as $item) {
            if (!is_array($item) || !is_array($item['embedding'] ?? null)) {
                continue;
            }
            $vec = array_map('floatval', $item['embedding']);
            if ($normalize) {
                $vec = $this->l2Normalize($vec);
            }
            $embeddings[] = $vec;
        }

        if (count($embeddings) === 0) {
            throw new RuntimeException('llama.cpp /v1/embeddings returned no embedding vectors');
        }

        $dimensions = count($embeddings[0]);
        $usage = is_array($decoded['usage'] ?? null) ? $decoded['usage'] : [];
        $tokensUsed = (int) ($usage['prompt_tokens'] ?? ($usage['total_tokens'] ?? 0));

        return [
            'embeddings' => $embeddings,
            'dimensions' => $dimensions,
            'tokens_used' => $tokensUsed,
            'duration_ms' => $elapsedMs,
        ];
    }

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

    /** @param array<int, float> $vec */
    private function l2Normalize(array $vec): array
    {
        $norm = 0.0;
        foreach ($vec as $v) {
            $norm += $v * $v;
        }
        $norm = sqrt($norm);
        if ($norm < 1e-12) {
            return $vec;
        }
        return array_map(static fn(float $v): float => $v / $norm, $vec);
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

    private function postEmbedding(string $url, string $jsonBody, int $timeoutMs): string
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
                        throw new RuntimeException("llama.cpp /v1/embeddings returned HTTP {$status}: " . substr($response['body'], 0, 200));
                    }
                    return $response['body'];
                }
            } catch (RuntimeException $rethrow) {
                throw $rethrow;
            } catch (Throwable $ignored) {
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
            throw new RuntimeException('llama.cpp /v1/embeddings produced no response body');
        }
        if ($status !== 0 && ($status < 200 || $status >= 300)) {
            throw new RuntimeException("llama.cpp /v1/embeddings returned HTTP {$status}: " . substr($body, 0, 200));
        }
        return $body;
    }

    public function __destruct()
    {
        $this->drainAll();
    }
}
