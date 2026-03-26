--TEST--
King WebSocket close keeps already echoed frames drainable until the queue is empty
--FILE--
<?php
require __DIR__ . '/websocket_test_helper.inc';

$server = king_websocket_test_start_server();
$capture = [];

try {
    $websocket = king_client_websocket_connect(
        'ws://127.0.0.1:' . $server['port'] . '/queue',
        null,
        ['max_payload_size' => 5]
    );

    var_dump(king_client_websocket_send($websocket, 'abcde'));
    var_dump(king_websocket_send($websocket, 'z'));
    usleep(100000);
    var_dump(king_client_websocket_close($websocket, 1000, 'queued'));
    var_dump(king_client_websocket_get_status($websocket));
    var_dump(king_client_websocket_receive($websocket, 0));
    var_dump(king_client_websocket_receive($websocket, 0));
    var_dump(king_client_websocket_receive($websocket, 0));
    var_dump(king_client_websocket_get_last_error());
    var_dump(king_client_websocket_close($websocket, 1000, 'again'));
    var_dump(king_client_websocket_get_last_error());
} finally {
    $capture = king_websocket_test_stop_server($server);
}

var_dump(array_column($capture[0]['frames'], 'opcode') === [1, 1, 8]);
var_dump($capture[0]['frames'][2]['close_reason'] === 'queued');
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
bool(true)
bool(true)
