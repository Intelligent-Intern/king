<?php
declare(strict_types=1);

function king_baseline_usage(): void
{
    fwrite(STDERR, <<<TXT
Usage: bin/king-openai-baseline [options]

Options:
  --runs N             Runs per profile. Default: 3.
  --max-tokens N       Completion cap per run. Default: 8.
  --port-start N       First local router port. Default: 19080.
  --profiles CSV       Profiles: cpu-gemma3,gpu-gemma3,gpu-large. Default: all.
  --skip-large         Skip the configured larger GPU profile.
  --json               Print JSON only.

The script starts one local King OpenAI router per profile, measures streamed
chat completions, records host/GPU/model/artifact metadata, and shuts each
router down again.
TXT);
}

function king_baseline_option(array $argv, string $name, ?string $default = null): ?string
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

function king_baseline_flag(array $argv, string $name): bool
{
    return in_array($name, $argv, true);
}

function king_baseline_int(array $argv, string $name, int $default, int $min = 1): int
{
    $value = king_baseline_option($argv, $name);
    return is_string($value) && preg_match('/^\d+$/', $value) === 1
        ? max($min, (int) $value)
        : $default;
}

function king_baseline_percentile(array $values, float $percentile): ?float
{
    $numbers = array_values(array_filter($values, static fn (mixed $v): bool => is_int($v) || is_float($v)));
    if ($numbers === []) {
        return null;
    }
    sort($numbers, SORT_NUMERIC);
    $index = (int) ceil(($percentile / 100.0) * count($numbers)) - 1;
    $index = max(0, min(count($numbers) - 1, $index));
    return round((float) $numbers[$index], 3);
}

function king_baseline_shell_line(string $command): string
{
    $lines = [];
    $code = 0;
    exec($command, $lines, $code);
    return $code === 0 && isset($lines[0]) ? trim($lines[0]) : '';
}

function king_baseline_gpu_snapshot(): array
{
    $line = king_baseline_shell_line(
        'nvidia-smi --query-gpu=name,driver_version,temperature.gpu,utilization.gpu,memory.used,memory.total,power.draw --format=csv,noheader,nounits 2>/dev/null'
    );
    if ($line === '') {
        return ['available' => false];
    }
    $parts = array_map('trim', explode(',', $line));
    return [
        'available' => true,
        'name' => $parts[0] ?? '',
        'driver_version' => $parts[1] ?? '',
        'temperature_c' => isset($parts[2]) && is_numeric($parts[2]) ? (float) $parts[2] : null,
        'utilization_percent' => isset($parts[3]) && is_numeric($parts[3]) ? (float) $parts[3] : null,
        'vram_used_mb' => isset($parts[4]) && is_numeric($parts[4]) ? (float) $parts[4] : null,
        'vram_total_mb' => isset($parts[5]) && is_numeric($parts[5]) ? (float) $parts[5] : null,
        'power_w' => isset($parts[6]) && is_numeric($parts[6]) ? (float) $parts[6] : null,
    ];
}

function king_baseline_cuda_runtime_version(): ?string
{
    $version = king_baseline_shell_line('cat /usr/local/cuda/version.txt 2>/dev/null');
    if ($version !== '') {
        return $version;
    }

    $version = king_baseline_shell_line('nvcc --version 2>/dev/null');
    return $version !== '' ? $version : null;
}

function king_baseline_gpu_delta(array $before, array $after): array
{
    $delta = [];
    foreach (['temperature_c', 'utilization_percent', 'vram_used_mb', 'vram_total_mb', 'power_w'] as $key) {
        $delta[$key] = is_numeric($before[$key] ?? null) && is_numeric($after[$key] ?? null)
            ? round((float) $after[$key] - (float) $before[$key], 3)
            : null;
    }
    return $delta;
}

function king_baseline_cpu_model(): string
{
    $lines = @file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return php_uname('m');
    }
    foreach ($lines as $line) {
        if (str_starts_with($line, 'model name')) {
            $parts = explode(':', $line, 2);
            return trim($parts[1] ?? php_uname('m'));
        }
    }
    return php_uname('m');
}

function king_baseline_memory_total_mb(): ?int
{
    $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return null;
    }
    foreach ($lines as $line) {
        if (preg_match('/^MemTotal:\s+(\d+)\s+kB$/', $line, $m) === 1) {
            return (int) floor(((int) $m[1]) / 1024);
        }
    }
    return null;
}

function king_baseline_artifact(string $path): array
{
    $real = realpath($path) ?: $path;
    $readable = is_file($path) && is_readable($path);
    return [
        'path' => $path,
        'real_path' => $real,
        'readable' => $readable,
        'bytes' => $readable ? filesize($path) : null,
        'sha256' => $readable ? hash_file('sha256', $path) : null,
    ];
}

function king_baseline_http_json(string $method, string $url, ?array $payload = null, float $timeout = 10.0): array
{
    $options = ['http' => [
        'method' => $method,
        'header' => "content-type: application/json\r\nconnection: close\r\n",
        'ignore_errors' => true,
        'timeout' => $timeout,
    ]];
    if ($payload !== null) {
        $options['http']['content'] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $body = @file_get_contents($url, false, stream_context_create($options));
    $headers = $http_response_header ?? [];
    $statusLine = $headers[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $match);
    $status = isset($match[1]) ? (int) $match[1] : 0;
    $json = is_string($body) && $body !== '' ? json_decode($body, true) : null;
    return [$status, is_array($json) ? $json : null, is_string($body) ? $body : ''];
}

function king_baseline_wait_for_router(string $modelsUrl, int $timeoutSeconds): bool
{
    $deadline = time() + $timeoutSeconds;
    while (time() <= $deadline) {
        [$status] = king_baseline_http_json('GET', $modelsUrl, null, 1.0);
        if ($status === 200) {
            return true;
        }
        usleep(250_000);
    }
    return false;
}

function king_baseline_model_meta(string $modelsUrl, string $model): array
{
    [$status, $json, $raw] = king_baseline_http_json('GET', $modelsUrl, null, 5.0);
    $meta = ['status' => $status, 'listed' => false, 'raw_error' => $status === 200 ? '' : substr($raw, 0, 500)];
    if ($status !== 200 || !is_array($json['data'] ?? null)) {
        return $meta;
    }
    foreach ($json['data'] as $item) {
        if (!is_array($item) || ($item['id'] ?? null) !== $model) {
            continue;
        }
        $king = is_array($item['x_king'] ?? null) ? $item['x_king'] : [];
        $truth = is_array($king['runtime_truth'] ?? null) ? $king['runtime_truth'] : [];
        $readiness = is_array($king['native_engine_readiness'] ?? null) ? $king['native_engine_readiness'] : [];
        $gpuRuntime = is_array($king['gpu_runtime'] ?? null) ? $king['gpu_runtime'] : [];
        $route = is_array($king['openai_route'] ?? null) ? $king['openai_route'] : [];
        return $meta + [
            'listed' => true,
            'id' => $item['id'],
            'backend' => $king['backend'] ?? null,
            'active_device' => $truth['active_device'] ?? null,
            'silent_cpu_fallback' => $truth['silent_cpu_fallback'] ?? null,
            'model_resident' => $truth['model_resident'] ?? null,
            'context_tokens' => $truth['context_tokens'] ?? null,
            'quantization' => $king['quantization'] ?? null,
            'openai_generation' => $king['openai_generation'] ?? null,
            'native_engine_state' => $readiness['state'] ?? null,
            'plain_text_generation_admitted' => $readiness['plain_text_generation_admitted'] ?? null,
            'gpu_generation_ready' => $gpuRuntime['generation_ready'] ?? null,
            'gpu_reason' => $gpuRuntime['reason'] ?? null,
            'cuda_driver_version' => $gpuRuntime['cuda_driver']['driver_version'] ?? null,
            'cuda_device_name' => $gpuRuntime['cuda_driver']['first_device_name'] ?? null,
            'batch_prefill_status' => $route['batch_prefill_status'] ?? null,
            'batch_prefill_admitted' => $route['batch_prefill_admitted'] ?? null,
        ];
    }
    return $meta;
}

function king_baseline_stream_run(string $url, string $model, string $prompt, int $maxTokens): array
{
    $payload = [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'stream' => true,
        'max_completion_tokens' => $maxTokens,
        'temperature' => 0.0,
    ];
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "content-type: application/json\r\naccept: text/event-stream\r\nconnection: close\r\n",
        'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'ignore_errors' => true,
        'timeout' => 180,
    ]]);
    $start = hrtime(true);
    $handle = @fopen($url, 'rb', false, $context);
    if (!is_resource($handle)) {
        return ['ok' => false, 'error' => 'connect_failed'];
    }
    $headers = $http_response_header ?? [];
    $statusLine = $headers[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $match);
    $status = isset($match[1]) ? (int) $match[1] : 0;
    $firstByte = null;
    $firstContent = null;
    $lastContent = null;
    $events = 0;
    $contentEvents = 0;
    $contentBytes = 0;
    $done = false;
    $finishReason = null;
    $serverFirstTokenMs = null;
    $serverLastEventMs = null;
    $serverGeneratedChunks = null;
    $error = '';

    while (!feof($handle)) {
        $line = fgets($handle);
        $now = hrtime(true);
        if ($line === false) {
            usleep(10_000);
            continue;
        }
        if ($firstByte === null && $line !== '') {
            $firstByte = $now;
        }
        $line = rtrim($line, "\r\n");
        if (!str_starts_with($line, 'data: ')) {
            continue;
        }
        $data = substr($line, 6);
        if ($data === '[DONE]') {
            $done = true;
            break;
        }
        $event = json_decode($data, true);
        if (!is_array($event)) {
            continue;
        }
        $events++;
        $choice = $event['choices'][0] ?? null;
        $delta = is_array($choice) && is_array($choice['delta'] ?? null) && is_string($choice['delta']['content'] ?? null)
            ? $choice['delta']['content']
            : '';
        $finish = is_array($choice) ? ($choice['finish_reason'] ?? null) : null;
        if (is_string($finish)) {
            $finishReason = $finish;
        }
        $streaming = is_array($event['x_king']['streaming'] ?? null) ? $event['x_king']['streaming'] : [];
        if (is_numeric($streaming['first_token_elapsed_ms'] ?? null)) {
            $serverFirstTokenMs = (float) $streaming['first_token_elapsed_ms'];
        }
        if (is_numeric($streaming['request_elapsed_ms'] ?? null)) {
            $serverLastEventMs = (float) $streaming['request_elapsed_ms'];
        }
        if (is_int($streaming['generated_token_chunks'] ?? null)) {
            $serverGeneratedChunks = $streaming['generated_token_chunks'];
        }
        if ($delta !== '') {
            if ($firstContent === null) {
                $firstContent = $now;
            }
            $lastContent = $now;
            $contentEvents++;
            $contentBytes += strlen($delta);
        }
    }
    fclose($handle);
    $end = hrtime(true);

    if ($status !== 200) {
        $error = 'http_status_' . $status;
    } else if (!$done) {
        $error = 'missing_done';
    } else if ($contentEvents === 0) {
        $error = 'no_content_events';
    }

    $totalMs = ($end - $start) / 1_000_000;
    $ttfbMs = $firstContent !== null ? ($firstContent - $start) / 1_000_000 : null;
    $firstByteMs = $firstByte !== null ? ($firstByte - $start) / 1_000_000 : null;
    $clientDecodeMs = $firstContent !== null ? max(0.001, ($end - $firstContent) / 1_000_000) : null;
    $serverDecodeMs = is_numeric($serverFirstTokenMs) && is_numeric($serverLastEventMs)
        ? max(0.001, $serverLastEventMs - $serverFirstTokenMs)
        : null;
    $generated = $serverGeneratedChunks ?? $contentEvents;
    $clientTokensPerSecond = $clientDecodeMs !== null ? ($generated * 1000.0) / $clientDecodeMs : null;
    $serverTokensPerSecond = $serverDecodeMs !== null ? ($generated * 1000.0) / $serverDecodeMs : null;
    $tokensPerSecond = $serverTokensPerSecond ?? $clientTokensPerSecond;
    $promptEstimate = max(1, count(preg_split('/\s+/', trim($prompt)) ?: []));
    $prefillPerSecond = $ttfbMs !== null && $ttfbMs > 0.0 ? ($promptEstimate * 1000.0) / $ttfbMs : null;

    return [
        'ok' => $error === '',
        'error' => $error,
        'status' => $status,
        'events' => $events,
        'content_events' => $contentEvents,
        'content_bytes' => $contentBytes,
        'first_byte_ms' => $firstByteMs !== null ? round($firstByteMs, 3) : null,
        'ttfb_ms' => $ttfbMs !== null ? round($ttfbMs, 3) : null,
        'total_ms' => round($totalMs, 3),
        'client_decode_ms' => $clientDecodeMs !== null ? round($clientDecodeMs, 3) : null,
        'server_decode_ms' => $serverDecodeMs !== null ? round($serverDecodeMs, 3) : null,
        'server_first_token_ms' => $serverFirstTokenMs,
        'server_last_event_ms' => $serverLastEventMs,
        'generated_tokens_estimate' => $generated,
        'tokens_per_second' => $tokensPerSecond !== null ? round($tokensPerSecond, 3) : null,
        'client_tokens_per_second' => $clientTokensPerSecond !== null ? round($clientTokensPerSecond, 3) : null,
        'server_tokens_per_second' => $serverTokensPerSecond !== null ? round($serverTokensPerSecond, 3) : null,
        'prefill_tokens_per_second_estimate' => $prefillPerSecond !== null ? round($prefillPerSecond, 3) : null,
        'finish_reason' => $finishReason,
    ];
}

function king_baseline_error_counts(array $runs): array
{
    $errors = [];
    foreach ($runs as $run) {
        $error = is_string($run['error'] ?? null) ? $run['error'] : '';
        if ($error === '') {
            continue;
        }
        $errors[$error] = ($errors[$error] ?? 0) + 1;
    }
    ksort($errors);
    return $errors;
}

function king_baseline_first_error(array $runs): string
{
    foreach ($runs as $run) {
        $error = is_string($run['error'] ?? null) ? $run['error'] : '';
        if ($error !== '') {
            return $error;
        }
    }
    return 'unknown';
}

function king_baseline_aggregate(array $runs): array
{
    $okRuns = array_values(array_filter($runs, static fn (array $run): bool => !empty($run['ok'])));
    $field = static fn (string $key): array => array_map(static fn (array $run): mixed => $run[$key] ?? null, $okRuns);
    return [
        'runs' => count($runs),
        'successful_runs' => count($okRuns),
        'failed_runs' => count($runs) - count($okRuns),
        'errors' => king_baseline_error_counts($runs),
        'ttfb_ms' => [
            'p50' => king_baseline_percentile($field('ttfb_ms'), 50),
            'p95' => king_baseline_percentile($field('ttfb_ms'), 95),
        ],
        'total_ms' => [
            'p50' => king_baseline_percentile($field('total_ms'), 50),
            'p95' => king_baseline_percentile($field('total_ms'), 95),
        ],
        'tokens_per_second' => [
            'p50' => king_baseline_percentile($field('tokens_per_second'), 50),
            'p95' => king_baseline_percentile($field('tokens_per_second'), 95),
        ],
        'client_tokens_per_second' => [
            'p50' => king_baseline_percentile($field('client_tokens_per_second'), 50),
            'p95' => king_baseline_percentile($field('client_tokens_per_second'), 95),
        ],
        'server_tokens_per_second' => [
            'p50' => king_baseline_percentile($field('server_tokens_per_second'), 50),
            'p95' => king_baseline_percentile($field('server_tokens_per_second'), 95),
        ],
        'prefill_tokens_per_second_estimate' => [
            'p50' => king_baseline_percentile($field('prefill_tokens_per_second_estimate'), 50),
            'p95' => king_baseline_percentile($field('prefill_tokens_per_second_estimate'), 95),
        ],
        'generated_tokens_estimate_total' => array_sum(array_map(static fn (array $run): int => (int) ($run['generated_tokens_estimate'] ?? 0), $okRuns)),
        'finish_reasons' => array_values(array_unique(array_filter(array_map(static fn (array $run): mixed => $run['finish_reason'] ?? null, $okRuns), 'is_string'))),
    ];
}

function king_baseline_start_router(array $profile, int $port, string $root, string $php, string $ini, string $extension): array
{
    $log = sys_get_temp_dir() . '/king-baseline-' . $profile['label'] . '-' . $port . '.log';
    $cmd = [
        $php,
        '-n',
        '-c', $ini,
        '-d', 'extension=' . $extension,
        '-d', 'king.security_allow_config_override=1',
        '-d', 'memory_limit=4G',
        '-d', 'king.inference_preferred_model_profile=' . $profile['runtime_profile'],
        '-d', 'king.inference_cpu_model_name=' . $profile['cpu_model'],
        '-d', 'king.inference_cpu_model_artifact=' . $profile['cpu_artifact'],
        '-d', 'king.inference_gpu_model_name=' . $profile['gpu_model'],
        '-d', 'king.inference_gpu_model_artifact=' . $profile['gpu_artifact'],
        $root . '/infra/inference/openai-router.php',
    ];
    $env = array_merge($_ENV, [
        'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        'KING_ROOT' => $root,
        'KING_OPENAI_HOST' => '127.0.0.1',
        'KING_OPENAI_PORT' => (string) $port,
        'KING_OPENAI_CONTEXT_POLICY' => 'full',
        'KING_OPENAI_DEFAULT_MAX_TOKENS' => '32',
        'KING_OPENAI_MAX_COMPLETION_TOKENS' => '32',
        'PHP_INI_SCAN_DIR' => '',
        'LD_LIBRARY_PATH' => trim('/usr/local/cuda/targets/x86_64-linux/lib:' . (getenv('LD_LIBRARY_PATH') ?: ''), ':'),
    ]);
    $process = proc_open(
        $cmd,
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'],
            2 => ['file', $log, 'a'],
        ],
        $pipes,
        $root,
        $env
    );
    if (!is_resource($process)) {
        throw new RuntimeException('failed to start router');
    }
    return ['process' => $process, 'log' => $log, 'cmd' => $cmd];
}

function king_baseline_stop_router(mixed $process): void
{
    if (!is_resource($process)) {
        return;
    }
    proc_terminate($process);
    usleep(500_000);
    $status = proc_get_status($process);
    if (($status['running'] ?? false) === true) {
        proc_terminate($process, 9);
        usleep(250_000);
    }
    proc_close($process);
}

if (king_baseline_flag($argv, '--help') || king_baseline_flag($argv, '-h')) {
    king_baseline_usage();
    exit(0);
}

$root = dirname(__DIR__, 2);
$php = getenv('PHP_BIN') ?: PHP_BINARY;
$ini = getenv('KING_INFERENCE_PHP_INI') ?: $root . '/infra/inference/local-gpu.php.ini';
$extension = getenv('KING_EXTENSION') ?: $root . '/extension/modules/king.so';
$runs = king_baseline_int($argv, '--runs', 3);
$maxTokens = king_baseline_int($argv, '--max-tokens', 8);
$portStart = king_baseline_int($argv, '--port-start', 19080, 1024);
$jsonOnly = king_baseline_flag($argv, '--json');
$selectedProfiles = array_filter(array_map('trim', explode(',', king_baseline_option($argv, '--profiles', 'cpu-gemma3,gpu-gemma3,gpu-large') ?? '')));
if (king_baseline_flag($argv, '--skip-large')) {
    $selectedProfiles = array_values(array_filter($selectedProfiles, static fn (string $p): bool => $p !== 'gpu-large'));
}

$cpuArtifact = $root . '/var/inference-models/gemma3-1b.gguf';
$largeArtifact = $root . '/var/inference-models/gemma4-12b.gguf';
$allProfiles = [
    'cpu-gemma3' => [
        'label' => 'cpu-gemma3',
        'runtime_profile' => 'cpu',
        'model' => 'gemma3:1b',
        'cpu_model' => 'gemma3:1b',
        'cpu_artifact' => $cpuArtifact,
        'gpu_model' => 'gemma3:1b',
        'gpu_artifact' => $cpuArtifact,
    ],
    'gpu-gemma3' => [
        'label' => 'gpu-gemma3',
        'runtime_profile' => 'gpu',
        'model' => 'gemma3:1b',
        'cpu_model' => 'gemma3:1b',
        'cpu_artifact' => $cpuArtifact,
        'gpu_model' => 'gemma3:1b',
        'gpu_artifact' => $cpuArtifact,
    ],
    'gpu-large' => [
        'label' => 'gpu-large',
        'runtime_profile' => 'gpu',
        'model' => getenv('KING_INFERENCE_LARGE_GPU_MODEL_NAME') ?: 'gemma4:12b',
        'cpu_model' => 'gemma3:1b',
        'cpu_artifact' => $cpuArtifact,
        'gpu_model' => getenv('KING_INFERENCE_LARGE_GPU_MODEL_NAME') ?: 'gemma4:12b',
        'gpu_artifact' => getenv('KING_INFERENCE_LARGE_GPU_MODEL_PATH') ?: $largeArtifact,
    ],
];

$report = [
    'schema_version' => 1,
    'generated_at' => date(DATE_ATOM),
    'host' => [
        'os' => php_uname(),
        'php_version' => PHP_VERSION,
        'cpu_model' => king_baseline_cpu_model(),
        'cpu_cores_visible' => (int) trim(king_baseline_shell_line('nproc 2>/dev/null') ?: '0'),
        'memory_total_mb' => king_baseline_memory_total_mb(),
        'cuda_runtime_version' => king_baseline_cuda_runtime_version(),
    ],
    'gpu_before_all' => king_baseline_gpu_snapshot(),
    'runs_per_profile' => $runs,
    'max_tokens' => $maxTokens,
    'profiles' => [],
];

$prompt = 'Answer in one short sentence: what is a tokenizer?';
$profileIndex = 0;
foreach ($selectedProfiles as $profileName) {
    $profile = $allProfiles[$profileName] ?? null;
    if ($profile === null) {
        $report['profiles'][] = ['label' => $profileName, 'status' => 'skipped', 'reason' => 'unknown_profile'];
        continue;
    }

    $artifact = king_baseline_artifact($profile['runtime_profile'] === 'gpu' ? $profile['gpu_artifact'] : $profile['cpu_artifact']);
    $entry = [
        'label' => $profile['label'],
        'status' => 'not_started',
        'runtime_profile' => $profile['runtime_profile'],
        'model' => $profile['model'],
        'artifact' => $artifact,
        'port' => $portStart + $profileIndex,
        'runs' => [],
    ];
    $profileIndex++;

    if (!$artifact['readable']) {
        $entry['status'] = 'skipped';
        $entry['reason'] = 'artifact_not_readable';
        $report['profiles'][] = $entry;
        continue;
    }

    $router = null;
    try {
        $router = king_baseline_start_router($profile, $entry['port'], $root, $php, $ini, $extension);
        $modelsUrl = 'http://127.0.0.1:' . $entry['port'] . '/v1/models';
        $chatUrl = 'http://127.0.0.1:' . $entry['port'] . '/v1/chat/completions';
        if (!king_baseline_wait_for_router($modelsUrl, 90)) {
            $entry['status'] = 'failed';
            $entry['reason'] = 'router_not_ready';
            $entry['router_log'] = $router['log'];
            $report['profiles'][] = $entry;
            king_baseline_stop_router($router['process']);
            continue;
        }

        $entry['model_before'] = king_baseline_model_meta($modelsUrl, $profile['model']);
        $entry['gpu_before'] = king_baseline_gpu_snapshot();
        for ($i = 0; $i < $runs; $i++) {
            $entry['runs'][] = king_baseline_stream_run($chatUrl, $profile['model'], $prompt, $maxTokens);
        }
        $entry['gpu_after'] = king_baseline_gpu_snapshot();
        $entry['gpu_delta'] = king_baseline_gpu_delta($entry['gpu_before'], $entry['gpu_after']);
        $entry['model_after'] = king_baseline_model_meta($modelsUrl, $profile['model']);
        $entry['aggregate'] = king_baseline_aggregate($entry['runs']);
        $entry['fallback_status'] = [
            'backend' => $entry['model_after']['backend'] ?? null,
            'active_device' => $entry['model_after']['active_device'] ?? null,
            'silent_cpu_fallback' => $entry['model_after']['silent_cpu_fallback'] ?? null,
        ];
        $entry['status'] = ($entry['aggregate']['successful_runs'] ?? 0) > 0 ? 'measured' : 'failed';
        $entry['reason'] = $entry['status'] === 'measured'
            ? 'ok'
            : 'no_successful_runs:' . king_baseline_first_error($entry['runs']);
        king_baseline_stop_router($router['process']);
    } catch (Throwable $e) {
        if (is_array($router ?? null) && isset($router['process'])) {
            king_baseline_stop_router($router['process']);
        }
        $entry['status'] = 'failed';
        $entry['reason'] = 'exception';
        $entry['error'] = $e::class . ': ' . $e->getMessage();
    }

    $report['profiles'][] = $entry;
}

$report['gpu_after_all'] = king_baseline_gpu_snapshot();

if ($jsonOnly) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

foreach ($report['profiles'] as $profile) {
    echo 'profile=' . ($profile['label'] ?? '') . ' status=' . ($profile['status'] ?? '') . ' model=' . ($profile['model'] ?? '') . "\n";
    if (isset($profile['aggregate']) && is_array($profile['aggregate'])) {
        echo '  ttfb_p50_ms=' . json_encode($profile['aggregate']['ttfb_ms']['p50'] ?? null)
            . ' total_p50_ms=' . json_encode($profile['aggregate']['total_ms']['p50'] ?? null)
            . ' tps_p50=' . json_encode($profile['aggregate']['tokens_per_second']['p50'] ?? null)
            . ' server_tps_p50=' . json_encode($profile['aggregate']['server_tokens_per_second']['p50'] ?? null)
            . ' prefill_est_p50=' . json_encode($profile['aggregate']['prefill_tokens_per_second_estimate']['p50'] ?? null)
            . ' generated_total=' . json_encode($profile['aggregate']['generated_tokens_estimate_total'] ?? null)
            . "\n";
        echo '  fallback=' . json_encode($profile['fallback_status'] ?? null, JSON_UNESCAPED_SLASHES)
            . ' errors=' . json_encode($profile['aggregate']['errors'] ?? [], JSON_UNESCAPED_SLASHES)
            . ' reason=' . ($profile['reason'] ?? '')
            . "\n";
    } else {
        echo '  reason=' . ($profile['reason'] ?? '') . "\n";
    }
}
