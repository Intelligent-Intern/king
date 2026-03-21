--TEST--
King Session sendRequest returns a local Stream runtime with a live HTTP/1 Response bridge
--FILE--
<?php
function king_session_start_stream_response_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-session-stream-runtime-');
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

$body = "alpha-beta";
$response = "HTTP/1.1 200 OK\r\n"
    . "Content-Type: text/plain\r\n"
    . "Content-Length: " . strlen($body) . "\r\n"
    . "Connection: close\r\n\r\n"
    . $body;
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
        throw new RuntimeException('failed to launch local session stream response server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local session stream response server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_session_stop_stream_response_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$server = king_session_start_stream_response_server();
try {
    $session = new King\Session('127.0.0.1', $server[3]);

    $stream = $session->sendRequest('GET', '/demo');
    var_dump($stream instanceof King\Stream);
    var_dump($stream->isClosed());

    $response = $stream->receiveResponse();
    var_dump($response instanceof King\Response);
    var_dump($response->getStatusCode());
    var_dump($response->read(5));
    var_dump($response->read(5));
    var_dump($response->getBody());
    var_dump($stream->isClosed());
    var_dump($session->stats()['cancel_calls']);

    $second = $session->sendRequest('POST', '/next');
    $second->close();
    $stats = $session->stats();
    var_dump($second->isClosed());
    var_dump($stats['cancel_calls']);
    var_dump($stats['last_canceled_stream_id']);
    var_dump($stats['last_cancel_mode']);
} finally {
    king_session_stop_stream_response_server($server);
}
?>
--EXPECT--
bool(true)
bool(false)
bool(true)
int(200)
string(5) "alpha"
string(5) "-beta"
string(10) "alpha-beta"
bool(true)
int(0)
bool(true)
int(1)
int(4)
string(4) "both"
