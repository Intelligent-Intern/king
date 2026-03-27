--TEST--
King HTTP/3 runtime reuses session tickets through the shared ring and recovers cleanly from stale ticket seeds
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
], 'king-http3-ticket-fixture-');

$config = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

$port = null;

try {
    $first = king_http3_one_shot_result_with_retry(
        static function () use ($fixture, &$port) {
            $server = king_http3_start_ticket_test_server(
                $fixture['cert'],
                $fixture['key'],
                $fixture['root']
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
            && $response['tls_has_session_ticket']
            && $response['tls_session_ticket_length'] > 0
            && $response['tls_ticket_source'] === 'none'
            && $response['tls_session_resumed'] === false
    )['result'];

    $second = king_http3_one_shot_result_with_retry(
        static function () use ($fixture, $port) {
            return king_http3_start_ticket_test_server(
                $fixture['cert'],
                $fixture['key'],
                $fixture['root'],
                '127.0.0.1',
                $port,
                'localhost'
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
            && $response['tls_has_session_ticket']
            && $response['tls_ticket_source'] === 'ring'
            && $response['tls_session_resumed'] === true
    )['result'];

    $session = king_connect('127.0.0.1', 443);
    king_client_tls_import_session_ticket($session, 'stale-h3-ticket');
    king_close($session);

    $third = king_http3_one_shot_result_with_retry(
        static function () use ($fixture, $port) {
            return king_http3_start_ticket_test_server(
                $fixture['cert'],
                $fixture['key'],
                $fixture['root'],
                '127.0.0.1',
                $port,
                'localhost'
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
            && $response['tls_has_session_ticket']
            && $response['tls_ticket_source'] === 'none'
            && $response['tls_session_resumed'] === false
    )['result'];

    $fourth = king_http3_one_shot_result_with_retry(
        static function () use ($fixture, $port) {
            return king_http3_start_ticket_test_server(
                $fixture['cert'],
                $fixture['key'],
                $fixture['root'],
                '127.0.0.1',
                $port,
                'localhost'
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
            && $response['tls_has_session_ticket']
            && $response['tls_ticket_source'] === 'ring'
            && $response['tls_session_resumed'] === true
    )['result'];
} finally {
    king_http3_destroy_fixture($fixture);
}

var_dump($first['status']);
var_dump($first['tls_has_session_ticket']);
var_dump($first['tls_session_ticket_length'] > 0);
var_dump($first['tls_ticket_source']);
var_dump($first['tls_session_resumed']);

var_dump($second['status']);
var_dump($second['tls_has_session_ticket']);
var_dump($second['tls_ticket_source']);
var_dump($second['tls_session_resumed']);

var_dump($third['status']);
var_dump($third['tls_has_session_ticket']);
var_dump($third['tls_ticket_source']);
var_dump($third['tls_session_resumed']);

var_dump($fourth['status']);
var_dump($fourth['tls_has_session_ticket']);
var_dump($fourth['tls_ticket_source']);
var_dump($fourth['tls_session_resumed']);
?>
--EXPECT--
int(200)
bool(true)
bool(true)
string(4) "none"
bool(false)
int(200)
bool(true)
string(4) "ring"
bool(true)
int(200)
bool(true)
string(4) "none"
bool(false)
int(200)
bool(true)
string(4) "ring"
bool(true)
