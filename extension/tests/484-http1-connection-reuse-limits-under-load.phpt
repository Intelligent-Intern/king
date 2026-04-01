--TEST--
King HTTP/1 runtime caps idle keep-alive reuse under load and reopens honestly after close
--FILE--
<?php
function king_http1_484_reserve_port(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    return (int) $port;
}

function king_http1_484_start_server(
    string $script,
    int $serverId,
    bool $closeOnSecondReuse,
    int $expectedRequests
): array {
    $port = king_http1_484_reserve_port();
    $command = [
        PHP_BINARY,
        '-n',
        $script,
        (string) $port,
        (string) $serverId,
        $closeOnSecondReuse ? '1' : '0',
        (string) $expectedRequests,
    ];
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException("failed to launch HTTP/1 load server $serverId");
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        throw new RuntimeException(
            "HTTP/1 load server $serverId failed: " . trim($stderr)
        );
    }

    return [$process, $pipes, $port];
}

function king_http1_484_stop_server(array $server): void
{
    [$process, $pipes] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
}

$script = tempnam(sys_get_temp_dir(), 'king-http1-load-reuse-');
file_put_contents($script, <<<'PHP'
<?php
function king_http1_484_read_request($conn): array
{
    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $request .= $chunk;
    }

    if (!str_contains($request, "\r\n\r\n")) {
        return [[], [], '', false];
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

    return [$parts, $headers, $body, true];
}

$port = (int) $argv[1];
$serverId = (int) $argv[2];
$closeOnSecondReuse = ($argv[3] ?? '0') === '1';
$expectedRequests = (int) ($argv[4] ?? 2);
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(2);
}

fwrite(STDOUT, "READY\n");
$connectionId = 0;
$handled = 0;

while ($handled < $expectedRequests) {
    $conn = @stream_socket_accept($server, 10);
    if ($conn === false) {
        fwrite(STDERR, "accept failed\n");
        exit(3);
    }

    $connectionId++;
    $perConnectionRequestCount = 0;
    stream_set_timeout($conn, 5);

    while ($handled < $expectedRequests) {
        [$parts, $headers, $body, $ok] = king_http1_484_read_request($conn);
        if (!$ok) {
            break;
        }

        $handled++;
        $perConnectionRequestCount++;
        $responseConnection = 'keep-alive';
        if ($closeOnSecondReuse && $connectionId === 1 && $perConnectionRequestCount === 2) {
            $responseConnection = 'close';
        }

        $payload = json_encode([
            'serverId' => $serverId,
            'connectionId' => $connectionId,
            'requestCount' => $perConnectionRequestCount,
            'totalHandled' => $handled,
            'path' => $parts[1] ?? '',
            'requestConnection' => strtolower($headers['connection'] ?? ''),
            'responseConnection' => $responseConnection,
        ], JSON_UNESCAPED_SLASHES);

        $response = "HTTP/1.1 200 OK\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($payload) . "\r\n"
            . "Connection: {$responseConnection}\r\n\r\n"
            . $payload;
        fwrite($conn, $response);

        if ($responseConnection === 'close') {
            fclose($conn);
            continue 2;
        }
    }

    fclose($conn);
}

fclose($server);
PHP);

$closeOnReuseIds = [5, 10, 15];
$keepAliveProbeIds = [6, 11, 16];
$servers = [];

for ($serverId = 1; $serverId <= 18; $serverId++) {
    $expectedRequests = in_array($serverId, array_merge($closeOnReuseIds, $keepAliveProbeIds), true) ? 3 : 2;
    $servers[$serverId] = king_http1_484_start_server(
        $script,
        $serverId,
        in_array($serverId, $closeOnReuseIds, true),
        $expectedRequests
    );
}

$firstBurst = [];
$secondBurst = [];
$closeReopen = [];
$keepAliveReuse = [];

try {
    for ($serverId = 1; $serverId <= 18; $serverId++) {
        $port = $servers[$serverId][2];
        $response = king_http1_request_send("http://127.0.0.1:$port/phase-one/$serverId");
        $firstBurst[$serverId] = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    for ($serverId = 18; $serverId >= 1; $serverId--) {
        $port = $servers[$serverId][2];
        $response = king_http1_request_send("http://127.0.0.1:$port/phase-two/$serverId");
        $secondBurst[$serverId] = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    foreach ($closeOnReuseIds as $serverId) {
        $port = $servers[$serverId][2];
        $response = king_http1_request_send("http://127.0.0.1:$port/phase-three/reopen");
        $closeReopen[$serverId] = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
    }

    foreach ($keepAliveProbeIds as $serverId) {
        $port = $servers[$serverId][2];
        $response = king_http1_request_send("http://127.0.0.1:$port/phase-three/reuse");
        $keepAliveReuse[$serverId] = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
    }
} finally {
    foreach ($servers as $server) {
        king_http1_484_stop_server($server);
    }
    @unlink($script);
}

var_dump($firstBurst[1]['connectionId']);
var_dump($firstBurst[18]['connectionId']);
var_dump($secondBurst[18]['connectionId']);
var_dump($secondBurst[18]['requestCount']);
var_dump($secondBurst[3]['connectionId']);
var_dump($secondBurst[3]['requestCount']);
var_dump($secondBurst[2]['connectionId']);
var_dump($secondBurst[2]['requestCount']);
var_dump($secondBurst[1]['connectionId']);
var_dump($secondBurst[1]['requestCount']);
var_dump($secondBurst[15]['responseConnection']);
var_dump($secondBurst[10]['responseConnection']);
var_dump($secondBurst[5]['responseConnection']);
var_dump($closeReopen[15]['connectionId']);
var_dump($closeReopen[15]['requestCount']);
var_dump($closeReopen[10]['connectionId']);
var_dump($closeReopen[10]['requestCount']);
var_dump($closeReopen[5]['connectionId']);
var_dump($closeReopen[5]['requestCount']);
var_dump($keepAliveReuse[16]['connectionId']);
var_dump($keepAliveReuse[16]['requestCount']);
var_dump($keepAliveReuse[11]['connectionId']);
var_dump($keepAliveReuse[11]['requestCount']);
var_dump($keepAliveReuse[6]['connectionId']);
var_dump($keepAliveReuse[6]['requestCount']);
?>
--EXPECT--
int(1)
int(1)
int(1)
int(2)
int(1)
int(2)
int(2)
int(1)
int(2)
int(1)
string(5) "close"
string(5) "close"
string(5) "close"
int(2)
int(1)
int(2)
int(1)
int(2)
int(1)
int(1)
int(3)
int(1)
int(3)
int(1)
int(3)
