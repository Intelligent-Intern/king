--TEST--
King telemetry preserves live span lifecycle under sustained request and worker churn
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';
require __DIR__ . '/server_websocket_wire_helper.inc';

function king_telemetry_span_lifecycle_pick_request_port(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve request span lifecycle port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);
    return (int) $port;
}

function king_telemetry_span_lifecycle_start_request_server(int $collectorPort, int $iterations): array
{
    $capture = tempnam(sys_get_temp_dir(), 'king-telemetry-span-lifecycle-request-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $port = king_telemetry_span_lifecycle_pick_request_port();
    $command = sprintf(
        '%s -n -d %s -d %s %s %s %d %d %d',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg(__DIR__ . '/telemetry_span_lifecycle_boundary_server.inc'),
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
        throw new RuntimeException('failed to launch telemetry span lifecycle request harness');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('telemetry span lifecycle request harness failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => $port,
    ];
}

function king_telemetry_span_lifecycle_stop_request_server(array $server): array
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
        throw new RuntimeException('telemetry span lifecycle request harness failed: ' . trim($stderr . "\n" . $stdout));
    }

    return $capture;
}

function king_telemetry_span_lifecycle_run_json_child(string $command): array
{
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('telemetry span lifecycle child script failed with exit code ' . $exitCode);
    }

    $decoded = json_decode(implode("\n", $output), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('telemetry span lifecycle child script did not return JSON');
    }

    return $decoded;
}

function king_telemetry_span_lifecycle_assert_iteration(string $prefix, array $iteration, int $index): void
{
    if (!array_key_exists('before_context', $iteration) || $iteration['before_context'] !== null) {
        throw new RuntimeException($prefix . ' iteration started with stale trace context.');
    }

    $rootId = $iteration['root_id'] ?? null;
    $rootContext = $iteration['root_context'] ?? null;
    $childId = $iteration['child_id'] ?? null;
    $childContext = $iteration['child_context'] ?? null;
    $afterChild = $iteration['after_child_context'] ?? null;
    $hasAfterRoot = array_key_exists('after_root_context', $iteration);
    $afterRoot = $hasAfterRoot ? $iteration['after_root_context'] : 'missing';
    $afterFlush = $iteration['after_flush_status'] ?? null;

    if (!is_string($rootId) || strlen($rootId) !== 16) {
        throw new RuntimeException($prefix . ' root span id did not have the expected shape.');
    }
    if (!is_array($rootContext)
        || ($rootContext['operation_name'] ?? null) !== $prefix . '-root-' . $index
        || ($rootContext['span_id'] ?? null) !== $rootId
        || strlen((string) ($rootContext['trace_id'] ?? '')) !== 32
        || ($rootContext['attributes']['role'] ?? null) !== 'root'
        || (int) ($rootContext['attributes']['iteration'] ?? -1) !== $index
        || array_key_exists('closed', $rootContext['attributes'] ?? [])) {
        throw new RuntimeException($prefix . ' root span snapshot drifted.');
    }

    if (!is_string($childId) || strlen($childId) !== 16 || $childId === $rootId) {
        throw new RuntimeException($prefix . ' child span id did not have the expected shape.');
    }
    if (!is_array($childContext)
        || ($childContext['operation_name'] ?? null) !== $prefix . '-child-' . $index
        || ($childContext['span_id'] ?? null) !== $childId
        || ($childContext['parent_span_id'] ?? null) !== $rootId
        || ($childContext['trace_id'] ?? null) !== ($rootContext['trace_id'] ?? null)
        || ($childContext['attributes']['role'] ?? null) !== 'child'
        || (int) ($childContext['attributes']['iteration'] ?? -1) !== $index
        || array_key_exists('closed', $childContext['attributes'] ?? [])) {
        throw new RuntimeException($prefix . ' child span snapshot drifted.');
    }

    if (($iteration['child_end_result'] ?? false) !== true) {
        throw new RuntimeException($prefix . ' child span did not end successfully.');
    }
    if (!is_array($afterChild)
        || ($afterChild['span_id'] ?? null) !== $rootId
        || ($afterChild['operation_name'] ?? null) !== $prefix . '-root-' . $index) {
        throw new RuntimeException($prefix . ' runtime did not restore the parent span after child close.');
    }

    if (($iteration['root_end_result'] ?? false) !== true) {
        throw new RuntimeException($prefix . ' root span did not end successfully.');
    }
    if (!$hasAfterRoot || $afterRoot !== null) {
        throw new RuntimeException($prefix . ' runtime kept a closed root span active.');
    }

    if (($iteration['flush_result'] ?? false) !== true
        || !is_array($afterFlush)
        || (int) ($afterFlush['pending_span_count'] ?? -1) !== 0
        || (int) ($afterFlush['pending_log_count'] ?? -1) !== 0
        || (int) ($afterFlush['queue_size'] ?? -1) !== 0) {
        throw new RuntimeException($prefix . ' flush left span residue behind.');
    }
}

function king_telemetry_span_lifecycle_collect_exported_spans(array $collectorCapture): array
{
    $spans = [];

    foreach ($collectorCapture as $entry) {
        if (($entry['path'] ?? null) !== '/v1/traces') {
            throw new RuntimeException('telemetry span lifecycle collector observed an unexpected OTLP path.');
        }

        $payload = json_decode((string) ($entry['body'] ?? ''), true);
        if (!is_array($payload)) {
            throw new RuntimeException('telemetry span lifecycle collector emitted malformed JSON.');
        }

        $bodySpans = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? null;
        if (!is_array($bodySpans)) {
            throw new RuntimeException('telemetry span lifecycle collector emitted malformed OTLP span data.');
        }

        foreach ($bodySpans as $span) {
            if (is_array($span) && isset($span['name'])) {
                $spans[$span['name']] = $span;
            }
        }
    }

    return $spans;
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

$statePath = tempnam(sys_get_temp_dir(), 'king-telemetry-span-lifecycle-worker-state-');
$queuePath = sys_get_temp_dir() . '/king-telemetry-span-lifecycle-worker-queue-' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-span-lifecycle-controller-');
$workerScript = tempnam(sys_get_temp_dir(), 'king-telemetry-span-lifecycle-worker-');

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
        ['text' => 'worker-' . $i],
        [['tool' => 'summarizer']],
        ['trace_id' => 'telemetry-span-worker-' . $i]
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
    $work = king_pipeline_orchestrator_worker_run_next();
    $beforeContext = king_telemetry_get_trace_context();

    $rootId = king_telemetry_start_span('worker-root-' . $i, [
        'boundary' => 'worker',
        'role' => 'root',
        'iteration' => $i,
    ]);
    $rootContext = king_telemetry_get_trace_context();

    $childId = king_telemetry_start_span('worker-child-' . $i, [
        'boundary' => 'worker',
        'role' => 'child',
        'iteration' => $i,
    ]);
    $childContext = king_telemetry_get_trace_context();

    $result['iterations'][] = [
        'run_status' => $work['status'] ?? null,
        'run_text' => $work['result']['text'] ?? null,
        'before_context' => $beforeContext,
        'root_id' => $rootId,
        'root_context' => $rootContext,
        'child_id' => $childId,
        'child_context' => $childContext,
        'child_end_result' => king_telemetry_end_span($childId, ['closed' => 'child']),
        'after_child_context' => king_telemetry_get_trace_context(),
        'root_end_result' => king_telemetry_end_span($rootId, ['closed' => 'root']),
        'after_root_context' => king_telemetry_get_trace_context(),
        'flush_result' => king_telemetry_flush(),
        'after_flush_status' => king_telemetry_get_status(),
    ];
}

$result['empty'] = king_pipeline_orchestrator_worker_run_next();

echo json_encode($result, JSON_UNESCAPED_SLASHES);
PHP);

try {
    $requestServer = king_telemetry_span_lifecycle_start_request_server($collector['port'], $requestIterations);

    for ($i = 0; $i < $requestIterations; $i++) {
        $response = king_server_http1_wire_request_retry(
            $requestServer['port'],
            "GET /telemetry-span-lifecycle?iteration={$i} HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Connection: close\r\n\r\n"
        );
        $parsed = king_server_http1_wire_parse_response($response);
        if (($parsed['status'] ?? 0) !== 204) {
            throw new RuntimeException('request span lifecycle listener returned an unexpected HTTP status.');
        }
    }

    $requestCapture = king_telemetry_span_lifecycle_stop_request_server($requestServer);
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

    $controller = king_telemetry_span_lifecycle_run_json_child(sprintf(
        $baseCommand,
        escapeshellarg($controllerScript) . ' ' . $workerIterations
    ));
    $workerCapture = king_telemetry_span_lifecycle_run_json_child(sprintf(
        $baseCommand,
        escapeshellarg($workerScript) . ' ' . $collector['port'] . ' ' . $workerIterations
    ));
} finally {
    if ($requestServer !== null) {
        king_telemetry_span_lifecycle_stop_request_server($requestServer);
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
    throw new RuntimeException('request span lifecycle harness did not process every iteration.');
}
foreach ($requestCapture['listen_results'] as $result) {
    if ($result !== true) {
        throw new RuntimeException('request span lifecycle harness observed a failed listener iteration.');
    }
}
if (count($requestCapture['iteration_captures'] ?? []) !== $requestIterations) {
    throw new RuntimeException('request span lifecycle harness did not record every iteration.');
}
foreach ($requestCapture['iteration_captures'] as $index => $iteration) {
    king_telemetry_span_lifecycle_assert_iteration('request', $iteration, $index);
}

if (($controller['register'] ?? false) !== true) {
    throw new RuntimeException('worker span lifecycle controller failed to register the tool.');
}
if (count($controller['dispatches'] ?? []) !== $workerIterations) {
    throw new RuntimeException('worker span lifecycle controller did not queue the expected runs.');
}
foreach ($controller['dispatches'] as $dispatch) {
    if (($dispatch['backend'] ?? null) !== 'file_worker' || ($dispatch['status'] ?? null) !== 'queued') {
        throw new RuntimeException('worker span lifecycle controller observed a dispatch drift.');
    }
}

if (count($workerCapture['iterations'] ?? []) !== $workerIterations) {
    throw new RuntimeException('worker span lifecycle runner did not process the expected runs.');
}
foreach ($workerCapture['iterations'] as $index => $iteration) {
    if (($iteration['run_status'] ?? null) !== 'completed') {
        throw new RuntimeException('worker span lifecycle runner did not complete one queued run.');
    }
    if (($iteration['run_text'] ?? null) !== 'worker-' . $index) {
        throw new RuntimeException('worker span lifecycle runner returned an unexpected payload.');
    }
    king_telemetry_span_lifecycle_assert_iteration('worker', $iteration, $index);
}
if (($workerCapture['empty'] ?? true) !== false) {
    throw new RuntimeException('worker span lifecycle runner expected the queue to be empty at the end.');
}

if (count($collectorCapture) !== $requestIterations + $workerIterations) {
    throw new RuntimeException('span lifecycle collector did not observe the expected number of trace flushes.');
}

$exportedSpans = king_telemetry_span_lifecycle_collect_exported_spans($collectorCapture);
if (count($exportedSpans) !== ($requestIterations + $workerIterations) * 2) {
    throw new RuntimeException('span lifecycle collector did not observe every root and child span.');
}

for ($i = 0; $i < $requestIterations; $i++) {
    $root = $exportedSpans['request-root-' . $i] ?? null;
    $child = $exportedSpans['request-child-' . $i] ?? null;
    if (!is_array($root) || !is_array($child)) {
        throw new RuntimeException('request span lifecycle export is missing one span.');
    }
    if (($child['parentSpanId'] ?? null) !== ($root['spanId'] ?? null)) {
        throw new RuntimeException('request child span export lost its parent id.');
    }
    if (($child['traceId'] ?? null) !== ($root['traceId'] ?? null)) {
        throw new RuntimeException('request child span export lost its trace id.');
    }
}

for ($i = 0; $i < $workerIterations; $i++) {
    $root = $exportedSpans['worker-root-' . $i] ?? null;
    $child = $exportedSpans['worker-child-' . $i] ?? null;
    if (!is_array($root) || !is_array($child)) {
        throw new RuntimeException('worker span lifecycle export is missing one span.');
    }
    if (($child['parentSpanId'] ?? null) !== ($root['spanId'] ?? null)) {
        throw new RuntimeException('worker child span export lost its parent id.');
    }
    if (($child['traceId'] ?? null) !== ($root['traceId'] ?? null)) {
        throw new RuntimeException('worker child span export lost its trace id.');
    }
}

echo "OK\n";
?>
--EXPECT--
OK
