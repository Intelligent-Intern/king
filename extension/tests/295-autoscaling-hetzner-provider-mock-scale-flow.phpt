--TEST--
King autoscaling drives the honest Hetzner provider path against a local mock API
--INI--
king.cluster_autoscale_hetzner_api_token=test-token
king.security_allow_config_override=1
--FILE--
<?php
function king_autoscaling_start_mock_hetzner_api(string $logFile): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve mock Hetzner port: $errstr");
    }

    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $address, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-hetzner-mock-');
    file_put_contents($script, <<<'PHP'
<?php
$port = (int) $argv[1];
$logFile = $argv[2];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(2);
}

$nextId = 7000;
fwrite(STDOUT, "READY\n");

for ($i = 0; $i < 6; $i++) {
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

    [$head, $body] = explode("\r\n\r\n", $request, 2) + ['', ''];
    $lines = explode("\r\n", $head);
    $requestLine = array_shift($lines);
    [$method, $path] = explode(' ', $requestLine, 3);
    $headers = [];
    $contentLength = 0;

    foreach ($lines as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        $name = strtolower(trim($name));
        $value = trim($value);
        $headers[$name] = $value;
        if ($name === 'content-length') {
            $contentLength = (int) $value;
        }
    }

    while (strlen($body) < $contentLength) {
        $chunk = fread($conn, $contentLength - strlen($body));
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $body .= $chunk;
    }

    file_put_contents($logFile, json_encode([
        'method' => $method,
        'path' => $path,
        'authorization' => $headers['authorization'] ?? '',
        'body' => $body,
    ]) . "\n", FILE_APPEND);

    if ($method === 'POST' && $path === '/v1/servers') {
        $id = $nextId++;
        $payload = json_encode([
            'server' => [
                'id' => $id,
                'name' => "king-test-$id",
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

function king_autoscaling_stop_mock_hetzner_api(array $server): void
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
$server = king_autoscaling_start_mock_hetzner_api($logFile);

try {
    var_dump(king_autoscaling_init([
        'provider' => 'hetzner',
        'api_endpoint' => 'http://127.0.0.1:' . $server[3] . '/v1',
        'state_path' => $statePath,
        'server_name_prefix' => 'king-test',
        'instance_type' => 'cx22',
        'instance_image_id' => '123456',
        'region' => 'nbg1',
        'network_config' => '111,222',
        'firewall_ids' => '333',
        'placement_group_id' => '444',
        'prepared_release_url' => 'https://releases.example/king.tar.gz',
        'join_endpoint' => 'https://controller.example/join',
        'instance_tags' => 'env=test,role=worker',
        'max_nodes' => 5,
        'max_scale_step' => 2,
    ]));

    var_dump(king_autoscaling_scale_up(2));
    $status = king_autoscaling_get_status();
    var_dump($status['provider']);
    var_dump($status['provider_mode']);
    var_dump($status['controller_token_configured']);
    var_dump($status['current_instances']);
    var_dump($status['managed_nodes']);
    var_dump($status['active_managed_nodes']);
    var_dump($status['provisioned_managed_nodes']);
    var_dump($status['action_count']);
    var_dump($status['last_action_kind']);

    $nodes = king_autoscaling_get_nodes();
    var_dump(count($nodes));
    var_dump($nodes[0]['lifecycle']);
    var_dump($nodes[0]['active']);

    var_dump(king_autoscaling_mark_node_ready($nodes[0]['server_id']));
    $status = king_autoscaling_get_status();
    var_dump(str_contains($status['last_error'], 'must register before it can become ready'));

    var_dump(king_autoscaling_register_node($nodes[0]['server_id'], 'worker-1'));
    var_dump(king_autoscaling_mark_node_ready($nodes[0]['server_id']));
    var_dump(king_autoscaling_register_node($nodes[1]['server_id'], 'worker-2'));
    var_dump(king_autoscaling_mark_node_ready($nodes[1]['server_id']));

    $status = king_autoscaling_get_status();
    var_dump($status['current_instances']);
    var_dump($status['active_managed_nodes']);
    var_dump($status['provisioned_managed_nodes']);
    var_dump($status['registered_managed_nodes']);
    var_dump($status['last_action_kind']);

    $nodes = king_autoscaling_get_nodes();
    var_dump($nodes[0]['name']);
    var_dump($nodes[1]['name']);

    var_dump(king_autoscaling_drain_node($nodes[0]['server_id']));
    $status = king_autoscaling_get_status();
    var_dump($status['current_instances']);
    var_dump($status['active_managed_nodes']);
    var_dump($status['draining_managed_nodes']);
    var_dump($status['last_action_kind']);

    var_dump(king_autoscaling_scale_down(1));
    $status = king_autoscaling_get_status();
    var_dump($status['current_instances']);
    var_dump($status['active_managed_nodes']);
    var_dump($status['draining_managed_nodes']);
    var_dump($status['action_count']);
    var_dump($status['last_action_kind']);

    $requests = array_map(
        static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_values(array_filter(
            file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
        ))
    );

    var_dump(count($requests));
    var_dump($requests[0]['authorization']);
    var_dump($requests[0]['path']);
    var_dump(str_contains($requests[0]['body'], '"server_type":"cx22"'));
    var_dump(str_contains($requests[0]['body'], '"image":"123456"'));
    var_dump(str_contains($requests[0]['body'], '"location":"nbg1"'));
    var_dump(str_contains($requests[0]['body'], '"placement_group":444'));
    var_dump(str_contains($requests[0]['body'], '"networks":[111,222]'));
    var_dump(str_contains($requests[0]['body'], '"firewalls":[{"firewall":333}]'));
    var_dump(str_contains($requests[0]['body'], '"managed_by":"king"'));
    var_dump(str_contains($requests[0]['body'], '"env":"test"'));
    var_dump(str_contains($requests[0]['body'], '"user_data":"#cloud-config\\nruncmd:\\n'));
    var_dump(str_starts_with($requests[2]['path'], '/v1/servers/'));

    $nodeLines = array_values(array_filter(
        file($statePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
        static fn(string $line): bool => str_starts_with($line, 'node' . "\t")
    ));
    $lifecycles = array_map(
        static fn(string $line): string => explode("\t", $line)[8],
        $nodeLines
    );
    sort($lifecycles);
    var_dump($lifecycles);
} finally {
    king_autoscaling_stop_mock_hetzner_api($server);
    @unlink($statePath);
    @unlink($logFile);
}
?>
--EXPECT--
bool(true)
bool(true)
string(7) "hetzner"
string(14) "hetzner_active"
bool(true)
int(1)
int(2)
int(0)
int(2)
int(2)
string(8) "scale_up"
int(2)
string(11) "provisioned"
bool(false)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(3)
int(2)
int(0)
int(0)
string(15) "mark_node_ready"
string(8) "worker-1"
string(8) "worker-2"
bool(true)
int(2)
int(1)
int(1)
string(10) "drain_node"
bool(true)
int(2)
int(1)
int(0)
int(3)
string(10) "scale_down"
int(3)
string(17) "Bearer test-token"
string(11) "/v1/servers"
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
array(2) {
  [0]=>
  string(7) "deleted"
  [1]=>
  string(5) "ready"
}
