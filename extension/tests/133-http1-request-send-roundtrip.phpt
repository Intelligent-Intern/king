--TEST--
King HTTP/1 request runtime can perform a real local roundtrip
--FILE--
<?php
function king_http1_start_test_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-server-');
    file_put_contents($script, <<<'PHP'
<?php
$port = (int) $argv[1];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(2);
}

fwrite(STDOUT, "READY\n");
$conn = @stream_socket_accept($server, 5);
if ($conn === false) {
    fwrite(STDERR, "accept failed\n");
    exit(3);
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

[$head, $body] = array_pad(explode("\r\n\r\n", $request, 2), 2, '');
$lines = $head === '' ? [] : explode("\r\n", $head);
$requestLine = array_shift($lines) ?? '';
$parts = explode(' ', $requestLine, 3);
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

$payload = json_encode([
    'method' => $parts[0] ?? '',
    'path' => $parts[1] ?? '',
    'headers' => $headers,
    'body' => $body,
], JSON_UNESCAPED_SLASHES);

$response = "HTTP/1.1 201 Created\r\n"
    . "Content-Type: application/json\r\n"
    . "X-King-Test: active\r\n"
    . "Content-Length: " . strlen($payload) . "\r\n"
    . "Connection: close\r\n\r\n"
    . $payload;

fwrite($conn, $response);
fclose($conn);
fclose($server);
PHP);

    $command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script) . ' ' . (int) $port;
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($script);
        throw new RuntimeException('failed to launch local HTTP/1 test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/1 test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_stop_test_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$server = king_http1_start_test_server();
try {
    $response = king_http1_request_send(
        'http://127.0.0.1:' . $server[3] . '/demo?x=1',
        'POST',
        [
            'X-Test' => 'alpha',
            'Content-Type' => 'text/plain',
        ],
        'payload',
        [
            'connect_timeout_ms' => 1000,
            'timeout_ms' => 2000,
        ]
    );
} finally {
    king_http1_stop_test_server($server);
}

$echo = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump($response['status']);
var_dump($response['status_line']);
var_dump($response['headers']['content-type']);
var_dump($response['headers']['x-king-test']);
var_dump($response['protocol']);
var_dump($response['transport_backend']);
var_dump($echo['method']);
var_dump($echo['path']);
var_dump($echo['headers']['x-test']);
var_dump($echo['headers']['content-type']);
var_dump($echo['headers']['content-length']);
var_dump($echo['headers']['connection']);
var_dump(str_contains($echo['headers']['host'], '127.0.0.1'));
var_dump($echo['body']);
?>
--EXPECT--
int(201)
string(20) "HTTP/1.1 201 Created"
string(16) "application/json"
string(6) "active"
string(8) "http/1.1"
string(10) "tcp_socket"
string(4) "POST"
string(9) "/demo?x=1"
string(5) "alpha"
string(10) "text/plain"
string(1) "7"
string(10) "keep-alive"
bool(true)
string(7) "payload"
