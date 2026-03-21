--TEST--
King HTTP/1 response_stream contexts are single-use and stay alive through Response
--FILE--
<?php
function king_http1_start_stream_single_use_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-stream-single-use-');
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

$payload = "alpha-beta";
$response = "HTTP/1.1 200 OK\r\n"
    . "Content-Type: text/plain\r\n"
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
        throw new RuntimeException('failed to launch local HTTP/1 single-use streaming server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/1 single-use streaming server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_stop_stream_single_use_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$server = king_http1_start_stream_single_use_server();
try {
    $context = king_http1_request_send(
        'http://127.0.0.1:' . $server[3] . '/single-use',
        'GET',
        null,
        null,
        [
            'response_stream' => true,
            'timeout_ms' => 2000,
        ]
    );

    $shadow = $context;
    $response = king_receive_response($context);
    unset($context);

    var_dump($response->read(5));
    var_dump($response->getBody());

    try {
        king_receive_response($shadow);
        echo "no-exception\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump(str_starts_with(
            $e->getMessage(),
            'king_receive_response() request context has already been consumed.'
        ));
    }
} finally {
    king_http1_stop_stream_single_use_server($server);
}
?>
--EXPECT--
string(5) "alpha"
string(10) "alpha-beta"
string(21) "King\RuntimeException"
bool(true)
