--TEST--
King HTTP/1 dispatcher shares the same keep-alive origin pool
--FILE--
<?php
function king_http1_start_dispatch_reuse_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-dispatch-reuse-');
    file_put_contents($script, <<<'PHP'
<?php
function king_http1_read_request($conn): array
{
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
    foreach ($lines as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }

        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    return [$parts, $headers, $body];
}

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
for ($requestCount = 1; $requestCount <= 2; $requestCount++) {
    [$parts, $headers] = king_http1_read_request($conn);
    $payload = json_encode([
        'connectionId' => 1,
        'requestCount' => $requestCount,
        'method' => $parts[0] ?? '',
        'path' => $parts[1] ?? '',
        'mode' => $headers['x-mode'] ?? '',
    ], JSON_UNESCAPED_SLASHES);

    $connectionHeader = $requestCount < 2 ? 'keep-alive' : 'close';
    $response = "HTTP/1.1 200 OK\r\n"
        . "Content-Type: application/json\r\n"
        . "Content-Length: " . strlen($payload) . "\r\n"
        . "Connection: $connectionHeader\r\n\r\n"
        . $payload;
    fwrite($conn, $response);
}

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
        throw new RuntimeException('failed to launch local HTTP/1 dispatch reuse server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/1 dispatch reuse server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_stop_dispatch_reuse_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$server = king_http1_start_dispatch_reuse_server();
try {
    $direct = king_http1_request_send('http://127.0.0.1:' . $server[3] . '/direct');
    $dispatch = king_client_send_request(
        'http://127.0.0.1:' . $server[3] . '/dispatch',
        'GET',
        ['X-Mode' => 'dispatcher'],
        null,
        ['preferred_protocol' => 'http1.1']
    );
} finally {
    king_http1_stop_dispatch_reuse_server($server);
}

$directEcho = json_decode($direct['body'], true, flags: JSON_THROW_ON_ERROR);
$dispatchEcho = json_decode($dispatch['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump($direct['protocol']);
var_dump($dispatch['transport_backend']);
var_dump($directEcho['connectionId']);
var_dump($dispatchEcho['connectionId']);
var_dump($dispatchEcho['requestCount']);
var_dump($dispatchEcho['connectionId'] === $directEcho['connectionId']);
?>
--EXPECT--
string(8) "http/1.1"
string(10) "tcp_socket"
int(1)
int(1)
int(2)
bool(true)
