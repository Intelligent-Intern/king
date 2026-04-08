--TEST--
King system and autoscaling chaos harness keeps restart transitions and controller rehydration honest
--INI--
king.cluster_autoscale_hetzner_api_token=test-token
king.security_allow_config_override=1
--FILE--
<?php
function king_autoscaling_start_chaos_mock(string $logFile): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve mock port: $errstr");
    }

    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $address, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-chaos-autoscale-');
    file_put_contents($script, <<<'PHP'
<?php
$port = (int) $argv[1];
$logFile = $argv[2];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(2);
}

$nextId = 9100;
fwrite(STDOUT, "READY\n");

for ($i = 0; $i < 2; $i++) {
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

    $payload = json_encode([
        'server' => [
            'id' => $nextId++,
            'name' => 'king-chaos-node',
            'status' => 'running',
        ],
    ]);
    $response = "HTTP/1.1 201 Created\r\n"
        . "Content-Type: application/json\r\n"
        . "Content-Length: " . strlen($payload) . "\r\n"
        . "Connection: close\r\n\r\n"
        . $payload;
    fwrite($conn, $response);
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
        throw new RuntimeException('failed to launch autoscaling chaos mock');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('autoscaling chaos mock failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_autoscaling_stop_chaos_mock(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$statePath = tempnam(sys_get_temp_dir(), 'king-chaos-state-');
$logFile = tempnam(sys_get_temp_dir(), 'king-chaos-log-');
$server = king_autoscaling_start_chaos_mock($logFile);
$config = [
    'provider' => 'hetzner',
    'api_endpoint' => 'http://127.0.0.1:' . $server[3] . '/v1',
    'state_path' => $statePath,
    'server_name_prefix' => 'king-chaos',
    'instance_type' => 'cx11',
    'instance_image_id' => '654321',
    'region' => 'fsn1',
    'max_nodes' => 4,
    'max_scale_step' => 1,
];

try {
    var_dump(king_system_init(['component_timeout_seconds' => 1]));
    var_dump(king_autoscaling_init($config));
    var_dump(king_autoscaling_scale_up(2));

    $nodes = king_autoscaling_get_nodes();
    var_dump(king_autoscaling_register_node($nodes[0]['server_id'], 'chaos-ready'));
    var_dump(king_autoscaling_mark_node_ready($nodes[0]['server_id']));
    var_dump(king_autoscaling_register_node($nodes[1]['server_id'], 'chaos-registered'));

    $status = king_autoscaling_get_status();
    var_dump($status['current_instances']);
    var_dump($status['active_managed_nodes']);
    var_dump($status['registered_managed_nodes']);

    var_dump(king_system_restart_component('telemetry'));
    var_dump(king_system_restart_component('autoscaling'));

    $system = king_system_get_status();
    var_dump($system['lifecycle']);
    var_dump($system['components_draining']);
    var_dump($system['components']['telemetry']['status']);
    var_dump($system['components']['autoscaling']['status']);
    var_dump($system['components']['telemetry']['ready']);
    var_dump($system['components']['autoscaling']['ready']);
    var_dump($system['components']['telemetry']['readiness_reason']);
    var_dump($system['components']['autoscaling']['readiness_reason']);
    var_dump($system['components']['telemetry']['readiness_blocking']);
    var_dump($system['components']['autoscaling']['readiness_blocking']);
    var_dump(king_system_process_request([]));

    sleep(1);
    $system = king_system_get_status();
    var_dump($system['lifecycle']);
    var_dump($system['components_draining']);
    var_dump($system['components']['telemetry']['status']);
    var_dump($system['components']['autoscaling']['status']);
    var_dump($system['components']['telemetry']['readiness_reason']);
    var_dump($system['components']['autoscaling']['readiness_reason']);
    var_dump($system['components']['telemetry']['readiness_blocking']);
    var_dump($system['components']['autoscaling']['readiness_blocking']);
    var_dump(king_system_process_request([]));

    sleep(1);
    $system = king_system_get_status();
    var_dump($system['lifecycle']);
    var_dump($system['components_draining']);
    var_dump($system['components']['telemetry']['status']);
    var_dump($system['components']['autoscaling']['status']);
    var_dump($system['components']['telemetry']['readiness_reason']);
    var_dump($system['components']['autoscaling']['readiness_reason']);
    var_dump($system['components']['telemetry']['readiness_blocking']);
    var_dump($system['components']['autoscaling']['readiness_blocking']);
    var_dump(king_system_process_request([]));

    var_dump(king_autoscaling_init($config));
    $status = king_autoscaling_get_status();
    var_dump($status['provider_mode']);
    var_dump($status['current_instances']);
    var_dump($status['managed_nodes']);
    var_dump($status['active_managed_nodes']);
    var_dump($status['registered_managed_nodes']);
    var_dump($status['action_count']);
    var_dump($status['last_action_kind']);

    $nodes = king_autoscaling_get_nodes();
    var_dump($nodes[0]['lifecycle']);
    var_dump($nodes[1]['lifecycle']);

    $requests = array_values(array_filter(
        file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
    ));
    var_dump(count($requests));
} finally {
    king_autoscaling_stop_chaos_mock($server);
    @unlink($statePath);
    @unlink($logFile);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(2)
int(1)
int(1)
bool(true)
bool(true)
string(8) "draining"
int(2)
string(13) "shutting_down"
string(13) "shutting_down"
bool(false)
bool(false)
string(23) "component_shutting_down"
string(23) "component_shutting_down"
bool(true)
bool(true)
bool(false)
string(8) "starting"
int(0)
string(12) "initializing"
string(12) "initializing"
string(22) "component_initializing"
string(22) "component_initializing"
bool(true)
bool(true)
bool(false)
string(5) "ready"
int(0)
string(7) "running"
string(7) "running"
string(5) "ready"
string(5) "ready"
bool(false)
bool(false)
bool(true)
bool(true)
string(14) "hetzner_active"
int(2)
int(2)
int(1)
int(1)
int(0)
string(4) "init"
string(5) "ready"
string(10) "registered"
int(2)
