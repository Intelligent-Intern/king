--TEST--
King HTTP/3 direct and dispatcher paths expose the active QUIC transport-close contract
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
}

$library = getenv('KING_LSQUIC_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_LSQUIC_LIBRARY must point at a prebuilt liblsquic runtime";
}

if (trim((string) shell_exec('command -v cargo')) === '') {
    echo "skip cargo is required for the HTTP/3 failure-peer helper";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';

$fixture = king_http3_create_fixture(
    [
        'x.txt' => "transport-close\n",
    ],
    'king-http3-transport-close-'
);
$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);
$expectedDirect = 'king_http3_request_send() received a QUIC transport close before the HTTP/3 response completed (code 4919, reason "test transport abort").';
$expectedDispatch = 'king_client_send_request() received a QUIC transport close before the HTTP/3 response completed (code 4919, reason "test transport abort").';
$direct = null;

try {
    try {
        $direct = king_http3_one_shot_exception_with_retry(
            static fn () => king_http3_start_failure_peer('transport_close', $fixture['cert'], $fixture['key']),
            'king_http3_stop_failure_peer',
            static fn (array $peer) => king_http3_request_send(
                'https://' . $peer['host'] . ':' . $peer['port'] . '/x.txt',
                'GET',
                null,
                null,
                [
                    'connection_config' => $config,
                    'connect_timeout_ms' => 2000,
                    'timeout_ms' => 4000,
                ]
            ),
            'King\\QuicException',
            $expectedDirect
        );
        $e = $direct['exception'];
        var_dump(get_class($e));
        var_dump($e->getMessage() === $expectedDirect);
        var_dump(king_get_last_error() === $expectedDirect);
    } catch (Throwable $e) {
        echo "no-exception-1\n";
    }

    if (is_array($direct)) {
        $peerCapture = $direct['capture'];
        var_dump($peerCapture['exit_code'] === 15 || $peerCapture['exit_code'] === 0);
    } else {
        echo "no-peer-capture\n";
    }

    try {
        $dispatch = king_http3_one_shot_exception_with_retry(
            static fn () => king_http3_start_failure_peer('transport_close', $fixture['cert'], $fixture['key']),
            'king_http3_stop_failure_peer',
            static fn (array $peer) => king_client_send_request(
                'https://' . $peer['host'] . ':' . $peer['port'] . '/x.txt',
                'GET',
                null,
                null,
                [
                    'preferred_protocol' => 'http3',
                    'connection_config' => $config,
                    'connect_timeout_ms' => 2000,
                    'timeout_ms' => 4000,
                ]
            ),
            'King\\QuicException',
            $expectedDispatch
        );
        $e = $dispatch['exception'];
        var_dump(get_class($e));
        var_dump($e->getMessage() === $expectedDispatch);
        var_dump(king_get_last_error() === $expectedDispatch);
    } catch (Throwable $e) {
        echo "no-exception-2\n";
    }
} finally {
    king_http3_destroy_fixture($fixture);
}
?>
--EXPECT--
string(18) "King\QuicException"
bool(true)
bool(true)
bool(true)
string(18) "King\QuicException"
bool(true)
bool(true)
