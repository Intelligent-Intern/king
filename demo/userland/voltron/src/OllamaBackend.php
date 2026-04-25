/**
 * Ollama Backend Client
 * 
 * Works with ANY Ollama-compatible model:
 *   - llama, llama2, llama3, llama3.1, llama3.2
 *   - qwen, qwen2, qwen2.5, qwen2.5-coder
 *   - mistral, mistral-small
 *   - gemma, gemma2
 *   - phi, phi3
 *   - codellama, codellama2
 *   - Any model available via `ollama pull <model>`
 * 
 * Uses shell_exec(curl) to avoid PHP curl extension requirement.
 */
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

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        $cmd = sprintf(
            'curl -s -X POST %s -H "Content-Type: application/json" -d %s --max-time %d',
            escapeshellarg($url),
            escapeshellarg($jsonPayload),
            $this->timeout
        );
        
        $response = shell_exec($cmd);
        
        if ($response === null || $response === '') {
            throw new \RuntimeException("Ollama generate failed");
        }

        $data = json_decode($response, true);
        return [
            'response' => (string) ($data['response'] ?? ''),
            'done' => (bool) ($data['done'] ?? false),
        ];
    }

    public static function isAvailable(string $baseUrl = 'http://127.0.0.1:11434'): bool
    {
        $cmd = sprintf('curl -s -X GET %s --max-time 2', escapeshellarg($baseUrl . '/api/tags'));
        $response = shell_exec($cmd);
        return $response !== null && $response !== '';
    }
}