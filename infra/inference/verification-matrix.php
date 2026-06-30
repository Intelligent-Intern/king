<?php
declare(strict_types=1);

function king_verify_usage(): never
{
    fwrite(STDERR, <<<TXT
Usage: bin/king-inference-verify-matrix [options]

Options:
  --json              Print JSON report only.
  --strict-skips      Return non-zero when a gate is skipped.
  --gates CSV         Restrict gates by name.
  --model PATH        Use one GGUF artifact for CPU and GPU gates.
  --cpu-model PATH    Use a GGUF artifact for CPU-backed gates.
  --gpu-model PATH    Use a GGUF artifact for GPU-backed gates.

Default CI behavior does not download model weights. Model-backed gates skip
with the exact env/model path they need when no local artifact is configured.
TXT);
    exit(64);
}

function king_verify_option(array $argv, string $name, ?string $default = null): ?string
{
    $prefix = $name . '=';
    for ($i = 1, $count = count($argv); $i < $count; $i++) {
        if ($argv[$i] === $name && isset($argv[$i + 1])) {
            return $argv[$i + 1];
        }
        if (str_starts_with($argv[$i], $prefix)) {
            return substr($argv[$i], strlen($prefix));
        }
    }
    return $default;
}

function king_verify_flag(array $argv, string $name): bool
{
    return in_array($name, $argv, true);
}

function king_verify_bool_env(string $name, bool $default = false): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function king_verify_model_path(array $options, string $kind): array
{
    $root = dirname(__DIR__, 2);
    $names = $kind === 'gpu'
        ? ['KING_INFERENCE_GPU_TEST_MODEL_PATH', 'KING_INFERENCE_GPU_MODEL_PATH', 'KING_INFERENCE_TEST_MODEL_PATH']
        : ['KING_INFERENCE_CPU_TEST_MODEL_PATH', 'KING_INFERENCE_CPU_MODEL_PATH', 'KING_INFERENCE_TEST_MODEL_PATH'];
    $candidates = [];

    if ($kind === 'gpu') {
        $candidates[] = ['source' => '--gpu-model', 'path' => (string) ($options['gpu_model'] ?? '')];
    } else {
        $candidates[] = ['source' => '--cpu-model', 'path' => (string) ($options['cpu_model'] ?? '')];
    }
    $candidates[] = ['source' => '--model', 'path' => (string) ($options['model'] ?? '')];

    foreach ($names as $name) {
        $value = getenv($name);
        $candidates[] = ['source' => $name, 'path' => is_string($value) ? $value : ''];
    }

    $default = $root . '/var/inference-models/gemma3-1b.gguf';
    if (!king_verify_bool_env('KING_INFERENCE_VERIFY_DISABLE_DEFAULT_MODEL')) {
        $candidates[] = ['source' => 'default:' . $default, 'path' => $default];
    }

    foreach ($candidates as $candidate) {
        $path = $candidate['path'];
        if ($path !== '' && is_file($path) && is_readable($path)) {
            $real = realpath($path);
            return [
                'available' => true,
                'path' => $path,
                'real_path' => $real !== false ? $real : $path,
                'source' => $candidate['source'],
                'bytes' => filesize($path),
                'sha256' => hash_file('sha256', $path),
            ];
        }
    }

    return [
        'available' => false,
        'path' => '',
        'source' => '',
        'needed' => implode(' or ', array_merge(
            $kind === 'gpu' ? ['--gpu-model'] : ['--cpu-model'],
            ['--model'],
            $names,
            ['readable ' . $default . ' unless KING_INFERENCE_VERIFY_DISABLE_DEFAULT_MODEL=1']
        )),
    ];
}

function king_verify_skip(string $name, string $reason, array $meta = []): array
{
    return ['name' => $name, 'status' => 'skipped', 'reason' => $reason] + $meta;
}

function king_verify_pass(string $name, array $meta = []): array
{
    return ['name' => $name, 'status' => 'passed', 'reason' => 'ok'] + $meta;
}

function king_verify_fail(string $name, Throwable $e, array $meta = []): array
{
    return [
        'name' => $name,
        'status' => 'failed',
        'reason' => $e::class . ': ' . $e->getMessage(),
    ] + $meta;
}

function king_verify_cpu_model(array $path): King\Inference\Model
{
    return king_inference_model_load([
        'name' => 'verify-cpu',
        'artifact' => ['path' => $path['path']],
        'backend' => 'king_native_cpu',
        'context_tokens' => 2048,
        'with_memory' => false,
    ]);
}

function king_verify_gpu_model(array $path): King\Inference\Model
{
    return king_inference_model_load([
        'name' => 'verify-gpu',
        'artifact' => ['path' => $path['path']],
        'backend' => 'king_native_gpu',
        'context_tokens' => 2048,
        'with_memory' => false,
        'gpu' => [
            'enabled' => true,
            'max_gpu_layers' => 99,
            'vram_reserve_mb' => 1024,
            'min_free_vram_mb' => 1024,
            'thermal' => [
                'sensor_command' => 'nvidia-smi --query-gpu=temperature.gpu --format=csv,noheader,nounits',
                'max_temperature_c' => 78.0,
                'check_interval_seconds' => 15,
                'allow_unmonitored_gpu' => true,
            ],
        ],
    ]);
}

function king_verify_graph_options(King\Inference\Model $model): array
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

function king_verify_drain_text(King\Inference\Stream $stream, int $timeoutMs = 1000): array
{
    $text = '';
    $types = [];
    $events = 0;
    while (($event = king_inference_next($stream, $timeoutMs)) !== null) {
        if (!is_array($event)) {
            continue;
        }
        $events++;
        $type = (string) ($event['type'] ?? '');
        $types[] = $type;
        if ($type === 'token' && is_string($event['text'] ?? null)) {
            $text .= $event['text'];
        }
        if ($type === 'done' || $type === 'cancelled') {
            break;
        }
    }
    return ['text' => $text, 'types' => $types, 'events' => $events, 'metrics' => $stream->getMetrics()];
}

function king_verify_roundtrip_graphs(King\Inference\Model $model, string $text): array
{
    $encoded = king_inference_tokenize($model, $text);
    $tokens = is_array($encoded['tokens'] ?? null) ? $encoded['tokens'] : [];
    if ($tokens === []) {
        throw new RuntimeException('tokenizer produced no tokens');
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

function king_verify_openai_content(array $response): string
{
    if (($response['status'] ?? 0) !== 200) {
        throw new RuntimeException('OpenAI response status ' . (string) ($response['status'] ?? 0));
    }
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        throw new RuntimeException('OpenAI response body is not JSON');
    }
    $content = $payload['choices'][0]['message']['content'] ?? null;
    if (!is_string($content)) {
        throw new RuntimeException('OpenAI response has no assistant content');
    }
    return $content;
}

function king_verify_gate_gguf(array $cpuPath): array
{
    if (!$cpuPath['available']) {
        return king_verify_skip('gguf_load', 'missing_model_path', ['needed' => $cpuPath['needed']]);
    }
    try {
        $model = king_verify_cpu_model($cpuPath);
        $info = $model->info();
        if (($info['gguf']['header_loaded'] ?? false) !== true || (int) ($info['gguf']['tensor_count'] ?? 0) <= 0) {
            throw new RuntimeException('GGUF header or tensor directory was not loaded');
        }
        return king_verify_pass('gguf_load', [
            'model_path' => $cpuPath,
            'tensor_count' => $info['gguf']['tensor_count'] ?? null,
            'architecture' => $info['gguf']['architecture'] ?? null,
        ]);
    } catch (Throwable $e) {
        return king_verify_fail('gguf_load', $e, ['model_path' => $cpuPath]);
    }
}

function king_verify_gate_tokenizer(array $cpuPath): array
{
    if (!$cpuPath['available']) {
        return king_verify_skip('tokenizer', 'missing_model_path', ['needed' => $cpuPath['needed']]);
    }
    try {
        $model = king_verify_cpu_model($cpuPath);
        $encoded = king_inference_tokenize($model, 'Hello world');
        if ((int) ($encoded['token_count'] ?? 0) <= 0 || (int) ($encoded['unknown_count'] ?? 0) !== 0) {
            throw new RuntimeException('tokenizer did not produce clean token ids');
        }
        $stream = king_inference_stream($model, ['graphs' => king_verify_roundtrip_graphs($model, 'Hello world')], [
            'with_memory' => false,
            'max_native_stream_tokens' => 16,
        ]);
        $result = king_verify_drain_text($stream);
        if (trim($result['text']) !== 'Hello world') {
            throw new RuntimeException('token roundtrip mismatch');
        }
        return king_verify_pass('tokenizer', [
            'model_path' => $cpuPath,
            'token_count' => $encoded['token_count'] ?? null,
            'events' => $result['events'],
        ]);
    } catch (Throwable $e) {
        return king_verify_fail('tokenizer', $e, ['model_path' => $cpuPath]);
    }
}

function king_verify_gate_cpu_reference(array $cpuPath): array
{
    if (!$cpuPath['available']) {
        return king_verify_skip('cpu_reference', 'missing_model_path', ['needed' => $cpuPath['needed']]);
    }
    try {
        $model = king_verify_cpu_model($cpuPath);
        $encoded = king_inference_tokenize($model, 'Hello');
        $graph = king_inference_token_decode_graph($model, $encoded, 0, [
            'emit_token' => false,
            'emit_logits' => true,
        ]);
        $result = king_inference_graph_run($model, $graph, king_verify_graph_options($model));
        $logits = $result['final']['logits'] ?? null;
        if (!is_array($logits) || (int) ($logits['length'] ?? 0) <= 0) {
            throw new RuntimeException('CPU reference produced no logits');
        }
        if (!is_array($result['state']['kv_cache'] ?? null) || count($result['state']['kv_cache']) === 0) {
            throw new RuntimeException('CPU reference produced no KV state');
        }
        return king_verify_pass('cpu_reference', [
            'model_path' => $cpuPath,
            'op_count' => $result['op_count'] ?? null,
            'logit_count' => $logits['length'] ?? null,
        ]);
    } catch (Throwable $e) {
        return king_verify_fail('cpu_reference', $e, ['model_path' => $cpuPath]);
    }
}

function king_verify_gate_gpu_smoke(array $gpuPath): array
{
    if (!$gpuPath['available']) {
        return king_verify_skip('gpu_smoke', 'missing_model_path', ['needed' => $gpuPath['needed']]);
    }
    if (trim((string) shell_exec('command -v nvidia-smi 2>/dev/null')) === '') {
        return king_verify_skip('gpu_smoke', 'missing_gpu_runtime', ['needed' => 'nvidia-smi and CUDA runtime']);
    }
    try {
        $model = king_verify_gpu_model($gpuPath);
        $info = $model->info();
        if (($info['runtime_truth']['silent_cpu_fallback'] ?? true) !== false) {
            throw new RuntimeException('GPU smoke detected silent CPU fallback');
        }
        $stream = king_inference_stream($model, ['graphs' => king_verify_roundtrip_graphs($model, 'Hello world')], [
            'with_memory' => false,
            'max_native_stream_tokens' => 16,
        ]);
        $result = king_verify_drain_text($stream);
        if (trim($result['text']) !== 'Hello world') {
            throw new RuntimeException('GPU token-vector roundtrip mismatch');
        }
        return king_verify_pass('gpu_smoke', [
            'model_path' => $gpuPath,
            'backend' => $info['backend'] ?? null,
            'active_device' => $info['runtime_truth']['active_device'] ?? null,
            'events' => $result['events'],
        ]);
    } catch (Throwable $e) {
        return king_verify_fail('gpu_smoke', $e, ['model_path' => $gpuPath]);
    }
}

function king_verify_gate_cpu_gpu_match(array $cpuPath, array $gpuPath): array
{
    if (!$cpuPath['available']) {
        return king_verify_skip('cpu_gpu_match', 'missing_cpu_model_path', ['needed' => $cpuPath['needed']]);
    }
    if (!$gpuPath['available']) {
        return king_verify_skip('cpu_gpu_match', 'missing_gpu_model_path', ['needed' => $gpuPath['needed']]);
    }
    if (trim((string) shell_exec('command -v nvidia-smi 2>/dev/null')) === '') {
        return king_verify_skip('cpu_gpu_match', 'missing_gpu_runtime', ['needed' => 'nvidia-smi and CUDA runtime']);
    }

    try {
        $text = 'Hello world';
        $cpuModel = king_verify_cpu_model($cpuPath);
        $cpuStream = king_inference_stream($cpuModel, ['graphs' => king_verify_roundtrip_graphs($cpuModel, $text)], [
            'with_memory' => false,
            'max_native_stream_tokens' => 16,
        ]);
        $cpu = king_verify_drain_text($cpuStream);

        $gpuModel = king_verify_gpu_model($gpuPath);
        $gpuInfo = $gpuModel->info();
        if (($gpuInfo['runtime_truth']['silent_cpu_fallback'] ?? true) !== false) {
            throw new RuntimeException('GPU side used silent CPU fallback');
        }
        $gpuStream = king_inference_stream($gpuModel, ['graphs' => king_verify_roundtrip_graphs($gpuModel, $text)], [
            'with_memory' => false,
            'max_native_stream_tokens' => 16,
        ]);
        $gpu = king_verify_drain_text($gpuStream);

        if (trim($cpu['text']) !== $text || trim($gpu['text']) !== $text || trim($cpu['text']) !== trim($gpu['text'])) {
            throw new RuntimeException('CPU/GPU token-vector roundtrip output mismatch');
        }

        return king_verify_pass('cpu_gpu_match', [
            'cpu_model_path' => $cpuPath,
            'gpu_model_path' => $gpuPath,
            'active_device' => $gpuInfo['runtime_truth']['active_device'] ?? null,
            'cpu_events' => $cpu['events'],
            'gpu_events' => $gpu['events'],
        ]);
    } catch (Throwable $e) {
        return king_verify_fail('cpu_gpu_match', $e, ['cpu_model_path' => $cpuPath, 'gpu_model_path' => $gpuPath]);
    }
}

function king_verify_gate_openai(array $cpuPath): array
{
    if (!$cpuPath['available']) {
        return king_verify_skip('openai_route_smoke', 'missing_model_path', ['needed' => $cpuPath['needed']]);
    }
    try {
        $model = king_verify_cpu_model($cpuPath);
        $response = king_inference_openai_http_response(['verify-cpu' => $model], [
            'method' => 'POST',
            'path' => '/v1/chat/completions',
            'body' => json_encode([
                'model' => 'verify-cpu',
                'messages' => [['role' => 'user', 'content' => 'Say hello.']],
                'max_tokens' => 1,
                'temperature' => 0,
            ], JSON_UNESCAPED_SLASHES),
        ], ['read_timeout_ms' => 250, 'max_events' => 64, 'max_idle_events' => 16]);
        $content = king_verify_openai_content($response);
        if (trim($content) === '') {
            throw new RuntimeException('OpenAI route emitted empty content');
        }
        return king_verify_pass('openai_route_smoke', ['model_path' => $cpuPath, 'content_bytes' => strlen($content)]);
    } catch (Throwable $e) {
        return king_verify_fail('openai_route_smoke', $e, ['model_path' => $cpuPath]);
    }
}

function king_verify_gate_long_prompt(array $cpuPath): array
{
    if (!$cpuPath['available']) {
        return king_verify_skip('long_prompt', 'missing_model_path', ['needed' => $cpuPath['needed']]);
    }
    try {
        $model = king_verify_cpu_model($cpuPath);
        $words = [];
        for ($i = 0; $i < 384; $i++) {
            $words[] = 'context' . ($i % 31);
        }
        $prompt = implode(' ', $words) . '. Answer with one token.';
        $response = king_inference_openai_http_response(['verify-cpu' => $model], [
            'method' => 'POST',
            'path' => '/v1/chat/completions',
            'body' => json_encode([
                'model' => 'verify-cpu',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'max_tokens' => 1,
                'temperature' => 0,
            ], JSON_UNESCAPED_SLASHES),
        ], ['read_timeout_ms' => 500, 'max_events' => 128, 'max_idle_events' => 32]);
        $content = king_verify_openai_content($response);
        if (trim($content) === '') {
            throw new RuntimeException('long prompt emitted empty content');
        }
        return king_verify_pass('long_prompt', ['model_path' => $cpuPath, 'prompt_words' => count($words)]);
    } catch (Throwable $e) {
        return king_verify_fail('long_prompt', $e, ['model_path' => $cpuPath]);
    }
}

function king_verify_gate_stop(array $cpuPath): array
{
    if (!$cpuPath['available']) {
        return king_verify_skip('stop_tokens', 'missing_model_path', ['needed' => $cpuPath['needed']]);
    }
    try {
        $model = king_verify_cpu_model($cpuPath);
        $response = king_inference_openai_http_response(['verify-cpu' => $model], [
            'method' => 'POST',
            'path' => '/v1/chat/completions',
            'body' => json_encode([
                'model' => 'verify-cpu',
                'messages' => [['role' => 'user', 'content' => 'Write alpha STOP_HERE beta.']],
                'max_tokens' => 16,
                'temperature' => 0,
                'stop' => ['STOP_HERE'],
            ], JSON_UNESCAPED_SLASHES),
        ], ['read_timeout_ms' => 250, 'max_events' => 64, 'max_idle_events' => 16]);
        $content = king_verify_openai_content($response);
        if (str_contains($content, 'STOP_HERE')) {
            throw new RuntimeException('stop sequence leaked into assistant content');
        }
        return king_verify_pass('stop_tokens', ['model_path' => $cpuPath, 'content_bytes' => strlen($content)]);
    } catch (Throwable $e) {
        return king_verify_fail('stop_tokens', $e, ['model_path' => $cpuPath]);
    }
}

function king_verify_gate_cancellation(array $cpuPath): array
{
    if (!$cpuPath['available']) {
        return king_verify_skip('cancellation', 'missing_model_path', ['needed' => $cpuPath['needed']]);
    }
    try {
        $model = king_verify_cpu_model($cpuPath);
        $stream = king_inference_stream($model, ['graphs' => king_verify_roundtrip_graphs($model, 'Hello world')], [
            'with_memory' => false,
            'max_native_stream_tokens' => 16,
        ]);
        $first = king_inference_next($stream, 1000);
        if (!is_array($first) || ($first['type'] ?? null) !== 'start') {
            throw new RuntimeException('stream did not start before cancellation');
        }
        if (!king_inference_cancel($stream)) {
            throw new RuntimeException('king_inference_cancel returned false');
        }
        $next = king_inference_next($stream, 1000);
        $metrics = $stream->getMetrics();
        if (($metrics['cancelled'] ?? false) !== true && (!is_array($next) || ($next['type'] ?? null) !== 'cancelled')) {
            throw new RuntimeException('stream did not report cancelled state');
        }
        return king_verify_pass('cancellation', [
            'model_path' => $cpuPath,
            'next_type' => is_array($next) ? ($next['type'] ?? null) : null,
        ]);
    } catch (Throwable $e) {
        return king_verify_fail('cancellation', $e, ['model_path' => $cpuPath]);
    }
}

function king_verify_gate_error_taxonomy(): array
{
    try {
        $response = king_inference_openai_http_response([], [
            'method' => 'POST',
            'path' => '/v1/chat/completions',
            'body' => '{',
        ], []);
        if (($response['status'] ?? 0) < 400) {
            throw new RuntimeException('invalid request did not return an error status');
        }
        $payload = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($payload)) {
            throw new RuntimeException('error response body is not JSON');
        }
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $code = $error['code'] ?? null;
        $king = is_array($error['x_king'] ?? null) ? $error['x_king'] : [];
        if (!is_string($code) || !str_starts_with($code, 'king.')) {
            throw new RuntimeException('error response did not expose a stable King error code');
        }
        if (($king['prompt_included'] ?? true) !== false || ($king['request_body_included'] ?? true) !== false) {
            throw new RuntimeException('error taxonomy leaked prompt or request body');
        }

        return king_verify_pass('error_taxonomy', [
            'status' => $response['status'] ?? null,
            'code' => $code,
            'category' => $king['category'] ?? null,
        ]);
    } catch (Throwable $e) {
        return king_verify_fail('error_taxonomy', $e);
    }
}

if (king_verify_flag($argv, '--help') || king_verify_flag($argv, '-h')) {
    king_verify_usage();
}

$options = [
    'json' => king_verify_flag($argv, '--json'),
    'strict_skips' => king_verify_flag($argv, '--strict-skips') || king_verify_bool_env('KING_INFERENCE_VERIFY_STRICT_SKIPS'),
    'model' => king_verify_option($argv, '--model', ''),
    'cpu_model' => king_verify_option($argv, '--cpu-model', ''),
    'gpu_model' => king_verify_option($argv, '--gpu-model', ''),
];
$gateCsv = king_verify_option($argv, '--gates', getenv('KING_INFERENCE_VERIFY_GATES') ?: '');
$selected = $gateCsv !== '' ? array_flip(array_filter(array_map('trim', explode(',', $gateCsv)))) : null;
$includeExpensive = king_verify_bool_env('KING_INFERENCE_VERIFY_INCLUDE_EXPENSIVE');

$cpuPath = king_verify_model_path($options, 'cpu');
$gpuPath = king_verify_model_path($options, 'gpu');
$expensiveGates = array_fill_keys(['cpu_reference', 'long_prompt'], true);
$gates = [
    'tokenizer' => static fn (): array => king_verify_gate_tokenizer($cpuPath),
    'gguf_load' => static fn (): array => king_verify_gate_gguf($cpuPath),
    'cpu_reference' => static fn (): array => king_verify_gate_cpu_reference($cpuPath),
    'gpu_smoke' => static fn (): array => king_verify_gate_gpu_smoke($gpuPath),
    'cpu_gpu_match' => static fn (): array => king_verify_gate_cpu_gpu_match($cpuPath, $gpuPath),
    'openai_route_smoke' => static fn (): array => king_verify_gate_openai($cpuPath),
    'long_prompt' => static fn (): array => king_verify_gate_long_prompt($cpuPath),
    'stop_tokens' => static fn (): array => king_verify_gate_stop($cpuPath),
    'cancellation' => static fn (): array => king_verify_gate_cancellation($cpuPath),
    'error_taxonomy' => static fn (): array => king_verify_gate_error_taxonomy(),
];

$results = [];
foreach ($gates as $name => $runner) {
    if ($selected !== null && !array_key_exists($name, $selected)) {
        continue;
    }
    if ($selected === null && !$includeExpensive && isset($expensiveGates[$name]) && $cpuPath['available']) {
        $results[] = king_verify_skip($name, 'expensive_gate_not_requested', [
            'needed' => '--gates=' . $name . ' or KING_INFERENCE_VERIFY_INCLUDE_EXPENSIVE=1',
            'model_path' => $cpuPath,
        ]);
        continue;
    }
    $results[] = $runner();
}

$counts = ['passed' => 0, 'failed' => 0, 'skipped' => 0];
foreach ($results as $result) {
    $status = (string) ($result['status'] ?? 'failed');
    $counts[$status] = ($counts[$status] ?? 0) + 1;
}

$report = [
    'schema_version' => 1,
    'generated_at' => date(DATE_ATOM),
    'default_ci_downloads_models' => false,
    'cpu_model_path' => $cpuPath,
    'gpu_model_path' => $gpuPath,
    'counts' => $counts,
    'gates' => $results,
];

$exit = $counts['failed'] > 0 || ($options['strict_skips'] && $counts['skipped'] > 0) ? 1 : 0;
if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($exit);
}

foreach ($results as $result) {
    echo ($result['status'] ?? 'failed') . ' ' . ($result['name'] ?? '?') . ': ' . ($result['reason'] ?? '') . "\n";
    if (($result['status'] ?? '') === 'skipped' && isset($result['needed'])) {
        echo '  needed: ' . $result['needed'] . "\n";
    }
}
echo 'summary passed=' . $counts['passed'] . ' skipped=' . $counts['skipped'] . ' failed=' . $counts['failed'] . "\n";
exit($exit);
