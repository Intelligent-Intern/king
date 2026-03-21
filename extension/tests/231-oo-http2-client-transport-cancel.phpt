--TEST--
King OO Http2Client can cancel an active HTTP/2 transport via CancelToken
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v node')) === '') {
    echo "skip node is required for the local HTTP/2 cancel fixture";
}
if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
    echo "skip pcntl and posix are required for the active cancel fixture";
}
?>
--FILE--
<?php
function king_http2_start_cancel_test_server(int $delayMs = 500): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http2-cancel-server-');
    file_put_contents($script, <<<'JS'
const http2 = require('node:http2');

const port = Number(process.argv[2]);
const delayMs = Number(process.argv[3] || 500);
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
        throw new RuntimeException('failed to launch local HTTP/2 cancel test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/2 cancel test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http2_stop_cancel_test_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
    @unlink($script);
}

function king_schedule_http2_cancel_signal(King\CancelToken $token, int $delayUs = 100000, int $signal = SIGUSR1): int
{
    pcntl_async_signals(true);
    pcntl_signal($signal, static function () use ($token): void {
        $token->cancel();
    });

    $pid = pcntl_fork();
    if ($pid < 0) {
        throw new RuntimeException('failed to fork cancel helper');
    }
    if ($pid === 0) {
        usleep($delayUs);
        posix_kill(posix_getppid(), $signal);
        exit(0);
    }

    return $pid;
}

function king_wait_http2_cancel_signal(int $pid, int $signal = SIGUSR1): void
{
    pcntl_waitpid($pid, $status);
    pcntl_signal($signal, SIG_DFL);
}

$server = king_http2_start_cancel_test_server();
$cancel = new King\CancelToken();
$cancelPid = king_schedule_http2_cancel_signal($cancel);

try {
    $client = new King\Client\Http2Client();
    try {
        $client->request('GET', 'http://127.0.0.1:' . $server[3] . '/cancel', [], '', $cancel);
        echo "no-exception\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
        var_dump(king_get_last_error());
    }
} finally {
    king_wait_http2_cancel_signal($cancelPid);
    king_http2_stop_cancel_test_server($server);
}
?>
--EXPECT--
string(21) "King\RuntimeException"
string(76) "HttpClient::request() cancelled the active HTTP/2 transport via CancelToken."
string(76) "HttpClient::request() cancelled the active HTTP/2 transport via CancelToken."
