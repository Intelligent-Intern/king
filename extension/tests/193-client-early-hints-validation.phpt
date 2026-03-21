--TEST--
King client Early Hints APIs validate request contexts and split multi-value Link headers
--FILE--
<?php
function king_client_start_early_hints_validation_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-client-early-hints-validation-');
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

$body = "ok";
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
        throw new RuntimeException('failed to launch local client early hints validation server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local client early hints validation server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_client_stop_early_hints_validation_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

try {
    king_client_early_hints_get_pending(fopen('php://memory', 'r'));
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'King\HttpRequestContext'));
}

try {
    king_client_early_hints_process(fopen('php://memory', 'r'), []);
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'King\HttpRequestContext'));
}

$server = king_client_start_early_hints_validation_server();
try {
    $context = king_client_send_request(
        'http://127.0.0.1:' . $server[3] . '/dispatch-early-hints',
        'GET',
        null,
        null,
        [
            'response_stream' => true,
            'timeout_ms' => 2000,
        ]
    );

    var_dump(king_client_early_hints_process($context, [
        'Link' => '</alpha.css>; rel=preload; as=style, </beta.js>; rel="modulepreload"; as="script", malformed-entry',
    ]));

    $pending = king_client_early_hints_get_pending($context);
    var_dump(count($pending));
    var_dump($pending[0]['url']);
    var_dump($pending[0]['rel']);
    var_dump($pending[1]['url']);
    var_dump($pending[1]['rel']);

    var_dump(king_client_early_hints_process($context, [
        'x-test' => '</ignored.css>; rel=preload',
    ]));
    var_dump(count(king_client_early_hints_get_pending($context)));

    $response = king_receive_response($context);
    var_dump($response->getBody());
} finally {
    king_client_stop_early_hints_validation_server($server);
}
?>
--EXPECT--
string(9) "TypeError"
bool(true)
string(9) "TypeError"
bool(true)
bool(true)
int(2)
string(10) "/alpha.css"
string(7) "preload"
string(8) "/beta.js"
string(13) "modulepreload"
bool(true)
int(2)
string(2) "ok"
