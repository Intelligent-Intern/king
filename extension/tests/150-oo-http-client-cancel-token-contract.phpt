--TEST--
King OO HttpClient preserves pre-cancel checks and can cancel an active HTTP/1 transport
--SKIPIF--
<?php
if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
    echo "skip pcntl and posix are required for the active cancel fixture";
}
?>
--FILE--
<?php
function king_http1_start_cancel_test_server(int $delayUs = 500000): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-cancel-server-');
    file_put_contents($script, <<<'PHP'
<?php
$port = (int) $argv[1];
$delayUs = (int) $argv[2];
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

usleep($delayUs);
$body = "late-http1";
$response = "HTTP/1.1 200 OK\r\n"
    . "Content-Type: text/plain\r\n"
    . "Content-Length: " . strlen($body) . "\r\n"
    . "Connection: close\r\n\r\n"
    . $body;
fwrite($conn, $response);
fclose($conn);
fclose($server);
PHP);

    $command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script) . ' ' . (int) $port . ' ' . (int) $delayUs;
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($script);
        throw new RuntimeException('failed to launch local HTTP/1 cancel test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/1 cancel test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_stop_cancel_test_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    @proc_terminate($process);
    proc_close($process);
    @unlink($script);
}

function king_schedule_cancel_signal(King\CancelToken $token, int $delayUs = 100000, int $signal = SIGUSR1): int
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

function king_wait_cancel_signal(int $pid, int $signal = SIGUSR1): void
{
    pcntl_waitpid($pid, $status);
    pcntl_signal($signal, SIG_DFL);
}

$client = new King\Client\HttpClient();

$cancelled = new King\CancelToken();
$cancelled->cancel();

try {
    $client->request('GET', 'http://127.0.0.1:80/', [], '', $cancelled);
    echo "no-exception-1\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

$pending = new King\CancelToken();
$server = king_http1_start_cancel_test_server();
$cancelPid = king_schedule_cancel_signal($pending);

try {
    try {
        $client->request('GET', 'http://127.0.0.1:' . $server[3] . '/cancel', [], '', $pending);
        echo "no-exception-2\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
        var_dump(king_get_last_error());
    }
} finally {
    king_wait_cancel_signal($cancelPid);
    king_http1_stop_cancel_test_server($server);
}
?>
--EXPECT--
string(21) "King\RuntimeException"
string(71) "HttpClient::request() received a CancelToken that is already cancelled."
string(21) "King\RuntimeException"
string(76) "HttpClient::request() cancelled the active HTTP/1 transport via CancelToken."
string(76) "HttpClient::request() cancelled the active HTTP/1 transport via CancelToken."
