--TEST--
King HTTP/3 multi request runtime keeps fast streams progressing while a slow sibling stream is still active
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
    echo "skip cargo is required for the HTTP/3 multi peer helper";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';

$fixture = king_http3_create_fixture([], 'king-http3-multi-peer-');
$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

$peer = king_http3_start_multi_peer($fixture['cert'], $fixture['key']);
$capture = null;

try {
    $responses = king_http3_request_with_retry(
        static fn () => king_http3_request_send_multi(
            [
                ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/slow'],
                ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/fast-a'],
                ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/fast-b'],
            ],
            [
                'connection_config' => $config,
                'connect_timeout_ms' => 2000,
                'timeout_ms' => 4000,
            ]
        )
    );
} finally {
    $capture = king_http3_stop_failure_peer($peer);
    king_http3_destroy_fixture($fixture);
}

$slow = json_decode($responses[0]['body'], true, flags: JSON_THROW_ON_ERROR);
$fastA = json_decode($responses[1]['body'], true, flags: JSON_THROW_ON_ERROR);
$fastB = json_decode($responses[2]['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump(count($responses));
var_dump($responses[0]['status']);
var_dump($responses[1]['status']);
var_dump($responses[2]['status']);
var_dump($slow['connectionId'] === $fastA['connectionId']);
var_dump($fastA['connectionId'] === $fastB['connectionId']);
var_dump($slow['finishOrder'] === 3);
var_dump($fastA['finishOrder'] === 1);
var_dump($fastB['finishOrder'] === 2);
var_dump($fastA['activeAtStart'] >= 2);
var_dump($fastB['activeAtStart'] >= 2);
var_dump($slow['maxActiveStreams'] >= 3);
var_dump($capture['exit_code'] === 15 || $capture['exit_code'] === 0);
?>
--EXPECT--
int(3)
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
