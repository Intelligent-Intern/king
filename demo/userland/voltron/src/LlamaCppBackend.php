<?php
declare(strict_types=1);

namespace King\Voltron;

class LlamaCppBackend
{
    private string $baseUrl;
    private string $model;
    private int $timeout;
    private int $port;

    public function __construct(
        string $host = '127.0.0.1',
        int $port = 9700,
        string $model = 'qwen2.5-coder:3b',
        int $timeout = 120
    ) {
        $this->baseUrl = "http://{$host}:{$port}";
        $this->port = $port;
        $this->model = $model;
        $this->timeout = $timeout;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function completeRaw(
        string $prompt,
        array $sampling = []
    ): array {
        $url = $this->baseUrl . '/completion';

        $body = [
            'prompt' => $prompt,
            'n_predict' => $sampling['max_tokens'] ?? 1,
            'temperature' => $sampling['temperature'] ?? 0.0,
            'top_k' => $sampling['top_k'] ?? 1,
            'top_p' => $sampling['top_p'] ?? 1.0,
            'min_p' => $sampling['min_p'] ?? 0.0,
            'typical_p' => $sampling['typical_p'] ?? 1.0,
            'repeat_penalty' => $sampling['repeat_penalty'] ?? 1.0,
            'presence_penalty' => $sampling['presence_penalty'] ?? 0.0,
            'frequency_penalty' => $sampling['frequency_penalty'] ?? 0.0,
            'stream' => false,
        ];

        if (isset($sampling['seed']) && is_int($sampling['seed'])) {
            $body['seed'] = (int) $sampling['seed'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            throw new \RuntimeException("llama.cpp /completion failed: {$error}");
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("llama.cpp returned non-JSON: " . substr($response, 0, 200));
        }

        return [
            'content' => (string) ($decoded['content'] ?? ''),
            'stop' => (bool) ($decoded['stop'] ?? false),
            'stop_type' => (string) ($decoded['stop_type'] ?? ''),
            'tokens_predicted' => (int) ($decoded['tokens_predicted'] ?? 0),
            'timings' => is_array($decoded['timings'] ?? null) ? $decoded['timings'] : [],
        ];
    }

    public function healthCheck(): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/health',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }
}