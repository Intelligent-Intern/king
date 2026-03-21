--TEST--
King WebSocket close keeps queued local frames drainable until the queue is empty
--FILE--
<?php
$websocket = king_client_websocket_connect(
    'ws://127.0.0.1/queue',
    null,
    ['max_payload_size' => 5]
);

var_dump(king_client_websocket_send($websocket, 'abcde'));
var_dump(king_websocket_send($websocket, 'z'));
var_dump(king_client_websocket_close($websocket, 1000, 'queued'));
var_dump(king_client_websocket_get_status($websocket));
var_dump(king_client_websocket_receive($websocket, 0));
var_dump(king_client_websocket_receive($websocket, 0));
var_dump(king_client_websocket_receive($websocket, 0));
var_dump(king_client_websocket_get_last_error());
var_dump(king_client_websocket_close($websocket, 1000, 'again'));
var_dump(king_client_websocket_get_last_error());
?>
--EXPECTF--
bool(true)
bool(true)
bool(true)
int(3)
string(5) "abcde"
string(1) "z"
bool(false)
string(%d) "king_client_websocket_receive() cannot run on a closed WebSocket connection."
bool(true)
string(0) ""
