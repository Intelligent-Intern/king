--TEST--
King OO HttpClient and Response wrappers use the active HTTP/1 runtime
--FILE--
<?php
function king_http1_start_test_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-oo-server-');
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

$payload = json_encode([
    'method' => $parts[0] ?? '',
    'path' => $parts[1] ?? '',
    'body' => $body,
    'headers' => $headers,
], JSON_UNESCAPED_SLASHES);

$response = "HTTP/1.1 202 Accepted\r\n"
    . "Content-Type: application/json\r\n"
    . "X-King-Test: oo-http1\r\n"
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
        throw new RuntimeException('failed to launch local HTTP/1 OO test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/1 OO test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_stop_test_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$server = king_http1_start_test_server();
try {
    $client = new King\Client\HttpClient();
    $response = $client->request(
        'POST',
        'http://127.0.0.1:' . $server[3] . '/oo-http1',
        [
            'X-Test' => 'beta',
            'Content-Type' => 'text/plain',
        ],
        'payload'
    );
} finally {
    king_http1_stop_test_server($server);
}

$echo = json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);
$headers = $response->getHeaders();

var_dump($response instanceof King\Response);
var_dump($response->getStatusCode());
var_dump($headers['x-king-test']);
var_dump($response->read(4));
var_dump($response->isEndOfBody());
var_dump($response->read(4096) !== '');
var_dump($response->isEndOfBody());
var_dump($echo['method']);
var_dump($echo['path']);
var_dump($echo['headers']['x-test']);
var_dump($echo['body']);

$client->close();
try {
    $client->request('GET', 'http://127.0.0.1:80/');
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECT--
bool(true)
int(202)
string(8) "oo-http1"
string(4) "{"me"
bool(false)
bool(true)
bool(true)
string(4) "POST"
string(9) "/oo-http1"
string(4) "beta"
string(7) "payload"
string(21) "King\RuntimeException"
string(52) "HttpClient::request() cannot run on a closed client."
