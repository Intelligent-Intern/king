<?php
declare(strict_types=1);

function king_bench_usage(): void
{
    fwrite(STDERR, <<<TXT
Usage: bin/king-openai-bench [options]

Options:
  --url URL                    OpenAI chat completions URL.
  --model NAME                 Model id to request.
  --prompt TEXT                Prompt text.
  --prompt-file PATH           Read prompt text from a file.
  --prompt-tokens N            Generate a synthetic prompt with about N words.
  --max-tokens N               Requested completion token cap.
  --thermal-ceiling N          Refuse request when GPU temperature is >= N.
  --allow-hot                  Do not refuse a hot GPU.
  --json                       Print only JSON metrics.

The router must already be running. This script does not start King or the GPU.
Stream usage is requested by default so prompt/completion tokens are tokenizer-backed
when the selected King model can count them.
TXT);
}

function king_bench_option(array $argv, string $name, ?string $default = null): ?string
{
    $prefix = $name . '=';
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $arg = $argv[$i];
        if ($arg === $name && isset($argv[$i + 1])) {
            return $argv[$i + 1];
        }
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

function king_bench_flag(array $argv, string $name): bool
{
    return in_array($name, $argv, true);
}

function king_bench_int_option(array $argv, string $name, int $default): int
{
    $value = king_bench_option($argv, $name);
    if ($value === null || !preg_match('/^\d+$/', $value)) {
        return $default;
    }
    return (int) $value;
}

function king_bench_float_option(array $argv, string $name, float $default): float
{
    $value = king_bench_option($argv, $name);
    if ($value === null || !is_numeric($value)) {
        return $default;
    }
    return (float) $value;
}

function king_bench_gpu_snapshot(): array
{
    $command = 'nvidia-smi --query-gpu=temperature.gpu,utilization.gpu,memory.used,memory.total,power.draw --format=csv,noheader,nounits 2>/dev/null';
    $lines = [];
    $exitCode = 0;
    exec($command, $lines, $exitCode);
    if ($exitCode !== 0 || $lines === []) {
        return ['available' => false];
    }

    $parts = array_map('trim', explode(',', $lines[0]));
    return [
        'available' => true,
        'temperature_c' => isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : null,
        'utilization_percent' => isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : null,
        'vram_used_mb' => isset($parts[2]) && is_numeric($parts[2]) ? (float) $parts[2] : null,
        'vram_total_mb' => isset($parts[3]) && is_numeric($parts[3]) ? (float) $parts[3] : null,
        'power_w' => isset($parts[4]) && is_numeric($parts[4]) ? (float) $parts[4] : null,
    ];
}

function king_bench_prompt(array $argv): string
{
    $promptFile = king_bench_option($argv, '--prompt-file');
    if ($promptFile !== null) {
        $content = @file_get_contents($promptFile);
        if (!is_string($content)) {
            fwrite(STDERR, "king-openai-bench: cannot read prompt file: {$promptFile}\n");
            exit(2);
        }
        return $content;
    }

    $prompt = king_bench_option($argv, '--prompt');
    if ($prompt !== null) {
        return $prompt;
    }

    $promptTokens = king_bench_int_option($argv, '--prompt-tokens', 0);
    if ($promptTokens > 0) {
        $words = [];
        for ($i = 0; $i < $promptTokens; $i++) {
            $words[] = 'context' . ($i % 97);
        }
        return implode(' ', $words) . "\nAnswer with one concise sentence.";
    }

    return 'Say hello in one short sentence.';
}

function king_bench_estimated_tokens(string $text): int
{
    $words = preg_split('/\s+/', trim($text));
    $wordCount = is_array($words) && $words !== [''] ? count($words) : 0;
    return max(1, $wordCount);
}

function king_bench_models_url(string $chatUrl): string
{
    return preg_replace('#/chat/completions$#', '/models', $chatUrl) ?? $chatUrl;
}

function king_bench_model_state(string $chatUrl, string $model): array
{
    $state = [
        'listed' => false,
        'resident' => null,
        'openai_generation' => null,
        'backend' => null,
        'gpu_generation_ready' => null,
        'gpu_reason' => null,
    ];
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 1.5,
            'ignore_errors' => true,
        ],
    ]);
    $json = @file_get_contents(king_bench_models_url($chatUrl), false, $context);
    if (!is_string($json) || $json === '') {
        return $state;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
        return $state;
    }
    foreach ($decoded['data'] as $item) {
        if (is_array($item) && (($item['id'] ?? null) === $model)) {
            $king = is_array($item['x_king'] ?? null) ? $item['x_king'] : [];
            $truth = is_array($king['runtime_truth'] ?? null) ? $king['runtime_truth'] : [];
            $gpuRuntime = is_array($king['gpu_runtime'] ?? null) ? $king['gpu_runtime'] : [];

            $state['listed'] = true;
            $state['resident'] = is_bool($truth['model_resident'] ?? null) ? $truth['model_resident'] : null;
            $state['openai_generation'] = is_bool($king['openai_generation'] ?? null) ? $king['openai_generation'] : null;
            $state['backend'] = is_string($king['backend'] ?? null) ? $king['backend'] : null;
            $state['gpu_generation_ready'] = is_bool($gpuRuntime['generation_ready'] ?? null)
                ? $gpuRuntime['generation_ready']
                : null;
            $state['gpu_reason'] = is_string($gpuRuntime['reason'] ?? null) ? $gpuRuntime['reason'] : null;
            return $state;
        }
    }
    return $state;
}

function king_bench_gpu_delta(array $before, array $after): array
{
    $fields = ['temperature_c', 'utilization_percent', 'vram_used_mb', 'vram_total_mb', 'power_w'];
    $delta = [];

    foreach ($fields as $field) {
        $beforeValue = $before[$field] ?? null;
        $afterValue = $after[$field] ?? null;
        $delta[$field] = is_float($beforeValue) && is_float($afterValue)
            ? $afterValue - $beforeValue
            : null;
    }

    return $delta;
}

function king_bench_sse_content(array $event): string
{
    $choice = $event['choices'][0] ?? null;
    if (!is_array($choice)) {
        return '';
    }
    $delta = $choice['delta'] ?? null;
    if (is_array($delta) && is_string($delta['content'] ?? null)) {
        return $delta['content'];
    }
    $message = $choice['message'] ?? null;
    if (is_array($message) && is_string($message['content'] ?? null)) {
        return $message['content'];
    }
    return '';
}

if (king_bench_flag($argv, '--help') || king_bench_flag($argv, '-h')) {
    king_bench_usage();
    exit(0);
}

$url = king_bench_option($argv, '--url', getenv('KING_OPENAI_BENCH_URL') ?: 'http://127.0.0.1:8080/v1/chat/completions');
$model = king_bench_option($argv, '--model', getenv('KING_OPENAI_BENCH_MODEL') ?: getenv('KING_INFERENCE_GPU_MODEL_NAME') ?: 'gemma4:12b');
$maxTokens = king_bench_int_option($argv, '--max-tokens', 128);
$thermalCeiling = king_bench_float_option($argv, '--thermal-ceiling', (float) (getenv('KING_INFERENCE_GPU_THERMAL_MAX_TEMPERATURE_C') ?: 78));
$allowHot = king_bench_flag($argv, '--allow-hot');
$jsonOnly = king_bench_flag($argv, '--json');
$prompt = king_bench_prompt($argv);
$gpuBefore = king_bench_gpu_snapshot();

if (!$allowHot
    && ($gpuBefore['available'] ?? false)
    && is_float($gpuBefore['temperature_c'] ?? null)
    && $gpuBefore['temperature_c'] >= $thermalCeiling
) {
    fwrite(STDERR, "king-openai-bench: refusing request, GPU is {$gpuBefore['temperature_c']}C and ceiling is {$thermalCeiling}C\n");
    exit(3);
}

$payload = [
    'model' => $model,
    'stream' => true,
    'messages' => [
        ['role' => 'user', 'content' => $prompt],
    ],
    'max_tokens' => $maxTokens,
    'temperature' => 0,
    'stream_options' => [
        'include_usage' => true,
    ],
];
$body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
$modelStateBefore = king_bench_model_state($url, $model);

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "content-type: application/json\r\naccept: text/event-stream\r\nconnection: close\r\n",
        'content' => $body,
        'ignore_errors' => true,
        'timeout' => 180,
    ],
]);

$startNs = hrtime(true);
$firstByteNs = null;
$firstContentNs = null;
$contentChunks = 0;
$contentBytes = 0;
$usage = null;
$errorEvents = [];
$handle = @fopen($url, 'rb', false, $context);
if ($handle === false) {
    fwrite(STDERR, "king-openai-bench: cannot connect to {$url}\n");
    exit(4);
}

while (!feof($handle)) {
    $line = fgets($handle);
    if ($line === false) {
        usleep(10_000);
        continue;
    }
    if ($firstByteNs === null && $line !== '') {
        $firstByteNs = hrtime(true);
    }
    $line = trim($line);
    if ($line === '' || str_starts_with($line, ':')) {
        continue;
    }
    if (str_starts_with($line, 'event: error')) {
        $errorEvents[] = 'error';
        continue;
    }
    if (!str_starts_with($line, 'data: ')) {
        continue;
    }
    $data = substr($line, 6);
    if ($data === '[DONE]') {
        break;
    }
    $event = json_decode($data, true);
    if (!is_array($event)) {
        continue;
    }
    if (is_array($event['usage'] ?? null)) {
        $usage = $event['usage'];
    }
    $content = king_bench_sse_content($event);
    if ($content === '') {
        continue;
    }
    if ($firstContentNs === null) {
        $firstContentNs = hrtime(true);
    }
    $contentChunks++;
    $contentBytes += strlen($content);
}
fclose($handle);
$endNs = hrtime(true);
$gpuAfter = king_bench_gpu_snapshot();
$modelStateAfter = king_bench_model_state($url, $model);

$totalMs = ($endNs - $startNs) / 1_000_000;
$ttfbMs = $firstContentNs !== null ? ($firstContentNs - $startNs) / 1_000_000 : null;
$firstByteMs = $firstByteNs !== null ? ($firstByteNs - $startNs) / 1_000_000 : null;
$decodeSeconds = $firstContentNs !== null ? max(0.001, ($endNs - $firstContentNs) / 1_000_000_000) : null;
$promptTokens = is_array($usage) && is_int($usage['prompt_tokens'] ?? null)
    ? $usage['prompt_tokens']
    : null;
$generatedTokens = is_array($usage) && is_int($usage['completion_tokens'] ?? null)
    ? $usage['completion_tokens']
    : $contentChunks;
$tokensPerSecond = $decodeSeconds !== null ? $generatedTokens / $decodeSeconds : null;

$metrics = [
    'url' => $url,
    'model' => $model,
    'model_state_before_request' => $modelStateBefore,
    'model_state_after_request' => $modelStateAfter,
    'resident_before_request' => $modelStateBefore['resident'],
    'resident_after_request' => $modelStateAfter['resident'],
    'request_body_bytes' => strlen($body),
    'tool_count' => 0,
    'prompt_tokens' => $promptTokens,
    'prompt_tokens_estimate' => king_bench_estimated_tokens($prompt),
    'requested_max_tokens' => $maxTokens,
    'generated_tokens' => $generatedTokens,
    'generated_tokens_stream_chunks' => $contentChunks,
    'generated_content_bytes' => $contentBytes,
    'first_byte_ms' => $firstByteMs,
    'ttfb_ms' => $ttfbMs,
    'total_ms' => $totalMs,
    'tokens_per_second' => $tokensPerSecond,
    'usage' => $usage,
    'thermal_ceiling_c' => $thermalCeiling,
    'gpu_before' => $gpuBefore,
    'gpu_after' => $gpuAfter,
    'gpu_delta' => king_bench_gpu_delta($gpuBefore, $gpuAfter),
    'error_events' => $errorEvents,
];

if ($jsonOnly) {
    echo json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

foreach ($metrics as $key => $value) {
    if (is_array($value)) {
        echo $key . '=' . json_encode($value, JSON_UNESCAPED_SLASHES) . "\n";
        continue;
    }
    if ($value === null) {
        echo $key . "=null\n";
        continue;
    }
    if (is_bool($value)) {
        echo $key . '=' . ($value ? 'yes' : 'no') . "\n";
        continue;
    }
    echo $key . '=' . $value . "\n";
}
