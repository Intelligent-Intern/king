--TEST--
King telemetry preserves log lifecycle under sustained request and worker churn
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';
require __DIR__ . '/server_websocket_wire_helper.inc';

function king_telemetry_log_lifecycle_pick_request_port(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve request log lifecycle port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);
    return (int) $port;
}

function king_telemetry_log_lifecycle_start_request_server(int $collectorPort, int $iterations): array
{
    $capture = tempnam(sys_get_temp_dir(), 'king-telemetry-log-lifecycle-request-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $port = king_telemetry_log_lifecycle_pick_request_port();
    $command = sprintf(
        '%s -n -d %s -d %s %s %s %d %d %d',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg(__DIR__ . '/telemetry_log_lifecycle_boundary_server.inc'),
        escapeshellarg($capture),
        $port,
        $collectorPort,
        $iterations
    );

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch telemetry log lifecycle request harness');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('telemetry log lifecycle request harness failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => $port,
    ];
}

function king_telemetry_log_lifecycle_stop_request_server(array $server): array
{
    $stdout = isset($server['pipes'][1]) ? stream_get_contents($server['pipes'][1]) : '';
    $stderr = isset($server['pipes'][2]) ? stream_get_contents($server['pipes'][2]) : '';

    foreach ($server['pipes'] as $pipe) {
        fclose($pipe);
    }
    $exitCode = proc_close($server['process']);

    $capture = [];
    if (is_file($server['capture'])) {
        $capture = json_decode((string) file_get_contents($server['capture']), true);
        if (!is_array($capture)) {
            $capture = [];
        }
        @unlink($server['capture']);
    }

    if ($capture === [] && ($stdout !== '' || $stderr !== '' || $exitCode !== 0)) {
        throw new RuntimeException('telemetry log lifecycle request harness failed: ' . trim($stderr . "\n" . $stdout));
    }

    return $capture;
}

function king_telemetry_log_lifecycle_run_json_child(string $command): array
{
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('telemetry log lifecycle child script failed with exit code ' . $exitCode);
    }

    $decoded = json_decode(implode("\n", $output), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('telemetry log lifecycle child script did not return JSON');
    }

    return $decoded;
}

function king_telemetry_log_lifecycle_assert_iteration(string $prefix, array $iteration): void
{
    $beforeStatus = $iteration['before_status'] ?? null;
    $midStatus = $iteration['mid_status'] ?? null;
    $afterStatus = $iteration['after_status'] ?? null;

    if (!is_array($beforeStatus) || (int) ($beforeStatus['pending_log_count'] ?? -1) !== 0) {
        throw new RuntimeException($prefix . ' iteration started with stale pending logs.');
    }
    if (!is_array($midStatus) || (int) ($midStatus['pending_log_count'] ?? -1) !== 2) {
        throw new RuntimeException($prefix . ' iteration did not keep the two fresh logs in the pending buffer before flush.');
    }
    if (($iteration['flush_result'] ?? false) !== true
        || !is_array($afterStatus)
        || (int) ($afterStatus['pending_log_count'] ?? -1) !== 0
        || (int) ($afterStatus['queue_size'] ?? -1) !== 0) {
        throw new RuntimeException($prefix . ' flush did not drain the pending log buffer cleanly.');
    }
}

$requestIterations = 4;
$workerIterations = 4;
$collector = king_telemetry_test_start_collector(array_fill(0, $requestIterations + $workerIterations, [
    'status' => 200,
    'body' => 'ok',
]));
$requestServer = null;
$requestCapture = [];
$workerCapture = [];
$collectorCapture = [];

$statePath = tempnam(sys_get_temp_dir(), 'king-telemetry-log-lifecycle-worker-state-');
$queuePath = sys_get_temp_dir() . '/king-telemetry-log-lifecycle-worker-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-log-lifecycle-controller-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-log-lifecycle-worker-');

@unlink($statePath);
@mkdir($queuePath, 0700, true);

file_put_contents($controllerScript, <<<'PHP'
<?php
$iterations = (int) $argv[1];
$result = [
    'register' => king_pipeline_orchestrator_register_tool('summarizer', [
        'model' => 'gpt-sim',
        'max_tokens' => 32,
    ]),
    'dispatches' => [],
];

for ($i = 0; $i < $iterations; $i++) {
    $dispatch = king_pipeline_orchestrator_dispatch(
        ['text' => 'log-worker-' . $i],
        [['tool' => 'summarizer']],
        ['trace_id' => 'telemetry-log-worker-' . $i]
    );
    $result['dispatches'][] = [
        'backend' => $dispatch['backend'] ?? null,
        'status' => $dispatch['status'] ?? null,
    ];
}

echo json_encode($result, JSON_UNESCAPED_SLASHES);
PHP);

file_put_contents($workerScript, <<<'PHP'
<?php
$collectorPort = (int) $argv[1];
$iterations = (int) $argv[2];

king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collectorPort,
    'exporter_timeout_ms' => 500,
]);

$result = [
    'iterations' => [],
];

for ($i = 0; $i < $iterations; $i++) {
    $beforeStatus = king_telemetry_get_status();
    $work = king_pipeline_orchestrator_worker_run_next();

    king_telemetry_log('warn', 'worker-log-a-' . $i, [
        'slot' => 'a',
        'iteration' => $i,
    ]);
    king_telemetry_log('error', 'worker-log-b-' . $i, [
        'slot' => 'b',
        'iteration' => $i,
    ]);

    $result['iterations'][] = [
        'run_status' => $work['status'] ?? null,
        'run_text' => $work['result']['text'] ?? null,
        'before_status' => $beforeStatus,
        'mid_status' => king_telemetry_get_status(),
        'flush_result' => king_telemetry_flush(),
        'after_status' => king_telemetry_get_status(),
    ];
}

$result['empty'] = king_pipeline_orchestrator_worker_run_next();

echo json_encode($result, JSON_UNESCAPED_SLASHES);
PHP);

try {
    $requestServer = king_telemetry_log_lifecycle_start_request_server(
        $collector['port'],
        $requestIterations
    );

    for ($i = 0; $i < $requestIterations; $i++) {
        $response = king_server_http1_wire_request_retry(
            $requestServer['port'],
            "GET /telemetry-logs?iteration={$i} HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Connection: close\r\n\r\n"
        );
        $parsed = king_server_http1_wire_parse_response($response);
        if (($parsed['status'] ?? 0) !== 204) {
            throw new RuntimeException('log lifecycle request listener returned an unexpected HTTP status.');
        }
    }

    $requestCapture = king_telemetry_log_lifecycle_stop_request_server($requestServer);
    $requestServer = null;

    $baseCommand = sprintf(
        '%s -n -d %s -d %s -d %s -d %s -d %s %%s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg('king.orchestrator_execution_backend=file_worker'),
        escapeshellarg('king.orchestrator_worker_queue_path=' . $queuePath),
        escapeshellarg('king.orchestrator_state_path=' . $statePath)
    );

    $controller = king_telemetry_log_lifecycle_run_json_child(sprintf(
        $baseCommand,
        escapeshellarg($controllerScript) . ' ' . $workerIterations
    ));
    $workerCapture = king_telemetry_log_lifecycle_run_json_child(sprintf(
        $baseCommand,
        escapeshellarg($workerScript) . ' ' . $collector['port'] . ' ' . $workerIterations
    ));
} finally {
    if ($requestServer !== null) {
        king_telemetry_log_lifecycle_stop_request_server($requestServer);
    }

    $collectorCapture = king_telemetry_test_stop_collector($collector);
    @unlink($controllerScript);
    @unlink($workerScript);
    @unlink($statePath);
    if (is_dir($queuePath)) {
        foreach (scandir($queuePath) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($queuePath . '/' . $entry);
            }
        }
        @rmdir($queuePath);
    }
}

if (count($requestCapture['listen_results'] ?? []) !== $requestIterations) {
    throw new RuntimeException('log lifecycle request harness did not process every iteration.');
}
foreach ($requestCapture['listen_results'] as $result) {
    if ($result !== true) {
        throw new RuntimeException('log lifecycle request harness observed a failed listener iteration.');
    }
}
if (count($requestCapture['iterations'] ?? []) !== $requestIterations) {
    throw new RuntimeException('log lifecycle request harness did not capture every iteration.');
}
foreach ($requestCapture['iterations'] as $iteration) {
    king_telemetry_log_lifecycle_assert_iteration('request', $iteration);
}

if (($controller['register'] ?? false) !== true) {
    throw new RuntimeException('log lifecycle worker controller failed to register the tool.');
}
if (count($controller['dispatches'] ?? []) !== $workerIterations) {
    throw new RuntimeException('log lifecycle worker controller did not queue the expected runs.');
}
foreach ($controller['dispatches'] as $dispatch) {
    if (($dispatch['backend'] ?? null) !== 'file_worker' || ($dispatch['status'] ?? null) !== 'queued') {
        throw new RuntimeException('log lifecycle worker controller observed a dispatch drift.');
    }
}

if (count($workerCapture['iterations'] ?? []) !== $workerIterations) {
    throw new RuntimeException('log lifecycle worker runner did not process the expected runs.');
}
foreach ($workerCapture['iterations'] as $index => $iteration) {
    if (($iteration['run_status'] ?? null) !== 'completed') {
        throw new RuntimeException('log lifecycle worker runner did not complete one queued run.');
    }
    if (($iteration['run_text'] ?? null) !== 'log-worker-' . $index) {
        throw new RuntimeException('log lifecycle worker runner returned an unexpected payload.');
    }

    king_telemetry_log_lifecycle_assert_iteration('worker', $iteration);
}
if (($workerCapture['empty'] ?? true) !== false) {
    throw new RuntimeException('log lifecycle worker runner expected the queue to be empty at the end.');
}

$logBatches = [];
foreach ($collectorCapture as $entry) {
    if (($entry['path'] ?? null) === '/v1/logs') {
        $logBatches[] = $entry;
    }
}
if (count($logBatches) !== $requestIterations + $workerIterations) {
    throw new RuntimeException('log lifecycle collector did not observe the expected number of log flushes.');
}

for ($i = 0; $i < $requestIterations; $i++) {
    $body = (string) ($logBatches[$i]['body'] ?? '');
    if (!str_contains($body, 'request-log-a-' . $i) || !str_contains($body, 'request-log-b-' . $i)) {
        throw new RuntimeException('request log batch did not contain the expected fresh logs.');
    }
    for ($other = 0; $other < $requestIterations; $other++) {
        if ($other !== $i
            && (str_contains($body, 'request-log-a-' . $other)
                || str_contains($body, 'request-log-b-' . $other))) {
            throw new RuntimeException('request log batch leaked logs from a different request iteration.');
        }
    }
}

for ($i = 0; $i < $workerIterations; $i++) {
    $body = (string) ($logBatches[$requestIterations + $i]['body'] ?? '');
    if (!str_contains($body, 'worker-log-a-' . $i) || !str_contains($body, 'worker-log-b-' . $i)) {
        throw new RuntimeException('worker log batch did not contain the expected fresh logs.');
    }
    for ($other = 0; $other < $workerIterations; $other++) {
        if ($other !== $i
            && (str_contains($body, 'worker-log-a-' . $other)
                || str_contains($body, 'worker-log-b-' . $other))) {
            throw new RuntimeException('worker log batch leaked logs from a different worker iteration.');
        }
    }
}

echo "OK\n";
?>
--EXPECT--
OK
