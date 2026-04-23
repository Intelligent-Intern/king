--TEST--
King HTTP/3 direct and dispatcher paths expose QUIC idle-timeout and application-close propagation against real peers
--SKIPIF--
<?php
require __DIR__ . '/http3_new_stack_skip.inc';
king_http3_skipif_require_lsquic_runtime();
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
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

$fixture = king_http3_create_fixture([], 'king-http3-quic-close-');
$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

$assertCloseCapture = static function (array $capture, array $expect): void {
    var_dump($capture['mode'] === $expect['mode']);
    var_dump($capture['saw_initial'] === true);
    var_dump($capture['saw_established'] === true);
    var_dump($capture['saw_h3_open'] === true);
    var_dump($capture['saw_request_headers'] === true);
    var_dump($capture['close_trigger'] === $expect['close_trigger']);
    var_dump($capture['is_timed_out'] === $expect['is_timed_out']);
    var_dump($capture['peer_error_present'] === false);
    var_dump($capture['local_error_present'] === $expect['local_error_present']);

    if ($expect['local_error_present']) {
        var_dump($capture['local_error_is_app'] === true);
        var_dump($capture['local_error_code'] === $expect['local_error_code']);
        var_dump($capture['local_error_reason'] === $expect['local_error_reason']);
    }
};

$cases = [
    [
        'mode' => 'idle_timeout',
        'path' => '/idle-timeout',
        'exception_class' => 'King\\QuicException',
        'expected_direct' => 'king_http3_request_send() observed the active QUIC connection hit the negotiated idle timeout before the HTTP/3 response completed.',
        'expected_dispatch' => 'king_client_send_request() observed the active QUIC connection hit the negotiated idle timeout before the HTTP/3 response completed.',
        'close_trigger' => 'idle_timeout',
        'is_timed_out' => true,
        'local_error_present' => false,
        'local_error_code' => 0,
        'local_error_reason' => '',
    ],
    [
        'mode' => 'application_close',
        'path' => '/application-close',
        'exception_class' => 'King\\ProtocolException',
        'expected_direct' => 'king_http3_request_send() received a protocol close before the HTTP/3 response completed (code 4660, reason "test application abort").',
        'expected_dispatch' => 'king_client_send_request() received a protocol close before the HTTP/3 response completed (code 4660, reason "test application abort").',
        'close_trigger' => 'application_close',
        'is_timed_out' => false,
        'local_error_present' => true,
        'local_error_code' => 4660,
        'local_error_reason' => 'test application abort',
    ],
];

try {
    foreach ($cases as $case) {
        $direct = king_http3_one_shot_exception_with_retry(
            static fn () => king_http3_start_failure_peer(
                $case['mode'],
                $fixture['cert'],
                $fixture['key']
            ),
            'king_http3_stop_failure_peer',
            static fn (array $peer) => king_http3_request_send(
                'https://' . $peer['host'] . ':' . $peer['port'] . $case['path'],
                'GET',
                null,
                null,
                [
                    'connection_config' => $config,
                    'connect_timeout_ms' => 2000,
                    'timeout_ms' => 4000,
                ]
            ),
            $case['exception_class'],
            $case['expected_direct']
        );
        $e = $direct['exception'];
        $capture = king_http3_failure_peer_close_capture($direct['capture']);
        var_dump(get_class($e));
        var_dump($e->getMessage() === $case['expected_direct']);
        var_dump(king_get_last_error() === $case['expected_direct']);
        $assertCloseCapture($capture, $case);

        $dispatch = king_http3_one_shot_exception_with_retry(
            static fn () => king_http3_start_failure_peer(
                $case['mode'],
                $fixture['cert'],
                $fixture['key']
            ),
            'king_http3_stop_failure_peer',
            static fn (array $peer) => king_client_send_request(
                'https://' . $peer['host'] . ':' . $peer['port'] . $case['path'],
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
            $case['exception_class'],
            $case['expected_dispatch']
        );
        $e = $dispatch['exception'];
        $capture = king_http3_failure_peer_close_capture($dispatch['capture']);
        var_dump(get_class($e));
        var_dump($e->getMessage() === $case['expected_dispatch']);
        var_dump(king_get_last_error() === $case['expected_dispatch']);
        $assertCloseCapture($capture, $case);
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
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
bool(true)
bool(true)
