--TEST--
King HTTP/3 one-shot listener accepts real direct and dispatcher clients, invokes the handler, writes the response, and closes cleanly
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the on-wire HTTP/3 fixture";
}

$library = getenv('KING_QUICHE_LIBRARY');
if (!is_string($library) || $library === '' || !is_file($library)) {
    echo "skip KING_QUICHE_LIBRARY must point at a prebuilt libquiche runtime";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';
require __DIR__ . '/http3_server_wire_helper.inc';

$fixture = king_http3_create_fixture([]);
$cfg = king_new_config([
    'tls_default_ca_file' => $fixture['cert'],
]);

$directCapture = [];
$dispatcherCapture = [];

try {
    $directRun = king_http3_one_shot_result_with_retry(
        static fn () => king_http3_server_wire_start_server($fixture['cert'], $fixture['key']),
        'king_http3_server_wire_stop_server',
        static fn (array $server) => king_http3_request_send(
            'https://localhost:' . $server['port'] . '/wire?room=alpha',
            'POST',
            [
                'x-mode' => 'wire-h3',
            ],
            'payload',
            [
                'connection_config' => $cfg,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        ),
        static fn (array $response) => $response['status'] === 201
            && $response['protocol'] === 'http/3'
            && $response['transport_backend'] === 'quiche_h3'
            && $response['response_complete'] === true
            && ($response['headers']['x-reply-mode'] ?? null) === 'wire-h3'
            && ($response['headers']['content-type'] ?? null) === 'text/plain'
            && $response['body'] === 'reply:payload'
    );
    $directResponse = $directRun['result'];
    $directCapture = $directRun['capture'];

    $dispatcherRun = king_http3_one_shot_result_with_retry(
        static fn () => king_http3_server_wire_start_server($fixture['cert'], $fixture['key']),
        'king_http3_server_wire_stop_server',
        static fn (array $server) => king_client_send_request(
            'https://localhost:' . $server['port'] . '/wire?room=alpha',
            'POST',
            [
                'x-mode' => 'wire-h3',
            ],
            'payload',
            [
                'preferred_protocol' => 'http3',
                'connection_config' => $cfg,
                'connect_timeout_ms' => 10000,
                'timeout_ms' => 30000,
            ]
        ),
        static fn (array $response) => $response['status'] === 201
            && $response['protocol'] === 'http/3'
            && $response['transport_backend'] === 'quiche_h3'
            && $response['response_complete'] === true
            && ($response['headers']['x-reply-mode'] ?? null) === 'wire-h3'
            && ($response['headers']['content-type'] ?? null) === 'text/plain'
            && $response['body'] === 'reply:payload'
    );
    $dispatcherResponse = $dispatcherRun['result'];
    $dispatcherCapture = $dispatcherRun['capture'];
} finally {
    king_http3_destroy_fixture($fixture);
}

var_dump($directResponse['status']);
var_dump($directResponse['protocol']);
var_dump($directResponse['transport_backend']);
var_dump($directResponse['response_complete']);
var_dump($directResponse['headers']['x-reply-mode']);
var_dump($directResponse['headers']['content-type']);
var_dump($directResponse['body']);

var_dump($dispatcherResponse['status']);
var_dump($dispatcherResponse['protocol']);
var_dump($dispatcherResponse['transport_backend']);
var_dump($dispatcherResponse['response_complete']);
var_dump($dispatcherResponse['headers']['x-reply-mode']);
var_dump($dispatcherResponse['headers']['content-type']);
var_dump($dispatcherResponse['body']);

var_dump($directCapture['listen_result']);
var_dump($directCapture['listen_error']);
var_dump($directCapture['request']['protocol']);
var_dump($directCapture['request']['scheme']);
var_dump($directCapture['request']['method']);
var_dump($directCapture['request']['uri']);
echo $directCapture['request']['host'], "\n";
var_dump($directCapture['request']['body']);
var_dump($directCapture['request']['stream_id']);
echo $directCapture['request']['authority'], "\n";
var_dump($directCapture['request']['mode']);
var_dump($directCapture['request']['session_is_resource']);
var_dump($directCapture['request']['capability_is_int']);
var_dump($directCapture['request']['transport_backend_before']);
var_dump($directCapture['request']['alpn_before']);
var_dump($directCapture['request']['transport_socket_family_before']);
var_dump($directCapture['request']['transport_has_socket_before']);
var_dump($directCapture['post_stats']['state']);
var_dump($directCapture['post_stats']['transport_backend']);
var_dump($directCapture['post_stats']['alpn']);
var_dump($directCapture['post_stats']['transport_has_socket']);

var_dump($dispatcherCapture['listen_result']);
var_dump($dispatcherCapture['listen_error']);
var_dump($dispatcherCapture['request']['protocol']);
var_dump($dispatcherCapture['request']['scheme']);
var_dump($dispatcherCapture['request']['method']);
var_dump($dispatcherCapture['request']['uri']);
echo $dispatcherCapture['request']['host'], "\n";
var_dump($dispatcherCapture['request']['body']);
var_dump($dispatcherCapture['request']['stream_id']);
echo $dispatcherCapture['request']['authority'], "\n";
var_dump($dispatcherCapture['request']['mode']);
var_dump($dispatcherCapture['request']['session_is_resource']);
var_dump($dispatcherCapture['request']['capability_is_int']);
var_dump($dispatcherCapture['request']['transport_backend_before']);
var_dump($dispatcherCapture['request']['alpn_before']);
var_dump($dispatcherCapture['request']['transport_socket_family_before']);
var_dump($dispatcherCapture['request']['transport_has_socket_before']);
var_dump($dispatcherCapture['post_stats']['state']);
var_dump($dispatcherCapture['post_stats']['transport_backend']);
var_dump($dispatcherCapture['post_stats']['alpn']);
var_dump($dispatcherCapture['post_stats']['transport_has_socket']);
?>
--EXPECTF--
int(201)
string(6) "http/3"
string(9) "quiche_h3"
bool(true)
string(7) "wire-h3"
string(10) "text/plain"
string(13) "reply:payload"
int(201)
string(6) "http/3"
string(9) "quiche_h3"
bool(true)
string(7) "wire-h3"
string(10) "text/plain"
string(13) "reply:payload"
bool(true)
string(0) ""
string(6) "http/3"
string(5) "https"
string(4) "POST"
string(16) "/wire?room=alpha"
localhost:%d
string(7) "payload"
int(0)
localhost:%d
string(7) "wire-h3"
bool(true)
bool(true)
string(19) "server_http3_socket"
string(2) "h3"
string(3) "udp"
bool(true)
string(6) "closed"
string(19) "server_http3_socket"
string(2) "h3"
bool(false)
bool(true)
string(0) ""
string(6) "http/3"
string(5) "https"
string(4) "POST"
string(16) "/wire?room=alpha"
localhost:%d
string(7) "payload"
int(0)
localhost:%d
string(7) "wire-h3"
bool(true)
bool(true)
string(19) "server_http3_socket"
string(2) "h3"
string(3) "udp"
bool(true)
string(6) "closed"
string(19) "server_http3_socket"
string(2) "h3"
bool(false)
