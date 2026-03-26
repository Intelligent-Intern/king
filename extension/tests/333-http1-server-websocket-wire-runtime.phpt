--TEST--
King HTTP/1 one-shot listener upgrades websocket requests on wire and preserves handler-owned frame flow
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

$server = king_server_websocket_wire_start_server('wire');
$capture = [];

try {
    $websocket = king_server_websocket_wire_connect_retry(
        'ws://127.0.0.1:' . $server['port'] . '/chat?room=alpha'
    );

    var_dump(is_resource($websocket));
    var_dump(get_resource_type($websocket));
    var_dump(king_client_websocket_get_status($websocket));
    var_dump(king_client_websocket_send($websocket, 'alpha'));
    var_dump(king_client_websocket_receive($websocket, 1000));
    $afterClose = king_client_websocket_receive($websocket, 1000);
    $afterCloseError = king_get_last_error();
    var_dump($afterClose);
    var_dump($afterCloseError);
    var_dump(king_client_websocket_get_status($websocket));
} finally {
    $capture = king_server_websocket_wire_stop_server($server);
}

var_dump($capture['listen_result']);
var_dump($capture['listen_error']);
var_dump($capture['upgrade_is_resource']);
var_dump($capture['upgrade_error']);
var_dump($capture['upgrade_status_before']);
var_dump($capture['received']);
var_dump($capture['send_ok']);
var_dump($capture['send_error']);
var_dump($capture['close_ok']);
var_dump($capture['close_error']);
var_dump($capture['upgrade_status_after']);
var_dump($capture['request_uri']);
var_dump($capture['stats']['transport_backend']);
var_dump($capture['stats']['transport_has_socket']);
var_dump($capture['stats']['server_last_websocket_url'] === 'ws://127.0.0.1:' . $server['port'] . '/chat?room=alpha');
var_dump($capture['stats']['server_websocket_upgrade_count']);
var_dump($capture['stats']['server_last_websocket_stream_id']);
?>
--EXPECTF--
bool(true)
string(14) "King\WebSocket"
int(1)
bool(true)
string(4) "beta"
bool(false)
string(%d) "king_client_websocket_receive() cannot run on a closed WebSocket connection."
int(3)
bool(true)
string(0) ""
bool(true)
string(0) ""
int(1)
string(5) "alpha"
bool(true)
string(0) ""
bool(true)
string(0) ""
int(3)
string(16) "/chat?room=alpha"
string(19) "server_http1_socket"
bool(true)
bool(true)
int(1)
int(0)
