--TEST--
King HTTP/3 direct dispatcher and multi-request paths recover from injected packet loss with visible retransmit stats
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
    echo "skip cargo is required to build the HTTP/3 ticket test server";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';

$fixture = king_http3_create_fixture([
    'direct-loss.txt' => "direct-loss\n",
    'dispatch-loss.txt' => "dispatch-loss\n",
    'follow-one.txt' => "follow-one\n",
    'follow-two.txt' => "follow-two\n",
], 'king-http3-loss-fixture-');

$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

try {
    $direct = king_http3_one_shot_result_with_retry(
        static function () use ($fixture) {
            return king_http3_start_ticket_test_server(
                $fixture['cert'],
                $fixture['key'],
                $fixture['root'],
                'localhost',
                null,
                null,
                false,
                true
            );
        },
        'king_http3_stop_ticket_test_server',
        static function (array $server) use ($config) {
            return king_http3_request_send(
                king_http3_test_server_url($server, '/direct-loss.txt'),
                'GET',
                null,
                null,
                [
                    'connection_config' => $config,
                    'connect_timeout_ms' => 10000,
                    'timeout_ms' => 10000,
                ]
            );
        },
        static fn (array $response) => $response['status'] === 200
            && $response['body'] === "direct-loss\n"
            && ($response['quic_packets_lost'] ?? 0) > 0
            && ($response['quic_packets_retransmitted'] ?? 0) > 0
            && ($response['quic_lost_bytes'] ?? 0) > 0
    )['result'];

    $dispatch = king_http3_one_shot_result_with_retry(
        static function () use ($fixture) {
            return king_http3_start_ticket_test_server(
                $fixture['cert'],
                $fixture['key'],
                $fixture['root'],
                'localhost',
                null,
                null,
                false,
                true
            );
        },
        'king_http3_stop_ticket_test_server',
        static function (array $server) use ($config) {
            return king_client_send_request(
                king_http3_test_server_url($server, '/dispatch-loss.txt'),
                'GET',
                null,
                null,
                [
                    'preferred_protocol' => 'http3',
                    'connection_config' => $config,
                    'connect_timeout_ms' => 10000,
                    'timeout_ms' => 10000,
                ]
            );
        },
        static fn (array $response) => $response['status'] === 200
            && $response['body'] === "dispatch-loss\n"
            && ($response['quic_packets_lost'] ?? 0) > 0
            && ($response['quic_packets_retransmitted'] ?? 0) > 0
            && ($response['quic_lost_bytes'] ?? 0) > 0
    )['result'];

    $multi = king_http3_one_shot_result_with_retry(
        static function () use ($fixture) {
            return king_http3_start_ticket_test_server(
                $fixture['cert'],
                $fixture['key'],
                $fixture['root'],
                'localhost',
                null,
                null,
                false,
                true
            );
        },
        'king_http3_stop_ticket_test_server',
        static function (array $server) use ($config) {
            return king_http3_request_send_multi(
                [
                    ['url' => king_http3_test_server_url($server, '/follow-one.txt')],
                    ['url' => king_http3_test_server_url($server, '/follow-two.txt')],
                ],
                [
                    'connection_config' => $config,
                    'connect_timeout_ms' => 10000,
                    'timeout_ms' => 10000,
                ]
            );
        },
        static fn (array $responses) => count($responses) === 2
            && $responses[0]['status'] === 200
            && $responses[1]['status'] === 200
            && $responses[0]['body'] === "follow-one\n"
            && $responses[1]['body'] === "follow-two\n"
            && ($responses[0]['quic_packets_lost'] ?? 0) > 0
            && ($responses[0]['quic_packets_retransmitted'] ?? 0) > 0
            && ($responses[1]['quic_packets_lost'] ?? 0) > 0
            && ($responses[1]['quic_packets_retransmitted'] ?? 0) > 0
    )['result'];
} finally {
    king_http3_destroy_fixture($fixture);
}

var_dump($direct['status']);
var_dump($direct['body']);
var_dump($direct['quic_packets_lost'] > 0);
var_dump($direct['quic_packets_retransmitted'] > 0);
var_dump($direct['quic_lost_bytes'] > 0);

var_dump($dispatch['status']);
var_dump($dispatch['body']);
var_dump($dispatch['quic_packets_lost'] > 0);
var_dump($dispatch['quic_packets_retransmitted'] > 0);
var_dump($dispatch['quic_lost_bytes'] > 0);

var_dump(count($multi));
var_dump($multi[0]['status']);
var_dump($multi[1]['status']);
var_dump($multi[0]['body']);
var_dump($multi[1]['body']);
var_dump($multi[0]['quic_packets_lost'] > 0);
var_dump($multi[0]['quic_packets_retransmitted'] > 0);
var_dump($multi[1]['quic_packets_lost'] > 0);
var_dump($multi[1]['quic_packets_retransmitted'] > 0);
?>
--EXPECT--
int(200)
string(12) "direct-loss
"
bool(true)
bool(true)
bool(true)
int(200)
string(14) "dispatch-loss
"
bool(true)
bool(true)
bool(true)
int(2)
int(200)
int(200)
string(11) "follow-one
"
string(11) "follow-two
"
bool(true)
bool(true)
bool(true)
bool(true)
