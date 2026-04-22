--TEST--
King QUIC stream lifecycle proves open body finish and read-drain behavior against real peers
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

$fixture = king_http3_create_fixture([], 'king-http3-quic-stream-lifecycle-fixture-');
$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);
$payload = "stream-body-alpha\nstream-body-beta";
$expectedBody = 'stream-ack:' . $payload;

$cases = [
    'direct' => static function (array $server) use ($config, $payload) {
        return king_http3_request_send(
            king_http3_test_server_url($server, '/stream-lifecycle'),
            'POST',
            ['content-type' => 'text/plain'],
            $payload,
            [
                'connection_config' => $config,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        );
    },
    'dispatch' => static function (array $server) use ($config, $payload) {
        return king_client_send_request(
            king_http3_test_server_url($server, '/stream-lifecycle'),
            'POST',
            ['content-type' => 'text/plain'],
            $payload,
            [
                'preferred_protocol' => 'http3',
                'connection_config' => $config,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        );
    },
];

$captures = [];
$responses = [];

try {
    foreach ($cases as $label => $attempt) {
        $run = king_http3_one_shot_result_with_retry(
            static function () use ($fixture) {
                return king_http3_start_ticket_test_server(
                    $fixture['cert'],
                    $fixture['key'],
                    $fixture['root']
                );
            },
            'king_http3_stop_ticket_test_server',
            $attempt,
            static fn (array $response) => $response['status'] === 200
                && $response['protocol'] === 'http/3'
                && $response['transport_backend'] === 'lsquic_h3'
                && $response['stream_kind'] === 'request'
                && $response['response_complete'] === true
                && ($response['body'] ?? null) === $expectedBody
        );

        $responses[$label] = $run['result'];
        $captures[$label] = $run['capture'];
    }
} finally {
    king_http3_destroy_fixture($fixture);
}

foreach (['direct', 'dispatch'] as $label) {
    $capture = $captures[$label];
    $lifecycle = king_http3_ticket_server_lifecycle($capture);

    if (($capture['exit_code'] ?? 1) !== 0) {
        throw new RuntimeException($label . ' ticket server exited uncleanly.');
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
        'response_drained_before_close',
        'saw_draining',
        'saw_closed',
    ] as $field) {
        if (($lifecycle[$field] ?? false) !== true) {
            throw new RuntimeException(
                $label . ' missing stream lifecycle proof for ' . $field . ': ' . json_encode($lifecycle)
            );
        }
    }

    if (($lifecycle['request_body_bytes'] ?? -1) !== strlen($payload)) {
        throw new RuntimeException(
            $label . ' recorded unexpected request body size: ' . json_encode($lifecycle)
        );
    }

    var_dump($responses[$label]['status']);
    var_dump($responses[$label]['protocol']);
    var_dump($responses[$label]['transport_backend']);
    var_dump($responses[$label]['stream_kind']);
    var_dump($responses[$label]['response_complete']);
    var_dump($responses[$label]['body']);
    var_dump($responses[$label]['body_bytes']);
    var_dump($lifecycle['saw_initial']);
    var_dump($lifecycle['saw_established']);
    var_dump($lifecycle['saw_h3_open']);
    var_dump($lifecycle['saw_request_stream_open']);
    var_dump($lifecycle['saw_request_body']);
    var_dump($lifecycle['request_body_bytes']);
    var_dump($lifecycle['saw_request_finished']);
    var_dump($lifecycle['request_finished_before_response']);
    var_dump($lifecycle['request_body_drained_before_response']);
    var_dump($lifecycle['response_on_request_stream']);
    var_dump($lifecycle['saw_response_drain']);
    var_dump($lifecycle['response_drained_before_close']);
    var_dump($lifecycle['saw_draining']);
    var_dump($lifecycle['saw_closed']);
}
?>
--EXPECT--
int(200)
string(6) "http/3"
string(9) "lsquic_h3"
string(7) "request"
bool(true)
string(45) "stream-ack:stream-body-alpha
stream-body-beta"
int(45)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(34)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(200)
string(6) "http/3"
string(9) "lsquic_h3"
string(7) "request"
bool(true)
string(45) "stream-ack:stream-body-alpha
stream-body-beta"
int(45)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(34)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
