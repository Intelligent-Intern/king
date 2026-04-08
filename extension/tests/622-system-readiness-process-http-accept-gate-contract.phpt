--TEST--
King coordinated runtime gates process requests and new on-wire HTTP accepts while the system is not ready
--SKIPIF--
<?php
if (
    !function_exists('proc_open')
    || !function_exists('proc_get_status')
    || !function_exists('proc_terminate')
    || !function_exists('stream_socket_server')
) {
    echo "skip proc_open, proc_get_status, proc_terminate, and stream_socket_server are required";
}
?>
--FILE--
<?php
function king_system_readiness_pick_port(string $transport): int
{
    $flags = $transport === 'udp'
        ? STREAM_SERVER_BIND
        : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
    $server = stream_socket_server($transport . '://127.0.0.1:0', $errno, $errstr, $flags);
    if ($server === false) {
        throw new RuntimeException("failed to reserve $transport test port: $errstr");
    }

    $address = stream_socket_get_name($server, false);
    fclose($server);
    [, $port] = explode(':', $address, 2);

    return (int) $port;
}

function king_system_readiness_run_listener_gate_child(string $protocol, int $port): array
{
    $capturePath = tempnam(sys_get_temp_dir(), 'king-system-readiness-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $command = implode(' ', [
        escapeshellarg(PHP_BINARY),
        '-n',
        '-d',
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg(__DIR__ . '/system_readiness_http_listener_gate_server.inc'),
        escapeshellarg($capturePath),
        escapeshellarg($protocol),
        (string) $port,
    ]);
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException("failed to launch $protocol readiness child");
    }

    $deadline = microtime(true) + 3.0;
    $status = proc_get_status($process);
    while ($status['running'] && microtime(true) < $deadline) {
        usleep(50000);
        $status = proc_get_status($process);
    }

    $timedOut = $status['running'];
    if ($timedOut) {
        @proc_terminate($process);
        usleep(100000);
        $status = proc_get_status($process);
        if ($status['running']) {
            @proc_terminate($process, 9);
        }
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $capture = [];
    if (is_file($capturePath)) {
        $capture = json_decode((string) file_get_contents($capturePath), true);
        if (!is_array($capture)) {
            $capture = [];
        }
        @unlink($capturePath);
    }

    $capture['timed_out'] = $timedOut;
    $capture['stdout'] = $stdout;
    $capture['stderr'] = $stderr;
    $capture['exit_code'] = $exitCode;

    return $capture;
}

function king_system_readiness_wait_until_ready(int $maxSeconds = 8): void
{
    for ($i = 0; $i < $maxSeconds; $i++) {
        $status = king_system_get_status();
        if (($status['lifecycle'] ?? null) === 'ready') {
            return;
        }

        sleep(1);
    }

    throw new RuntimeException('system did not become ready before readiness gate scenario');
}

var_dump(king_system_init(['component_timeout_seconds' => 1]));
king_system_readiness_wait_until_ready();
var_dump(king_system_restart_component('telemetry'));

$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['readiness_blocker_count']);
var_dump($status['admission']['process_requests']);
var_dump($status['admission']['http_listener_accepts']);

var_dump(king_system_process_request(['action' => 'blocked']));
$processError = king_get_last_error();
var_dump(str_contains($processError, 'cannot admit process_requests'));
var_dump(str_contains($processError, "lifecycle is 'draining'"));
var_dump(str_contains($processError, '1 readiness blocker(s)'));

$http1 = king_system_readiness_run_listener_gate_child('http1', king_system_readiness_pick_port('tcp'));
var_dump($http1['timed_out'] === false);
var_dump(($http1['lifecycle'] ?? null) === 'draining');
var_dump(($http1['admission'] ?? null) === false);
var_dump(($http1['result'] ?? null) === false);
var_dump(($http1['handler_called'] ?? null) === false);
var_dump(str_contains((string) ($http1['error'] ?? ''), 'cannot admit http_listener_accepts'));

$http2 = king_system_readiness_run_listener_gate_child('http2', king_system_readiness_pick_port('tcp'));
var_dump($http2['timed_out'] === false);
var_dump(($http2['lifecycle'] ?? null) === 'draining');
var_dump(($http2['admission'] ?? null) === false);
var_dump(($http2['result'] ?? null) === false);
var_dump(($http2['handler_called'] ?? null) === false);
var_dump(str_contains((string) ($http2['error'] ?? ''), 'cannot admit http_listener_accepts'));

$http3 = king_system_readiness_run_listener_gate_child('http3', king_system_readiness_pick_port('udp'));
var_dump($http3['timed_out'] === false);
var_dump(($http3['lifecycle'] ?? null) === 'draining');
var_dump(($http3['admission'] ?? null) === false);
var_dump(($http3['result'] ?? null) === false);
var_dump(($http3['handler_called'] ?? null) === false);
var_dump(str_contains((string) ($http3['error'] ?? ''), 'cannot admit http_listener_accepts'));

sleep(1);
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['admission']['process_requests']);

sleep(1);
$status = king_system_get_status();
var_dump($status['lifecycle']);
var_dump($status['admission']['process_requests']);
var_dump($status['admission']['http_listener_accepts']);
var_dump(king_system_process_request(['action' => 'ready']));
var_dump(king_get_last_error());
var_dump(king_system_shutdown());
var_dump(king_system_get_status()['initialized']);
?>
--EXPECT--
bool(true)
bool(true)
string(8) "draining"
int(1)
bool(false)
bool(false)
bool(false)
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
string(8) "starting"
bool(false)
string(5) "ready"
bool(true)
bool(true)
bool(true)
string(0) ""
bool(true)
bool(false)
