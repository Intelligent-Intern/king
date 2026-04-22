--TEST--
King HTTP/3 direct and dispatcher paths expose the active QUIC/TLS handshake-failure contract
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
}

$server = getenv('KING_LSQUIC_SERVER');
if (!is_string($server) || $server === '' || !is_executable($server)) {
    echo "skip KING_LSQUIC_SERVER must point at a prebuilt lsquic server binary";
}

$library = getenv('KING_LSQUIC_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_LSQUIC_LIBRARY must point at a prebuilt liblsquic runtime";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';

$fixture = king_http3_create_fixture(
    [
        'x.txt' => "handshake-failure\n",
    ],
    'king-http3-handshake-failure-'
);
$server = king_http3_start_test_server($fixture['cert'], $fixture['key'], $fixture['root']);
$url = king_http3_test_server_url($server, '/x.txt');
$expectedDirect = 'king_http3_request_send() failed during the QUIC/TLS handshake (-10).';
$expectedDispatch = 'king_client_send_request() failed during the QUIC/TLS handshake (-10).';

try {
    try {
        $e = king_http3_exception_with_retry(
            static fn () => king_http3_request_send(
                $url,
                'GET',
                null,
                null,
                [
                    'connect_timeout_ms' => 2000,
                    'timeout_ms' => 4000,
                ]
            ),
            'King\\TlsException',
            $expectedDirect
        );
        var_dump(get_class($e));
        var_dump($e->getMessage() === $expectedDirect);
        var_dump(king_get_last_error() === $expectedDirect);
    } catch (Throwable $e) {
        echo "no-exception-1\n";
    }

    try {
        $e = king_http3_exception_with_retry(
            static fn () => king_client_send_request(
                $url,
                'GET',
                null,
                null,
                [
                    'preferred_protocol' => 'http3',
                    'connect_timeout_ms' => 2000,
                    'timeout_ms' => 4000,
                ]
            ),
            'King\\TlsException',
            $expectedDispatch
        );
        var_dump(get_class($e));
        var_dump($e->getMessage() === $expectedDispatch);
        var_dump(king_get_last_error() === $expectedDispatch);
    } catch (Throwable $e) {
        echo "no-exception-2\n";
    }
} finally {
    king_http3_stop_test_server($server);
    king_http3_destroy_fixture($fixture);
}
?>
--EXPECT--
string(17) "King\TlsException"
bool(true)
bool(true)
string(17) "King\TlsException"
bool(true)
bool(true)
