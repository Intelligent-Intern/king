--TEST--
King HTTP/3 runtime survives repeated mixed-load bursts and keeps later healthy sessions clean
--SKIPIF--
<?php
require __DIR__ . '/http3_new_stack_skip.inc';
king_http3_skipif_require_lsquic_runtime();
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
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

$fixture = king_http3_create_fixture([
    'stable-direct.txt' => "stable-direct-http3\n",
    'stable-dispatch.txt' => "stable-dispatch-http3\n",
], 'king-http3-soak-fixture-');
$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

$roundCount = 6;
$roundOk = [];

try {
    for ($round = 0; $round < $roundCount; $round++) {
        $peer = king_http3_start_multi_peer(
            $fixture['cert'],
            $fixture['key'],
            expectedRequests: 7
        );
        $capture = null;

        try {
            $responses = king_http3_request_with_retry(
                static fn () => king_http3_request_send_multi(
                    [
                        ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/900/round-' . $round . '-slow-root'],
                        ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/30/round-' . $round . '-wave-1-fast-a'],
                        ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/50/round-' . $round . '-wave-1-fast-b'],
                        ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/220/round-' . $round . '-wave-2-fast-a'],
                        ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/240/round-' . $round . '-wave-2-fast-b'],
                        ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/420/round-' . $round . '-wave-3-fast-a'],
                        ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/440/round-' . $round . '-wave-3-fast-b'],
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
        }

        $slow = json_decode($responses[0]['body'], true, flags: JSON_THROW_ON_ERROR);
        $wave1A = json_decode($responses[1]['body'], true, flags: JSON_THROW_ON_ERROR);
        $wave1B = json_decode($responses[2]['body'], true, flags: JSON_THROW_ON_ERROR);
        $wave2A = json_decode($responses[3]['body'], true, flags: JSON_THROW_ON_ERROR);
        $wave2B = json_decode($responses[4]['body'], true, flags: JSON_THROW_ON_ERROR);
        $wave3A = json_decode($responses[5]['body'], true, flags: JSON_THROW_ON_ERROR);
        $wave3B = json_decode($responses[6]['body'], true, flags: JSON_THROW_ON_ERROR);

        $roundOk[] =
            count($responses) === 7
            && $responses[0]['status'] === 200
            && $responses[1]['status'] === 200
            && $responses[2]['status'] === 200
            && $responses[3]['status'] === 200
            && $responses[4]['status'] === 200
            && $responses[5]['status'] === 200
            && $responses[6]['status'] === 200
            && $slow['connectionId'] === $wave1A['connectionId']
            && $wave1A['connectionId'] === $wave1B['connectionId']
            && $wave1B['connectionId'] === $wave2A['connectionId']
            && $wave2A['connectionId'] === $wave2B['connectionId']
            && $wave2B['connectionId'] === $wave3A['connectionId']
            && $wave3A['connectionId'] === $wave3B['connectionId']
            && $wave1A['finishOrder'] === 1
            && $wave1B['finishOrder'] === 2
            && $wave2A['finishOrder'] === 3
            && $wave2B['finishOrder'] === 4
            && $wave3A['finishOrder'] === 5
            && $wave3B['finishOrder'] === 6
            && $slow['finishOrder'] === 7
            && $wave3B['finishOrder'] < $slow['finishOrder']
            && $slow['maxActiveStreams'] >= 7
            && ($capture['exit_code'] === 15 || $capture['exit_code'] === 0);
    }

    $healthyServer = king_http3_start_test_server($fixture['cert'], $fixture['key'], $fixture['root']);
    try {
        $direct = king_http3_request_with_retry(
            static fn () => king_http3_request_send(
                king_http3_test_server_url($healthyServer, '/stable-direct.txt'),
                'GET',
                null,
                null,
                [
                    'connection_config' => $config,
                    'connect_timeout_ms' => 5000,
                    'timeout_ms' => 15000,
                ]
            )
        );

        $dispatch = king_http3_request_with_retry(
            static fn () => king_client_send_request(
                king_http3_test_server_url($healthyServer, '/stable-dispatch.txt'),
                'GET',
                null,
                null,
                [
                    'preferred_protocol' => 'http3',
                    'connection_config' => $config,
                    'connect_timeout_ms' => 5000,
                    'timeout_ms' => 15000,
                ]
            )
        );
    } finally {
        king_http3_stop_test_server($healthyServer);
    }
} finally {
    king_http3_destroy_fixture($fixture);
}

var_dump(count($roundOk));
foreach ($roundOk as $ok) {
    var_dump($ok);
}
var_dump($direct['status']);
var_dump($direct['body']);
var_dump($direct['transport_backend']);
var_dump($dispatch['status']);
var_dump($dispatch['body']);
var_dump($dispatch['transport_backend']);
?>
--EXPECT--
int(6)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(200)
string(20) "stable-direct-http3
"
string(9) "lsquic_h3"
int(200)
string(22) "stable-dispatch-http3
"
string(9) "lsquic_h3"
