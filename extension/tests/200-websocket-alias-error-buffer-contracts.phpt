--TEST--
King WebSocket alias paths and shared error buffer keep a stable socket-backed contract
--FILE--
<?php
require __DIR__ . '/websocket_test_helper.inc';

$server = king_websocket_test_start_server();
$capture = [];

try {
    $websocket = king_client_websocket_connect(
        'ws://127.0.0.1:' . $server['port'] . '/alias',
        null,
        ['max_payload_size' => 2]
    );

    var_dump(king_websocket_send($websocket, 'ok', true));
    var_dump(king_client_websocket_receive($websocket, 500));
    var_dump(king_client_websocket_ping($websocket, str_repeat('a', 125)));
    var_dump(king_client_websocket_receive($websocket, -2));
    var_dump(king_client_websocket_get_last_error());
    var_dump(king_websocket_send($websocket, 'no'));
    var_dump(king_client_websocket_get_last_error());
    var_dump(king_client_websocket_close($websocket));
    var_dump(king_websocket_send($websocket, 'x'));
    var_dump(king_client_websocket_get_last_error());
} finally {
    $capture = king_websocket_test_stop_server($server);
}

var_dump(array_column($capture[0]['frames'], 'opcode') === [2, 9, 1, 8]);
var_dump($capture[0]['frames'][1]['payload'] === str_repeat('a', 125));
?>
--EXPECTF--
bool(true)
string(2) "ok"
bool(true)
bool(false)
string(%d) "king_client_websocket_receive() timeout_ms must be -1 or >= 0."
bool(true)
string(0) ""
bool(true)
bool(false)
string(%d) "king_websocket_send() cannot run on a closed WebSocket connection."
bool(true)
bool(true)
