--TEST--
King server request-root spans and child work inherit extracted incoming trace context without leaking it across requests
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';
require __DIR__ . '/server_websocket_wire_helper.inc';

function king_server_telemetry_propagation_pick_port(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve telemetry propagation port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);
    return (int) $port;
}

function king_server_telemetry_propagation_start_server(int $collectorPort, int $iterations): array
{
    $capture = tempnam(sys_get_temp_dir(), 'king-server-telemetry-propagation-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $port = king_server_telemetry_propagation_pick_port();
    $command = sprintf(
        '%s -n -d %s -d %s %s %s %d %d %d',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg(__DIR__ . '/telemetry_incoming_trace_context_propagation_server.inc'),
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
        throw new RuntimeException('failed to launch telemetry propagation harness');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('telemetry propagation harness failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => $port,
    ];
}

function king_server_telemetry_propagation_stop_server(array $server): array
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
        throw new RuntimeException('telemetry propagation harness failed: ' . trim($stderr . "\n" . $stdout));
    }

    return $capture;
}

function king_server_telemetry_collect_exported_spans(array $collectorCapture): array
{
    $spans = [];

    foreach ($collectorCapture as $entry) {
        if (($entry['path'] ?? null) !== '/v1/traces') {
            throw new RuntimeException('telemetry propagation collector observed an unexpected OTLP path.');
        }

        $payload = json_decode((string) ($entry['body'] ?? ''), true);
        if (!is_array($payload)) {
            throw new RuntimeException('telemetry propagation collector emitted malformed JSON.');
        }

        $bodySpans = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? null;
        if (!is_array($bodySpans)) {
            throw new RuntimeException('telemetry propagation collector emitted malformed OTLP span data.');
        }

        foreach ($bodySpans as $span) {
            if (is_array($span) && isset($span['name'])) {
                $spans[$span['name']] = $span;
            }
        }
    }

    return $spans;
}

function king_server_telemetry_assert_request_span_iteration(
    array $iteration,
    int $index,
    ?string $expectedTraceId,
    ?string $expectedParentSpanId,
    ?string $expectedTraceState
): void {
    if (!array_key_exists('before_context', $iteration) || $iteration['before_context'] !== null) {
        throw new RuntimeException('request iteration started with a stale active span.');
    }

    $requestTelemetry = $iteration['request_telemetry'] ?? null;
    if (!is_array($requestTelemetry)) {
        throw new RuntimeException('request iteration did not capture request telemetry.');
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
        throw new RuntimeException('request root span id did not have the expected shape.');
    }
    if (!is_string($childId) || strlen($childId) !== 16 || $childId === $rootId) {
        throw new RuntimeException('request child span id did not have the expected shape.');
    }

    if (!is_array($rootContext)
        || ($rootContext['operation_name'] ?? null) !== 'server-request-root-' . $index
        || ($rootContext['span_id'] ?? null) !== $rootId
        || strlen((string) ($rootContext['trace_id'] ?? '')) !== 32
        || ($rootContext['attributes']['role'] ?? null) !== 'root'
        || (int) ($rootContext['attributes']['iteration'] ?? -1) !== $index) {
        throw new RuntimeException('request root span snapshot drifted.');
    }

    if (!is_array($childContext)
        || ($childContext['operation_name'] ?? null) !== 'server-request-child-' . $index
        || ($childContext['span_id'] ?? null) !== $childId
        || ($childContext['parent_span_id'] ?? null) !== $rootId
        || ($childContext['trace_id'] ?? null) !== ($rootContext['trace_id'] ?? null)
        || ($childContext['attributes']['role'] ?? null) !== 'child'
        || (int) ($childContext['attributes']['iteration'] ?? -1) !== $index) {
        throw new RuntimeException('request child span snapshot drifted.');
    }

    if ($expectedTraceId !== null) {
        $incoming = $requestTelemetry['incoming_trace_context'] ?? null;
        if (!is_array($incoming)
            || ($incoming['trace_id'] ?? null) !== $expectedTraceId
            || ($incoming['parent_span_id'] ?? null) !== $expectedParentSpanId
            || ($incoming['trace_state'] ?? null) !== $expectedTraceState) {
            throw new RuntimeException('request telemetry lost the extracted incoming trace context.');
        }

        if (($rootContext['trace_id'] ?? null) !== $expectedTraceId
            || ($rootContext['parent_span_id'] ?? null) !== $expectedParentSpanId
            || (int) ($rootContext['trace_flags'] ?? -1) !== 1
            || ($rootContext['trace_state'] ?? null) !== $expectedTraceState) {
            throw new RuntimeException('request root span did not adopt the extracted incoming trace context.');
        }

        if (($childContext['trace_state'] ?? null) !== $expectedTraceState
            || (int) ($childContext['trace_flags'] ?? -1) !== 1) {
            throw new RuntimeException('request child span did not stay on the inherited incoming trace context.');
        }
    } else {
        if (!array_key_exists('incoming_trace_context', $requestTelemetry)
            || $requestTelemetry['incoming_trace_context'] !== null) {
            throw new RuntimeException('request without trace headers should not expose incoming trace context.');
        }

        if (($rootContext['trace_id'] ?? null) === null
            || ($rootContext['trace_id'] ?? null) === ''
            || ($rootContext['trace_id'] ?? null) === ($expectedParentSpanId ?? '')) {
            throw new RuntimeException('request root span did not materialize a fresh local trace id.');
        }

        if (array_key_exists('parent_span_id', $rootContext)
            || array_key_exists('trace_state', $rootContext)) {
            throw new RuntimeException('request without trace headers inherited stale incoming parent state.');
        }
    }

    if (($iteration['child_end_result'] ?? false) !== true) {
        throw new RuntimeException('request child span did not end successfully.');
    }
    if (!is_array($afterChild)
        || ($afterChild['span_id'] ?? null) !== $rootId
        || ($afterChild['operation_name'] ?? null) !== 'server-request-root-' . $index) {
        throw new RuntimeException('request runtime did not restore the root span after child close.');
    }

    if (($iteration['root_end_result'] ?? false) !== true) {
        throw new RuntimeException('request root span did not end successfully.');
    }
    if (!$hasAfterRoot || $afterRoot !== null) {
        throw new RuntimeException('request runtime kept a closed root span active.');
    }

    if (($iteration['flush_result'] ?? false) !== true
        || !is_array($afterFlush)
        || (int) ($afterFlush['pending_span_count'] ?? -1) !== 0
        || (int) ($afterFlush['pending_log_count'] ?? -1) !== 0
        || (int) ($afterFlush['queue_size'] ?? -1) !== 0) {
        throw new RuntimeException('request flush left telemetry residue behind.');
    }
}

$incomingTraceId = '0123456789abcdef0123456789abcdef';
$incomingParentSpanId = '89abcdef01234567';
$incomingTraceState = 'vendor=value,second=entry';
$collector = king_telemetry_test_start_collector([
    ['status' => 200, 'body' => 'ok'],
    ['status' => 200, 'body' => 'ok'],
]);
$server = king_server_telemetry_propagation_start_server($collector['port'], 2);
$capture = [];
$collectorCapture = [];

try {
    $firstResponse = king_server_http1_wire_request_retry(
        $server['port'],
        "GET /trace-propagation HTTP/1.1\r\n"
        . "Host: 127.0.0.1\r\n"
        . "TraceParent: 00-0123456789ABCDEF0123456789ABCDEF-89ABCDEF01234567-01\r\n"
        . "TraceState: vendor=value,second=entry\r\n"
        . "Connection: close\r\n\r\n"
    );
    $firstParsed = king_server_http1_wire_parse_response($firstResponse);
    if (($firstParsed['status'] ?? 0) !== 204) {
        throw new RuntimeException('first propagation request returned an unexpected status.');
    }

    $secondResponse = king_server_http1_wire_request_retry(
        $server['port'],
        "GET /trace-propagation HTTP/1.1\r\n"
        . "Host: 127.0.0.1\r\n"
        . "Connection: close\r\n\r\n"
    );
    $secondParsed = king_server_http1_wire_parse_response($secondResponse);
    if (($secondParsed['status'] ?? 0) !== 204) {
        throw new RuntimeException('second propagation request returned an unexpected status.');
    }

    $capture = king_server_telemetry_propagation_stop_server($server);
    $server = null;
    $collectorCapture = king_telemetry_test_stop_collector($collector);
    $collector = null;
} finally {
    if ($server !== null) {
        king_server_telemetry_propagation_stop_server($server);
    }
    if ($collector !== null) {
        king_telemetry_test_stop_collector($collector);
    }
}

if (count($capture['listen_results'] ?? []) !== 2) {
    throw new RuntimeException('telemetry propagation harness did not process both requests.');
}
foreach ($capture['listen_results'] as $result) {
    if ($result !== true) {
        throw new RuntimeException('telemetry propagation harness observed a failed listener iteration.');
    }
}

$firstIteration = $capture['iterations'][0] ?? null;
$secondIteration = $capture['iterations'][1] ?? null;
if (!is_array($firstIteration) || !is_array($secondIteration)) {
    throw new RuntimeException('telemetry propagation harness did not capture both iterations.');
}

king_server_telemetry_assert_request_span_iteration(
    $firstIteration,
    0,
    $incomingTraceId,
    $incomingParentSpanId,
    $incomingTraceState
);
king_server_telemetry_assert_request_span_iteration(
    $secondIteration,
    1,
    null,
    null,
    null
);

$secondRootContext = $secondIteration['root_context'] ?? [];
if (($secondRootContext['trace_id'] ?? null) === $incomingTraceId) {
    throw new RuntimeException('request without trace headers reused the previous incoming trace id.');
}

$exportedSpans = king_server_telemetry_collect_exported_spans($collectorCapture);

$firstExportedRoot = $exportedSpans['server-request-root-0'] ?? null;
$firstExportedChild = $exportedSpans['server-request-child-0'] ?? null;
$secondExportedRoot = $exportedSpans['server-request-root-1'] ?? null;
$secondExportedChild = $exportedSpans['server-request-child-1'] ?? null;

if (!is_array($firstExportedRoot)
    || ($firstExportedRoot['traceId'] ?? null) !== $incomingTraceId
    || ($firstExportedRoot['parentSpanId'] ?? null) !== $incomingParentSpanId) {
    throw new RuntimeException('exported first root span did not keep the incoming request parent linkage.');
}

if (!is_array($firstExportedChild)
    || ($firstExportedChild['traceId'] ?? null) !== $incomingTraceId
    || ($firstExportedChild['parentSpanId'] ?? null) !== ($firstIteration['root_id'] ?? null)) {
    throw new RuntimeException('exported first child span did not stay on the propagated incoming trace.');
}

if (!is_array($secondExportedRoot)
    || ($secondExportedRoot['traceId'] ?? null) !== ($secondRootContext['trace_id'] ?? null)
    || array_key_exists('parentSpanId', $secondExportedRoot)) {
    throw new RuntimeException('exported second root span inherited stale incoming parent state.');
}

if (!is_array($secondExportedChild)
    || ($secondExportedChild['traceId'] ?? null) !== ($secondRootContext['trace_id'] ?? null)
    || ($secondExportedChild['parentSpanId'] ?? null) !== ($secondIteration['root_id'] ?? null)) {
    throw new RuntimeException('exported second child span did not stay on the fresh local request trace.');
}

echo "OK\n";
?>
--EXPECT--
OK
