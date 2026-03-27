--TEST--
King HTTP/2 one-shot listener accepts a real h2c client, invokes the handler, writes the response, and closes cleanly
--FILE--
<?php
require __DIR__ . '/http2_server_wire_helper.inc';

$server = king_http2_server_wire_start_server();
$capture = [];

try {
    $response = king_http2_server_wire_request_retry($server['port'], [
        'method' => 'POST',
        'path' => '/wire?room=alpha',
        'headers' => [
            'x-mode' => 'wire-h2c',
        ],
        'body' => 'payload',
    ]);

    var_dump($response['status']);
    var_dump($response['headers']['x-reply-mode']);
    var_dump($response['headers']['content-type']);
    var_dump($response['body']);
    var_dump($response['saw_goaway']);
    var_dump($response['peer_closed']);
    var_dump($response['tail']);
} finally {
    $capture = king_http2_server_wire_stop_server($server);
}

var_dump($capture['listen_result']);
var_dump($capture['listen_error']);
var_dump($capture['request']['protocol']);
var_dump($capture['request']['scheme']);
var_dump($capture['request']['method']);
var_dump($capture['request']['uri']);
echo $capture['request']['host'], "\n";
var_dump($capture['request']['body']);
var_dump($capture['request']['stream_id']);
echo $capture['request']['authority'], "\n";
var_dump($capture['request']['mode']);
var_dump($capture['request']['session_is_resource']);
var_dump($capture['request']['capability_is_int']);
var_dump($capture['request']['transport_backend_before']);
var_dump($capture['request']['alpn_before']);
var_dump($capture['request']['transport_has_socket_before']);
var_dump($capture['post_stats']['state']);
var_dump($capture['post_stats']['transport_backend']);
var_dump($capture['post_stats']['alpn']);
var_dump($capture['post_stats']['transport_has_socket']);
?>
--EXPECTF--
int(201)
string(8) "wire-h2c"
string(10) "text/plain"
string(13) "reply:payload"
bool(true)
bool(true)
string(0) ""
bool(true)
string(0) ""
string(6) "http/2"
string(4) "http"
string(4) "POST"
string(16) "/wire?room=alpha"
127.0.0.1:%d
string(7) "payload"
int(1)
127.0.0.1:%d
string(8) "wire-h2c"
bool(true)
bool(true)
string(19) "server_http2_socket"
string(3) "h2c"
bool(true)
string(6) "closed"
string(19) "server_http2_socket"
string(3) "h2c"
bool(false)
