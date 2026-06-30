<?php
declare(strict_types=1);

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "king-inference-layer0-debug: {$message}\n");
    exit($code);
}

function usage(): never
{
    fwrite(STDERR, "Usage: bin/king-inference-layer0-debug [--prompt='Hello world'] [--position=0]\n");
    fwrite(STDERR, "       [--timeout-ms=30000] [--max-values=8] [--include-raw] [--json]\n");
    exit(64);
}

function parse_cli(array $argv): array
{
    $options = [
        'prompt' => getenv('KING_INFERENCE_LAYER0_PROMPT') ?: 'Hello world',
        'position' => getenv('KING_INFERENCE_LAYER0_POSITION') ?: '0',
        'timeout_ms' => getenv('KING_INFERENCE_LAYER0_TIMEOUT_MS') ?: '30000',
        'max_values' => getenv('KING_INFERENCE_LAYER0_NUMERIC_MAX_VALUES') ?: '8',
        'include_raw' => false,
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
        } elseif ($arg === '--include-raw') {
            $options['include_raw'] = true;
        } elseif (str_starts_with($arg, '--prompt=')) {
            $options['prompt'] = substr($arg, strlen('--prompt='));
        } elseif ($arg === '--prompt') {
            $options['prompt'] = $next();
        } elseif (str_starts_with($arg, '--position=')) {
            $options['position'] = substr($arg, strlen('--position='));
        } elseif ($arg === '--position') {
            $options['position'] = $next();
        } elseif (str_starts_with($arg, '--timeout-ms=')) {
            $options['timeout_ms'] = substr($arg, strlen('--timeout-ms='));
        } elseif ($arg === '--timeout-ms') {
            $options['timeout_ms'] = $next();
        } elseif (str_starts_with($arg, '--max-values=')) {
            $options['max_values'] = substr($arg, strlen('--max-values='));
        } elseif ($arg === '--max-values') {
            $options['max_values'] = $next();
        } else {
            fail("unsupported argument {$arg}", 64);
        }
    }

    foreach (['position', 'timeout_ms', 'max_values'] as $key) {
        if (!preg_match('/^[0-9]+$/', (string) $options[$key])) {
            fail("{$key} must be a non-negative integer", 64);
        }
        $options[$key] = (int) $options[$key];
    }
    if ((string) $options['prompt'] === '') {
        fail('prompt must not be empty', 64);
    }
    if ($options['timeout_ms'] <= 0 || $options['max_values'] <= 0) {
        fail('timeout-ms and max-values must be positive', 64);
    }

    return $options;
}

function layer0_model_config(int $maxValues): array
{
    $config = king_inference_runtime_model_config();
    if (($config['backend'] ?? null) !== 'king_native_gpu') {
        fail('layer-0 debug requires the runtime GPU profile; set the effective King inference profile to gpu');
    }

    $config['with_memory'] = false;
    $config['gpu'] = is_array($config['gpu'] ?? null) ? $config['gpu'] : [];
    $config['gpu']['debug'] = is_array($config['gpu']['debug'] ?? null) ? $config['gpu']['debug'] : [];
    $config['gpu']['debug']['numeric_compare_enabled'] = true;
    $config['gpu']['debug']['numeric_compare_max_values'] = $maxValues;

    return $config;
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

function reference_contract_report(King\Inference\Model $model): array
{
    $info = $model->info();
    $capabilities = is_array($info['backend_capabilities'] ?? null) ? $info['backend_capabilities'] : [];
    $contract = is_array($capabilities['reference_backend'] ?? null) ? $capabilities['reference_backend'] : [];
    $operations = is_array($contract['compared_operations'] ?? null) ? $contract['compared_operations'] : [];
    $required = [
        'embedding',
        'rms_norm',
        'qkv_projection',
        'rope',
        'attention_score',
        'attention_softmax',
        'attention_value',
        'ffn_gate_up',
        'ffn_swiglu',
        'ffn_down',
        'final_norm',
        'logits_projection',
    ];
    $missing = array_values(array_diff($required, $operations));
    if (($contract['available'] ?? false) !== true
        || ($contract['selected_reference'] ?? null) !== 'king_internal_cpu_reference'
        || ($contract['production_execution_path'] ?? true) !== false
        || $missing !== []) {
        fail('GPU reference backend contract is not wired for layer-0 numeric debug');
    }

    $gpuRuntime = is_array($info['gpu_runtime'] ?? null) ? $info['gpu_runtime'] : [];
    $ops = is_array($info['device_vector_ops'] ?? null)
        ? $info['device_vector_ops']
        : (is_array($gpuRuntime['device_vector_ops'] ?? null) ? $gpuRuntime['device_vector_ops'] : []);
    $hook = is_array($ops['numeric_compare_hook'] ?? null) ? $ops['numeric_compare_hook'] : [];

    return [
        'schema_version' => $contract['schema_version'] ?? null,
        'selected_reference' => $contract['selected_reference'] ?? null,
        'comparison_path' => $contract['comparison_path'] ?? null,
        'activation' => $contract['activation'] ?? null,
        'production_execution_path' => $contract['production_execution_path'] ?? null,
        'required_operations_present' => true,
        'numeric_compare_hook_status' => $hook['status'] ?? null,
        'numeric_compare_hook_reference' => $hook['reference_backend'] ?? null,
    ];
}

function first_graph_result(King\Inference\Stream $stream, int $timeoutMs): array
{
    while (($event = king_inference_next($stream, $timeoutMs)) !== null) {
        if (!is_array($event)) {
            continue;
        }
        if (($event['type'] ?? null) === 'gpu_decoder_graph_execution_result') {
            return $event;
        }
        if (($event['type'] ?? null) === 'error') {
            fail((string) ($event['message'] ?? 'native stream returned an error'));
        }
        if (($event['type'] ?? null) === 'done') {
            break;
        }
    }

    fail('native stream ended without gpu_decoder_graph_execution_result');
}

function collect_compares(mixed $value, array &$compares, array &$failed, string $path = ''): void
{
    if (!is_array($value)) {
        return;
    }
    if ((isset($value['type']) && is_string($value['type']) && str_contains($value['type'], 'numeric_compare'))
        || (array_key_exists('matched', $value) && array_key_exists('status', $value))) {
        $status = (string) ($value['status'] ?? '');
        $matched = $value['matched'] ?? null;
        $ok = ($matched === true) || $status === 'matched';
        $compares[] = [
            'path' => $path,
            'type' => $value['type'] ?? basename(str_replace('.', '/', $path)),
            'status' => $status !== '' ? $status : ($ok ? 'matched' : 'unknown'),
            'compared_values' => $value['compared_values'] ?? null,
            'matched_values' => $value['matched_values'] ?? null,
            'max_abs_diff' => $value['max_abs_diff'] ?? null,
            'tolerance' => $value['tolerance'] ?? null,
        ];
        if (!$ok) {
            $failed[] = $path;
        }
        return;
    }

    foreach ($value as $key => $child) {
        $childPath = $path === '' ? (string) $key : $path . '.' . (string) $key;
        if (is_string($key) && str_ends_with($key, '_numeric_compare') && is_array($child)) {
            $status = (string) ($child['status'] ?? '');
            $matched = $child['matched'] ?? null;
            $ok = ($matched === true) || $status === 'matched';
            $compares[] = [
                'path' => $childPath,
                'type' => $child['type'] ?? $key,
                'status' => $status !== '' ? $status : ($ok ? 'matched' : 'unknown'),
                'compared_values' => $child['compared_values'] ?? null,
                'matched_values' => $child['matched_values'] ?? null,
                'max_abs_diff' => $child['max_abs_diff'] ?? null,
                'tolerance' => $child['tolerance'] ?? null,
            ];
            if (!$ok) {
                $failed[] = $childPath;
            }
            continue;
        }
        if (is_string($key) && str_ends_with($key, '_numeric_compare_failed') && $child === true) {
            $failed[] = $childPath;
            continue;
        }
        collect_compares($child, $compares, $failed, $childPath);
    }
}

function stage_report(string $name, array $result, array $keys, ?string $compareTypeContains = null): array
{
    $present = [];
    $compares = [];
    $failed = [];

    foreach ($keys as $key) {
        if (!array_key_exists($key, $result)) {
            continue;
        }
        $present[] = $key;
        collect_compares($result[$key], $compares, $failed, $key);
    }
    if ($compareTypeContains !== null) {
        $compares = array_values(array_filter(
            $compares,
            static fn (array $compare): bool => str_contains((string) ($compare['type'] ?? ''), $compareTypeContains)
                || str_contains((string) ($compare['path'] ?? ''), $compareTypeContains)
        ));
        $failed = array_values(array_filter(
            $failed,
            static fn (string $path): bool => str_contains($path, $compareTypeContains)
        ));
    }

    return [
        'name' => $name,
        'ready' => $present !== [] && $failed === [],
        'present_keys' => $present,
        'compare_count' => count($compares),
        'compare_status' => $compares === [] ? 'missing' : ($failed === [] ? 'matched' : 'failed'),
        'failed_compares' => array_values(array_unique($failed)),
        'compares' => $compares,
    ];
}

function summarize_layer0(array $result): array
{
    $stages = [
        stage_report('embedding', $result, ['embedding_device_execution', 'embedding_numeric_compare']),
        stage_report('norm', $result, ['rms_norm_device_execution', 'first_rms_norm_numeric_compare']),
        stage_report('qkv', $result, ['linear_device_execution', 'block0_qkv_projection_numeric_compares']),
        stage_report('rope', $result, ['rope_device_execution', 'kv_head_prepare_device_execution'], 'rope'),
        stage_report('attention', $result, [
            'kv_head_prepare_device_execution',
            'block0_attention_score_numeric_compare',
            'block0_attention_softmax_numeric_compare',
            'block0_attention_value_numeric_compare',
        ]),
        stage_report('residual', $result, [
            'attention_output_projection_device_execution',
            'attention_residual_device_execution',
        ]),
        stage_report('ffn', $result, [
            'ffn_norm_device_execution',
            'ffn_gate_up_projection_device_execution',
            'ffn_swiglu_device_execution',
            'ffn_down_projection_device_execution',
            'ffn_output_residual_device_execution',
        ]),
        stage_report('logits', $result, ['final_norm_device_execution', 'logits_projection_device_execution']),
    ];

    $missing = [];
    $failed = [];
    foreach ($stages as $stage) {
        if ($stage['present_keys'] === []) {
            $missing[] = $stage['name'];
        }
        if ($stage['compare_status'] !== 'matched') {
            $failed[] = $stage['name'];
        }
    }

    return [
        'stages' => $stages,
        'all_required_stages_present' => $missing === [],
        'all_stage_compares_matched' => $failed === [],
        'missing_stages' => $missing,
        'stages_without_matched_compares' => $failed,
    ];
}

$options = parse_cli($argv);
$model = king_inference_model_load(layer0_model_config((int) $options['max_values']));
$referenceContract = reference_contract_report($model);
$encoded = king_inference_tokenize($model, (string) $options['prompt']);
$tokens = $encoded['tokens'] ?? null;
if (!is_array($tokens) || $tokens === []) {
    fail('tokenizer produced no token ids');
}
if (!array_key_exists((int) $options['position'], $tokens)) {
    fail('position exceeds tokenized prompt length', 64);
}

$graph = king_inference_token_decode_graph($model, $encoded, (int) $options['position'], [
    'debug_layer_limit' => 1,
    'emit_token' => true,
    'temperature' => 0.0,
    'sampler' => 'argmax',
]);
$stream = king_inference_stream(
    $model,
    ['graphs' => [$graph], 'graph_options' => graph_options($model)],
    ['with_memory' => false, 'max_native_stream_tokens' => 1]
);
$result = first_graph_result($stream, (int) $options['timeout_ms']);
$summary = summarize_layer0($result);
$payload = [
    'ok' => $summary['all_required_stages_present'] && $summary['all_stage_compares_matched'],
    'prompt' => $options['prompt'],
    'position' => $options['position'],
    'token_id' => $result['token_id'] ?? null,
    'block_count' => $result['block_count'] ?? null,
    'model_block_count' => $result['model_block_count'] ?? null,
    'debug_layer_limit' => $result['debug_layer_limit'] ?? null,
    'device_execution_result_ready' => $result['device_execution_result_ready'] ?? false,
    'executed_device_ops' => $result['executed_device_ops'] ?? null,
    'reference_backend_contract' => $referenceContract,
] + $summary;

if ($options['include_raw']) {
    $payload['raw_result'] = $result;
}

if ($options['json']) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    echo "Layer-0 debug: " . ($payload['ok'] ? 'OK' : 'FAILED') . "\n";
    echo "Token id: " . (string) $payload['token_id'] . "\n";
    echo "Blocks: " . (string) $payload['block_count'] . " / model " . (string) $payload['model_block_count'] . "\n";
    foreach ($payload['stages'] as $stage) {
        echo "- {$stage['name']}: {$stage['compare_status']} ({$stage['compare_count']} compares)\n";
    }
}

if (!$payload['ok']) {
    exit(2);
}
