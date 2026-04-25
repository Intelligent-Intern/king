<?php
declare(strict_types=1);
/**
 * First Token Parity Test
 *
 * Compares Voltron's first token output with direct Ollama calls to verify M0 parity.
 * 
 * Usage:
 *   php test_first_token_parity.php [prompt]
 * 
 * Default prompt: "2+2="
 */

declare(strict_types=1);

$prompt = $argv[1] ?? '2+2=';

echo "=== First Token Parity Test ===\n";
echo "Prompt: {$prompt}\n\n";

$ollamaUrl = getenv('VOLTRON_OLLAMA_URL') ?: 'http://127.0.0.1:11434';
$model = getenv('VOLTRON_OLLAMA_MODEL') ?: 'qwen2.5-coder:3b';

echo "Ollama: {$ollamaUrl}\n";
echo "Model:  {$model}\n\n";

$testPrompt = $prompt;
if (strpos($model, 'qwen') !== false) {
    $testPrompt = "<|im_start|>assistant\n{$prompt}";
}

echo "Test prompt: " . json_encode($testPrompt) . "\n\n";

echo "--- Option 1: Direct Ollama API ---\n";

$url = $ollamaUrl . '/api/generate';
$payload = [
    'model' => $model,
    'prompt' => $testPrompt,
    'stream' => false,
    'options' => [
        'num_predict' => 1,
        'temperature' => 0,
        'top_k' => 1,
        'top_p' => 1.0,
        'repeat_penalty' => 1.0,
    ],
];

$cmd = sprintf(
    'curl -s -X POST %s -H "Content-Type: application/json" -d %s --max-time 30',
    escapeshellarg($url),
    escapeshellarg(json_encode($payload))
);
$response = shell_exec($cmd);

if ($response === null || $response === '') {
    echo "ERROR: No response from Ollama\n";
    exit(1);
}

$data = json_decode($response, true);
$ollamaResponse = $data['response'] ?? '';
echo "Response: " . json_encode($ollamaResponse) . "\n";

$firstTokenOllama = $ollamaResponse !== '' ? $ollamaResponse[0] : '';
echo "First char: " . json_encode($firstTokenOllama) . "\n\n";

echo "--- Option 2: Voltron Kernels ---\n";

require_once __DIR__ . '/src/VoltronKernels.php';
require_once __DIR__ . '/src/VoltronTokenizer.php';

$tokenizer = new \King\Voltron\VoltronTokenizer($model);
$formatted = $tokenizer->formatPrompt($prompt);

$params = [
    'inference_max_tokens' => 1,
];

try {
    $state = \King\Voltron\VoltronKernels::initialState($prompt, $params);
    $state = \King\Voltron\VoltronKernels::executeBlock('output_head', $state, $params);
    
    $generatedText = $state['generated_text'] ?? '';
    echo "Response: " . json_encode($generatedText) . "\n";
    
    $firstTokenVoltron = $generatedText !== '' ? $generatedText[0] : '';
    echo "First char: " . json_encode($firstTokenVoltron) . "\n\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== Parity Result ===\n";
echo "Ollama first token:  " . json_encode($firstTokenOllama) . "\n";
echo "Voltron first token: " . json_encode($firstTokenVoltron) . "\n";

if ($firstTokenOllama === $firstTokenVoltron) {
    echo "\n✓ PARITY CONFIRMED\n";
    exit(0);
} else {
    echo "\n✗ PARITY FAILED\n";
    exit(1);
}