--TEST--
King HTTP/2 direct and dispatcher paths expose the active timeout contract
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v node')) === '') {
    echo "skip node is required for the local HTTP/2 timeout fixture";
}
?>
--FILE--
<?php
function king_http2_start_timeout_test_server(int $delayMs = 250): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http2-timeout-server-');
    file_put_contents($script, <<<'JS'
const http2 = require('node:http2');

const port = Number(process.argv[2]);
const delayMs = Number(process.argv[3] || 250);
const server = http2.createServer();

server.on('stream', (stream) => {
  stream.on('error', () => {});
  setTimeout(() => {
    if (stream.destroyed) {
      return;
    }

    stream.respond({
      ':status': 200,
      'content-type': 'text/plain'
    });
    stream.end('late-http2');
  }, delayMs);
});

server.on('error', (err) => {
  console.error(err && err.stack ? err.stack : String(err));
  process.exit(2);
});

server.listen(port, '127.0.0.1', () => {
  console.log('READY');
});
JS);

    $node = trim((string) shell_exec('command -v node'));
    $command = escapeshellarg($node) . ' ' . escapeshellarg($script) . ' ' . (int) $port . ' ' . (int) $delayMs;
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($script);
        throw new RuntimeException('failed to launch local HTTP/2 timeout test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/2 timeout test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http2_stop_timeout_test_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
    @unlink($script);
}

$server = king_http2_start_timeout_test_server();
try {
    try {
        king_http2_request_send(
            'http://127.0.0.1:' . $server[3] . '/direct-timeout',
            'GET',
            null,
            null,
            [
                'connect_timeout_ms' => 1000,
                'timeout_ms' => 100,
            ]
        );
        echo "no-exception-1\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump(str_starts_with(
            $e->getMessage(),
            'king_http2_request_send() libcurl HTTP/2 transfer failed:'
        ));
        var_dump(str_starts_with(
            king_get_last_error(),
            'king_http2_request_send() libcurl HTTP/2 transfer failed:'
        ));
    }

    try {
        king_client_send_request(
            'http://127.0.0.1:' . $server[3] . '/dispatch-timeout',
            'GET',
            null,
            null,
            [
                'preferred_protocol' => 'http2',
                'connect_timeout_ms' => 1000,
                'timeout_ms' => 100,
            ]
        );
        echo "no-exception-2\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump(str_starts_with(
            $e->getMessage(),
            'king_client_send_request() libcurl HTTP/2 transfer failed:'
        ));
        var_dump(str_starts_with(
            king_get_last_error(),
            'king_client_send_request() libcurl HTTP/2 transfer failed:'
        ));
    }
} finally {
    king_http2_stop_timeout_test_server($server);
}
?>
--EXPECT--
string(21) "King\TimeoutException"
bool(true)
bool(true)
string(21) "King\TimeoutException"
bool(true)
bool(true)
