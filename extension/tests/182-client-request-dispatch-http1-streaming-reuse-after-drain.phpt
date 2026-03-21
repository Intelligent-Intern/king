--TEST--
King client dispatcher returns a drained HTTP/1 response_stream keep-alive socket to the shared reuse pool
--FILE--
<?php
function king_http1_start_dispatch_stream_reuse_after_drain_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-dispatch-stream-reuse-after-drain-');
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
$firstRequest = '';
while (!str_contains($firstRequest, "\r\n\r\n")) {
    $chunk = fread($conn, 8192);
    if ($chunk === '' || $chunk === false) {
        break;
    }
    $firstRequest .= $chunk;
}

$firstBody = "alpha-beta";
$firstHead = "HTTP/1.1 200 OK\r\n"
    . "Content-Type: text/plain\r\n"
    . "Content-Length: " . strlen($firstBody) . "\r\n"
    . "Connection: keep-alive\r\n\r\n";
fwrite($conn, $firstHead . $firstBody);

$secondRequest = '';
while (!str_contains($secondRequest, "\r\n\r\n")) {
    $chunk = fread($conn, 8192);
    if ($chunk === '' || $chunk === false) {
        break;
    }
    $secondRequest .= $chunk;
}

if ($secondRequest === '') {
    fwrite(STDERR, "second request missing on reused connection\n");
    exit(4);
}

$secondBody = json_encode(['connection' => 1], JSON_UNESCAPED_SLASHES);
$secondHead = "HTTP/1.1 200 OK\r\n"
    . "Content-Type: application/json\r\n"
    . "Content-Length: " . strlen($secondBody) . "\r\n"
    . "Connection: close\r\n\r\n";
fwrite($conn, $secondHead . $secondBody);

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
        throw new RuntimeException('failed to launch local HTTP/1 dispatch stream reuse-after-drain server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/1 dispatch stream reuse-after-drain server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_stop_dispatch_stream_reuse_after_drain_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$server = king_http1_start_dispatch_stream_reuse_after_drain_server();
try {
    $context = king_client_send_request(
        'http://127.0.0.1:' . $server[3] . '/dispatch-stream-reuse',
        'GET',
        null,
        null,
        [
            'response_stream' => true,
            'preferred_protocol' => 'http1',
            'timeout_ms' => 2000,
        ]
    );

    $response = king_receive_response($context);
    var_dump($response->read(5));
    var_dump($response->getBody());
    var_dump($response->read(8192));
    var_dump($response->isEndOfBody());

    $second = king_client_send_request(
        'http://127.0.0.1:' . $server[3] . '/dispatch-stream-reuse',
        'GET',
        null,
        null,
        [
            'preferred_protocol' => 'http1',
            'timeout_ms' => 2000,
        ]
    );

    $payload = json_decode($second['body'], true, flags: JSON_THROW_ON_ERROR);
    var_dump($payload['connection']);
} finally {
    king_http1_stop_dispatch_stream_reuse_after_drain_server($server);
}
?>
--EXPECT--
string(5) "alpha"
string(10) "alpha-beta"
string(5) "-beta"
bool(true)
int(1)
