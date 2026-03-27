--TEST--
King HTTP/3 direct and dispatcher paths expose the active QUIC transport-close contract
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
}

$library = getenv('KING_QUICHE_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_QUICHE_LIBRARY must point at a prebuilt libquiche runtime";
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
$peer = king_http3_start_failure_peer('transport_close', $fixture['cert'], $fixture['key']);
$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);
$url = 'https://' . $peer['host'] . ':' . $peer['port'] . '/x.txt';
$expectedDirect = 'king_http3_request_send() received a QUIC transport close before the HTTP/3 response completed (code 4919, reason "test transport abort").';
$expectedDispatch = 'king_client_send_request() received a QUIC transport close before the HTTP/3 response completed (code 4919, reason "test transport abort").';

try {
    try {
        king_http3_request_send(
            $url,
            'GET',
            null,
            null,
            [
                'connection_config' => $config,
                'connect_timeout_ms' => 2000,
                'timeout_ms' => 4000,
            ]
        );
        echo "no-exception-1\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage() === $expectedDirect);
        var_dump(king_get_last_error() === $expectedDirect);
    }

    $peerCapture = king_http3_stop_failure_peer($peer);
    $peer = king_http3_start_failure_peer('transport_close', $fixture['cert'], $fixture['key']);
    $url = 'https://' . $peer['host'] . ':' . $peer['port'] . '/x.txt';
    var_dump($peerCapture['exit_code'] === 15 || $peerCapture['exit_code'] === 0);

    try {
        king_client_send_request(
            $url,
            'GET',
            null,
            null,
            [
                'preferred_protocol' => 'http3',
                'connection_config' => $config,
                'connect_timeout_ms' => 2000,
                'timeout_ms' => 4000,
            ]
        );
        echo "no-exception-2\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage() === $expectedDispatch);
        var_dump(king_get_last_error() === $expectedDispatch);
    }
} finally {
    king_http3_stop_failure_peer($peer);
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
