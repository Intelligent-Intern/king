--TEST--
King telemetry injects the live current span into outgoing HTTP/1, HTTP/2, and HTTP/3 client requests
--SKIPIF--
<?php
require __DIR__ . '/http3_new_stack_skip.inc';
king_http3_skipif_require_openssl();
king_http3_skipif_require_lsquic_runtime();
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';
require __DIR__ . '/http2_server_wire_helper.inc';
require __DIR__ . '/http3_server_wire_helper.inc';
require __DIR__ . '/http3_test_helper.inc';

function king_telemetry_outgoing_trace_pick_port(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve outgoing trace-context port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);
    return (int) $port;
}

function king_telemetry_outgoing_trace_start_server(
    int $http1Port,
    int $http2Port,
    int $http3Port,
    string $http3CaFile
): array
{
    $capture = tempnam(sys_get_temp_dir(), 'king-telemetry-outgoing-trace-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $port = king_telemetry_outgoing_trace_pick_port();
    $command = sprintf(
        '%s -n -d %s -d %s %s %s %d %d %d %d %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg(__DIR__ . '/telemetry_outgoing_trace_context_server.inc'),
        escapeshellarg($capture),
        $port,
        $http1Port,
        $http2Port,
        $http3Port,
        escapeshellarg($http3CaFile)
    );

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch outgoing trace-context harness');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('outgoing trace-context harness failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => $port,
    ];
}

function king_telemetry_outgoing_trace_stop_server(array $server): array
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
        throw new RuntimeException('outgoing trace-context harness failed: ' . trim($stderr . "\n" . $stdout));
    }

    return $capture;
}

function king_telemetry_expected_traceparent(array $context): string
{
    return sprintf(
        '00-%s-%s-%02x',
        $context['trace_id'],
        $context['span_id'],
        (int) $context['trace_flags']
    );
}

$incomingTraceId = '0123456789abcdef0123456789abcdef';
$incomingParentSpanId = '89abcdef01234567';
$incomingTraceState = 'vendor=value,second=entry';
$http1Server = king_server_websocket_wire_start_server('capture-headers');
$http2Server = king_http2_server_wire_start_server(null, 'capture-headers');
$fixture = king_http3_create_fixture([]);
$http3Server = null;
$boundaryServer = null;
$boundaryCapture = [];
$http1Capture = [];
$http2Capture = [];
$http3Capture = [];

try {
    $http3Server = king_http3_server_wire_start_server(
        $fixture['cert'],
        $fixture['key'],
        null,
        'capture-headers'
    );
    $boundaryServer = king_telemetry_outgoing_trace_start_server(
        $http1Server['port'],
        $http2Server['port'],
        $http3Server['port'],
        $fixture['cert']
    );

    $response = king_server_http1_wire_request_retry(
        $boundaryServer['port'],
        "GET /outgoing-trace HTTP/1.1\r\n"
        . "Host: 127.0.0.1\r\n"
        . "TraceParent: 00-0123456789ABCDEF0123456789ABCDEF-89ABCDEF01234567-01\r\n"
        . "TraceState: vendor=value,second=entry\r\n"
        . "Connection: close\r\n\r\n"
    );
    $parsed = king_server_http1_wire_parse_response($response);
    if (($parsed['status'] ?? 0) !== 204) {
        throw new RuntimeException('outgoing trace-context harness returned an unexpected HTTP status.');
    }

    $boundaryCapture = king_telemetry_outgoing_trace_stop_server($boundaryServer);
    $boundaryServer = null;

    $http1Capture = king_server_websocket_wire_stop_server($http1Server);
    $http1Server = null;
    $http2Capture = king_http2_server_wire_stop_server($http2Server);
    $http2Server = null;
    $http3Capture = king_http3_server_wire_stop_server($http3Server);
    $http3Server = null;
} finally {
    if ($boundaryServer !== null) {
        king_telemetry_outgoing_trace_stop_server($boundaryServer);
    }
    if ($http1Server !== null) {
        king_server_websocket_wire_stop_server($http1Server);
    }
    if ($http2Server !== null) {
        king_http2_server_wire_stop_server($http2Server);
    }
    if ($http3Server !== null) {
        king_http3_server_wire_stop_server($http3Server);
    }
    king_http3_destroy_fixture($fixture);
}

$incoming = $boundaryCapture['request_telemetry']['incoming_trace_context'] ?? null;
if (!is_array($incoming)
    || ($incoming['trace_id'] ?? null) !== $incomingTraceId
    || ($incoming['parent_span_id'] ?? null) !== $incomingParentSpanId
    || ($incoming['trace_flags'] ?? null) !== '01'
    || ($incoming['trace_state'] ?? null) !== $incomingTraceState) {
    throw new RuntimeException('outgoing trace-context harness lost the normalized incoming trace metadata.');
}

if (!array_key_exists('before_context', $boundaryCapture) || $boundaryCapture['before_context'] !== null) {
    throw new RuntimeException('outgoing trace-context harness started with stale active span state.');
}

$rootContext = $boundaryCapture['root_context'] ?? null;
if (!is_array($rootContext)
    || ($rootContext['trace_id'] ?? null) !== $incomingTraceId
    || ($rootContext['parent_span_id'] ?? null) !== $incomingParentSpanId
    || ($rootContext['trace_state'] ?? null) !== $incomingTraceState
    || (int) ($rootContext['trace_flags'] ?? -1) !== 1
    || strlen((string) ($rootContext['span_id'] ?? '')) !== 16) {
    throw new RuntimeException('outgoing trace-context harness root span did not inherit the incoming request trace.');
}

$expectedTraceparent = king_telemetry_expected_traceparent($rootContext);
$helperHeaders = $boundaryCapture['helper_headers'] ?? null;
if (!is_array($helperHeaders)
    || ($helperHeaders['x-helper'] ?? null) !== '1'
    || ($helperHeaders['traceparent'] ?? null) !== $expectedTraceparent
    || ($helperHeaders['tracestate'] ?? null) !== $incomingTraceState) {
    throw new RuntimeException('king_telemetry_inject_context() did not materialize the live current span inside the server handler.');
}
if (($rootContext['span_id'] ?? null) === $incomingParentSpanId) {
    throw new RuntimeException('outgoing trace-context harness root span reused the remote parent span id.');
}

$overrideHeaders = $boundaryCapture['helper_override_headers'] ?? null;
if (!is_array($overrideHeaders)
    || ($overrideHeaders['TraceParent'] ?? null) !== '00-11111111111111111111111111111111-2222222222222222-01'
    || ($overrideHeaders['TraceState'] ?? null) !== 'override=1'
    || ($overrideHeaders['x-helper'] ?? null) !== '2'
    || array_key_exists('traceparent', $overrideHeaders)
    || array_key_exists('tracestate', $overrideHeaders)) {
    throw new RuntimeException('king_telemetry_inject_context() should preserve explicit caller trace headers without adding duplicates.');
}

foreach (['after_http1_context', 'after_http2_context', 'after_http3_context'] as $key) {
    $context = $boundaryCapture[$key] ?? null;
    if (!is_array($context)
        || ($context['trace_id'] ?? null) !== ($rootContext['trace_id'] ?? null)
        || ($context['span_id'] ?? null) !== ($rootContext['span_id'] ?? null)
        || ($context['trace_state'] ?? null) !== $incomingTraceState) {
        throw new RuntimeException('outgoing client requests should not disturb the live current span.');
    }
}

if (($boundaryCapture['http1_status'] ?? null) !== 200
    || ($boundaryCapture['http2_status'] ?? null) !== 200
    || ($boundaryCapture['http3_status'] ?? null) !== 200) {
    throw new RuntimeException('one outgoing client transport did not complete successfully.');
}

if (($boundaryCapture['root_end_result'] ?? false) !== true
    || !array_key_exists('after_root_context', $boundaryCapture)
    || $boundaryCapture['after_root_context'] !== null) {
    throw new RuntimeException('outgoing trace-context harness leaked the request root span after close.');
}

$http1Headers = $http1Capture['request_headers'] ?? null;
if (!is_array($http1Headers)
    || ($http1Headers['traceparent'] ?? null) !== $expectedTraceparent
    || ($http1Headers['tracestate'] ?? null) !== $incomingTraceState
    || ($http1Headers['x-mode'] ?? null) !== 'outgoing-http1') {
    throw new RuntimeException('HTTP/1 client request did not carry the live trace context on-wire.');
}

$http2Headers = $http2Capture['request']['headers'] ?? null;
if (!is_array($http2Headers)
    || ($http2Headers['traceparent'] ?? null) !== $expectedTraceparent
    || ($http2Headers['tracestate'] ?? null) !== $incomingTraceState
    || ($http2Headers['x-mode'] ?? null) !== 'outgoing-http2') {
    throw new RuntimeException('HTTP/2 client request did not carry the live trace context on-wire.');
}

$http3Headers = $http3Capture['request']['headers'] ?? null;
if (!is_array($http3Headers)
    || ($http3Headers['traceparent'] ?? null) !== $expectedTraceparent
    || ($http3Headers['tracestate'] ?? null) !== $incomingTraceState
    || ($http3Headers['x-mode'] ?? null) !== 'outgoing-http3') {
    throw new RuntimeException('HTTP/3 client request did not carry the live trace context on-wire.');
}

echo "OK\n";
?>
--EXPECT--
OK
