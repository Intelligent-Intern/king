--TEST--
King telemetry preserves unsampled incoming parent decisions across request-root spans and outgoing propagation
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';
require __DIR__ . '/server_websocket_wire_helper.inc';

function king_telemetry_sampling_pick_port(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve telemetry sampling port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);
    return (int) $port;
}

function king_telemetry_sampling_start_server(int $collectorPort): array
{
    $capture = tempnam(sys_get_temp_dir(), 'king-telemetry-sampling-parent-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $port = king_telemetry_sampling_pick_port();
    $command = sprintf(
        '%s -n -d %s -d %s %s %s %d %d',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg(__DIR__ . '/telemetry_sampling_parent_boundary_server.inc'),
        escapeshellarg($capture),
        $port,
        $collectorPort
    );

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch telemetry unsampled-parent harness');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('telemetry unsampled-parent harness failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => $port,
    ];
}

function king_telemetry_sampling_stop_server(array $server): array
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
        throw new RuntimeException('telemetry unsampled-parent harness failed: ' . trim($stderr . "\n" . $stdout));
    }

    return $capture;
}

$incomingTraceId = 'fedcba9876543210fedcba9876543210';
$incomingParentSpanId = '76543210fedcba98';
$incomingTraceState = 'vendor=value,second=entry';
$collector = king_telemetry_test_start_collector([]);
$server = king_telemetry_sampling_start_server($collector['port']);
$capture = [];
$collectorCapture = [];

try {
    $response = king_server_http1_wire_request_retry(
        $server['port'],
        "GET /sampling HTTP/1.1\r\n"
        . "Host: 127.0.0.1\r\n"
        . "TraceParent: 00-" . strtoupper($incomingTraceId) . '-' . strtoupper($incomingParentSpanId) . "-00\r\n"
        . "TraceState: {$incomingTraceState}\r\n"
        . "Connection: close\r\n\r\n"
    );
    $parsed = king_server_http1_wire_parse_response($response);
    if (($parsed['status'] ?? 0) !== 204) {
        throw new RuntimeException('telemetry unsampled-parent harness returned an unexpected HTTP status.');
    }

    $capture = king_telemetry_sampling_stop_server($server);
    $server = null;
} finally {
    if ($server !== null) {
        king_telemetry_sampling_stop_server($server);
    }

    $collectorCapture = king_telemetry_test_stop_collector($collector);
}

if (!array_key_exists('before_context', $capture) || $capture['before_context'] !== null) {
    throw new RuntimeException('telemetry unsampled-parent harness started with stale active span state.');
}

$incoming = $capture['request_telemetry']['incoming_trace_context'] ?? null;
if (!is_array($incoming)
    || ($incoming['trace_id'] ?? null) !== $incomingTraceId
    || ($incoming['parent_span_id'] ?? null) !== $incomingParentSpanId
    || ($incoming['trace_flags'] ?? null) !== '00'
    || ($incoming['trace_state'] ?? null) !== $incomingTraceState) {
    throw new RuntimeException('telemetry unsampled-parent harness lost the normalized incoming trace metadata.');
}

$rootId = $capture['root_id'] ?? null;
$rootContext = $capture['root_context'] ?? null;
if (!is_string($rootId) || strlen($rootId) !== 16 || $rootId === $incomingParentSpanId) {
    throw new RuntimeException('telemetry unsampled-parent harness produced an invalid local root span id.');
}
if (!is_array($rootContext)
    || ($rootContext['trace_id'] ?? null) !== $incomingTraceId
    || ($rootContext['parent_span_id'] ?? null) !== $incomingParentSpanId
    || (int) ($rootContext['trace_flags'] ?? -1) !== 0
    || ($rootContext['trace_state'] ?? null) !== $incomingTraceState) {
    throw new RuntimeException('telemetry unsampled-parent harness root span did not preserve the incoming unsampled trace decision.');
}

$helperHeaders = $capture['helper_headers'] ?? null;
if (!is_array($helperHeaders)
    || ($helperHeaders['x-helper'] ?? null) !== '1'
    || ($helperHeaders['traceparent'] ?? null) !== sprintf('00-%s-%s-00', $incomingTraceId, $rootId)
    || ($helperHeaders['tracestate'] ?? null) !== $incomingTraceState) {
    throw new RuntimeException('telemetry unsampled-parent harness did not propagate the unsampled trace context back out.');
}

if (($capture['root_end_result'] ?? false) !== true
    || !array_key_exists('after_root_context', $capture)
    || $capture['after_root_context'] !== null
    || ($capture['flush_result'] ?? false) !== true) {
    throw new RuntimeException('telemetry unsampled-parent harness failed to close and flush the root span cleanly.');
}

$afterFlush = $capture['after_flush_status'] ?? null;
if (!is_array($afterFlush)
    || (int) ($afterFlush['pending_span_count'] ?? -1) !== 0
    || (int) ($afterFlush['pending_log_count'] ?? -1) !== 0
    || (int) ($afterFlush['queue_size'] ?? -1) !== 0) {
    throw new RuntimeException('telemetry unsampled-parent harness left telemetry residue behind.');
}

if ($collectorCapture !== []) {
    throw new RuntimeException('telemetry unsampled-parent harness should not export unsampled spans.');
}

echo "OK\n";
?>
--EXPECT--
OK
