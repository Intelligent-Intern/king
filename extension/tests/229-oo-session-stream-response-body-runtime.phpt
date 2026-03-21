--TEST--
King Stream buffers request body writes locally and delivers a live Response after finish
--FILE--
<?php
function king_session_start_body_echo_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-session-stream-body-');
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

[$headerBlock, $body] = explode("\r\n\r\n", $request, 2) + ['', ''];
$contentLength = 0;
if (preg_match('/^content-length:\s*(\d+)\s*$/mi', $headerBlock, $matches)) {
    $contentLength = (int) $matches[1];
}

while (strlen($body) < $contentLength) {
    $chunk = fread($conn, $contentLength - strlen($body));
    if ($chunk === '' || $chunk === false) {
        break;
    }
    $body .= $chunk;
}

$requestLine = strtok($headerBlock, "\r\n");
[$method, $target] = explode(' ', $requestLine, 3);
$demoHeader = '';
if (preg_match('/^x-king-demo:\s*(.+)\s*$/mi', $headerBlock, $matches)) {
    $demoHeader = trim($matches[1]);
}

$responseBody = $method . '|' . $target . '|' . $demoHeader . '|' . $body;
$response = "HTTP/1.1 201 Created\r\n"
    . "Content-Type: text/plain\r\n"
    . "Content-Length: " . strlen($responseBody) . "\r\n"
    . "Connection: close\r\n\r\n"
    . $responseBody;
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
        throw new RuntimeException('failed to launch local session stream body server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local session stream body server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_session_stop_body_echo_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$server = king_session_start_body_echo_server();
try {
    $session = new King\Session('127.0.0.1', $server[3]);
    $stream = $session->sendRequest(
        'POST',
        '/echo?mode=full',
        ['X-King-Demo' => 'yes'],
        'alpha'
    );

    var_dump($stream->send('-beta'));
    $stream->finish('-omega');
    var_dump($stream->isClosed());

    $response = $stream->receiveResponse(2000);
    var_dump($response instanceof King\Response);
    var_dump($response->getStatusCode());
    var_dump($response->getBody());
} finally {
    king_session_stop_body_echo_server($server);
}
?>
--EXPECT--
int(5)
bool(true)
bool(true)
int(201)
string(41) "POST|/echo?mode=full|yes|alpha-beta-omega"
