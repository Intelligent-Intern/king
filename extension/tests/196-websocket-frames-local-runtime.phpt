--TEST--
King WebSocket frames flow over a real local socket for send receive ping status and close
--FILE--
<?php
require __DIR__ . '/websocket_test_helper.inc';

$server = king_websocket_test_start_server();
$capture = [];

try {
    $websocket = king_client_websocket_connect(
        'ws://127.0.0.1:' . $server['port'] . '/chat',
        null,
        ['max_payload_size' => 8]
    );

    var_dump(is_resource($websocket));
    var_dump(king_client_websocket_get_status($websocket));
    var_dump(king_client_websocket_send($websocket, 'alpha'));
    var_dump(king_websocket_send($websocket, '01', true));
    var_dump(king_client_websocket_receive($websocket, 500));
    var_dump(king_client_websocket_receive($websocket, 500));
    var_dump(king_client_websocket_receive($websocket, 0));
    var_dump(king_client_websocket_ping($websocket, 'ok'));
    var_dump(king_client_websocket_close($websocket, 1001, 'done'));
    var_dump(king_client_websocket_get_status($websocket));
    var_dump(king_client_websocket_receive($websocket, 0));
    var_dump(king_client_websocket_get_last_error());
    var_dump(king_client_websocket_close($websocket, 1000, 'again'));
    var_dump(king_client_websocket_get_last_error());
} finally {
    $capture = king_websocket_test_stop_server($server);
}

var_dump(array_column($capture[0]['frames'], 'opcode') === [1, 2, 9, 8]);
var_dump($capture[0]['frames'][0]['payload'] === 'alpha');
var_dump($capture[0]['frames'][1]['payload'] === '01');
var_dump($capture[0]['frames'][2]['payload'] === 'ok');
var_dump($capture[0]['frames'][3]['close_code'] === 1001);
var_dump($capture[0]['frames'][3]['close_reason'] === 'done');
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
