<?php
declare(strict_types=1);

namespace King\Voltron;

class OllamaBackend
{
    private string $baseUrl;
    private string $model;
    private int $timeout;

    public function __construct(
        string $baseUrl = 'http://127.0.0.1:11434',
        string $model = 'qwen2.5-coder:3b',
        int $timeout = 120
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->model = $model;
        $this->timeout = $timeout;
    }

    public function generate(
        string $prompt,
        array $options = []
    ): array {
        $url = $this->baseUrl . '/api/generate';

        $payload = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
        ];

        $defaults = [
            'num_predict' => 64,
            'temperature' => 0.7,
            'top_p' => 0.9,
            'top_k' => 40,
            'repeat_penalty' => 1.1,
        ];

        $options = array_merge($defaults, $options);
        foreach ($options as $k => $v) {
            if (is_int($v) || is_float($v)) {
                $payload['options'][$k] = $v;
            }
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new \RuntimeException(
                "Ollama request failed: {$error} (HTTP {$httpCode})"
            );
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid Ollama response");
        }

        return [
            'response' => $data['response'] ?? '',
            'done' => $data['done'] ?? false,
            'done_reason' => $data['done_reason'] ?? '',
            'context' => $data['context'] ?? [],
            'total_duration' => $data['total_duration'] ?? 0,
            'load_duration' => $data['load_duration'] ?? 0,
            'prompt_eval_count' => $data['prompt_eval_count'] ?? 0,
            'eval_count' => $data['eval_count'] ?? 0,
        ];
    }

    public function embedding(string $text): array
    {
        $url = $this->baseUrl . '/api/embeddings';
        $payload = [
            'model' => $this->model,
            'prompt' => $text,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_RETURNTRANSFER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException("Ollama embedding failed (HTTP {$httpCode})");
        }

        $data = json_decode($response, true);
        return $data['embedding'] ?? [];
    }

    public static function isAvailable(string $baseUrl = 'http://127.0.0.1:11434'): bool
    {
        $ch = curl_init($baseUrl . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT => 2,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $httpCode === 200;
    }
}