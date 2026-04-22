--TEST--
King QUIC session lifecycle proves handshake, open, response drain, and close against real peers
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
    'lifecycle.txt' => "quic-lifecycle\n",
], 'king-http3-quic-session-lifecycle-fixture-');

$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

$cases = [
    'direct' => static function (array $server) use ($config) {
        return king_http3_request_send(
            king_http3_test_server_url($server, '/lifecycle.txt'),
            'GET',
            null,
            null,
            [
                'connection_config' => $config,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        );
    },
    'dispatch' => static function (array $server) use ($config) {
        return king_client_send_request(
            king_http3_test_server_url($server, '/lifecycle.txt'),
            'GET',
            null,
            null,
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
                && $response['transport_backend'] === 'quiche_h3'
                && $response['response_complete'] === true
                && ($response['body'] ?? null) === "quic-lifecycle\n"
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
        'saw_request_headers',
        'saw_response_headers',
        'saw_response_drain',
        'response_drained_before_close',
        'saw_draining',
        'saw_closed',
    ] as $field) {
        if (($lifecycle[$field] ?? false) !== true) {
            throw new RuntimeException(
                $label . ' missing lifecycle proof for ' . $field . ': ' . json_encode($lifecycle)
            );
        }
    }

    if (!in_array($lifecycle['close_source'] ?? null, ['peer_draining_close', 'server_idle_close'], true)) {
        throw new RuntimeException(
            $label . ' closed from unexpected lifecycle path: ' . json_encode($lifecycle)
        );
    }

    var_dump($responses[$label]['status']);
    var_dump($responses[$label]['protocol']);
    var_dump($responses[$label]['transport_backend']);
    var_dump($responses[$label]['response_complete']);
    var_dump($lifecycle['saw_initial']);
    var_dump($lifecycle['saw_established']);
    var_dump($lifecycle['saw_h3_open']);
    var_dump($lifecycle['saw_response_drain']);
    var_dump($lifecycle['response_drained_before_close']);
    var_dump($lifecycle['saw_draining']);
    var_dump($lifecycle['saw_closed']);
    var_dump($lifecycle['close_source']);
}
?>
--EXPECT--
int(200)
string(6) "http/3"
string(9) "quiche_h3"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(19) "peer_draining_close"
int(200)
string(6) "http/3"
string(9) "quiche_h3"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(19) "peer_draining_close"
