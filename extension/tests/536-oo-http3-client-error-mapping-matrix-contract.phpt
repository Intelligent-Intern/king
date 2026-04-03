--TEST--
King OO Http3Client keeps QUIC TLS transport protocol timeout and cancellation failures on stable public exception classes
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
}

$server = getenv('KING_QUICHE_SERVER');
if (!is_string($server) || $server === '' || !is_executable($server)) {
    echo "skip KING_QUICHE_SERVER must point at a prebuilt quiche-server binary";
}

$library = getenv('KING_QUICHE_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_QUICHE_LIBRARY must point at a prebuilt libquiche runtime";
}

if (trim((string) shell_exec('command -v cargo')) === '') {
    echo "skip cargo is required for the HTTP/3 failure-peer helper";
}

if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
    echo "skip pcntl and posix are required for the active cancel fixture";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';

function king_http3_start_silent_udp_error_map_server(): array
{
    $server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    if ($server === false) {
        throw new RuntimeException("failed to reserve silent UDP test port: $errstr");
    }

    $serverName = stream_socket_get_name($server, false);
    [, $port] = explode(':', $serverName, 2);

    return [$server, (int) $port];
}

function king_http3_stop_silent_udp_error_map_server(array $server): void
{
    fclose($server[0]);
}

function king_schedule_http3_error_map_cancel_signal(
    King\CancelToken $token,
    int $delayUs = 100000,
    int $signal = SIGUSR1
): int {
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

function king_wait_http3_error_map_cancel_signal(int $pid, int $signal = SIGUSR1): void
{
    pcntl_waitpid($pid, $status);
    pcntl_signal($signal, SIG_DFL);
}

$fixture = king_http3_create_fixture([
    'x.txt' => "error-map\n",
], 'king-http3-oo-error-mapping-');

$trustedConfig = new King\Config([
    'tls_default_ca_file' => $fixture['cert'],
]);

$assertCloseCapture = static function (array $capture, string $mode, bool $isApp, int $code, string $reason): void {
    var_dump($capture['mode'] === $mode);
    var_dump($capture['saw_initial'] === true);
    var_dump($capture['saw_established'] === true);
    var_dump($capture['peer_error_present'] === false);
    var_dump($capture['local_error_present'] === true);
    var_dump($capture['local_error_is_app'] === $isApp);
    var_dump($capture['local_error_code'] === $code);
    var_dump($capture['local_error_reason'] === $reason);

    if ($mode === 'transport_close') {
        var_dump($capture['saw_h3_open'] === false);
        var_dump($capture['saw_request_headers'] === false);
        return;
    }

    var_dump($capture['saw_h3_open'] === true);
    var_dump($capture['saw_request_headers'] === true);
};

try {
    $server = king_http3_start_test_server($fixture['cert'], $fixture['key'], $fixture['root']);
    try {
        $client = new King\Client\Http3Client();
        $expected = 'King\\Client\\HttpClient::request() failed during the QUIC/TLS handshake (-10).';
        $e = king_http3_exception_with_retry(
            static fn () => $client->request(
                'GET',
                king_http3_test_server_url($server, '/x.txt')
            ),
            'King\\TlsException',
            $expected
        );
        var_dump(get_class($e));
        var_dump($e->getMessage() === $expected);
        var_dump(king_get_last_error() === $expected);
    } finally {
        king_http3_stop_test_server($server);
    }

    $transportClient = new King\Client\Http3Client($trustedConfig);
    $transportExpected = 'King\\Client\\HttpClient::request() received a QUIC transport close before the HTTP/3 response completed (code 4919, reason "test transport abort").';
    $transport = king_http3_one_shot_exception_with_retry(
        static fn () => king_http3_start_failure_peer(
            'transport_close',
            $fixture['cert'],
            $fixture['key']
        ),
        'king_http3_stop_failure_peer',
        static fn (array $peer) => $transportClient->request(
            'GET',
            'https://' . $peer['host'] . ':' . $peer['port'] . '/transport-close'
        ),
        'King\\QuicException',
        $transportExpected
    );
    $transportException = $transport['exception'];
    $transportCapture = king_http3_failure_peer_close_capture($transport['capture']);
    var_dump(get_class($transportException));
    var_dump($transportException->getMessage() === $transportExpected);
    var_dump(king_get_last_error() === $transportExpected);
    $assertCloseCapture($transportCapture, 'transport_close', false, 4919, 'test transport abort');

    $protocolClient = new King\Client\Http3Client($trustedConfig);
    $protocolExpected = 'King\\Client\\HttpClient::request() received a protocol close before the HTTP/3 response completed (code 4660, reason "test application abort").';
    $protocol = king_http3_one_shot_exception_with_retry(
        static fn () => king_http3_start_failure_peer(
            'application_close',
            $fixture['cert'],
            $fixture['key']
        ),
        'king_http3_stop_failure_peer',
        static fn (array $peer) => $protocolClient->request(
            'GET',
            'https://' . $peer['host'] . ':' . $peer['port'] . '/application-close'
        ),
        'King\\ProtocolException',
        $protocolExpected
    );
    $protocolException = $protocol['exception'];
    $protocolCapture = king_http3_failure_peer_close_capture($protocol['capture']);
    var_dump(get_class($protocolException));
    var_dump($protocolException->getMessage() === $protocolExpected);
    var_dump(king_get_last_error() === $protocolExpected);
    $assertCloseCapture($protocolCapture, 'application_close', true, 4660, 'test application abort');

    $timeoutServer = king_http3_start_silent_udp_error_map_server();
    try {
        $timeoutClient = new King\Client\Http3Client(new King\Config([
            'tcp_connect_timeout_ms' => 100,
        ]));
        $timeoutExpected = 'King\\Client\\HttpClient::request() timed out while establishing the QUIC connection.';
        try {
            $timeoutClient->request(
                'GET',
                'https://127.0.0.1:' . $timeoutServer[1] . '/timeout'
            );
            echo "no-timeout-exception\n";
        } catch (Throwable $e) {
            var_dump(get_class($e));
            var_dump($e->getMessage() === $timeoutExpected);
            var_dump(king_get_last_error() === $timeoutExpected);
        }
    } finally {
        king_http3_stop_silent_udp_error_map_server($timeoutServer);
    }

    $cancelServer = king_http3_start_silent_udp_error_map_server();
    $cancel = new King\CancelToken();
    $cancelPid = king_schedule_http3_error_map_cancel_signal($cancel);
    try {
        $cancelClient = new King\Client\Http3Client();
        $cancelExpected = 'HttpClient::request() cancelled the active HTTP/3 transport via CancelToken.';
        try {
            $cancelClient->request(
                'GET',
                'https://127.0.0.1:' . $cancelServer[1] . '/cancel',
                [],
                '',
                $cancel
            );
            echo "no-cancel-exception\n";
        } catch (Throwable $e) {
            var_dump(get_class($e));
            var_dump($e->getMessage() === $cancelExpected);
            var_dump(king_get_last_error() === $cancelExpected);
        }
    } finally {
        king_wait_http3_error_map_cancel_signal($cancelPid);
        king_http3_stop_silent_udp_error_map_server($cancelServer);
    }
} finally {
    king_http3_destroy_fixture($fixture);
}
?>
--EXPECT--
string(17) "King\TlsException"
bool(true)
bool(true)
string(18) "King\QuicException"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(22) "King\ProtocolException"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(21) "King\TimeoutException"
bool(true)
bool(true)
string(21) "King\RuntimeException"
bool(true)
bool(true)
