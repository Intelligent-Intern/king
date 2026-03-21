--TEST--
King WebSocket frames expose a local send receive ping status and close runtime
--FILE--
<?php
$websocket = king_client_websocket_connect(
    'ws://127.0.0.1/chat',
    null,
    ['max_payload_size' => 8]
);

var_dump(is_resource($websocket));
var_dump(king_client_websocket_get_status($websocket));
var_dump(king_client_websocket_send($websocket, 'alpha'));
var_dump(king_websocket_send($websocket, '01', true));
var_dump(king_client_websocket_receive($websocket, 0));
var_dump(king_client_websocket_receive($websocket, 0));
var_dump(king_client_websocket_receive($websocket, 0));
var_dump(king_client_websocket_ping($websocket, 'ok'));
var_dump(king_client_websocket_close($websocket, 1001, 'done'));
var_dump(king_client_websocket_get_status($websocket));
var_dump(king_client_websocket_receive($websocket, 0));
var_dump(king_client_websocket_get_last_error());
var_dump(king_client_websocket_close($websocket, 1000, 'again'));
var_dump(king_client_websocket_get_last_error());
?>
--EXPECTF--
bool(true)
int(1)
bool(true)
bool(true)
string(5) "alpha"
string(2) "01"
string(0) ""
bool(true)
bool(true)
int(3)
bool(false)
string(%d) "king_client_websocket_receive() cannot run on a closed WebSocket connection."
bool(true)
string(0) ""
