--TEST--
King HTTP/1 response_stream keeps an open response out of the keep-alive reuse pool
--FILE--
<?php
function king_http1_start_open_stream_reuse_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-open-stream-reuse-');
    file_put_contents($script, <<<'PHP'
<?php
$port = (int) $argv[1];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(2);
}

fwrite(STDOUT, "READY\n");

$first = @stream_socket_accept($server, 5);
if ($first === false) {
    fwrite(STDERR, "first accept failed\n");
    exit(3);
}

stream_set_timeout($first, 5);
$firstRequest = '';
while (!str_contains($firstRequest, "\r\n\r\n")) {
    $chunk = fread($first, 8192);
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
fwrite($first, $firstHead . "alpha");

$second = @stream_socket_accept($server, 5);
if ($second === false) {
    fwrite(STDERR, "second accept failed\n");
    exit(4);
}

stream_set_timeout($second, 5);
$secondRequest = '';
while (!str_contains($secondRequest, "\r\n\r\n")) {
    $chunk = fread($second, 8192);
    if ($chunk === '' || $chunk === false) {
        break;
    }
    $secondRequest .= $chunk;
}

$secondBody = json_encode(['connection' => 2], JSON_UNESCAPED_SLASHES);
$secondHead = "HTTP/1.1 200 OK\r\n"
    . "Content-Type: application/json\r\n"
    . "Content-Length: " . strlen($secondBody) . "\r\n"
    . "Connection: close\r\n\r\n";
fwrite($second, $secondHead . $secondBody);
fwrite($first, "-beta");

fclose($second);
fclose($first);
fclose($server);
PHP);

    $command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script) . ' ' . (int) $port;
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($script);
        throw new RuntimeException('failed to launch local HTTP/1 open-stream reuse server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/1 open-stream reuse server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_stop_open_stream_reuse_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$server = king_http1_start_open_stream_reuse_server();
try {
    $context = king_http1_request_send(
        'http://127.0.0.1:' . $server[3] . '/stream-reuse',
        'GET',
        null,
        null,
        [
            'response_stream' => true,
            'timeout_ms' => 2000,
        ]
    );

    $response = king_receive_response($context);
    var_dump($response->read(5));

    $second = king_http1_request_send(
        'http://127.0.0.1:' . $server[3] . '/stream-reuse',
        'GET',
        null,
        null,
        ['timeout_ms' => 2000]
    );

    $payload = json_decode($second['body'], true, flags: JSON_THROW_ON_ERROR);
    var_dump($payload['connection']);
} finally {
    king_http1_stop_open_stream_reuse_server($server);
}
?>
--EXPECT--
string(5) "alpha"
int(2)
