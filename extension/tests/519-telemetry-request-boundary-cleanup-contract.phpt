--TEST--
King telemetry drops stale request-local span and log residue before the next on-wire request under load
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/telemetry_otlp_test_helper.inc';
require __DIR__ . '/server_websocket_wire_helper.inc';

function king_telemetry_cleanup_pick_request_port(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve request cleanup port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);
    return (int) $port;
}

function king_telemetry_cleanup_start_request_server(int $collectorPort, int $iterations): array
{
    $capture = tempnam(sys_get_temp_dir(), 'king-telemetry-request-cleanup-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $port = king_telemetry_cleanup_pick_request_port();
    $command = sprintf(
        '%s -n -d %s -d %s %s %s %d %d %d',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg(__DIR__ . '/telemetry_cleanup_boundary_server.inc'),
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
        throw new RuntimeException('failed to launch telemetry request cleanup harness');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('telemetry request cleanup harness failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => $port,
    ];
}

function king_telemetry_cleanup_stop_request_server(array $server): array
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
        throw new RuntimeException('telemetry request cleanup harness failed: ' . trim($stderr . "\n" . $stdout));
    }

    return $capture;
}

$iterations = 6;
$collector = king_telemetry_test_start_collector(array_fill(0, (int) ($iterations / 2), [
    'status' => 200,
    'body' => 'ok',
]));
$server = null;
$requestCapture = [];
$collectorCapture = [];

try {
    $server = king_telemetry_cleanup_start_request_server($collector['port'], $iterations);

    for ($i = 0; $i < $iterations; $i++) {
        $response = king_server_http1_wire_request_retry(
            $server['port'],
            "GET /telemetry-cleanup?iteration={$i} HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Connection: close\r\n\r\n"
        );
        $parsed = king_server_http1_wire_parse_response($response);
        if (($parsed['status'] ?? 0) !== 204) {
            throw new RuntimeException('request cleanup listener returned an unexpected HTTP status.');
        }
    }

    $requestCapture = king_telemetry_cleanup_stop_request_server($server);
    $server = null;
} finally {
    if ($server !== null) {
        king_telemetry_cleanup_stop_request_server($server);
    }
    $collectorCapture = king_telemetry_test_stop_collector($collector);
}

if (count($requestCapture['listen_results'] ?? []) !== $iterations) {
    throw new RuntimeException('request cleanup harness did not process every iteration.');
}
foreach ($requestCapture['listen_results'] as $result) {
    if ($result !== true) {
        throw new RuntimeException('request cleanup harness observed a failed listener iteration.');
    }
}
foreach ($requestCapture['before_statuses'] as $status) {
    if (($status['pending_log_count'] ?? -1) !== 0 || ($status['pending_span_count'] ?? -1) !== 0) {
        throw new RuntimeException('request-boundary cleanup left stale telemetry pending before the next request.');
    }
}

$allBodies = '';
if (count($collectorCapture) !== (int) ($iterations / 2)) {
    throw new RuntimeException('request cleanup collector did not observe the expected number of fresh flushes.');
}
foreach ($collectorCapture as $entry) {
    if (($entry['path'] ?? null) !== '/v1/logs') {
        throw new RuntimeException('request cleanup collector observed an unexpected OTLP path.');
    }
    $allBodies .= $entry['body'] ?? '';
}

for ($i = 1; $i < $iterations; $i += 2) {
    if (!str_contains($allBodies, 'request-fresh-' . $i)) {
        throw new RuntimeException('fresh request log was not exported after boundary cleanup.');
    }
}
for ($i = 0; $i < $iterations; $i += 2) {
    if (str_contains($allBodies, 'request-stale-' . $i)) {
        throw new RuntimeException('stale request log leaked across a request boundary.');
    }
}
if (str_contains($allBodies, '"traceId"') || str_contains($allBodies, '"spanId"')) {
    throw new RuntimeException('fresh request logs inherited a stale trace/span context.');
}

echo "OK\n";
?>
--EXPECT--
OK
