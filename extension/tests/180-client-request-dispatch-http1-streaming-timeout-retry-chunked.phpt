--TEST--
King client dispatcher can continue chunked HTTP/1 response_stream reads after a timeout once the peer resumes
--FILE--
<?php
function king_http1_start_dispatch_chunked_timeout_retry_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-dispatch-chunked-timeout-retry-');
    $release = tempnam(sys_get_temp_dir(), 'king-http1-dispatch-chunked-timeout-retry-release-');
    @unlink($release);
    file_put_contents($script, <<<'PHP'
<?php
$port = (int) $argv[1];
$release = $argv[2];
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

$head = "HTTP/1.1 200 OK\r\n"
    . "Content-Type: text/plain\r\n"
    . "Transfer-Encoding: chunked\r\n"
    . "Connection: close\r\n\r\n";
fwrite($conn, $head . "5\r\nalpha\r\n");

$deadline = microtime(true) + 5.0;
while (!file_exists($release) && microtime(true) < $deadline) {
    usleep(10000);
}

if (!file_exists($release)) {
    fwrite(STDERR, "release timeout\n");
    exit(4);
}

fwrite($conn, "5\r\n-beta\r\n0\r\n\r\n");
fclose($conn);
fclose($server);
PHP);

    $command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script) . ' '
        . (int) $port . ' ' . escapeshellarg($release);
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($script);
        @unlink($release);
        throw new RuntimeException('failed to launch local HTTP/1 dispatch chunked timeout-retry server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        @unlink($release);
        throw new RuntimeException('local HTTP/1 dispatch chunked timeout-retry server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, $release, (int) $port];
}

function king_http1_stop_dispatch_chunked_timeout_retry_server(array $server): void
{
    [$process, $pipes, $script, $release] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
    @unlink($release);
}

$server = king_http1_start_dispatch_chunked_timeout_retry_server();
try {
    $context = king_client_send_request(
        'http://127.0.0.1:' . $server[4] . '/dispatch-timeout-retry',
        'GET',
        null,
        null,
        [
            'response_stream' => true,
            'preferred_protocol' => 'http1',
            'timeout_ms' => 200,
        ]
    );

    $response = king_receive_response($context);
    var_dump($response->read(5));

    try {
        $response->read(5);
        echo "no-exception\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    touch($server[3]);

    var_dump($response->read(5));
    var_dump($response->isEndOfBody());
} finally {
    king_http1_stop_dispatch_chunked_timeout_retry_server($server);
}
?>
--EXPECT--
string(5) "alpha"
string(21) "King\TimeoutException"
string(70) "Response::read() timed out while waiting for the HTTP/1 receive phase."
string(5) "-beta"
bool(true)
