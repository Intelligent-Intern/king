--TEST--
King OO Http3Client can cancel an active HTTP/3 transport via CancelToken
--SKIPIF--
<?php
$library = getenv('KING_LSQUIC_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_LSQUIC_LIBRARY must point at a prebuilt liblsquic runtime";
}
if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
    echo "skip pcntl and posix are required for the active cancel fixture";
}
?>
--FILE--
<?php
function king_http3_start_silent_udp_cancel_server(): array
{
    $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    if ($server === false) {
        throw new RuntimeException("failed to reserve silent UDP test port: $errstr");
    }

    $serverName = stream_socket_get_name($server, false);
    [, $port] = explode(':', $serverName, 2);

    return [$server, (int) $port];
}

function king_http3_stop_silent_udp_cancel_server(array $server): void
{
    fclose($server[0]);
}

function king_schedule_http3_cancel_signal(King\CancelToken $token, int $delayUs = 100000, int $signal = SIGUSR1): int
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

function king_wait_http3_cancel_signal(int $pid, int $signal = SIGUSR1): void
{
    pcntl_waitpid($pid, $status);
    pcntl_signal($signal, SIG_DFL);
}

$server = king_http3_start_silent_udp_cancel_server();
$cancel = new King\CancelToken();
$cancelPid = king_schedule_http3_cancel_signal($cancel);

try {
    $client = new King\Client\Http3Client();
    try {
        $client->request(
            'GET',
            'https://127.0.0.1:' . $server[1] . '/',
            [],
            '',
            $cancel
        );
        echo "no-exception\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
        var_dump(king_get_last_error());
    }
} finally {
    king_wait_http3_cancel_signal($cancelPid);
    king_http3_stop_silent_udp_cancel_server($server);
}
?>
--EXPECT--
string(21) "King\RuntimeException"
string(76) "HttpClient::request() cancelled the active HTTP/3 transport via CancelToken."
string(76) "HttpClient::request() cancelled the active HTTP/3 transport via CancelToken."
