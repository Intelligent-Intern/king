<?php
declare(strict_types=1);

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "king-native-hello-world: {$message}\n");
    exit($code);
}

function usage(): never
{
    fwrite(STDERR, "Usage: bin/king-native-hello-world [--backend=cpu|gpu|both] [--mode=roundtrip|prompt|both]\n");
    fwrite(STDERR, "       [--text='Hello world'] [--prompt='Say hello.'] [--tokens=1]\n");
    fwrite(STDERR, "       [--expect-prompt='Hello world']\n");
    fwrite(STDERR, "       [--prompt-template=auto|none]\n");
    fwrite(STDERR, "       [--cpu-model=/path/model.gguf] [--gpu-model=/path/model.gguf] [--json]\n");
    fwrite(STDERR, "       Defaults: --backend=both --mode=roundtrip --text='Hello world'\n");
    exit(64);
}

function parse_cli(array $argv): array
{
    $options = [
        'backend' => getenv('KING_INFERENCE_HELLO_BACKEND') ?: 'both',
        'mode' => getenv('KING_INFERENCE_HELLO_MODE') ?: 'roundtrip',
        'text' => getenv('KING_INFERENCE_HELLO_TEXT') ?: 'Hello world',
        'prompt' => getenv('KING_INFERENCE_HELLO_PROMPT') ?: 'Say hello.',
        'expect_prompt' => getenv('KING_INFERENCE_HELLO_EXPECT_PROMPT') ?: '',
        'prompt_template' => getenv('KING_INFERENCE_HELLO_PROMPT_TEMPLATE') ?: 'auto',
        'tokens' => getenv('KING_INFERENCE_HELLO_TOKENS') ?: '1',
        'cpu_model' => getenv('KING_INFERENCE_HELLO_CPU_MODEL_PATH') ?: '',
        'gpu_model' => getenv('KING_INFERENCE_HELLO_GPU_MODEL_PATH') ?: '',
        'model' => getenv('KING_INFERENCE_HELLO_MODEL_PATH') ?: '',
        'json' => false,
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = (string) $argv[$i];
        $next = static function () use (&$i, $argv, $arg): string {
            if (!array_key_exists($i + 1, $argv)) {
                fail("missing value for {$arg}", 64);
            }
            return (string) $argv[++$i];
        };

        if ($arg === '--help' || $arg === '-h') {
            usage();
        } elseif ($arg === '--json') {
            $options['json'] = true;
        } elseif ($arg === '--cpu') {
            $options['backend'] = 'cpu';
        } elseif ($arg === '--gpu') {
            $options['backend'] = 'gpu';
        } elseif ($arg === '--both') {
            $options['backend'] = 'both';
        } elseif (str_starts_with($arg, '--backend=')) {
            $options['backend'] = substr($arg, strlen('--backend='));
        } elseif ($arg === '--backend') {
            $options['backend'] = $next();
        } elseif (str_starts_with($arg, '--mode=')) {
            $options['mode'] = substr($arg, strlen('--mode='));
        } elseif ($arg === '--mode') {
            $options['mode'] = $next();
        } elseif (str_starts_with($arg, '--text=')) {
            $options['text'] = substr($arg, strlen('--text='));
        } elseif ($arg === '--text') {
            $options['text'] = $next();
        } elseif (str_starts_with($arg, '--prompt=')) {
            $options['prompt'] = substr($arg, strlen('--prompt='));
        } elseif ($arg === '--prompt') {
            $options['prompt'] = $next();
        } elseif (str_starts_with($arg, '--expect-prompt=')) {
            $options['expect_prompt'] = substr($arg, strlen('--expect-prompt='));
        } elseif ($arg === '--expect-prompt') {
            $options['expect_prompt'] = $next();
        } elseif (str_starts_with($arg, '--prompt-template=')) {
            $options['prompt_template'] = substr($arg, strlen('--prompt-template='));
        } elseif ($arg === '--prompt-template') {
            $options['prompt_template'] = $next();
        } elseif (str_starts_with($arg, '--tokens=')) {
            $options['tokens'] = substr($arg, strlen('--tokens='));
        } elseif ($arg === '--tokens') {
            $options['tokens'] = $next();
        } elseif (str_starts_with($arg, '--model=')) {
            $options['model'] = substr($arg, strlen('--model='));
        } elseif ($arg === '--model') {
            $options['model'] = $next();
        } elseif (str_starts_with($arg, '--cpu-model=')) {
            $options['cpu_model'] = substr($arg, strlen('--cpu-model='));
        } elseif ($arg === '--cpu-model') {
            $options['cpu_model'] = $next();
        } elseif (str_starts_with($arg, '--gpu-model=')) {
            $options['gpu_model'] = substr($arg, strlen('--gpu-model='));
        } elseif ($arg === '--gpu-model') {
            $options['gpu_model'] = $next();
        } else {
            fail("unsupported argument {$arg}", 64);
        }
    }

    $backend = strtolower((string) $options['backend']);
    if (!in_array($backend, ['cpu', 'gpu', 'both'], true)) {
        fail('backend must be cpu, gpu, or both', 64);
    }
    $options['backend'] = $backend;
    $mode = strtolower((string) $options['mode']);
    if (!in_array($mode, ['roundtrip', 'prompt', 'both'], true)) {
        fail('mode must be roundtrip, prompt, or both', 64);
    }
    $options['mode'] = $mode;
    if ((string) $options['text'] === '') {
        fail('text must not be empty', 64);
    }
    if ((string) $options['prompt'] === '') {
        fail('prompt must not be empty', 64);
    }
    $promptTemplate = strtolower((string) $options['prompt_template']);
    if (!in_array($promptTemplate, ['auto', 'none'], true)) {
        fail('prompt-template must be auto or none', 64);
    }
    $options['prompt_template'] = $promptTemplate;
    if (!preg_match('/^[1-9][0-9]*$/', (string) $options['tokens'])) {
        fail('tokens must be a positive integer', 64);
    }
    $options['tokens'] = (int) $options['tokens'];

    return $options;
}

function env_int(string $name, int $default): int
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    if (!preg_match('/^-?[0-9]+$/', $value)) {
        fail("{$name} must be an integer");
    }
    return (int) $value;
}

function env_float(string $name, float $default): float
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    if (!is_numeric($value)) {
        fail("{$name} must be numeric");
    }
    return (float) $value;
}

function env_bool(string $name, bool $default): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    $normalized = strtolower($value);
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    fail("{$name} must be boolean");
}

function resolve_model_path(array $options, string $backend): string
{
    $root = dirname(__DIR__, 2);
    $fallback = $backend === 'gpu'
        ? $root . '/var/inference-models/gemma4-12b.gguf'
        : $root . '/var/inference-models/gemma3-1b.gguf';
    $candidates = [];

    if ($backend === 'cpu') {
        $candidates[] = (string) $options['cpu_model'];
        $candidates[] = getenv('KING_INFERENCE_CPU_MODEL_PATH') ?: '';
    } else {
        $candidates[] = (string) $options['gpu_model'];
        $candidates[] = getenv('KING_INFERENCE_GPU_MODEL_PATH') ?: '';
    }

    $candidates[] = (string) $options['model'];
    $candidates[] = getenv('KING_INFERENCE_MODEL_PATH') ?: '';
    $candidates[] = getenv('KING_INFERENCE_TEST_MODEL_PATH') ?: '';
    $candidates[] = $fallback;

    foreach ($candidates as $candidate) {
        if ($candidate !== '') {
            if (!is_readable($candidate)) {
                continue;
            }
            return $candidate;
        }
    }

    fail("no readable {$backend} model found; set KING_INFERENCE_HELLO_" . strtoupper($backend) . "_MODEL_PATH");
}

function model_config(string $backend, string $path): array
{
    $nativeBackend = $backend === 'gpu' ? 'king_native_gpu' : 'king_native_cpu';
    $contextTokens = env_int('KING_INFERENCE_CONTEXT_TOKENS', 2048);
    $config = [
        'name' => 'hello-world-' . $backend,
        'artifact' => ['path' => $path],
        'backend' => $nativeBackend,
        'context_tokens' => $contextTokens,
        'kv_cache' => [
            'max_context_tokens' => $contextTokens,
            'page_tokens' => env_int('KING_INFERENCE_KV_PAGE_TOKENS', 16),
            'element_bytes' => env_int('KING_INFERENCE_KV_ELEMENT_BYTES', 2),
        ],
        'with_memory' => false,
    ];

    if ($backend === 'gpu') {
        $thermal = [
            'max_temperature_c' => env_float('KING_INFERENCE_GPU_THERMAL_MAX_C', 78.0),
            'check_interval_seconds' => env_int('KING_INFERENCE_GPU_THERMAL_CHECK_INTERVAL_SEC', 15),
            'allow_unmonitored_gpu' => env_bool('KING_INFERENCE_GPU_ALLOW_UNMONITORED', true),
        ];
        $sensorCommand = getenv('KING_INFERENCE_GPU_THERMAL_SENSOR_COMMAND');
        if ($sensorCommand === false) {
            $sensorCommand = 'nvidia-smi --query-gpu=temperature.gpu --format=csv,noheader,nounits';
        }
        if ($sensorCommand !== '') {
            $thermal['sensor_command'] = $sensorCommand;
        }

        $power = [
            'max_watts' => env_float('KING_INFERENCE_GPU_POWER_MAX_WATTS', 0.0),
            'check_interval_seconds' => env_int('KING_INFERENCE_GPU_POWER_CHECK_INTERVAL_SEC', 15),
        ];
        $powerSensorCommand = getenv('KING_INFERENCE_GPU_POWER_SENSOR_COMMAND');
        if ($powerSensorCommand === false) {
            $powerSensorCommand = 'nvidia-smi --query-gpu=power.draw --format=csv,noheader,nounits';
        }
        if ($powerSensorCommand !== '') {
            $power['sensor_command'] = $powerSensorCommand;
        }

        $config['gpu'] = [
            'enabled' => true,
            'max_gpu_layers' => env_int('KING_INFERENCE_GPU_MAX_GPU_LAYERS', 99),
            'vram_reserve_mb' => env_int('KING_INFERENCE_GPU_VRAM_RESERVE_MB', 1024),
            'min_free_vram_mb' => env_int('KING_INFERENCE_GPU_MIN_FREE_VRAM_MB', 1024),
            'thermal' => $thermal,
            'power' => $power,
        ];
    }

    return $config;
}

function model_uses_gemma_start_turn_template(King\Inference\Model $model): bool
{
    $info = $model->info();
    $architecture = (string) ($info['gguf']['architecture'] ?? $info['architecture'] ?? '');
    if (!str_starts_with($architecture, 'gemma')) {
        return false;
    }

    $start = king_inference_tokenize($model, '<start_of_turn>');
    $end = king_inference_tokenize($model, '<end_of_turn>');
    return ($start['tokens'] ?? []) !== [] && ($end['tokens'] ?? []) !== [];
}

function prompt_already_formatted(string $prompt): bool
{
    return str_contains($prompt, '<start_of_turn>')
        || str_contains($prompt, '<end_of_turn>')
        || str_contains($prompt, '<|turn>')
        || str_contains($prompt, '<turn|>');
}

function format_prompt(King\Inference\Model $model, string $prompt, string $templateMode): string
{
    if ($templateMode === 'none' || prompt_already_formatted($prompt)) {
        return $prompt;
    }
    if (!model_uses_gemma_start_turn_template($model)) {
        return $prompt;
    }

    return "<start_of_turn>user\n" . $prompt . "<end_of_turn>\n<start_of_turn>model\n";
}

function hello_graphs(King\Inference\Model $model, string $text): array
{
    $encoded = king_inference_tokenize($model, $text);
    $tokens = $encoded['tokens'] ?? null;
    if (!is_array($tokens) || $tokens === []) {
        fail('tokenizer produced no token ids');
    }

    $graphs = [];
    foreach ($tokens as $tokenId) {
        $graphs[] = [
            'inputs' => ['token' => [(int) $tokenId]],
            'ops' => [[
                'id' => 'next_token',
                'op' => 'scale',
                'input' => 'token',
                'factor' => 1.0,
            ]],
            'output' => 'next_token',
        ];
    }

    return $graphs;
}

function gpu_snapshot(): array
{
    $line = @shell_exec('nvidia-smi --query-gpu=temperature.gpu,utilization.gpu,memory.used,memory.total,power.draw --format=csv,noheader,nounits 2>/dev/null | head -n 1');
    if (!is_string($line) || trim($line) === '') {
        return ['available' => false];
    }

    $parts = array_map('trim', explode(',', trim($line)));
    return [
        'available' => true,
        'temperature_c' => isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : null,
        'utilization_percent' => isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : null,
        'vram_used_mb' => isset($parts[2]) && is_numeric($parts[2]) ? (float) $parts[2] : null,
        'vram_total_mb' => isset($parts[3]) && is_numeric($parts[3]) ? (float) $parts[3] : null,
        'power_w' => isset($parts[4]) && is_numeric($parts[4]) ? (float) $parts[4] : null,
    ];
}

function gpu_delta(array $before, array $after): array
{
    $delta = [];
    foreach (['temperature_c', 'utilization_percent', 'vram_used_mb', 'vram_total_mb', 'power_w'] as $field) {
        $beforeValue = $before[$field] ?? null;
        $afterValue = $after[$field] ?? null;
        $delta[$field] = is_float($beforeValue) && is_float($afterValue)
            ? $afterValue - $beforeValue
            : null;
    }

    return $delta;
}

function monotonic_elapsed_ms(int $startNs, int $endNs): float
{
    return ($endNs - $startNs) / 1_000_000;
}

function text_token_count(King\Inference\Model $model, string $text): int
{
    $encoded = king_inference_tokenize($model, $text);
    $tokens = $encoded['tokens'] ?? null;
    return is_array($tokens) ? count($tokens) : 0;
}

function stream_text(King\Inference\Stream $stream, int $streamStartNs): array
{
    $raw = '';
    $types = [];
    $firstTokenNs = null;
    $tokenEvents = 0;

    while (($event = king_inference_next($stream, 1000)) !== null) {
        if (!is_array($event)) {
            continue;
        }
        $type = (string) ($event['type'] ?? 'array');
        $types[] = $type;
        if ($type === 'token' && isset($event['text']) && is_string($event['text'])) {
            if ($firstTokenNs === null) {
                $firstTokenNs = hrtime(true);
            }
            $tokenEvents++;
            $raw .= $event['text'];
        }
        if ($type === 'done') {
            break;
        }
    }

    $finishedNs = hrtime(true);
    $streamTotalMs = monotonic_elapsed_ms($streamStartNs, $finishedNs);
    $generatedTokens = $tokenEvents;

    return [
        $raw,
        $types,
        $stream->getMetrics(),
        [
            'stream_ttfb_ms' => $firstTokenNs !== null ? monotonic_elapsed_ms($streamStartNs, $firstTokenNs) : null,
            'stream_total_ms' => $streamTotalMs,
            'token_event_count' => $tokenEvents,
            'tokens_per_second' => $generatedTokens > 0 && $streamTotalMs > 0.0
                ? $generatedTokens / ($streamTotalMs / 1000.0)
                : null,
        ],
    ];
}

function graph_options(King\Inference\Model $model): array
{
    $info = $model->info();
    $gguf = is_array($info['gguf'] ?? null) ? $info['gguf'] : [];
    $vocab = max(1, (int) ($gguf['tokenizer_token_count'] ?? 262144));
    $hidden = max(1, (int) ($gguf['embedding_length'] ?? 1152));

    return [
        'max_vector_values' => max($vocab, 262144),
        'max_operations' => max($vocab * $hidden + 1024, 400000000),
        'return_outputs' => false,
    ];
}

function result_payload(
    string $backend,
    string $mode,
    string $modelPath,
    string $text,
    string $raw,
    array $types,
    array $metrics,
    array $measurement,
    array $runtimeTruth,
    ?array $gpuBefore,
    ?array $gpuAfter,
    int $promptTokens,
    float $modelLoadMs,
    float $endToEndMs
): array {
    $generatedTokens = is_int($metrics['native_decoder_tokens'] ?? null)
        ? $metrics['native_decoder_tokens']
        : ($measurement['token_event_count'] ?? 0);

    return [
        'backend' => $backend,
        'mode' => $mode,
        'engine' => $backend === 'gpu' ? 'king_native_gpu' : 'king_native_cpu',
        'model' => $modelPath,
        'text' => $text,
        'raw_text' => $raw,
        'event_types' => $types,
        'prompt_tokens' => $promptTokens,
        'generated_tokens' => $generatedTokens,
        'ttfb_ms' => $measurement['stream_ttfb_ms'] ?? null,
        'stream_total_ms' => $measurement['stream_total_ms'] ?? null,
        'end_to_end_ms' => $endToEndMs,
        'model_load_ms' => $modelLoadMs,
        'tokens_per_second' => $measurement['tokens_per_second'] ?? null,
        'model_resident' => $runtimeTruth['model_resident'] ?? null,
        'resident_state' => $runtimeTruth['resident_state'] ?? null,
        'gpu_before' => $gpuBefore,
        'gpu_after' => $gpuAfter,
        'gpu_delta' => $gpuBefore !== null && $gpuAfter !== null ? gpu_delta($gpuBefore, $gpuAfter) : null,
        'native_decoder_tokens' => $metrics['native_decoder_tokens'] ?? null,
        'native_decoder_last_token_id' => $metrics['native_decoder_last_token_id'] ?? null,
        'native_decoder_last_probability' => $metrics['native_decoder_last_probability'] ?? null,
        'native_decoder_last_logit' => $metrics['native_decoder_last_logit'] ?? null,
        'native_decoder_last_rank' => $metrics['native_decoder_last_rank'] ?? null,
        'gpu_thermal_preflight_checked' => $metrics['gpu_thermal_preflight_checked'] ?? false,
        'gpu_thermal_preflight_temperature_c' => $metrics['gpu_thermal_preflight_temperature_c'] ?? null,
        'gpu_power_preflight_checked' => $metrics['gpu_power_preflight_checked'] ?? false,
        'gpu_power_preflight_watts' => $metrics['gpu_power_preflight_watts'] ?? null,
        'gpu_power_aborted' => $metrics['gpu_power_aborted'] ?? false,
        'gpu_power_abort_watts' => $metrics['gpu_power_abort_watts'] ?? null,
        'gpu_power_abort_ceiling_watts' => $metrics['gpu_power_abort_ceiling_watts'] ?? null,
    ];
}

function run_roundtrip_backend(string $backend, string $text, array $options): array
{
    $overallStartNs = hrtime(true);
    $modelPath = resolve_model_path($options, $backend);
    $gpuBefore = $backend === 'gpu' ? gpu_snapshot() : null;
    $loadStartNs = hrtime(true);
    $model = king_inference_model_load(model_config($backend, $modelPath));
    $modelLoadMs = monotonic_elapsed_ms($loadStartNs, hrtime(true));
    $graphs = hello_graphs($model, $text);
    $streamStartNs = hrtime(true);
    $stream = king_inference_stream(
        $model,
        ['graphs' => $graphs],
        ['with_memory' => false, 'max_native_stream_tokens' => max(16, count($graphs))]
    );
    [$raw, $types, $metrics, $measurement] = stream_text($stream, $streamStartNs);
    $gpuAfter = $backend === 'gpu' ? gpu_snapshot() : null;
    $info = $model->info();
    $runtimeTruth = is_array($info['runtime_truth'] ?? null) ? $info['runtime_truth'] : [];

    $actual = trim($raw);
    $expected = trim($text);
    if ($actual !== $expected) {
        fail("{$backend} roundtrip produced " . json_encode($actual) . ", expected " . json_encode($expected), 2);
    }

    return result_payload(
        $backend,
        'roundtrip',
        $modelPath,
        $actual,
        $raw,
        $types,
        $metrics,
        $measurement,
        $runtimeTruth,
        $gpuBefore,
        $gpuAfter,
        0,
        $modelLoadMs,
        monotonic_elapsed_ms($overallStartNs, hrtime(true))
    );
}

function run_prompt_backend(string $backend, string $prompt, int $tokens, array $options): array
{
    $overallStartNs = hrtime(true);
    $modelPath = resolve_model_path($options, $backend);
    $gpuBefore = $backend === 'gpu' ? gpu_snapshot() : null;
    $loadStartNs = hrtime(true);
    $model = king_inference_model_load(model_config($backend, $modelPath));
    $modelLoadMs = monotonic_elapsed_ms($loadStartNs, hrtime(true));
    $formattedPrompt = format_prompt($model, $prompt, (string) $options['prompt_template']);
    $promptTokens = text_token_count($model, $formattedPrompt);
    $streamStartNs = hrtime(true);
    $stream = king_inference_stream(
        $model,
        [
            'native_prompt_text' => $formattedPrompt,
            'max_tokens' => $tokens,
            'graph_options' => graph_options($model),
            'temperature' => 0,
        ],
        ['with_memory' => false, 'max_native_stream_tokens' => $tokens]
    );
    [$raw, $types, $metrics, $measurement] = stream_text($stream, $streamStartNs);
    $gpuAfter = $backend === 'gpu' ? gpu_snapshot() : null;
    $info = $model->info();
    $runtimeTruth = is_array($info['runtime_truth'] ?? null) ? $info['runtime_truth'] : [];
    $actual = trim($raw);

    if ($actual === '' || !in_array('token', $types, true)) {
        fail("{$backend} prompt mode produced no token text", 2);
    }
    if ((string) $options['expect_prompt'] !== '' && $actual !== trim((string) $options['expect_prompt'])) {
        fail(
            "{$backend} prompt mode produced " . json_encode($actual) . ", expected " . json_encode(trim((string) $options['expect_prompt'])),
            2
        );
    }

    return result_payload(
        $backend,
        'prompt',
        $modelPath,
        $actual,
        $raw,
        $types,
        $metrics,
        $measurement,
        $runtimeTruth,
        $gpuBefore,
        $gpuAfter,
        $promptTokens,
        $modelLoadMs,
        monotonic_elapsed_ms($overallStartNs, hrtime(true))
    );
}

if (!extension_loaded('king')) {
    fail('King extension is not loaded');
}

$options = parse_cli($argv);
$backends = $options['backend'] === 'both' ? ['cpu', 'gpu'] : [$options['backend']];
$modes = $options['mode'] === 'both' ? ['roundtrip', 'prompt'] : [$options['mode']];
$results = [];

foreach ($backends as $backend) {
    foreach ($modes as $mode) {
        if ($mode === 'roundtrip') {
            $results[] = run_roundtrip_backend($backend, (string) $options['text'], $options);
        } else {
            $results[] = run_prompt_backend($backend, (string) $options['prompt'], (int) $options['tokens'], $options);
        }
    }
}

if ($options['json']) {
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

foreach ($results as $result) {
    echo $result['backend'] . '/' . $result['mode'] . ' [' . $result['engine'] . ']: ' . $result['text'] . "\n";
}
