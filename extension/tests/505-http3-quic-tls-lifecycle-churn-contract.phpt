--TEST--
King HTTP/3 proves a coherent QUIC and TLS lifecycle across fresh handshakes, resumed sessions, and repeated same-port listener churn
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
    'direct.txt' => "direct-http3\n",
    'dispatch.txt' => "dispatch-http3\n",
], 'king-http3-quic-tls-lifecycle-fixture-');

$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
    'tls_enable_early_data' => true,
]);

$port = null;
$results = [];
$phases = [
    [
        'path' => '/direct.txt',
        'mode' => 'direct',
        'enable_early_data' => true,
        'expected_phase' => 'established',
        'expected_sent_in_early_data' => false,
        'expected_ticket_source' => 'none',
        'expected_resumed' => false,
    ],
    [
        'path' => '/direct.txt',
        'mode' => 'direct',
        'enable_early_data' => true,
        'expected_phase' => 'early_data',
        'expected_sent_in_early_data' => true,
        'expected_ticket_source' => 'ring',
        'expected_resumed' => true,
    ],
    [
        'path' => '/dispatch.txt',
        'mode' => 'dispatch',
        'enable_early_data' => true,
        'expected_phase' => 'early_data',
        'expected_sent_in_early_data' => true,
        'expected_ticket_source' => 'ring',
        'expected_resumed' => true,
    ],
    [
        'path' => '/direct.txt',
        'mode' => 'direct',
        'enable_early_data' => false,
        'expected_phase' => 'established',
        'expected_sent_in_early_data' => true,
        'expected_ticket_source' => 'ring',
        'expected_resumed' => true,
    ],
];

try {
    foreach ($phases as $phase) {
        $run = king_http3_one_shot_result_with_retry(
            static function () use ($fixture, &$port, $phase) {
                $server = king_http3_start_ticket_test_server(
                    $fixture['cert'],
                    $fixture['key'],
                    $fixture['root'],
                    'localhost',
                    $port,
                    null,
                    $phase['enable_early_data']
                );

                if ($port === null) {
                    $port = $server['port'];
                } elseif ($server['port'] !== $port) {
                    throw new RuntimeException('HTTP/3 listener churn moved to a different UDP port.');
                }

                return $server;
            },
            'king_http3_stop_ticket_test_server',
            static function (array $server) use ($config, $phase) {
                $url = king_http3_test_server_url($server, $phase['path']);
                $options = [
                    'connection_config' => $config,
                    'connect_timeout_ms' => 10000,
                    'timeout_ms' => 30000,
                ];

                if ($phase['mode'] === 'dispatch') {
                    return king_client_send_request(
                        $url,
                        'GET',
                        null,
                        null,
                        [
                            'preferred_protocol' => 'http3',
                        ] + $options
                    );
                }

                return king_http3_request_send(
                    $url,
                    'GET',
                    null,
                    null,
                    $options
                );
            },
            static function (array $response) use ($phase) {
                return $response['status'] === 200
                    && $response['protocol'] === 'http/3'
                    && $response['transport_backend'] === 'quiche_h3'
                    && $response['response_complete'] === true
                    && $response['tls_enable_early_data'] === true
                    && $response['tls_has_session_ticket'] === true
                    && $response['tls_session_ticket_length'] > 0
                    && $response['tls_request_sent_in_early_data'] === $phase['expected_sent_in_early_data']
                    && $response['tls_ticket_source'] === $phase['expected_ticket_source']
                    && $response['tls_session_resumed'] === $phase['expected_resumed']
                    && ($response['headers']['x-king-early-data-phase'] ?? null) === $phase['expected_phase']
                    && ($response['body'] ?? null) === ($phase['path'] === '/dispatch.txt'
                        ? "dispatch-http3\n"
                        : "direct-http3\n");
            }
        );

        $results[] = $run['result'];
    }
} finally {
    king_http3_destroy_fixture($fixture);
}

foreach ($results as $result) {
    var_dump($result['status']);
    var_dump($result['protocol']);
    var_dump($result['transport_backend']);
    var_dump($result['tls_enable_early_data']);
    var_dump($result['tls_has_session_ticket']);
    var_dump($result['tls_session_ticket_length'] > 0);
    var_dump($result['tls_request_sent_in_early_data']);
    var_dump($result['headers']['x-king-early-data-phase']);
    var_dump($result['tls_ticket_source']);
    var_dump($result['tls_session_resumed']);
}
?>
--EXPECT--
int(200)
string(6) "http/3"
string(9) "quiche_h3"
bool(true)
bool(true)
bool(true)
bool(false)
string(11) "established"
string(4) "none"
bool(false)
int(200)
string(6) "http/3"
string(9) "quiche_h3"
bool(true)
bool(true)
bool(true)
bool(true)
string(10) "early_data"
string(4) "ring"
bool(true)
int(200)
string(6) "http/3"
string(9) "quiche_h3"
bool(true)
bool(true)
bool(true)
bool(true)
string(10) "early_data"
string(4) "ring"
bool(true)
int(200)
string(6) "http/3"
string(9) "quiche_h3"
bool(true)
bool(true)
bool(true)
bool(true)
string(11) "established"
string(4) "ring"
bool(true)
