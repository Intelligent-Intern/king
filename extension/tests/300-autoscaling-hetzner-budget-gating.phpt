--TEST--
King autoscaling applies spend/quota budget thresholds with warning continuation and hard stop
--INI--
king.cluster_autoscale_hetzner_api_token=test-token
king.security_allow_config_override=1
--FILE--
<?php
function king_autoscaling_start_budget_mock_api(string $logFile): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve mock port: $errstr");
    }

    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $address, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-hetzner-budget-');
    file_put_contents($script, <<<'PHP'
<?php
$port = (int) $argv[1];
$logFile = $argv[2];
$stateFile = $argv[3];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(2);
}

$state = json_decode(file_get_contents($stateFile), true);
if (!is_array($state) || !isset($state['budget_step'])) {
    $state = ['budget_step' => 0];
}
fwrite(STDOUT, "READY\n");

for ($i = 0; $i < 15; $i++) {
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
    $contentLength = 0;
    foreach ($lines as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$name, $value] = explode(':', $line, 2);
        if (strtolower(trim($name)) === 'content-length') {
            $contentLength = (int) trim($value);
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
        'body' => $body,
    ]) . "\n", FILE_APPEND);

    if ($method === 'GET' && preg_match('#/v1/billing/limits$#', $path)) {
        $state['budget_step']++;
        $step = $state['budget_step'];
        if ($step === 1) {
            $payload = ['spend' => 30, 'quota' => 25];
        } elseif ($step === 2) {
            $payload = ['spend' => 85, 'quota' => 35];
        } elseif ($step === 3) {
            $payload = ['spend' => 85, 'quota' => 95];
        } else {
            $payload = [];
        }

        $bodyPayload = json_encode($payload);
        $response = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($bodyPayload) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $bodyPayload;
        fwrite($conn, $response);
    } elseif ($method === 'POST' && preg_match('#/v1/servers$#', $path)) {
        $id = 6000 + $state['budget_step'];
        $payload = json_encode([
            'server' => [
                'id' => $id,
                'name' => "king-budget-$id",
                'status' => 'running',
            ],
        ]);
        $response = "HTTP/1.1 201 Created\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($payload) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $payload;
        fwrite($conn, $response);
    } else {
        $bodyPayload = json_encode(['error' => 'unexpected request']);
        $response = "HTTP/1.1 404 Not Found\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($bodyPayload) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $bodyPayload;
        fwrite($conn, $response);
    }

    file_put_contents($stateFile, json_encode($state));
    fclose($conn);
}

fclose($server);
PHP);

    $stateFile = tempnam(sys_get_temp_dir(), 'king-hetzner-budget-state-');
    file_put_contents($stateFile, json_encode(['budget_step' => 0]));

    $command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script) . ' ' . (int) $port . ' ' . escapeshellarg($logFile) . ' ' . escapeshellarg($stateFile);
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($script);
        @unlink($stateFile);
        throw new RuntimeException('failed to launch budget mock API process');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        @unlink($stateFile);
        throw new RuntimeException('budget mock API failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, $stateFile, (int) $port];
}

function king_autoscaling_stop_budget_mock_api(array $server): void
{
    [$process, $pipes, $script, $stateFile] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
    @unlink($stateFile);
}

$statePath = tempnam(sys_get_temp_dir(), 'king-autoscale-state-');
$logFile = tempnam(sys_get_temp_dir(), 'king-hetzner-budget-log-');
$server = king_autoscaling_start_budget_mock_api($logFile);

try {
    var_dump(king_autoscaling_init([
        'provider' => 'hetzner',
        'api_endpoint' => 'http://127.0.0.1:' . $server[4] . '/v1',
        'state_path' => $statePath,
        'server_name_prefix' => 'king-budget',
        'instance_type' => 'cx11',
        'instance_image_id' => '12345',
        'region' => 'nbg1',
        'max_nodes' => 8,
        'max_scale_step' => 1,
        'hetzner_budget_path' => 'billing/limits',
        'spend_warning_threshold_percent' => 70,
        'spend_hard_limit_percent' => 90,
        'quota_warning_threshold_percent' => 70,
        'quota_hard_limit_percent' => 90,
    ]));

    var_dump(king_autoscaling_scale_up(1));
    $status = king_autoscaling_get_status();
    var_dump($status['managed_nodes']);
    var_dump($status['spend_status']);
    var_dump($status['quota_status']);
    var_dump($status['spend_usage_percent']);
    var_dump($status['quota_usage_percent']);
    var_dump($status['last_warning']);
    var_dump($status['budget_probe_error']);
    var_dump($status['last_error']);

    var_dump(king_autoscaling_scale_up(1));
    $status = king_autoscaling_get_status();
    var_dump($status['managed_nodes']);
    var_dump($status['spend_status']);
    var_dump($status['quota_status']);
    var_dump(str_starts_with($status['last_warning'], 'Hetzner budget probe is in warning state'));
    var_dump($status['last_error']);

    var_dump(king_autoscaling_scale_up(1));
    $status = king_autoscaling_get_status();
    var_dump($status['managed_nodes']);
    var_dump(str_starts_with($status['last_error'], 'Scale-up blocked by Hetzner spend/quota hard limits'));
    var_dump($status['spend_status']);
    var_dump($status['quota_status']);
    var_dump($status['spend_usage_percent']);
    var_dump($status['quota_usage_percent']);
    var_dump($status['last_warning']);
    var_dump($status['budget_probe_error']);

    var_dump(king_autoscaling_scale_up(1));
    $status = king_autoscaling_get_status();
    var_dump($status['managed_nodes']);
    var_dump(str_starts_with($status['last_warning'], 'Hetzner budget probe was unavailable'));
    var_dump(str_starts_with($status['budget_probe_error'], 'Hetzner budget API response did not include all expected fields.'));
    var_dump($status['spend_status']);
    var_dump($status['quota_status']);
    var_dump($status['spend_usage_percent']);
    var_dump($status['quota_usage_percent']);

    $requests = array_map(
        static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_values(array_filter(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
    ));
    var_dump(count($requests));
    var_dump($requests[0]['method']);
    var_dump($requests[0]['path']);
    var_dump($requests[2]['method']);
    var_dump($requests[6]['path']);
    $requestMethods = array_count_values(array_column($requests, 'method'));
    var_dump($requestMethods['GET'] ?? 0);
    var_dump($requestMethods['POST'] ?? 0);
} finally {
    king_autoscaling_stop_budget_mock_api($server);
    @unlink($statePath);
    @unlink($logFile);
}
?>
--EXPECT--
bool(true)
bool(true)
int(1)
string(2) "ok"
string(2) "ok"
int(30)
int(25)
string(0) ""
string(0) ""
string(0) ""
bool(true)
int(2)
string(7) "warning"
string(2) "ok"
bool(false)
string(0) ""
bool(false)
int(2)
bool(true)
string(7) "warning"
string(10) "hard_limit"
int(85)
int(95)
string(0) ""
string(0) ""
bool(true)
int(3)
bool(true)
bool(true)
string(9) "api_error"
string(9) "api_error"
int(-1)
int(-1)
int(7)
string(3) "GET"
string(18) "/v1/billing/limits"
string(3) "GET"
string(11) "/v1/servers"
int(4)
int(3)
