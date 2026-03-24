--TEST--
King Hetzner autoscaling monitoring respects pending-node step guards and drain-before-delete downscales
--INI--
king.cluster_autoscale_hetzner_api_token=test-token
king.security_allow_config_override=1
--FILE--
<?php
function king_autoscaling_start_monitoring_mock_hetzner_api(string $logFile): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve mock Hetzner port: $errstr");
    }

    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $address, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-hetzner-monitor-');
    file_put_contents($script, <<<'PHP'
<?php
$port = (int) $argv[1];
$logFile = $argv[2];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(2);
}

$nextId = 9000;
fwrite(STDOUT, "READY\n");

for ($i = 0; $i < 8; $i++) {
    $conn = @stream_socket_accept($server, 10);
    if ($conn === false) {
        break;
    }

    stream_set_timeout($conn, 5);
    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $request .= $chunk;
    }

    [$head] = explode("\r\n\r\n", $request, 2) + [''];
    $requestLine = strtok($head, "\r\n");
    [$method, $path] = explode(' ', $requestLine, 3);
    file_put_contents($logFile, json_encode([
        'method' => $method,
        'path' => $path,
    ]) . "\n", FILE_APPEND);

    if ($method === 'POST' && $path === '/v1/servers') {
        $id = $nextId++;
        $payload = json_encode([
            'server' => [
                'id' => $id,
                'name' => "king-monitor-$id",
                'status' => 'running',
            ],
        ]);
        $response = "HTTP/1.1 201 Created\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($payload) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $payload;
        fwrite($conn, $response);
    } elseif ($method === 'DELETE' && preg_match('#^/v1/servers/\d+$#', $path)) {
        $response = "HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n";
        fwrite($conn, $response);
    } else {
        $payload = json_encode(['error' => 'unexpected request']);
        $response = "HTTP/1.1 404 Not Found\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($payload) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $payload;
        fwrite($conn, $response);
    }

    fclose($conn);
}

fclose($server);
PHP);

    $command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script) . ' ' . (int) $port . ' ' . escapeshellarg($logFile);
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($script);
        throw new RuntimeException('failed to launch mock Hetzner API process');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('mock Hetzner API failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_autoscaling_stop_monitoring_mock_hetzner_api(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$statePath = tempnam(sys_get_temp_dir(), 'king-autoscale-state-');
$logFile = tempnam(sys_get_temp_dir(), 'king-hetzner-log-');
$server = king_autoscaling_start_monitoring_mock_hetzner_api($logFile);

try {
    var_dump(king_autoscaling_init([
        'provider' => 'hetzner',
        'api_endpoint' => 'http://127.0.0.1:' . $server[3] . '/v1',
        'state_path' => $statePath,
        'server_name_prefix' => 'king-monitor',
        'instance_type' => 'cx22',
        'instance_image_id' => '123456',
        'region' => 'nbg1',
        'max_nodes' => 5,
        'max_scale_step' => 2,
        'scale_up_policy' => 'add_nodes:2',
        'cooldown_period_sec' => 1,
    ]));

    king_telemetry_init([]);
    king_telemetry_record_metric('autoscaling.cpu_utilization', 96.0, null, 'gauge');
    king_telemetry_record_metric('autoscaling.queue_depth', 10.0, null, 'gauge');
    king_telemetry_record_metric('autoscaling.requests_per_second', 2600.0, null, 'gauge');

    var_dump(king_autoscaling_start_monitoring());
    $status = king_autoscaling_get_status();
    var_dump($status['current_instances']);
    var_dump($status['provisioned_managed_nodes']);
    var_dump($status['last_action_kind']);

    sleep(1);
    var_dump(king_autoscaling_start_monitoring());
    $status = king_autoscaling_get_status();
    var_dump($status['current_instances']);
    var_dump($status['provisioned_managed_nodes']);
    var_dump($status['last_action_kind']);
    var_dump(str_contains($status['last_warning'], 'Pending Hetzner nodes'));

    $nodes = king_autoscaling_get_nodes();
    var_dump(king_autoscaling_register_node($nodes[0]['server_id'], 'guard-1'));
    var_dump(king_autoscaling_mark_node_ready($nodes[0]['server_id']));
    var_dump(king_autoscaling_register_node($nodes[1]['server_id'], 'guard-2'));
    var_dump(king_autoscaling_mark_node_ready($nodes[1]['server_id']));

    sleep(1);
    king_telemetry_record_metric('autoscaling.cpu_utilization', 5.0, null, 'gauge');
    king_telemetry_record_metric('autoscaling.queue_depth', 0.0, null, 'gauge');
    king_telemetry_record_metric('autoscaling.requests_per_second', 8.0, null, 'gauge');
    king_telemetry_record_metric('autoscaling.response_time_ms', 9.0, null, 'gauge');
    king_telemetry_record_metric('autoscaling.active_connections', 5.0, null, 'gauge');

    var_dump(king_autoscaling_start_monitoring());
    $status = king_autoscaling_get_status();
    var_dump($status['current_instances']);
    var_dump($status['draining_managed_nodes']);
    var_dump($status['last_action_kind']);

    sleep(1);
    var_dump(king_autoscaling_start_monitoring());
    $status = king_autoscaling_get_status();
    var_dump($status['current_instances']);
    var_dump($status['draining_managed_nodes']);
    var_dump($status['active_managed_nodes']);
    var_dump($status['last_action_kind']);

    $requests = array_values(array_filter(
        file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
    ));
    var_dump(count($requests));
    var_dump(str_starts_with(json_decode($requests[2], true, 512, JSON_THROW_ON_ERROR)['path'], '/v1/servers/'));
} finally {
    king_autoscaling_stop_monitoring_mock_hetzner_api($server);
    @unlink($statePath);
    @unlink($logFile);
}
?>
--EXPECT--
bool(true)
bool(true)
int(1)
int(2)
string(8) "scale_up"
bool(true)
int(1)
int(2)
string(12) "monitor_tick"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(2)
int(1)
string(10) "drain_node"
bool(true)
int(2)
int(0)
int(1)
string(10) "scale_down"
int(3)
bool(true)
