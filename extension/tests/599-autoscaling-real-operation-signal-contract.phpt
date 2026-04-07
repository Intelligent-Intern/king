--TEST--
King autoscaling captures CPU, memory, RPS, queue, and latency signals from live request operation
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

function king_autoscaling_real_operation_pick_request_port(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve autoscaling real-operation port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);
    return (int) $port;
}

function king_autoscaling_real_operation_start_server(int $iterations, int $plannedPressureRequests): array
{
    $capture = tempnam(sys_get_temp_dir(), 'king-autoscaling-real-operation-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $port = king_autoscaling_real_operation_pick_request_port();
    $command = sprintf(
        '%s -n -d %s -d %s -d %s %s %s %d %d %d',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg('memory_limit=64M'),
        escapeshellarg(__DIR__ . '/autoscaling_real_operation_signal_boundary_server.inc'),
        escapeshellarg($capture),
        $port,
        $iterations,
        $plannedPressureRequests
    );

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch autoscaling real-operation harness');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('autoscaling real-operation harness failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => $port,
    ];
}

function king_autoscaling_real_operation_stop_server(array $server): array
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
        throw new RuntimeException('autoscaling real-operation harness failed: ' . trim($stderr . "\n" . $stdout));
    }

    return $capture;
}

function king_autoscaling_real_operation_request_json(int $port, string $path): array
{
    $response = king_server_http1_wire_request_retry(
        $port,
        "GET {$path} HTTP/1.1\r\n"
        . "Host: 127.0.0.1\r\n"
        . "Connection: close\r\n\r\n"
    );
    $parsed = king_server_http1_wire_parse_response($response);
    if (($parsed['status'] ?? 0) !== 200) {
        throw new RuntimeException('autoscaling real-operation listener returned an unexpected HTTP status.');
    }

    $decoded = json_decode((string) ($parsed['body'] ?? ''), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('autoscaling real-operation listener returned malformed JSON.');
    }

    return $decoded;
}

function king_autoscaling_real_operation_has_signal(array $signals, string $needle): bool
{
    return in_array($needle, $signals, true);
}

$server = null;
$capture = [];
$pressure = [];
$relief = [];

try {
    $server = king_autoscaling_real_operation_start_server(5, 10);

    king_autoscaling_real_operation_request_json($server['port'], '/work?phase=pressure');
    king_autoscaling_real_operation_request_json($server['port'], '/work?phase=pressure');
    $pressure = king_autoscaling_real_operation_request_json($server['port'], '/monitor');

    sleep(1);

    king_autoscaling_real_operation_request_json($server['port'], '/work?phase=relief');
    $relief = king_autoscaling_real_operation_request_json($server['port'], '/monitor');

    $capture = king_autoscaling_real_operation_stop_server($server);
    $server = null;
} finally {
    if ($server !== null) {
        king_autoscaling_real_operation_stop_server($server);
    }
}

$pressureStatus = $pressure['status'] ?? [];
$pressureSignals = $pressureStatus['last_monitor_decision_details']['live_signals'] ?? [];
$pressureScaleUpSignals = $pressureStatus['last_monitor_decision_details']['scale_up_signals'] ?? [];
$pressureSnapshot = $pressureStatus['last_monitor_signal_snapshot'] ?? [];

$reliefStatus = $relief['status'] ?? [];
$reliefSignals = $reliefStatus['last_monitor_decision_details']['live_signals'] ?? [];
$reliefScaleDownSignals = $reliefStatus['last_monitor_decision_details']['scale_down_ready_signals'] ?? [];
$reliefSnapshot = $reliefStatus['last_monitor_signal_snapshot'] ?? [];

var_dump(count($capture['iterations'] ?? []) === 3);
var_dump(($pressure['monitor_started'] ?? false) === true);
var_dump(($pressureStatus['last_signal_source'] ?? null) === 'telemetry');
var_dump(($pressureStatus['last_monitor_decision'] ?? null) === 'scale_up');
var_dump(count($pressureSignals) === 6);
var_dump(king_autoscaling_real_operation_has_signal($pressureSignals, 'cpu'));
var_dump(king_autoscaling_real_operation_has_signal($pressureSignals, 'memory'));
var_dump(king_autoscaling_real_operation_has_signal($pressureSignals, 'active_connections'));
var_dump(king_autoscaling_real_operation_has_signal($pressureSignals, 'requests_per_second'));
var_dump(king_autoscaling_real_operation_has_signal($pressureSignals, 'response_time_ms'));
var_dump(king_autoscaling_real_operation_has_signal($pressureSignals, 'queue_depth'));
var_dump(($pressureSnapshot['cpu_utilization'] ?? 0.0) >= 75.0);
var_dump(($pressureSnapshot['memory_utilization'] ?? 0.0) > 0.0);
var_dump(($pressureSnapshot['active_connections'] ?? 0) >= 1);
var_dump(($pressureSnapshot['requests_per_second'] ?? 0) >= 1);
var_dump(($pressureSnapshot['response_time_ms'] ?? 0) >= 250);
var_dump(($pressureSnapshot['queue_depth'] ?? 0) >= 8);
var_dump(king_autoscaling_real_operation_has_signal($pressureScaleUpSignals, 'cpu'));
var_dump(king_autoscaling_real_operation_has_signal($pressureScaleUpSignals, 'response_time_ms'));
var_dump(king_autoscaling_real_operation_has_signal($pressureScaleUpSignals, 'queue_depth'));

var_dump(($relief['monitor_started'] ?? false) === true);
var_dump(($reliefStatus['last_signal_source'] ?? null) === 'telemetry');
var_dump(($reliefStatus['last_monitor_decision'] ?? null) === 'scale_down');
var_dump(count($reliefSignals) === 6);
var_dump(count($reliefScaleDownSignals) === 6);
var_dump(($reliefSnapshot['cpu_utilization'] ?? 100.0) < 25.0);
var_dump(($reliefSnapshot['memory_utilization'] ?? 100.0) < 70.0);
var_dump(($reliefSnapshot['active_connections'] ?? 0) >= 1);
var_dump(($reliefSnapshot['requests_per_second'] ?? 999) <= 90);
var_dump(($reliefSnapshot['response_time_ms'] ?? 999) < 50);
var_dump(($reliefSnapshot['queue_depth'] ?? 999) === 0);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
