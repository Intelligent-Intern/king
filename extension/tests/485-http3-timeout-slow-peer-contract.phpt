--TEST--
King HTTP/3 direct and dispatcher paths expose timeout behavior against real slow-reader and slow-writer peers
--SKIPIF--
<?php
require __DIR__ . '/http3_new_stack_skip.inc';
king_http3_skipif_require_openssl();
king_http3_skipif_require_lsquic_runtime();
king_http3_skipif_require_c_helpers();
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';

$fixture = king_http3_create_fixture(
    [],
    'king-http3-slow-peer-'
);
$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);
$expectedDirect = 'king_http3_request_send() timed out while waiting for the HTTP/3 response.';
$expectedDispatch = 'king_client_send_request() timed out while waiting for the HTTP/3 response.';
$largeBody = str_repeat('slow-reader-body-', 8192);
$captures = [];

try {
    $cases = [
        [
            'mode' => 'slow_response',
            'method' => 'GET',
            'path' => '/slow-response',
            'body' => null,
        ],
        [
            'mode' => 'slow_reader',
            'method' => 'POST',
            'path' => '/slow-reader',
            'body' => $largeBody,
        ],
    ];

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
                $case['method'],
                null,
                $case['body'],
                [
                    'connection_config' => $config,
                    'connect_timeout_ms' => 2000,
                    'timeout_ms' => 300,
                ]
            ),
            'King\\TimeoutException',
            $expectedDirect
        );
        $captures[] = $direct['capture']['exit_code'] === 15 || $direct['capture']['exit_code'] === 0;
        $e = $direct['exception'];
        var_dump(get_class($e));
        var_dump($e->getMessage() === $expectedDirect);
        var_dump(king_get_last_error() === $expectedDirect);

        $dispatch = king_http3_one_shot_exception_with_retry(
            static fn () => king_http3_start_failure_peer(
                $case['mode'],
                $fixture['cert'],
                $fixture['key']
            ),
            'king_http3_stop_failure_peer',
            static fn (array $peer) => king_client_send_request(
                'https://' . $peer['host'] . ':' . $peer['port'] . $case['path'],
                $case['method'],
                null,
                $case['body'],
                [
                    'preferred_protocol' => 'http3',
                    'connection_config' => $config,
                    'connect_timeout_ms' => 2000,
                    'timeout_ms' => 300,
                ]
            ),
            'King\\TimeoutException',
            $expectedDispatch
        );
        $captures[] = $dispatch['capture']['exit_code'] === 15 || $dispatch['capture']['exit_code'] === 0;
        $e = $dispatch['exception'];
        var_dump(get_class($e));
        var_dump($e->getMessage() === $expectedDispatch);
        var_dump(king_get_last_error() === $expectedDispatch);
    }
} finally {
    king_http3_destroy_fixture($fixture);
}

var_dump($captures);
?>
--EXPECT--
string(21) "King\TimeoutException"
bool(true)
bool(true)
string(21) "King\TimeoutException"
bool(true)
bool(true)
string(21) "King\TimeoutException"
bool(true)
bool(true)
string(21) "King\TimeoutException"
bool(true)
bool(true)
array(4) {
  [0]=>
  bool(true)
  [1]=>
  bool(true)
  [2]=>
  bool(true)
  [3]=>
  bool(true)
}
