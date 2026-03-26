--TEST--
King WebSocket runtime keeps multiple socket-backed resources isolated while sharing the global error buffer
--FILE--
<?php
require __DIR__ . '/websocket_test_helper.inc';

$leftServer = king_websocket_test_start_server();
$rightServer = king_websocket_test_start_server();

try {
    $left = king_client_websocket_connect('ws://127.0.0.1:' . $leftServer['port'] . '/left');
    $right = king_client_websocket_connect('ws://127.0.0.1:' . $rightServer['port'] . '/right');

    var_dump(is_resource($left));
    var_dump(is_resource($right));
    var_dump(king_client_websocket_send($left, 'left'));
    var_dump(king_client_websocket_send($right, 'right'));
    var_dump(king_client_websocket_receive($right, 500));
    var_dump(king_client_websocket_get_status($left));

    usleep(100000);
    var_dump(king_client_websocket_close($left, 1001, 'done'));
    var_dump(king_client_websocket_get_status($left));
    var_dump(king_client_websocket_get_status($right));
    var_dump(king_client_websocket_receive($left, 0));
    var_dump(king_client_websocket_receive($left, 0));
    var_dump(king_client_websocket_send($right, 'again'));
    var_dump(king_client_websocket_receive($right, 500));
    var_dump(king_client_websocket_get_last_error());
    var_dump(king_client_websocket_close($right, 1000, 'done'));
} finally {
    king_websocket_test_stop_server($leftServer);
    king_websocket_test_stop_server($rightServer);
}
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
bool(true)
