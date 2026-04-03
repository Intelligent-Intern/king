--TEST--
King QUIC proves zero-RTT acceptance and server-disabled fallback against real peers
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
    echo "skip cargo is required to build the HTTP/3 ticket test server";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';

$fixture = king_http3_create_fixture([
    'direct.txt' => "direct-zero-rtt\n",
    'dispatch.txt' => "dispatch-zero-rtt\n",
], 'king-http3-zero-rtt-fixture-');

$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
    'tls_enable_early_data' => true,
]);

$port = null;

$cases = [
    [
        'label' => 'direct-accept',
        'server_early_data' => true,
        'expected_body' => "direct-zero-rtt\n",
        'expected_header_phase' => 'early_data',
        'expected_sent_in_early_data' => true,
        'expected_request_phase' => 'early_data',
        'expected_reason_code' => 2,
        'expected_reason' => 'accepted',
        'attempt' => static function (array $server) use ($config) {
            return king_http3_request_send(
                king_http3_test_server_url($server, '/direct.txt'),
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
    ],
    [
        'label' => 'dispatch-accept',
        'server_early_data' => true,
        'expected_body' => "dispatch-zero-rtt\n",
        'expected_header_phase' => 'early_data',
        'expected_sent_in_early_data' => true,
        'expected_request_phase' => 'early_data',
        'expected_reason_code' => 2,
        'expected_reason' => 'accepted',
        'attempt' => static function (array $server) use ($config) {
            return king_client_send_request(
                king_http3_test_server_url($server, '/dispatch.txt'),
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
    ],
    [
        'label' => 'direct-fallback',
        'server_early_data' => false,
        'expected_body' => "direct-zero-rtt\n",
        'expected_header_phase' => 'established',
        'expected_sent_in_early_data' => true,
        'expected_request_phase' => 'established',
        'expected_reason_code' => 1,
        'expected_reason' => 'disabled',
        'attempt' => static function (array $server) use ($config) {
            return king_http3_request_send(
                king_http3_test_server_url($server, '/direct.txt'),
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
    ],
    [
        'label' => 'dispatch-fallback',
        'server_early_data' => false,
        'expected_body' => "dispatch-zero-rtt\n",
        'expected_header_phase' => 'established',
        'expected_sent_in_early_data' => false,
        'expected_request_phase' => 'established',
        'expected_reason_code' => 1,
        'expected_reason' => 'disabled',
        'attempt' => static function (array $server) use ($config) {
            return king_client_send_request(
                king_http3_test_server_url($server, '/dispatch.txt'),
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
    ],
];

try {
    $warmup = king_http3_one_shot_result_with_retry(
        static function () use ($fixture, &$port) {
            $server = king_http3_start_ticket_test_server(
                $fixture['cert'],
                $fixture['key'],
                $fixture['root'],
                'localhost',
                null,
                null,
                true
            );
            $port = $server['port'];
            return $server;
        },
        'king_http3_stop_ticket_test_server',
        static function (array $server) use ($config) {
            return king_http3_request_send(
                king_http3_test_server_url($server, '/direct.txt'),
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
        static fn (array $response) => $response['status'] === 200
            && $response['tls_ticket_source'] === 'none'
            && $response['tls_session_resumed'] === false
            && $response['tls_request_sent_in_early_data'] === false
    );

    $captures = [];
    $responses = [];

    foreach ($cases as $case) {
        $run = king_http3_one_shot_result_with_retry(
            static function () use ($fixture, $port, $case) {
                return king_http3_start_ticket_test_server(
                    $fixture['cert'],
                    $fixture['key'],
                    $fixture['root'],
                    'localhost',
                    $port,
                    null,
                    $case['server_early_data']
                );
            },
            'king_http3_stop_ticket_test_server',
            static fn (array $server) => $case['attempt']($server),
            static fn (array $response) => $response['status'] === 200
                && $response['protocol'] === 'http/3'
                && $response['transport_backend'] === 'quiche_h3'
                && $response['response_complete'] === true
                && $response['tls_enable_early_data'] === true
                && $response['tls_ticket_source'] === 'ring'
                && $response['tls_session_resumed'] === true
                && $response['tls_request_sent_in_early_data'] === $case['expected_sent_in_early_data']
                && ($response['headers']['x-king-early-data-phase'] ?? null) === $case['expected_header_phase']
                && ($response['body'] ?? null) === $case['expected_body']
        );

        $responses[$case['label']] = $run['result'];
        $captures[$case['label']] = king_http3_ticket_server_lifecycle($run['capture']);
    }
} finally {
    king_http3_destroy_fixture($fixture);
}

var_dump($warmup['result']['status']);
var_dump($warmup['result']['tls_ticket_source']);
var_dump($warmup['result']['tls_session_resumed']);
var_dump($warmup['result']['tls_request_sent_in_early_data']);

foreach ($cases as $case) {
    $label = $case['label'];
    $response = $responses[$label];
    $capture = $captures[$label];

    if (($capture['saw_initial'] ?? false) !== true
        || ($capture['saw_established'] ?? false) !== true
        || ($capture['saw_resumed'] ?? false) !== true
        || ($capture['saw_h3_open'] ?? false) !== true
        || ($capture['saw_request_headers'] ?? false) !== true
        || ($capture['saw_response_headers'] ?? false) !== true
        || ($capture['saw_response_drain'] ?? false) !== true
    ) {
        throw new RuntimeException($label . ' missing common zero-rtt lifecycle proof: ' . json_encode($capture));
    }

    if (($capture['early_data_reason_code'] ?? null) !== $case['expected_reason_code']
        || ($capture['early_data_reason'] ?? null) !== $case['expected_reason']
    ) {
        throw new RuntimeException($label . ' reported unexpected early-data reason: ' . json_encode($capture));
    }

    $requestInEarlyData = ($capture['request_headers_in_early_data'] ?? false) === true;
    $requestAfterEstablished = ($capture['request_headers_after_established'] ?? false) === true;
    $responseInEarlyData = ($capture['response_headers_in_early_data'] ?? false) === true;
    $responseAfterEstablished = ($capture['response_headers_after_established'] ?? false) === true;
    $sawEarlyDataState = ($capture['saw_early_data_state'] ?? false) === true;

    if ($case['expected_request_phase'] === 'early_data') {
        if (!$requestInEarlyData || $requestAfterEstablished || !$responseInEarlyData || $responseAfterEstablished || !$sawEarlyDataState) {
            throw new RuntimeException($label . ' did not keep request/response inside accepted early-data phase: ' . json_encode($capture));
        }
    } else {
        if ($requestInEarlyData || !$requestAfterEstablished || $responseInEarlyData || !$responseAfterEstablished || $sawEarlyDataState) {
            throw new RuntimeException($label . ' did not fall back to established phase after zero-rtt rejection: ' . json_encode($capture));
        }
    }

    var_dump($label);
    var_dump($response['status']);
    var_dump($response['tls_request_sent_in_early_data']);
    var_dump($response['headers']['x-king-early-data-phase']);
    var_dump($capture['saw_resumed']);
    var_dump($capture['saw_early_data_state']);
    var_dump($capture['early_data_reason']);
    var_dump($capture['request_headers_in_early_data']);
    var_dump($capture['request_headers_after_established']);
    var_dump($capture['response_headers_in_early_data']);
    var_dump($capture['response_headers_after_established']);
}
?>
--EXPECT--
int(200)
string(4) "none"
bool(false)
bool(false)
string(13) "direct-accept"
int(200)
bool(true)
string(10) "early_data"
bool(true)
bool(true)
string(8) "accepted"
bool(true)
bool(false)
bool(true)
bool(false)
string(15) "dispatch-accept"
int(200)
bool(true)
string(10) "early_data"
bool(true)
bool(true)
string(8) "accepted"
bool(true)
bool(false)
bool(true)
bool(false)
string(15) "direct-fallback"
int(200)
bool(true)
string(11) "established"
bool(true)
bool(false)
string(8) "disabled"
bool(false)
bool(true)
bool(false)
bool(true)
string(17) "dispatch-fallback"
int(200)
bool(false)
string(11) "established"
bool(true)
bool(false)
string(8) "disabled"
bool(false)
bool(true)
bool(false)
bool(true)
