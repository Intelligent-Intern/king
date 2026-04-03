--TEST--
King telemetry preserves metric lifecycle under sustained request and worker churn
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';
require __DIR__ . '/server_websocket_wire_helper.inc';

function king_telemetry_metric_lifecycle_pick_request_port(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve request metric lifecycle port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);
    return (int) $port;
}

function king_telemetry_metric_lifecycle_start_request_server(int $collectorPort, int $iterations): array
{
    $capture = tempnam(sys_get_temp_dir(), 'king-telemetry-metric-lifecycle-request-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $port = king_telemetry_metric_lifecycle_pick_request_port();
    $command = sprintf(
        '%s -n -d %s -d %s %s %s %d %d %d',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg(__DIR__ . '/telemetry_metric_lifecycle_boundary_server.inc'),
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
        throw new RuntimeException('failed to launch telemetry metric lifecycle request harness');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('telemetry metric lifecycle request harness failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => $port,
    ];
}

function king_telemetry_metric_lifecycle_stop_request_server(array $server): array
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
        throw new RuntimeException('telemetry metric lifecycle request harness failed: ' . trim($stderr . "\n" . $stdout));
    }

    return $capture;
}

function king_telemetry_metric_lifecycle_run_json_child(string $command): array
{
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('telemetry metric lifecycle child script failed with exit code ' . $exitCode);
    }

    $decoded = json_decode(implode("\n", $output), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('telemetry metric lifecycle child script did not return JSON');
    }

    return $decoded;
}

function king_telemetry_metric_lifecycle_assert_iteration(
    string $prefix,
    array $iteration,
    int $counterExpected,
    float $gaugeExpected
): void {
    $beforeStatus = $iteration['before_status'] ?? null;
    $midMetrics = $iteration['mid_metrics'] ?? null;
    $afterStatus = $iteration['after_status'] ?? null;
    $counterMetric = $midMetrics[$prefix . '.counter'] ?? null;
    $gaugeMetric = $midMetrics[$prefix . '.gauge'] ?? null;

    if (!is_array($beforeStatus) || (int) ($beforeStatus['active_metrics'] ?? -1) !== 0) {
        throw new RuntimeException($prefix . ' iteration started with stale live metrics.');
    }
    if (!is_array($midMetrics) || count($midMetrics) !== 2) {
        throw new RuntimeException($prefix . ' iteration did not expose the expected live metric registry.');
    }
    if (!is_array($counterMetric)
        || ($counterMetric['name'] ?? null) !== $prefix . '.counter'
        || (int) ($counterMetric['value'] ?? -1) !== $counterExpected) {
        throw new RuntimeException($prefix . ' counter aggregation drifted inside the live registry.');
    }
    if (!is_array($gaugeMetric)
        || ($gaugeMetric['name'] ?? null) !== $prefix . '.gauge'
        || abs((float) ($gaugeMetric['value'] ?? -INF) - $gaugeExpected) > 0.0001) {
        throw new RuntimeException($prefix . ' gauge replacement drifted inside the live registry.');
    }
    if (($iteration['flush_result'] ?? false) !== true
        || !is_array($afterStatus)
        || (int) ($afterStatus['active_metrics'] ?? -1) !== 0
        || (int) ($afterStatus['queue_size'] ?? -1) !== 0) {
        throw new RuntimeException($prefix . ' flush did not drain the live metric registry cleanly.');
    }
}

function king_telemetry_metric_lifecycle_collect_metric_batches(array $collectorCapture): array
{
    $batches = [];

    foreach ($collectorCapture as $entry) {
        if (($entry['path'] ?? null) !== '/v1/metrics') {
            throw new RuntimeException('telemetry metric lifecycle collector observed an unexpected OTLP path.');
        }

        $payload = json_decode((string) ($entry['body'] ?? ''), true);
        if (!is_array($payload)) {
            throw new RuntimeException('telemetry metric lifecycle collector emitted malformed JSON.');
        }

        $bodyMetrics = $payload['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? null;
        if (!is_array($bodyMetrics)) {
            throw new RuntimeException('telemetry metric lifecycle collector emitted malformed OTLP metric data.');
        }

        $batch = [];
        foreach ($bodyMetrics as $metric) {
            if (!is_array($metric) || !isset($metric['name'])) {
                continue;
            }

            $dataPoint = $metric['data']['dataPoints'][0] ?? null;
            if (!is_array($dataPoint)) {
                continue;
            }

            if (array_key_exists('asInt', $dataPoint)) {
                $batch[$metric['name']] = (int) $dataPoint['asInt'];
                continue;
            }

            if (array_key_exists('asDouble', $dataPoint)) {
                $batch[$metric['name']] = (float) $dataPoint['asDouble'];
            }
        }

        $batches[] = $batch;
    }

    return $batches;
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

$statePath = tempnam(sys_get_temp_dir(), 'king-telemetry-metric-lifecycle-worker-state-');
$queuePath = sys_get_temp_dir() . '/king-telemetry-metric-lifecycle-worker-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-metric-lifecycle-controller-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-metric-lifecycle-worker-');

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
        ['text' => 'metric-worker-' . $i],
        [['tool' => 'summarizer']],
        ['trace_id' => 'telemetry-metric-worker-' . $i]
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

    king_telemetry_record_metric('worker.counter', (float) ($i + 20), null, 'counter');
    king_telemetry_record_metric('worker.counter', 2.0, null, 'counter');
    king_telemetry_record_metric('worker.gauge', -1.0, null, 'gauge');
    king_telemetry_record_metric('worker.gauge', (float) (200 + $i), null, 'gauge');

    $result['iterations'][] = [
        'run_status' => $work['status'] ?? null,
        'run_text' => $work['result']['text'] ?? null,
        'before_status' => $beforeStatus,
        'mid_metrics' => king_telemetry_get_metrics(),
        'flush_result' => king_telemetry_flush(),
        'after_status' => king_telemetry_get_status(),
    ];
}

$result['empty'] = king_pipeline_orchestrator_worker_run_next();

echo json_encode($result, JSON_UNESCAPED_SLASHES);
PHP);

try {
    $requestServer = king_telemetry_metric_lifecycle_start_request_server(
        $collector['port'],
        $requestIterations
    );

    for ($i = 0; $i < $requestIterations; $i++) {
        $response = king_server_http1_wire_request_retry(
            $requestServer['port'],
            "GET /telemetry-metrics?iteration={$i} HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Connection: close\r\n\r\n"
        );
        $parsed = king_server_http1_wire_parse_response($response);
        if (($parsed['status'] ?? 0) !== 204) {
            throw new RuntimeException('metric lifecycle request listener returned an unexpected HTTP status.');
        }
    }

    $requestCapture = king_telemetry_metric_lifecycle_stop_request_server($requestServer);
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

    $controller = king_telemetry_metric_lifecycle_run_json_child(sprintf(
        $baseCommand,
        escapeshellarg($controllerScript) . ' ' . $workerIterations
    ));
    $workerCapture = king_telemetry_metric_lifecycle_run_json_child(sprintf(
        $baseCommand,
        escapeshellarg($workerScript) . ' ' . $collector['port'] . ' ' . $workerIterations
    ));
} finally {
    if ($requestServer !== null) {
        king_telemetry_metric_lifecycle_stop_request_server($requestServer);
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
    throw new RuntimeException('metric lifecycle request harness did not process every iteration.');
}
foreach ($requestCapture['listen_results'] as $result) {
    if ($result !== true) {
        throw new RuntimeException('metric lifecycle request harness observed a failed listener iteration.');
    }
}
if (count($requestCapture['iterations'] ?? []) !== $requestIterations) {
    throw new RuntimeException('metric lifecycle request harness did not capture every iteration.');
}
foreach ($requestCapture['iterations'] as $index => $iteration) {
    king_telemetry_metric_lifecycle_assert_iteration(
        'request',
        $iteration,
        $index + 11,
        (float) (100 + $index)
    );
}

if (($controller['register'] ?? false) !== true) {
    throw new RuntimeException('metric lifecycle worker controller failed to register the tool.');
}
if (count($controller['dispatches'] ?? []) !== $workerIterations) {
    throw new RuntimeException('metric lifecycle worker controller did not queue the expected runs.');
}
foreach ($controller['dispatches'] as $dispatch) {
    if (($dispatch['backend'] ?? null) !== 'file_worker' || ($dispatch['status'] ?? null) !== 'queued') {
        throw new RuntimeException('metric lifecycle worker controller observed a dispatch drift.');
    }
}

if (count($workerCapture['iterations'] ?? []) !== $workerIterations) {
    throw new RuntimeException('metric lifecycle worker runner did not process the expected runs.');
}
foreach ($workerCapture['iterations'] as $index => $iteration) {
    if (($iteration['run_status'] ?? null) !== 'completed') {
        throw new RuntimeException('metric lifecycle worker runner did not complete one queued run.');
    }
    if (($iteration['run_text'] ?? null) !== 'metric-worker-' . $index) {
        throw new RuntimeException('metric lifecycle worker runner returned an unexpected payload.');
    }

    king_telemetry_metric_lifecycle_assert_iteration(
        'worker',
        $iteration,
        $index + 22,
        (float) (200 + $index)
    );
}
if (($workerCapture['empty'] ?? true) !== false) {
    throw new RuntimeException('metric lifecycle worker runner expected the queue to be empty at the end.');
}

$metricBatches = king_telemetry_metric_lifecycle_collect_metric_batches($collectorCapture);
if (count($metricBatches) !== $requestIterations + $workerIterations) {
    throw new RuntimeException('metric lifecycle collector did not observe the expected number of metric flushes.');
}

for ($i = 0; $i < $requestIterations; $i++) {
    $batch = $metricBatches[$i];
    if (($batch['request.counter'] ?? null) !== $i + 11
        || abs((float) ($batch['request.gauge'] ?? -INF) - (float) (100 + $i)) > 0.0001) {
        throw new RuntimeException('request metric batch drifted after flush.');
    }
    if (array_key_exists('worker.counter', $batch) || array_key_exists('worker.gauge', $batch)) {
        throw new RuntimeException('request metric batch leaked worker metrics.');
    }
}

for ($i = 0; $i < $workerIterations; $i++) {
    $batch = $metricBatches[$requestIterations + $i];
    if (($batch['worker.counter'] ?? null) !== $i + 22
        || abs((float) ($batch['worker.gauge'] ?? -INF) - (float) (200 + $i)) > 0.0001) {
        throw new RuntimeException('worker metric batch drifted after flush.');
    }
    if (array_key_exists('request.counter', $batch) || array_key_exists('request.gauge', $batch)) {
        throw new RuntimeException('worker metric batch leaked request metrics.');
    }
}

echo "OK\n";
?>
--EXPECT--
OK
