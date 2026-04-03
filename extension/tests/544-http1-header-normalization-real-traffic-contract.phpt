--TEST--
King HTTP/1 client surfaces normalize mixed-case and repeated response headers under real traffic
--FILE--
<?php
function king_http1_header_normalization_start_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-header-normalization-');
    file_put_contents($script, <<<'PHP'
<?php
$port = (int) $argv[1];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(2);
}

fwrite(STDOUT, "READY\n");

for ($i = 0; $i < 3; $i++) {
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

    [$head] = array_pad(explode("\r\n\r\n", $request, 2), 2, '');
    $lines = $head === '' ? [] : explode("\r\n", $head);
    $requestLine = array_shift($lines) ?? '';
    $parts = explode(' ', $requestLine, 3);
    $path = $parts[1] ?? '/unknown';
    $body = json_encode(['path' => $path], JSON_UNESCAPED_SLASHES);

    $response = "HTTP/1.1 200 OK\r\n"
        . "Content-Type: application/json\r\n"
        . "X-Multi: alpha\r\n"
        . "x-multi: beta\r\n"
        . "X-Trim:   spaced value   \r\n"
        . "X-Mode: $path\r\n"
        . "cOnNeCtIoN: close\r\n"
        . "Content-Length: " . strlen($body) . "\r\n\r\n"
        . $body;

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
        throw new RuntimeException('failed to launch local HTTP/1 header normalization server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/1 header normalization server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_header_normalization_stop_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

function king_http1_header_normalization_assert_headers(array $headers, string $expectedMode): void
{
    if (($headers['content-type'] ?? null) !== 'application/json') {
        throw new RuntimeException('content-type should be exposed through a lowercase key.');
    }

    if (($headers['x-trim'] ?? null) !== 'spaced value') {
        throw new RuntimeException('header values should be trimmed before exposure.');
    }

    if (($headers['x-mode'] ?? null) !== $expectedMode) {
        throw new RuntimeException('x-mode should reflect the response path.');
    }

    if (($headers['connection'] ?? null) !== 'close') {
        throw new RuntimeException('connection should be normalized through a lowercase key.');
    }

    if (($headers['x-multi'] ?? null) !== ['alpha', 'beta']) {
        throw new RuntimeException('repeated response headers should be preserved in arrival order.');
    }

    if (array_key_exists('X-Multi', $headers) || array_key_exists('cOnNeCtIoN', $headers)) {
        throw new RuntimeException('original mixed-case response header keys should not leak into public maps.');
    }
}

$server = king_http1_header_normalization_start_server();
try {
    $oneShot = king_http1_request_send(
        'http://127.0.0.1:' . $server[3] . '/one-shot',
        'GET',
        null,
        null,
        [
            'connect_timeout_ms' => 1000,
            'timeout_ms' => 2000,
        ]
    );

    $streamContext = king_client_send_request(
        'http://127.0.0.1:' . $server[3] . '/dispatcher-stream',
        'GET',
        null,
        null,
        [
            'response_stream' => true,
            'preferred_protocol' => 'http1.1',
            'connect_timeout_ms' => 1000,
            'timeout_ms' => 2000,
        ]
    );
    $streamed = king_receive_response($streamContext);

    $client = new King\Client\Http1Client();
    try {
        $oo = $client->request(
            'GET',
            'http://127.0.0.1:' . $server[3] . '/oo-client'
        );
    } finally {
        $client->close();
    }
} finally {
    king_http1_header_normalization_stop_server($server);
}

$oneShotPayload = json_decode($oneShot['body'], true, flags: JSON_THROW_ON_ERROR);
$streamedPayload = json_decode($streamed->getBody(), true, flags: JSON_THROW_ON_ERROR);
$ooPayload = json_decode($oo->getBody(), true, flags: JSON_THROW_ON_ERROR);

king_http1_header_normalization_assert_headers($oneShot['headers'], '/one-shot');
king_http1_header_normalization_assert_headers($streamed->getHeaders(), '/dispatcher-stream');
king_http1_header_normalization_assert_headers($oo->getHeaders(), '/oo-client');

var_dump($oneShotPayload['path']);
var_dump($streamedPayload['path']);
var_dump($ooPayload['path']);
?>
--EXPECT--
string(9) "/one-shot"
string(18) "/dispatcher-stream"
string(10) "/oo-client"
