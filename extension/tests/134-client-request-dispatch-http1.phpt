--TEST--
King client request dispatchers route real traffic onto the HTTP/1 runtime
--FILE--
<?php
function king_http1_start_dispatch_server(string $responseLabel): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-dispatch-');
    file_put_contents($script, <<<'PHP'
<?php
$port = (int) $argv[1];
$label = $argv[2];
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

[$head] = array_pad(explode("\r\n\r\n", $request, 2), 1, '');
$lines = $head === '' ? [] : explode("\r\n", $head);
$requestLine = array_shift($lines) ?? '';
$parts = explode(' ', $requestLine, 3);
$headers = [];
foreach ($lines as $line) {
    if (!str_contains($line, ':')) {
        continue;
    }

    [$name, $value] = explode(':', $line, 2);
    $headers[strtolower(trim($name))] = trim($value);
}

$payload = json_encode([
    'label' => $label,
    'method' => $parts[0] ?? '',
    'path' => $parts[1] ?? '',
    'x-mode' => $headers['x-mode'] ?? '',
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

    $command = escapeshellarg(PHP_BINARY)
        . ' -n '
        . escapeshellarg($script)
        . ' '
        . (int) $port
        . ' '
        . escapeshellarg($responseLabel);
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($script);
        throw new RuntimeException('failed to launch local HTTP/1 dispatch server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/1 dispatch server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_stop_dispatch_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$dispatcherServer = king_http1_start_dispatch_server('dispatcher');
try {
    $dispatcherResponse = king_client_send_request(
        'http://127.0.0.1:' . $dispatcherServer[3] . '/dispatch',
        'GET',
        ['X-Mode' => 'dispatcher']
    );
} finally {
    king_http1_stop_dispatch_server($dispatcherServer);
}

$legacyServer = king_http1_start_dispatch_server('legacy');
try {
    $legacyResponse = king_send_request(
        'http://127.0.0.1:' . $legacyServer[3] . '/legacy',
        'GET',
        ['X-Mode' => 'legacy'],
        null,
        ['preferred_protocol' => 'http1.1']
    );
} finally {
    king_http1_stop_dispatch_server($legacyServer);
}

$dispatcherEcho = json_decode($dispatcherResponse['body'], true, flags: JSON_THROW_ON_ERROR);
$legacyEcho = json_decode($legacyResponse['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump($dispatcherResponse['status']);
var_dump($dispatcherResponse['protocol']);
var_dump($dispatcherEcho['label']);
var_dump($dispatcherEcho['path']);
var_dump($dispatcherEcho['x-mode']);

var_dump($legacyResponse['status']);
var_dump($legacyResponse['transport_backend']);
var_dump($legacyEcho['label']);
var_dump($legacyEcho['path']);
var_dump($legacyEcho['x-mode']);
?>
--EXPECT--
int(200)
string(8) "http/1.1"
string(10) "dispatcher"
string(9) "/dispatch"
string(10) "dispatcher"
int(200)
string(10) "tcp_socket"
string(6) "legacy"
string(7) "/legacy"
string(6) "legacy"
