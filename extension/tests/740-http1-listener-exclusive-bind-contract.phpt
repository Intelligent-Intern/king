--TEST--
King HTTP/1 one-shot listener keeps exclusive bind semantics even against same-UID SO_REUSEPORT attempts
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_client')) {
    echo "skip proc_open and stream_socket_client are required";
    return;
}
if (!is_readable('/proc/net/tcp')) {
    echo "skip /proc/net/tcp is required to observe the listener before accept";
    return;
}

$probe = trim((string) shell_exec(
    'command -v python3 >/dev/null 2>&1'
    . ' && python3 -c ' . escapeshellarg("import socket; raise SystemExit(0 if hasattr(socket, 'SO_REUSEPORT') else 1)")
    . ' >/dev/null 2>&1 && printf yes'
));
if ($probe !== 'yes') {
    echo "skip python3 with socket.SO_REUSEPORT is required";
}
?>
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

function king_http1_wait_for_listen_port(int $port, int $timeoutMs = 3000): void
{
    $deadline = microtime(true) + ($timeoutMs / 1000);
    $needle = strtoupper(str_pad(dechex($port), 4, '0', STR_PAD_LEFT));

    do {
        foreach (['/proc/net/tcp', '/proc/net/tcp6'] as $path) {
            if (!is_readable($path)) {
                continue;
            }

            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $columns = preg_split('/\s+/', trim($line));
                if (!isset($columns[1], $columns[3]) || $columns[0] === 'sl') {
                    continue;
                }

                $local = strtoupper($columns[1]);
                $state = strtoupper($columns[3]);
                if ($state === '0A' && str_ends_with($local, ':' . $needle)) {
                    return;
                }
            }
        }

        usleep(25000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('timed out waiting for King HTTP/1 listener to enter LISTEN state');
}

function king_http1_exclusive_bind_attempt(int $port): string
{
    $script = <<<'PY'
import socket
import sys

port = int(sys.argv[1])
sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
try:
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEPORT, 1)
    sock.bind(("127.0.0.1", port))
    sock.listen(1)
    print("BIND_OK")
except OSError as exc:
    print(f"BIND_FAIL errno={exc.errno}")
finally:
    sock.close()
PY;

    $command = 'python3 -c ' . escapeshellarg($script) . ' ' . (int) $port;
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('failed to launch duplicate bind probe');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    $exitCode = proc_close($process);

    if ($exitCode !== 0 && trim($stdout) === '') {
        throw new RuntimeException('duplicate bind probe failed: ' . trim($stderr));
    }

    return trim($stdout);
}

$server = king_server_websocket_wire_start_server('plain');
$capture = [];
$response = '';

try {
    king_http1_wait_for_listen_port($server['port']);
    $bindAttempt = king_http1_exclusive_bind_attempt($server['port']);

    $response = king_server_http1_wire_request_retry(
        $server['port'],
        "GET /exclusive-bind HTTP/1.1\r\n"
        . "Host: 127.0.0.1\r\n"
        . "Connection: close\r\n\r\n"
    );
} finally {
    $capture = king_server_websocket_wire_stop_server($server);
}

$parsed = king_server_http1_wire_parse_response($response);

var_dump($bindAttempt);
var_dump($parsed['status']);
var_dump($capture['listen_result']);
var_dump($capture['listen_error']);
?>
--EXPECTF--
string(18) "BIND_FAIL errno=98"
int(426)
bool(true)
string(0) ""
