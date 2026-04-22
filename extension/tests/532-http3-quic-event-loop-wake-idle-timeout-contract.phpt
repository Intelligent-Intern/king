--TEST--
King HTTP/3 event loop wakes on delayed peer progress, idles between bursts, and times out on sustained silence
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

$fixture = king_http3_create_fixture([], 'king-http3-event-loop-fixture-');
$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

$wakePeer = king_http3_start_multi_peer(
    $fixture['cert'],
    $fixture['key'],
    expectedRequests: 4
);
$wakeCapture = null;
$wakeElapsedMs = 0.0;

try {
    $wakeStartedAt = microtime(true);
    $wakeResponses = king_http3_request_with_retry(
        static fn () => king_http3_request_send_multi(
            [
                ['url' => 'https://' . $wakePeer['host'] . ':' . $wakePeer['port'] . '/delay/120/wake-a'],
                ['url' => 'https://' . $wakePeer['host'] . ':' . $wakePeer['port'] . '/delay/260/wake-b'],
                ['url' => 'https://' . $wakePeer['host'] . ':' . $wakePeer['port'] . '/delay/420/wake-c'],
                ['url' => 'https://' . $wakePeer['host'] . ':' . $wakePeer['port'] . '/delay/580/wake-d'],
            ],
            [
                'connection_config' => $config,
                'connect_timeout_ms' => 2000,
                'timeout_ms' => 2000,
            ]
        )
    );
    $wakeElapsedMs = (microtime(true) - $wakeStartedAt) * 1000;
} finally {
    $wakeCapture = king_http3_stop_failure_peer($wakePeer);
}

$wakeA = json_decode($wakeResponses[0]['body'], true, flags: JSON_THROW_ON_ERROR);
$wakeB = json_decode($wakeResponses[1]['body'], true, flags: JSON_THROW_ON_ERROR);
$wakeC = json_decode($wakeResponses[2]['body'], true, flags: JSON_THROW_ON_ERROR);
$wakeD = json_decode($wakeResponses[3]['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump(count($wakeResponses) === 4);
var_dump($wakeResponses[0]['status'] === 200);
var_dump($wakeResponses[1]['status'] === 200);
var_dump($wakeResponses[2]['status'] === 200);
var_dump($wakeResponses[3]['status'] === 200);
var_dump($wakeResponses[0]['transport_backend'] === 'quiche_h3');
var_dump($wakeResponses[1]['transport_backend'] === 'quiche_h3');
var_dump($wakeResponses[2]['transport_backend'] === 'quiche_h3');
var_dump($wakeResponses[3]['transport_backend'] === 'quiche_h3');
var_dump($wakeA['connectionId'] === $wakeB['connectionId']);
var_dump($wakeB['connectionId'] === $wakeC['connectionId']);
var_dump($wakeC['connectionId'] === $wakeD['connectionId']);
var_dump($wakeA['finishOrder'] === 1);
var_dump($wakeB['finishOrder'] === 2);
var_dump($wakeC['finishOrder'] === 3);
var_dump($wakeD['finishOrder'] === 4);
var_dump($wakeD['maxActiveStreams'] >= 4);
var_dump($wakeElapsedMs >= 450);
var_dump($wakeElapsedMs < 1500);
var_dump($wakeCapture['exit_code'] === 15 || $wakeCapture['exit_code'] === 0);

$expectedDirectTimeout = 'king_http3_request_send() timed out while waiting for the HTTP/3 response.';
$expectedDispatchTimeout = 'king_client_send_request() timed out while waiting for the HTTP/3 response.';
$directTimeoutElapsedMs = 0.0;
$dispatchTimeoutElapsedMs = 0.0;

try {
    $direct = king_http3_one_shot_exception_with_retry(
        static fn () => king_http3_start_failure_peer(
            'slow_response',
            $fixture['cert'],
            $fixture['key']
        ),
        'king_http3_stop_failure_peer',
        static function (array $peer) use ($config, &$directTimeoutElapsedMs) {
            $startedAt = microtime(true);

            try {
                return king_http3_request_send(
                    'https://' . $peer['host'] . ':' . $peer['port'] . '/slow-response',
                    'GET',
                    null,
                    null,
                    [
                        'connection_config' => $config,
                        'connect_timeout_ms' => 2000,
                        'timeout_ms' => 300,
                    ]
                );
            } finally {
                $directTimeoutElapsedMs = (microtime(true) - $startedAt) * 1000;
            }
        },
        'King\\TimeoutException',
        $expectedDirectTimeout
    );
    $e = $direct['exception'];
    var_dump(get_class($e));
    var_dump($e->getMessage() === $expectedDirectTimeout);
    var_dump(king_get_last_error() === $expectedDirectTimeout);
    var_dump($directTimeoutElapsedMs >= 250);
    var_dump($directTimeoutElapsedMs < 1500);
    var_dump($direct['capture']['exit_code'] === 15 || $direct['capture']['exit_code'] === 0);

    $dispatch = king_http3_one_shot_exception_with_retry(
        static fn () => king_http3_start_failure_peer(
            'slow_response',
            $fixture['cert'],
            $fixture['key']
        ),
        'king_http3_stop_failure_peer',
        static function (array $peer) use ($config, &$dispatchTimeoutElapsedMs) {
            $startedAt = microtime(true);

            try {
                return king_client_send_request(
                    'https://' . $peer['host'] . ':' . $peer['port'] . '/slow-response',
                    'GET',
                    null,
                    null,
                    [
                        'preferred_protocol' => 'http3',
                        'connection_config' => $config,
                        'connect_timeout_ms' => 2000,
                        'timeout_ms' => 300,
                    ]
                );
            } finally {
                $dispatchTimeoutElapsedMs = (microtime(true) - $startedAt) * 1000;
            }
        },
        'King\\TimeoutException',
        $expectedDispatchTimeout
    );
    $e = $dispatch['exception'];
    var_dump(get_class($e));
    var_dump($e->getMessage() === $expectedDispatchTimeout);
    var_dump(king_get_last_error() === $expectedDispatchTimeout);
    var_dump($dispatchTimeoutElapsedMs >= 250);
    var_dump($dispatchTimeoutElapsedMs < 1500);
    var_dump($dispatch['capture']['exit_code'] === 15 || $dispatch['capture']['exit_code'] === 0);
} finally {
    king_http3_destroy_fixture($fixture);
}
?>
--EXPECT--
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(21) "King\TimeoutException"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(21) "King\TimeoutException"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
