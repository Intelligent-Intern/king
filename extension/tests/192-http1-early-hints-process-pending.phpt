--TEST--
King HTTP/1 request contexts store parsed client Early Hints state
--FILE--
<?php
function king_http1_start_early_hints_pending_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-early-hints-pending-');
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

$body = "final-body";
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
        throw new RuntimeException('failed to launch local HTTP/1 early hints pending server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/1 early hints pending server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_stop_early_hints_pending_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$server = king_http1_start_early_hints_pending_server();
try {
    $context = king_http1_request_send(
        'http://127.0.0.1:' . $server[3] . '/early-hints',
        'GET',
        null,
        null,
        [
            'response_stream' => true,
            'timeout_ms' => 2000,
        ]
    );

    var_dump(king_client_early_hints_get_pending($context));

    var_dump(king_client_early_hints_process($context, [
        'Link' => '</app.css>; rel=preload; as=style',
    ]));

    $pending = king_client_early_hints_get_pending($context);
    var_dump(count($pending));
    var_dump($pending[0]['url']);
    var_dump($pending[0]['rel']);
    var_dump($pending[0]['as']);

    var_dump(king_client_early_hints_process($context, [
        'link' => [
            '</app.js>; rel="modulepreload"; as="script"',
            '</font.woff2>; rel=preload; as=font; crossorigin=anonymous',
        ],
        'x-ignore' => 'ignored',
    ]));

    $pending = king_client_early_hints_get_pending($context);
    var_dump(count($pending));
    var_dump($pending[1]['url']);
    var_dump($pending[1]['rel']);
    var_dump($pending[1]['as']);
    var_dump($pending[2]['crossorigin']);

    $response = king_receive_response($context);
    var_dump($response->getBody());
    var_dump(count(king_client_early_hints_get_pending($context)));
} finally {
    king_http1_stop_early_hints_pending_server($server);
}
?>
--EXPECT--
array(0) {
}
bool(true)
int(1)
string(8) "/app.css"
string(7) "preload"
string(5) "style"
bool(true)
int(3)
string(7) "/app.js"
string(13) "modulepreload"
string(6) "script"
string(9) "anonymous"
string(10) "final-body"
int(3)
