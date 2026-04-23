--TEST--
King HTTP/3 keeps supported QUIC congestion-control algorithms live under sustained constrained links
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
}

if (trim((string) shell_exec('command -v cargo')) === '') {
    echo "skip cargo is required for the HTTP/3 ticket test server";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';

$fixture = king_http3_create_fixture([], 'king-http3-cc-fixture-');
$payload = str_repeat('congestion-window-payload-', 4096);
$expectedBody = 'congestion-ack:' . strlen($payload);

$cases = [
    [
        'label' => 'direct-cubic',
        'algorithm' => 'cubic',
        'attempt' => static function (array $server, $config) use ($payload) {
            return king_http3_request_send(
                king_http3_test_server_url($server, '/congestion-control'),
                'POST',
                ['content-type' => 'text/plain'],
                $payload,
                [
                    'connection_config' => $config,
                    'connect_timeout_ms' => 10000,
                    'timeout_ms' => 20000,
                ]
            );
        },
    ],
    [
        'label' => 'dispatch-cubic',
        'algorithm' => 'cubic',
        'attempt' => static function (array $server, $config) use ($payload) {
            return king_client_send_request(
                king_http3_test_server_url($server, '/congestion-control'),
                'POST',
                ['content-type' => 'text/plain'],
                $payload,
                [
                    'preferred_protocol' => 'http3',
                    'connection_config' => $config,
                    'connect_timeout_ms' => 10000,
                    'timeout_ms' => 20000,
                ]
            );
        },
    ],
    [
        'label' => 'direct-bbr',
        'algorithm' => 'bbr',
        'attempt' => static function (array $server, $config) use ($payload) {
            return king_http3_request_send(
                king_http3_test_server_url($server, '/congestion-control'),
                'POST',
                ['content-type' => 'text/plain'],
                $payload,
                [
                    'connection_config' => $config,
                    'connect_timeout_ms' => 10000,
                    'timeout_ms' => 20000,
                ]
            );
        },
    ],
    [
        'label' => 'dispatch-bbr',
        'algorithm' => 'bbr',
        'attempt' => static function (array $server, $config) use ($payload) {
            return king_client_send_request(
                king_http3_test_server_url($server, '/congestion-control'),
                'POST',
                ['content-type' => 'text/plain'],
                $payload,
                [
                    'preferred_protocol' => 'http3',
                    'connection_config' => $config,
                    'connect_timeout_ms' => 10000,
                    'timeout_ms' => 20000,
                ]
            );
        },
    ],
];

try {
    foreach ($cases as $case) {
        $config = king_new_config([
            'tls_default_ca_file' => $fixture['cert'],
            'quic.cc_algorithm' => $case['algorithm'],
        ]);

        $result = king_http3_one_shot_result_with_retry(
            static function () use ($fixture) {
                return king_http3_start_ticket_test_server(
                    $fixture['cert'],
                    $fixture['key'],
                    $fixture['root'],
                    'localhost',
                    null,
                    null,
                    false,
                    4
                );
            },
            'king_http3_stop_ticket_test_server',
            static fn (array $server) => $case['attempt']($server, $config),
            static fn (array $response) => $response['status'] === 200
                && $response['body'] === $expectedBody
                && ($response['quic_packets_lost'] ?? 0) > 0
                && ($response['quic_packets_retransmitted'] ?? 0) > 0
                && ($response['quic_lost_bytes'] ?? 0) > 0
                && ($response['quic_stream_retransmitted_bytes'] ?? 0) > 0
        );

        $response = $result['result'];
        $capture = king_http3_ticket_server_lifecycle($result['capture']);
        var_dump($case['label']);
        var_dump($response['status']);
        var_dump($response['body'] === $expectedBody);
        var_dump($response['transport_backend'] === 'quiche_h3');
        var_dump($response['quic_packets_lost'] > 0);
        var_dump($response['quic_packets_retransmitted'] > 0);
        var_dump($response['quic_lost_bytes'] > 0);
        var_dump($response['quic_stream_retransmitted_bytes'] > 0);
        var_dump($capture['saw_initial'] === true);
        var_dump($capture['saw_established'] === true);
        var_dump($capture['saw_h3_open'] === true);
        var_dump($capture['saw_request_headers'] === true);
        var_dump($capture['saw_request_body'] === true);
        var_dump($capture['request_body_bytes'] === strlen($payload));
        var_dump($capture['saw_request_finished'] === true);
        var_dump($capture['request_body_drained_before_response'] === true);
        var_dump($capture['response_on_request_stream'] === true);
        var_dump($capture['saw_response_headers'] === true);
        var_dump($capture['saw_response_drain'] === true);
        var_dump(
            in_array(
                $capture['close_source'],
                ['peer_draining_close', 'server_idle_close', 'peer_closed'],
                true
            )
        );
    }
} finally {
    king_http3_destroy_fixture($fixture);
}
?>
--EXPECT--
string(12) "direct-cubic"
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
bool(true)
string(14) "dispatch-cubic"
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
bool(true)
string(10) "direct-bbr"
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
bool(true)
string(12) "dispatch-bbr"
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
bool(true)
