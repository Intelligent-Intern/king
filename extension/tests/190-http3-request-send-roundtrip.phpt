--TEST--
King HTTP/3 LSQUIC runtime can perform real local HTTPS roundtrips directly and over the dispatcher
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the local HTTP/3 fixture";
}

$server = getenv('KING_QUICHE_SERVER');
if (!is_string($server) || $server === '' || !is_executable($server)) {
    echo "skip KING_QUICHE_SERVER must point at a prebuilt quiche-server binary";
}

$library = getenv('KING_LSQUIC_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_LSQUIC_LIBRARY must point at a prebuilt liblsquic runtime";
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
]);
$server = king_http3_start_test_server($fixture['cert'], $fixture['key'], $fixture['root']);

try {
    $cfg = king_new_config([
        'tls_default_ca_file' => $fixture['cert'],
    ]);
    $directUrl = king_http3_test_server_url($server, '/direct.txt');
    $dispatchUrl = king_http3_test_server_url($server, '/dispatch.txt');

    king_http3_request_with_retry(
        static fn () => king_http3_request_send(
            $directUrl,
            'GET',
            null,
            null,
            [
                'connection_config' => $cfg,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        )
    );

    $directResponse = king_http3_request_with_retry(
        static fn () => king_http3_request_send(
            $directUrl,
            'GET',
            null,
            null,
            [
                'connection_config' => $cfg,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        )
    );

    $dispatcherResponse = king_http3_request_with_retry(
        static fn () => king_client_send_request(
            $dispatchUrl,
            'GET',
            null,
            null,
            [
                'preferred_protocol' => 'http3',
                'connection_config' => $cfg,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        )
    );
} finally {
    king_http3_stop_test_server($server);
    king_http3_destroy_fixture($fixture);
}

var_dump($directResponse['status']);
var_dump($directResponse['protocol']);
var_dump($directResponse['transport_backend']);
var_dump($directResponse['response_complete']);
var_dump($directResponse['body']);
var_dump($directResponse['body_bytes']);

var_dump($dispatcherResponse['status']);
var_dump($dispatcherResponse['protocol']);
var_dump($dispatcherResponse['transport_backend']);
var_dump($dispatcherResponse['response_complete']);
var_dump($dispatcherResponse['body']);
var_dump($dispatcherResponse['body_bytes']);
?>
--EXPECT--
int(200)
string(6) "http/3"
string(9) "lsquic_h3"
bool(true)
string(13) "direct-http3
"
int(13)
int(200)
string(6) "http/3"
string(9) "lsquic_h3"
bool(true)
string(15) "dispatch-http3
"
int(15)
