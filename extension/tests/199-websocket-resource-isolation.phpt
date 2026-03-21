--TEST--
King WebSocket local runtime keeps multiple resources isolated while sharing the global error buffer
--FILE--
<?php
$left = king_client_websocket_connect('ws://127.0.0.1/left');
$right = king_client_websocket_connect('ws://127.0.0.1/right');

var_dump(is_resource($left));
var_dump(is_resource($right));
var_dump(king_client_websocket_send($left, 'left'));
var_dump(king_client_websocket_send($right, 'right'));
var_dump(king_client_websocket_receive($right, 0));
var_dump(king_client_websocket_get_status($left));
var_dump(king_client_websocket_close($left, 1001, 'done'));
var_dump(king_client_websocket_get_status($left));
var_dump(king_client_websocket_get_status($right));
var_dump(king_client_websocket_receive($left, 0));
var_dump(king_client_websocket_receive($left, 0));
var_dump(king_client_websocket_send($right, 'again'));
var_dump(king_client_websocket_receive($right, 0));
var_dump(king_client_websocket_get_last_error());
?>
--EXPECTF--
bool(true)
bool(true)
bool(true)
bool(true)
string(5) "right"
int(1)
bool(true)
int(3)
int(1)
string(4) "left"
bool(false)
bool(true)
string(5) "again"
string(0) ""
