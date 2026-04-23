--TEST--
King HTTP/3 one-shot listener waits for DATA and FIN before invoking the handler
--SKIPIF--
<?php
if (trim((string) shell_exec('command -v openssl')) === '') {
    echo "skip openssl is required for the on-wire HTTP/3 fixture";
}

if (trim((string) shell_exec('command -v cargo')) === '') {
    echo "skip cargo is required to build the delayed HTTP/3 body client helper";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/http3_test_helper.inc';
require __DIR__ . '/http3_server_wire_helper.inc';

$fixture = king_http3_create_fixture([]);

try {
    $run = king_http3_one_shot_result_with_retry(
        static fn () => king_http3_server_wire_start_server($fixture['cert'], $fixture['key']),
        'king_http3_server_wire_stop_server',
        static fn (array $server) => king_http3_send_delayed_body_request(
            'https://localhost:' . $server['port'] . '/wire?room=delayed',
            'payload',
            250
        ),
        static fn (array $response) => $response['status'] === 201
            && $response['body'] === 'reply:payload'
            && $response['early_response'] === false
    );
    $response = $run['result'];
    $capture = $run['capture'];
} finally {
    king_http3_destroy_fixture($fixture);
}

var_dump($response['status']);
var_dump($response['body']);
var_dump($response['early_response']);
var_dump($capture['listen_result']);
var_dump($capture['listen_error']);
var_dump($capture['request']['method']);
var_dump($capture['request']['uri']);
echo $capture['request']['host'], "\n";
var_dump($capture['request']['body']);
var_dump($capture['request']['mode']);
var_dump($capture['request']['transport_backend_before']);
var_dump($capture['request']['alpn_before']);
var_dump($capture['post_stats']['state']);
?>
--EXPECTF--
int(201)
string(13) "reply:payload"
bool(false)
bool(true)
string(0) ""
string(4) "POST"
string(18) "/wire?room=delayed"
localhost:%d
string(7) "payload"
string(12) "delayed-body"
string(19) "server_http3_socket"
string(2) "h3"
string(6) "closed"
