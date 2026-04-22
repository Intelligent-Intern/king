--TEST--
King HTTP/3 direct and dispatcher paths recover after temporary QUIC network interruption and socket re-wake
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
    echo "skip cargo is required for the HTTP/3 ticket test server";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';

$fixture = king_http3_create_fixture([], 'king-http3-network-interruption-');
$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);
$payload = str_repeat('network-interruption-body-', 1024);
$expectedBody = 'interruption-ack:' . strlen($payload);

$cases = [
    [
        'label' => 'direct',
        'attempt' => static function (array $server, $config, string $payload) {
            return king_http3_request_send(
                king_http3_test_server_url($server, '/network-interruption'),
                'POST',
                ['content-type' => 'text/plain'],
                $payload,
                [
                    'connection_config' => $config,
                    'connect_timeout_ms' => 10000,
                    'timeout_ms' => 15000,
                ]
            );
        },
    ],
    [
        'label' => 'dispatch',
        'attempt' => static function (array $server, $config, string $payload) {
            return king_client_send_request(
                king_http3_test_server_url($server, '/network-interruption'),
                'POST',
                ['content-type' => 'text/plain'],
                $payload,
                [
                    'preferred_protocol' => 'http3',
                    'connection_config' => $config,
                    'connect_timeout_ms' => 10000,
                    'timeout_ms' => 15000,
                ]
            );
        },
    ],
];

try {
    foreach ($cases as $case) {
        $run = king_http3_one_shot_result_with_retry(
            static fn () => king_http3_start_ticket_test_server(
                $fixture['cert'],
                $fixture['key'],
                $fixture['root'],
                'localhost',
                null,
                null,
                false,
                0,
                700
            ),
            'king_http3_stop_ticket_test_server',
            static function (array $server) use ($case, $config, $payload) {
                $start = microtime(true);
                $response = $case['attempt']($server, $config, $payload);

                return [
                    'response' => $response,
                    'elapsed_ms' => (int) round((microtime(true) - $start) * 1000),
                ];
            },
            static fn (array $result) => ($result['response']['status'] ?? 0) === 200
                && ($result['response']['body'] ?? null) === $expectedBody
                && ($result['response']['transport_backend'] ?? null) === 'quiche_h3'
                && ($result['response']['response_complete'] ?? false) === true
                && ($result['response']['quic_packets_lost'] ?? 0) > 0
                && ($result['response']['quic_packets_retransmitted'] ?? 0) > 0
                && ($result['response']['quic_stream_retransmitted_bytes'] ?? 0) > 0
        );

        $response = $run['result']['response'];
        $elapsedMs = $run['result']['elapsed_ms'];
        $capture = king_http3_ticket_server_lifecycle($run['capture']);

        if (($run['capture']['exit_code'] ?? 1) !== 0) {
            throw new RuntimeException($case['label'] . ' ticket server exited uncleanly.');
        }

        foreach ([
            'saw_initial',
            'saw_established',
            'saw_h3_open',
            'saw_request_stream_open',
            'saw_request_headers',
            'saw_request_body',
            'saw_request_finished',
            'request_finished_before_response',
            'request_body_drained_before_response',
            'response_on_request_stream',
            'saw_response_headers',
            'saw_response_drain',
            'saw_draining',
            'saw_closed',
        ] as $field) {
            if (($capture[$field] ?? false) !== true) {
                throw new RuntimeException(
                    $case['label'] . ' missing interruption recovery proof for ' . $field . ': '
                    . json_encode($capture)
                );
            }
        }

        if (($capture['request_body_bytes'] ?? -1) !== strlen($payload)) {
            throw new RuntimeException(
                $case['label'] . ' recorded unexpected request body size: ' . json_encode($capture)
            );
        }

        if (($capture['interrupted_datagrams_dropped'] ?? 0) <= 0) {
            throw new RuntimeException(
                $case['label'] . ' never observed established-phase blackout traffic: '
                . json_encode($capture)
            );
        }

        var_dump($case['label']);
        var_dump($response['status']);
        var_dump($response['body'] === $expectedBody);
        var_dump($response['transport_backend'] === 'quiche_h3');
        var_dump($response['response_complete'] === true);
        var_dump($elapsedMs >= 450);
        var_dump($elapsedMs < 8000);
        var_dump($response['quic_packets_lost'] > 0);
        var_dump($response['quic_packets_retransmitted'] > 0);
        var_dump(($response['quic_stream_retransmitted_bytes'] ?? 0) > 0);
        var_dump($capture['saw_initial'] === true);
        var_dump($capture['saw_established'] === true);
        var_dump($capture['saw_h3_open'] === true);
        var_dump($capture['saw_request_stream_open'] === true);
        var_dump($capture['saw_request_body'] === true);
        var_dump($capture['request_body_bytes'] === strlen($payload));
        var_dump($capture['request_body_drained_before_response'] === true);
        var_dump($capture['response_on_request_stream'] === true);
        var_dump($capture['saw_response_drain'] === true);
        var_dump($capture['interrupted_datagrams_dropped'] > 0);
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
string(6) "direct"
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
bool(true)
string(8) "dispatch"
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
bool(true)
