--TEST--
King Stream receiveResponse validates timeout input and stays single-use after the first response
--FILE--
<?php
function king_session_start_once_response_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-session-stream-once-');
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

$body = 'once';
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
        throw new RuntimeException('failed to launch local session stream once server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local session stream once server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_session_stop_once_response_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$session = new King\Session('127.0.0.1', 443);
$stream = $session->sendRequest('GET', '/demo');

try {
    $stream->receiveResponse(-1);
    echo "no-exception-1\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

$server = king_session_start_once_response_server();
try {
    $session = new King\Session('127.0.0.1', $server[3]);
    $stream = $session->sendRequest('GET', '/once');
    $response = $stream->receiveResponse();
    var_dump($response->getBody());

    try {
        $stream->receiveResponse();
        echo "no-exception-2\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }
} finally {
    king_session_stop_once_response_server($server);
}
?>
--EXPECT--
string(24) "King\ValidationException"
string(64) "Stream::receiveResponse() timeout_ms must be >= 0 when provided."
string(4) "once"
string(26) "King\InvalidStateException"
string(67) "Stream::receiveResponse() can only be called once per local stream."
