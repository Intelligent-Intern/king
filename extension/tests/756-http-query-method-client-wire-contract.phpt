--TEST--
King HTTP/1 client sends RFC 10008 QUERY with request content on the wire
--FILE--
<?php
function king_http1_query_756_start_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-query-756-');
    file_put_contents($script, <<<'PHP'
<?php
function king_http1_query_756_read_request($conn): array
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
[$parts, $headers, $body] = king_http1_query_756_read_request($conn);
$payload = json_encode([
    'method' => $parts[0] ?? '',
    'path' => $parts[1] ?? '',
    'content-type' => $headers['content-type'] ?? '',
    'accept' => $headers['accept'] ?? '',
    'body' => $body,
], JSON_UNESCAPED_SLASHES);

$response = "HTTP/1.1 200 OK\r\n"
    . "Content-Type: application/json\r\n"
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
        throw new RuntimeException('failed to launch local HTTP/1 QUERY test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/1 QUERY test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_query_756_stop_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$server = king_http1_query_756_start_server();
try {
    $response = king_client_send_request(
        'http://127.0.0.1:' . $server[3] . '/search?stable=1',
        'QUERY',
        [
            'Content-Type' => 'application/query+json',
            'Accept' => 'application/json',
        ],
        '{"select":["invoice"],"limit":20}',
        ['preferred_protocol' => 'http1.1']
    );
} finally {
    king_http1_query_756_stop_server($server);
}

$echo = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump($response['status']);
var_dump($response['protocol']);
var_dump($echo['method']);
var_dump($echo['path']);
var_dump($echo['content-type']);
var_dump($echo['accept']);
var_dump($echo['body']);
?>
--EXPECT--
int(200)
string(8) "http/1.1"
string(5) "QUERY"
string(16) "/search?stable=1"
string(22) "application/query+json"
string(16) "application/json"
string(33) "{"select":["invoice"],"limit":20}"
