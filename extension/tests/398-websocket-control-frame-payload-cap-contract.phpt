--TEST--
King WebSocket runtime rejects oversized control-frame payload lengths before reading or allocating them
--FILE--
<?php
require __DIR__ . '/websocket_violation_helper.inc';

$server = king_websocket_violation_start_server('oversized_ping');

try {
    $websocket = king_client_websocket_connect(
        'ws://127.0.0.1:' . $server['port'] . '/violation?mode=oversized_ping'
    );

    $firstReceive = king_client_websocket_receive($websocket, 1000);
    $firstError = king_client_websocket_get_last_error();
    $statusAfterViolation = king_client_websocket_get_status($websocket);
    $secondReceive = king_client_websocket_receive($websocket, 0);
    $secondError = king_client_websocket_get_last_error();
    $sendAfterViolation = king_client_websocket_send($websocket, 'after');
    $sendError = king_client_websocket_get_last_error();
} finally {
    $capture = king_websocket_violation_stop_server($server);
}

var_dump($firstReceive === false);
var_dump($firstError === 'king_client_websocket_receive() received a control WebSocket frame payload 65536 that exceeds 125 bytes.');
var_dump($statusAfterViolation === 3);
var_dump($secondReceive === false);
var_dump($secondError === 'king_client_websocket_receive() cannot run on a closed WebSocket connection.');
var_dump($sendAfterViolation === false);
var_dump($sendError === 'king_client_websocket_send() cannot run on a closed WebSocket connection.');
var_dump($capture['client_frames'][0]['opcode'] === 8);
var_dump($capture['client_frames'][0]['close_code'] === 1002);
var_dump($capture['client_frames'][0]['close_reason'] === '');
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
