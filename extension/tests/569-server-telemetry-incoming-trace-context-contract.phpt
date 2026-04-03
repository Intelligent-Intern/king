--TEST--
King server request telemetry extracts normalized incoming trace context from HTTP headers for later request-scope propagation
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

function king_server_telemetry_trace_pick_port(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve server telemetry trace port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);
    return (int) $port;
}

function king_server_telemetry_trace_start_server(int $iterations): array
{
    $capture = tempnam(sys_get_temp_dir(), 'king-server-telemetry-trace-context-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $port = king_server_telemetry_trace_pick_port();
    $command = sprintf(
        '%s -n -d %s -d %s %s %s %d %d',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg(__DIR__ . '/telemetry_incoming_trace_context_server.inc'),
        escapeshellarg($capture),
        $port,
        $iterations
    );

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch server telemetry trace-context harness');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('server telemetry trace-context harness failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => $port,
    ];
}

function king_server_telemetry_trace_stop_server(array $server): array
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
        throw new RuntimeException('server telemetry trace-context harness failed: ' . trim($stderr . "\n" . $stdout));
    }

    return $capture;
}

$server = king_server_telemetry_trace_start_server(2);
$capture = [];

try {
    $validResponse = king_server_http1_wire_request_retry(
        $server['port'],
        "GET /trace-context HTTP/1.1\r\n"
        . "Host: 127.0.0.1\r\n"
        . "TraceParent: 00-0123456789ABCDEF0123456789ABCDEF-89ABCDEF01234567-01\r\n"
        . "TraceState: vendor=value,second=entry\r\n"
        . "Connection: close\r\n\r\n"
    );
    $parsedValid = king_server_http1_wire_parse_response($validResponse);
    if (($parsedValid['status'] ?? 0) !== 204) {
        throw new RuntimeException('valid trace-context request returned an unexpected status.');
    }

    $invalidResponse = king_server_http1_wire_request_retry(
        $server['port'],
        "GET /trace-context HTTP/1.1\r\n"
        . "Host: 127.0.0.1\r\n"
        . "traceparent: 00-00000000000000000000000000000000-89abcdef01234567-01\r\n"
        . "Connection: close\r\n\r\n"
    );
    $parsedInvalid = king_server_http1_wire_parse_response($invalidResponse);
    if (($parsedInvalid['status'] ?? 0) !== 204) {
        throw new RuntimeException('invalid trace-context request returned an unexpected status.');
    }

    $capture = king_server_telemetry_trace_stop_server($server);
    $server = null;
} finally {
    if ($server !== null) {
        king_server_telemetry_trace_stop_server($server);
    }
}

if (count($capture['listen_results'] ?? []) !== 2) {
    throw new RuntimeException('server telemetry trace-context harness did not process both requests.');
}
foreach ($capture['listen_results'] as $result) {
    if ($result !== true) {
        throw new RuntimeException('server telemetry trace-context harness observed a failed listener iteration.');
    }
}

$validTelemetry = $capture['iterations'][0]['telemetry'] ?? null;
if (!is_array($validTelemetry)) {
    throw new RuntimeException('valid request did not capture telemetry metadata.');
}
$validIncoming = $validTelemetry['incoming_trace_context'] ?? 'missing';
if (!is_array($validIncoming)
    || ($validIncoming['trace_id'] ?? null) !== '0123456789abcdef0123456789abcdef'
    || ($validIncoming['parent_span_id'] ?? null) !== '89abcdef01234567'
    || ($validIncoming['trace_flags'] ?? null) !== '01'
    || ($validIncoming['trace_state'] ?? null) !== 'vendor=value,second=entry') {
    throw new RuntimeException('valid request did not expose the normalized incoming trace context.');
}

$invalidTelemetry = $capture['iterations'][1]['telemetry'] ?? null;
if (!is_array($invalidTelemetry)) {
    throw new RuntimeException('invalid request did not capture telemetry metadata.');
}
if (!array_key_exists('incoming_trace_context', $invalidTelemetry)
    || $invalidTelemetry['incoming_trace_context'] !== null) {
    throw new RuntimeException('invalid request should not expose a partial incoming trace context.');
}

echo "OK\n";
?>
--EXPECT--
OK
