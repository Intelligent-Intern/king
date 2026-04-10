--TEST--
King QUIC HTTP/3 runtime stays stable across sustained stress rounds and repeated partial-failure recovery rounds
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
}

$library = getenv('KING_QUICHE_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_QUICHE_LIBRARY must point at a prebuilt libquiche runtime";
}

if (trim((string) shell_exec('command -v cargo')) === '') {
    echo "skip cargo is required for the HTTP/3 helper binaries";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';

function king_http3_645_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_http3_645_decode_delay_response(array $response): array
{
    return json_decode((string) ($response['body'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
}

function king_http3_645_run_stress_round(array $fixture, $config, int $round): void
{
    $peer = king_http3_start_multi_peer($fixture['cert'], $fixture['key'], expectedRequests: 7);
    $capture = null;

    try {
        $responses = king_http3_request_with_retry(
            static fn () => king_http3_request_send_multi(
                [
                    ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/900/stress-' . $round . '-slow-root'],
                    ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/30/stress-' . $round . '-wave-1-fast-a'],
                    ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/50/stress-' . $round . '-wave-1-fast-b'],
                    ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/220/stress-' . $round . '-wave-2-fast-a'],
                    ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/240/stress-' . $round . '-wave-2-fast-b'],
                    ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/420/stress-' . $round . '-wave-3-fast-a'],
                    ['url' => 'https://' . $peer['host'] . ':' . $peer['port'] . '/delay/440/stress-' . $round . '-wave-3-fast-b'],
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

    king_http3_645_assert(count($responses) === 7, 'stress round ' . $round . ' returned an unexpected response count');
    foreach ($responses as $index => $response) {
        king_http3_645_assert(
            (int) ($response['status'] ?? 0) === 200,
            'stress round ' . $round . ' response ' . $index . ' did not return HTTP 200'
        );
        king_http3_645_assert(
            (string) ($response['transport_backend'] ?? '') === 'quiche_h3',
            'stress round ' . $round . ' response ' . $index . ' drifted from quiche_h3 backend'
        );
    }

    $slow = king_http3_645_decode_delay_response($responses[0]);
    $wave1A = king_http3_645_decode_delay_response($responses[1]);
    $wave1B = king_http3_645_decode_delay_response($responses[2]);
    $wave2A = king_http3_645_decode_delay_response($responses[3]);
    $wave2B = king_http3_645_decode_delay_response($responses[4]);
    $wave3A = king_http3_645_decode_delay_response($responses[5]);
    $wave3B = king_http3_645_decode_delay_response($responses[6]);

    $connectionId = (string) ($slow['connectionId'] ?? '');
    king_http3_645_assert($connectionId !== '', 'stress round ' . $round . ' emitted an empty connection id');
    king_http3_645_assert((string) ($wave1A['connectionId'] ?? '') === $connectionId, 'stress round ' . $round . ' wave1A connection drifted');
    king_http3_645_assert((string) ($wave1B['connectionId'] ?? '') === $connectionId, 'stress round ' . $round . ' wave1B connection drifted');
    king_http3_645_assert((string) ($wave2A['connectionId'] ?? '') === $connectionId, 'stress round ' . $round . ' wave2A connection drifted');
    king_http3_645_assert((string) ($wave2B['connectionId'] ?? '') === $connectionId, 'stress round ' . $round . ' wave2B connection drifted');
    king_http3_645_assert((string) ($wave3A['connectionId'] ?? '') === $connectionId, 'stress round ' . $round . ' wave3A connection drifted');
    king_http3_645_assert((string) ($wave3B['connectionId'] ?? '') === $connectionId, 'stress round ' . $round . ' wave3B connection drifted');

    king_http3_645_assert((int) ($wave1A['finishOrder'] ?? 0) === 1, 'stress round ' . $round . ' wave1A finish order drifted');
    king_http3_645_assert((int) ($wave1B['finishOrder'] ?? 0) === 2, 'stress round ' . $round . ' wave1B finish order drifted');
    king_http3_645_assert((int) ($wave2A['finishOrder'] ?? 0) === 3, 'stress round ' . $round . ' wave2A finish order drifted');
    king_http3_645_assert((int) ($wave2B['finishOrder'] ?? 0) === 4, 'stress round ' . $round . ' wave2B finish order drifted');
    king_http3_645_assert((int) ($wave3A['finishOrder'] ?? 0) === 5, 'stress round ' . $round . ' wave3A finish order drifted');
    king_http3_645_assert((int) ($wave3B['finishOrder'] ?? 0) === 6, 'stress round ' . $round . ' wave3B finish order drifted');
    king_http3_645_assert((int) ($slow['finishOrder'] ?? 0) === 7, 'stress round ' . $round . ' slow finish order drifted');
    king_http3_645_assert((int) ($slow['maxActiveStreams'] ?? 0) >= 7, 'stress round ' . $round . ' max active streams dropped below 7');
    king_http3_645_assert(
        (($capture['exit_code'] ?? 1) === 0 || ($capture['exit_code'] ?? 1) === 15),
        'stress round ' . $round . ' peer exit drifted'
    );
}

function king_http3_645_run_partial_failure_round(array $fixture, $config, string $label, int $round): void
{
    $payload = str_repeat('partial-failure-body-' . $label . '-', 1024);
    $expectedBody = 'interruption-ack:' . strlen($payload);

    $attempt = static function (array $server) use ($label, $config, $payload) {
        if ($label === 'direct') {
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
        }

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
    };

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
        static function (array $server) use ($attempt) {
            $response = $attempt($server);
            return ['response' => $response];
        },
        static fn (array $result) => ($result['response']['status'] ?? 0) === 200
            && ($result['response']['response_complete'] ?? false) === true
    );

    $response = $run['result']['response'];
    $capture = king_http3_ticket_server_lifecycle($run['capture']);

    king_http3_645_assert(
        ($run['capture']['exit_code'] ?? 1) === 0,
        $label . ' round ' . $round . ' ticket server exited uncleanly'
    );
    king_http3_645_assert((int) ($response['status'] ?? 0) === 200, $label . ' round ' . $round . ' status drifted');
    king_http3_645_assert((string) ($response['body'] ?? '') === $expectedBody, $label . ' round ' . $round . ' body drifted');
    king_http3_645_assert((string) ($response['transport_backend'] ?? '') === 'quiche_h3', $label . ' round ' . $round . ' backend drifted');
    king_http3_645_assert(($response['response_complete'] ?? false) === true, $label . ' round ' . $round . ' response completion drifted');
    king_http3_645_assert((int) ($response['quic_packets_lost'] ?? 0) > 0, $label . ' round ' . $round . ' packet loss counter was not populated');
    king_http3_645_assert((int) ($response['quic_packets_retransmitted'] ?? 0) > 0, $label . ' round ' . $round . ' retransmit counter was not populated');
    king_http3_645_assert((int) ($response['quic_stream_retransmitted_bytes'] ?? 0) > 0, $label . ' round ' . $round . ' stream retransmit bytes were not populated');

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
        king_http3_645_assert(
            ($capture[$field] ?? false) === true,
            $label . ' round ' . $round . ' missing lifecycle field ' . $field
        );
    }

    king_http3_645_assert(
        (int) ($capture['request_body_bytes'] ?? -1) === strlen($payload),
        $label . ' round ' . $round . ' request body size drifted'
    );
    king_http3_645_assert(
        (int) ($capture['interrupted_datagrams_dropped'] ?? 0) > 0,
        $label . ' round ' . $round . ' did not record interruption drops'
    );
    king_http3_645_assert(
        in_array(
            (string) ($capture['close_source'] ?? ''),
            ['peer_draining_close', 'server_idle_close', 'peer_closed'],
            true
        ),
        $label . ' round ' . $round . ' close source drifted'
    );
}

$fixture = king_http3_create_fixture([], 'king-http3-stress-failure-matrix-');
$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

try {
    foreach (range(1, 4) as $round) {
        king_http3_645_run_stress_round($fixture, $config, $round);
    }

    foreach (['direct', 'dispatch'] as $label) {
        foreach (range(1, 3) as $round) {
            king_http3_645_run_partial_failure_round($fixture, $config, $label, $round);
        }
    }

    king_http3_645_run_stress_round($fixture, $config, 99);
} finally {
    king_http3_destroy_fixture($fixture);
}

echo "OK\n";
?>
--EXPECT--
OK
