--TEST--
King HTTP/1 runtime follows absolute redirects when enabled
--FILE--
<?php
function king_http1_start_absolute_redirect_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-redirect-absolute-');
    file_put_contents($script, <<<'PHP'
<?php
$port = (int) $argv[1];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(2);
}

fwrite(STDOUT, "READY\n");
for ($requestCount = 1; $requestCount <= 2; $requestCount++) {
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

    [$head] = array_pad(explode("\r\n\r\n", $request, 2), 1, '');
    $requestLine = explode("\r\n", $head)[0] ?? '';
    $parts = explode(' ', $requestLine, 3);
    $path = $parts[1] ?? '';

    if ($requestCount === 1) {
        $location = "http://127.0.0.1:$port/final";
        $response = "HTTP/1.1 302 Found\r\n"
            . "Location: $location\r\n"
            . "Content-Length: 0\r\n"
            . "Connection: close\r\n\r\n";
    } else {
        $payload = json_encode([
            'path' => $path,
            'requestCount' => $requestCount,
        ], JSON_UNESCAPED_SLASHES);
        $response = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($payload) . "\r\n"
            . "Connection: close\r\n\r\n"
            . $payload;
    }

    fwrite($conn, $response);
    fclose($conn);
}

fclose($server);
PHP);

    $command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script) . ' ' . (int) $port;
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($script);
        throw new RuntimeException('failed to launch local HTTP/1 absolute redirect server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/1 absolute redirect server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_stop_absolute_redirect_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$server = king_http1_start_absolute_redirect_server();
try {
    $response = king_http1_request_send(
        'http://127.0.0.1:' . $server[3] . '/start',
        'GET',
        null,
        null,
        [
            'follow_redirects' => true,
            'max_redirects' => 3,
        ]
    );
} finally {
    king_http1_stop_absolute_redirect_server($server);
}

$payload = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump($response['status']);
var_dump($payload['path']);
var_dump($payload['requestCount']);
var_dump(str_ends_with($response['effective_url'], '/final'));
?>
--EXPECT--
int(200)
string(6) "/final"
int(2)
bool(true)
