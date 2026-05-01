--TEST--
King HTTP/3 multi request runtime stays fair across sustained staggered load on one QUIC session
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

$fixture = king_http3_create_fixture([], 'king-http3-sustained-fairness-');
$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

$peer = king_http3_start_multi_peer($fixture['cert'], $fixture['key'], expectedRequests: 7);
$capture = null;

try {
    $responses = king_http3_request_with_retry(
        static fn () => king_http3_request_send_multi(
            [
                ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/900/slow-root'],
                ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/30/wave-1-fast-a'],
                ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/50/wave-1-fast-b'],
                ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/220/wave-2-fast-a'],
                ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/240/wave-2-fast-b'],
                ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/420/wave-3-fast-a'],
                ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/440/wave-3-fast-b'],
            ],
            [
                'connection_config' => $config,
                'connect_timeout_ms' => 2000,
                'timeout_ms' => 5000,
            ]
        )
    );
} finally {
    $capture = king_http3_stop_failure_peer($peer);
    king_http3_destroy_fixture($fixture);
}

$slow = json_decode($responses[0]['body'], true, flags: JSON_THROW_ON_ERROR);
$wave1A = json_decode($responses[1]['body'], true, flags: JSON_THROW_ON_ERROR);
$wave1B = json_decode($responses[2]['body'], true, flags: JSON_THROW_ON_ERROR);
$wave2A = json_decode($responses[3]['body'], true, flags: JSON_THROW_ON_ERROR);
$wave2B = json_decode($responses[4]['body'], true, flags: JSON_THROW_ON_ERROR);
$wave3A = json_decode($responses[5]['body'], true, flags: JSON_THROW_ON_ERROR);
$wave3B = json_decode($responses[6]['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump(count($responses));
var_dump($responses[0]['status']);
var_dump($responses[1]['status']);
var_dump($responses[2]['status']);
var_dump($responses[3]['status']);
var_dump($responses[4]['status']);
var_dump($responses[5]['status']);
var_dump($responses[6]['status']);
var_dump($slow['connectionId'] === $wave1A['connectionId']);
var_dump($wave1A['connectionId'] === $wave1B['connectionId']);
var_dump($wave1B['connectionId'] === $wave2A['connectionId']);
var_dump($wave2A['connectionId'] === $wave2B['connectionId']);
var_dump($wave2B['connectionId'] === $wave3A['connectionId']);
var_dump($wave3A['connectionId'] === $wave3B['connectionId']);
var_dump($wave1A['finishOrder'] === 1);
var_dump($wave1B['finishOrder'] === 2);
var_dump($wave2A['finishOrder'] === 3);
var_dump($wave2B['finishOrder'] === 4);
var_dump($wave3A['finishOrder'] === 5);
var_dump($wave3B['finishOrder'] === 6);
var_dump($slow['finishOrder'] === 7);
var_dump($wave3A['finishOrder'] < $slow['finishOrder']);
var_dump($wave3B['finishOrder'] < $slow['finishOrder']);
var_dump($slow['maxActiveStreams'] >= 7);
var_dump($capture['exit_code'] === 15 || $capture['exit_code'] === 0);
?>
--EXPECT--
int(7)
int(200)
int(200)
int(200)
int(200)
int(200)
int(200)
int(200)
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
