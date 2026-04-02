--TEST--
King on-wire websocket server sessions drain queued shutdown frames, reject new work after close, and release runtime ownership cleanly
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

$server = king_server_websocket_wire_start_server('shutdown-drain');
$capture = [];

try {
    $websocket = king_server_websocket_wire_connect_retry(
        'ws://127.0.0.1:' . $server['port'] . '/chat?room=shutdown'
    );

    if (!is_resource($websocket)) {
        throw new RuntimeException('websocket upgrade did not produce a resource');
    }

    var_dump(king_client_websocket_send($websocket, 'alpha'));
    var_dump(king_client_websocket_receive($websocket, 1000));
    var_dump(king_client_websocket_receive($websocket, 1000));

    $afterClose = king_client_websocket_receive($websocket, 1000);
    $afterCloseError = king_get_last_error();
    var_dump($afterClose);
    var_dump($afterCloseError);

    $sendAfterClose = king_client_websocket_send($websocket, 'after-close');
    $sendAfterCloseError = king_get_last_error();
    var_dump($sendAfterClose);
    var_dump($sendAfterCloseError);

    $pingAfterClose = king_client_websocket_ping($websocket, 'p');
    $pingAfterCloseError = king_get_last_error();
    var_dump($pingAfterClose);
    var_dump($pingAfterCloseError);

    var_dump(king_client_websocket_get_status($websocket));
} finally {
    $capture = king_server_websocket_wire_stop_server($server);
}

var_dump($capture['listen_result']);
var_dump($capture['listen_error']);
var_dump($capture['upgrade_is_resource']);
var_dump($capture['received']);
var_dump($capture['send_ok']);
var_dump($capture['send_error']);
var_dump($capture['shutdown_extra_send_ok']);
var_dump($capture['shutdown_extra_send_error']);
var_dump($capture['close_ok']);
var_dump($capture['close_error']);
var_dump($capture['send_after_close_ok']);
var_dump($capture['send_after_close_error']);
var_dump($capture['ping_after_close_ok']);
var_dump($capture['ping_after_close_error']);
var_dump($capture['upgrade_status_after']);
var_dump($capture['stats']['transport_backend']);
var_dump($capture['stats']['transport_has_socket']);
var_dump($capture['stats']['server_websocket_upgrade_count']);
var_dump($capture['post_stats']['state']);
var_dump($capture['post_stats']['transport_backend']);
var_dump($capture['post_stats']['transport_has_socket']);
var_dump($capture['post_stats']['server_websocket_upgrade_count']);
?>
--EXPECTF--
bool(true)
string(4) "beta"
string(5) "gamma"
bool(false)
string(%d) "king_client_websocket_receive() cannot run on a closed WebSocket connection."
bool(false)
string(%d) "king_client_websocket_send() cannot run on a closed WebSocket connection."
bool(false)
string(%d) "king_client_websocket_ping() cannot run on a closed WebSocket connection."
int(3)
bool(true)
string(0) ""
bool(true)
string(5) "alpha"
bool(true)
string(0) ""
bool(true)
string(0) ""
bool(true)
string(0) ""
bool(false)
string(%d) "king_websocket_send() cannot run on a closed WebSocket connection."
bool(false)
string(%d) "king_client_websocket_ping() cannot run on a closed WebSocket connection."
int(3)
string(19) "server_http1_socket"
bool(true)
int(1)
string(6) "closed"
string(19) "server_http1_socket"
bool(false)
int(1)
