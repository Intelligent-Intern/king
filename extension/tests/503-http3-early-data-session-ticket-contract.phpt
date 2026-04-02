--TEST--
King HTTP/3 runtime exposes honest early-data accept, reject, and fallback semantics on resumed ticket paths
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
    'direct.txt' => "direct-http3\n",
    'dispatch.txt' => "dispatch-http3\n",
], 'king-http3-early-data-fixture-');

$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
    'tls_enable_early_data' => true,
]);

$port = null;

try {
    $first = king_http3_one_shot_result_with_retry(
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
            && $response['tls_enable_early_data']
            && $response['tls_ticket_source'] === 'none'
            && $response['tls_session_resumed'] === false
            && $response['tls_request_sent_in_early_data'] === false
            && ($response['headers']['x-king-early-data-phase'] ?? null) === 'established'
    )['result'];

    $second = king_http3_one_shot_result_with_retry(
        static function () use ($fixture, $port) {
            return king_http3_start_ticket_test_server(
                $fixture['cert'],
                $fixture['key'],
                $fixture['root'],
                'localhost',
                $port,
                null,
                true
            );
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
            && $response['tls_enable_early_data']
            && $response['tls_ticket_source'] === 'ring'
            && $response['tls_session_resumed'] === true
            && $response['tls_request_sent_in_early_data'] === true
            && ($response['headers']['x-king-early-data-phase'] ?? null) === 'early_data'
    )['result'];

    $third = king_http3_one_shot_result_with_retry(
        static function () use ($fixture, $port) {
            return king_http3_start_ticket_test_server(
                $fixture['cert'],
                $fixture['key'],
                $fixture['root'],
                'localhost',
                $port,
                null,
                true
            );
        },
        'king_http3_stop_ticket_test_server',
        static function (array $server) use ($config) {
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
        static fn (array $response) => $response['status'] === 200
            && $response['tls_enable_early_data']
            && $response['tls_ticket_source'] === 'ring'
            && $response['tls_session_resumed'] === true
            && $response['tls_request_sent_in_early_data'] === true
            && ($response['headers']['x-king-early-data-phase'] ?? null) === 'early_data'
    )['result'];

    $fourth = king_http3_one_shot_result_with_retry(
        static function () use ($fixture, $port) {
            return king_http3_start_ticket_test_server(
                $fixture['cert'],
                $fixture['key'],
                $fixture['root'],
                'localhost',
                $port,
                null,
                false
            );
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
            && $response['tls_enable_early_data']
            && $response['tls_ticket_source'] === 'ring'
            && $response['tls_session_resumed'] === true
            && $response['tls_request_sent_in_early_data'] === true
            && ($response['headers']['x-king-early-data-phase'] ?? null) === 'established'
    )['result'];
} finally {
    king_http3_destroy_fixture($fixture);
}

var_dump($first['status']);
var_dump($first['tls_enable_early_data']);
var_dump($first['tls_request_sent_in_early_data']);
var_dump($first['headers']['x-king-early-data-phase']);
var_dump($first['tls_ticket_source']);
var_dump($first['tls_session_resumed']);

var_dump($second['status']);
var_dump($second['tls_request_sent_in_early_data']);
var_dump($second['headers']['x-king-early-data-phase']);
var_dump($second['tls_ticket_source']);
var_dump($second['tls_session_resumed']);

var_dump($third['status']);
var_dump($third['tls_request_sent_in_early_data']);
var_dump($third['headers']['x-king-early-data-phase']);
var_dump($third['tls_ticket_source']);
var_dump($third['tls_session_resumed']);

var_dump($fourth['status']);
var_dump($fourth['tls_request_sent_in_early_data']);
var_dump($fourth['headers']['x-king-early-data-phase']);
var_dump($fourth['tls_ticket_source']);
var_dump($fourth['tls_session_resumed']);
?>
--EXPECT--
int(200)
bool(true)
bool(false)
string(11) "established"
string(4) "none"
bool(false)
int(200)
bool(true)
string(10) "early_data"
string(4) "ring"
bool(true)
int(200)
bool(true)
string(10) "early_data"
string(4) "ring"
bool(true)
int(200)
bool(true)
string(11) "established"
string(4) "ring"
bool(true)
