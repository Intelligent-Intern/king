--TEST--
King HTTP/1 direct runtime reuses a keep-alive socket for the same origin
--FILE--
<?php
function king_http1_start_keepalive_reuse_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-keepalive-reuse-');
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
    [$parts, $headers, $body] = king_http1_read_request($conn);
    $payload = json_encode([
        'connectionId' => 1,
        'requestCount' => $requestCount,
        'method' => $parts[0] ?? '',
        'path' => $parts[1] ?? '',
        'requestConnection' => strtolower($headers['connection'] ?? ''),
        'body' => $body,
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
        throw new RuntimeException('failed to launch local HTTP/1 keep-alive reuse server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/1 keep-alive reuse server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_stop_keepalive_reuse_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$server = king_http1_start_keepalive_reuse_server();
try {
    $first = king_http1_request_send('http://127.0.0.1:' . $server[3] . '/first');
    $second = king_http1_request_send('http://127.0.0.1:' . $server[3] . '/second');
} finally {
    king_http1_stop_keepalive_reuse_server($server);
}

$firstEcho = json_decode($first['body'], true, flags: JSON_THROW_ON_ERROR);
$secondEcho = json_decode($second['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump($first['status']);
var_dump($second['status']);
var_dump($firstEcho['connectionId']);
var_dump($secondEcho['connectionId']);
var_dump($secondEcho['requestCount']);
var_dump(($firstEcho['requestConnection'] ?? '') !== 'close');
?>
--EXPECT--
int(200)
int(200)
int(1)
int(1)
int(2)
bool(true)
