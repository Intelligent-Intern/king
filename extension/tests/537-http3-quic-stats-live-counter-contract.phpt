--TEST--
King HTTP/3 stats fields stay tied to live QUIC counters and peer-observed request state
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

$fixture = king_http3_create_fixture([
    'stats-direct.txt' => "stats-direct\n",
    'stats-dispatch.txt' => "stats-dispatch\n",
    'stats-loss.txt' => "stats-loss\n",
], 'king-http3-stats-fixture-');

$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

$assertClean = static function (string $label, array $response, array $capture, string $expectedBody): void {
    var_dump($label);
    var_dump($response['status'] === 200);
    var_dump($response['body'] === $expectedBody);
    var_dump($response['transport_backend'] === 'quiche_h3');
    var_dump($response['response_complete'] === true);
    var_dump($response['body_bytes'] === strlen($expectedBody));
    var_dump(($response['header_bytes'] ?? 0) > 0);
    var_dump(($response['quic_packets_sent'] ?? 0) > 0);
    var_dump(($response['quic_packets_received'] ?? 0) > 0);
    var_dump(($capture['saw_initial'] ?? false) === true);
    var_dump(($capture['saw_established'] ?? false) === true);
    var_dump(($capture['saw_h3_open'] ?? false) === true);
    var_dump(($capture['saw_request_headers'] ?? false) === true);
    var_dump(($capture['saw_response_headers'] ?? false) === true);
    var_dump(($capture['saw_response_drain'] ?? false) === true);
    var_dump(($capture['peer_packets_received'] ?? 0) >= ($response['quic_packets_sent'] ?? 0));
    var_dump(($capture['peer_packets_sent'] ?? 0) >= ($response['quic_packets_received'] ?? 0));
};

$assertLoss = static function (string $label, array $response, array $capture, string $expectedBody): void {
    var_dump($label);
    var_dump($response['status'] === 200);
    var_dump($response['body'] === $expectedBody);
    var_dump(($response['quic_packets_lost'] ?? 0) > 0);
    var_dump(($response['quic_packets_retransmitted'] ?? 0) > 0);
    var_dump(($response['quic_lost_bytes'] ?? 0) > 0);
    var_dump(($capture['saw_initial'] ?? false) === true);
    var_dump(($capture['saw_established'] ?? false) === true);
    var_dump(($capture['saw_h3_open'] ?? false) === true);
    var_dump(($capture['saw_request_headers'] ?? false) === true);
    var_dump(($capture['saw_response_headers'] ?? false) === true);
    var_dump(($capture['saw_response_drain'] ?? false) === true);
};

$assertConstrained = static function (
    string $label,
    array $response,
    array $capture,
    string $expectedBody,
    string $payload
): void {
    var_dump($label);
    var_dump($response['status'] === 200);
    var_dump($response['body'] === $expectedBody);
    var_dump($response['response_complete'] === true);
    var_dump($response['body_bytes'] === strlen($expectedBody));
    var_dump(($response['header_bytes'] ?? 0) > 0);
    var_dump(($response['quic_packets_sent'] ?? 0) > 0);
    var_dump(($response['quic_packets_received'] ?? 0) > 0);
    var_dump(($response['quic_stream_retransmitted_bytes'] ?? 0) > 0);
    var_dump(($capture['saw_initial'] ?? false) === true);
    var_dump(($capture['saw_established'] ?? false) === true);
    var_dump(($capture['saw_h3_open'] ?? false) === true);
    var_dump(($capture['saw_request_headers'] ?? false) === true);
    var_dump(($capture['saw_request_body'] ?? false) === true);
    var_dump(($capture['request_body_bytes'] ?? -1) === strlen($payload));
    var_dump(($capture['saw_request_finished'] ?? false) === true);
    var_dump(($capture['request_body_drained_before_response'] ?? false) === true);
    var_dump(($capture['response_on_request_stream'] ?? false) === true);
    var_dump(($capture['saw_response_headers'] ?? false) === true);
    var_dump(($capture['saw_response_drain'] ?? false) === true);
};

$payload = str_repeat('quic-stats-payload-', 4096);
$expectedConstrainedBody = 'congestion-ack:' . strlen($payload);

try {
    $directClean = king_http3_one_shot_result_with_retry(
        static fn () => king_http3_start_ticket_test_server(
            $fixture['cert'],
            $fixture['key'],
            $fixture['root']
        ),
        'king_http3_stop_ticket_test_server',
        static fn (array $server) => king_http3_request_send(
            king_http3_test_server_url($server, '/stats-direct.txt'),
            'GET',
            null,
            null,
            ['connection_config' => $config, 'connect_timeout_ms' => 10000, 'timeout_ms' => 10000]
        ),
        static fn (array $response) => $response['status'] === 200
            && $response['body'] === "stats-direct\n"
            && ($response['quic_packets_sent'] ?? 0) > 0
            && ($response['quic_packets_received'] ?? 0) > 0
    );

    $dispatchClean = king_http3_one_shot_result_with_retry(
        static fn () => king_http3_start_ticket_test_server(
            $fixture['cert'],
            $fixture['key'],
            $fixture['root']
        ),
        'king_http3_stop_ticket_test_server',
        static fn (array $server) => king_client_send_request(
            king_http3_test_server_url($server, '/stats-dispatch.txt'),
            'GET',
            null,
            null,
            [
                'preferred_protocol' => 'http3',
                'connection_config' => $config,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 10000,
            ]
        ),
        static fn (array $response) => $response['status'] === 200
            && $response['body'] === "stats-dispatch\n"
            && ($response['quic_packets_sent'] ?? 0) > 0
            && ($response['quic_packets_received'] ?? 0) > 0
    );

    $lossDirect = king_http3_one_shot_result_with_retry(
        static fn () => king_http3_start_ticket_test_server(
            $fixture['cert'],
            $fixture['key'],
            $fixture['root'],
            'localhost',
            null,
            null,
            false,
            1
        ),
        'king_http3_stop_ticket_test_server',
        static fn (array $server) => king_http3_request_send(
            king_http3_test_server_url($server, '/stats-loss.txt'),
            'GET',
            null,
            null,
            ['connection_config' => $config, 'connect_timeout_ms' => 10000, 'timeout_ms' => 10000]
        ),
        static fn (array $response) => $response['status'] === 200
            && $response['body'] === "stats-loss\n"
            && ($response['quic_packets_lost'] ?? 0) > 0
            && ($response['quic_packets_retransmitted'] ?? 0) > 0
            && ($response['quic_lost_bytes'] ?? 0) > 0
    );

    $dispatchConstrained = king_http3_one_shot_result_with_retry(
        static fn () => king_http3_start_ticket_test_server(
            $fixture['cert'],
            $fixture['key'],
            $fixture['root'],
            'localhost',
            null,
            null,
            false,
            4
        ),
        'king_http3_stop_ticket_test_server',
        static fn (array $server) => king_client_send_request(
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
        ),
        static fn (array $response) => $response['status'] === 200
            && $response['body'] === $expectedConstrainedBody
            && ($response['quic_stream_retransmitted_bytes'] ?? 0) > 0
    );

    $assertClean(
        'direct-clean',
        $directClean['result'],
        king_http3_ticket_server_lifecycle($directClean['capture']),
        "stats-direct\n"
    );
    $assertClean(
        'dispatch-clean',
        $dispatchClean['result'],
        king_http3_ticket_server_lifecycle($dispatchClean['capture']),
        "stats-dispatch\n"
    );
    $assertLoss(
        'direct-loss',
        $lossDirect['result'],
        king_http3_ticket_server_lifecycle($lossDirect['capture']),
        "stats-loss\n"
    );
    $assertConstrained(
        'dispatch-constrained',
        $dispatchConstrained['result'],
        king_http3_ticket_server_lifecycle($dispatchConstrained['capture']),
        $expectedConstrainedBody,
        $payload
    );
} finally {
    king_http3_destroy_fixture($fixture);
}
?>
--EXPECT--
string(12) "direct-clean"
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
string(14) "dispatch-clean"
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
string(11) "direct-loss"
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
string(20) "dispatch-constrained"
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
