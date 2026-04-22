--TEST--
King WebSocket Server broadcasts across live accepted peers and stop() drains a real shutdown handshake over those same connections
--SKIPIF--
<?php
if (!extension_loaded('pcntl')) {
    echo "skip pcntl extension required for websocket server tests";
}
?>
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

$server = king_server_websocket_wire_start_server('oo-broadcast-shutdown', 1, 40540);
$capture = [];
$url = 'ws://127.0.0.1:' . $server['port'] . '/chat?room=broadcast';

try {
    $first = king_server_websocket_wire_connect_retry($url);
    $second = king_server_websocket_wire_connect_retry($url);

    var_dump(is_resource($first));
    var_dump(is_resource($second));
    var_dump(king_client_websocket_receive($first, 1000));
    var_dump(king_client_websocket_receive($second, 1000));
    var_dump(king_client_websocket_receive($first, 1000));
    var_dump(king_client_websocket_receive($second, 1000));

    $firstAfterStop = king_client_websocket_receive($first, 1000);
    $firstAfterStopError = king_get_last_error();
    $secondAfterStop = king_client_websocket_receive($second, 1000);
    $secondAfterStopError = king_get_last_error();

    var_dump($firstAfterStop);
    var_dump($firstAfterStopError);
    var_dump($secondAfterStop);
    var_dump($secondAfterStopError);
    var_dump(king_client_websocket_get_status($first));
    var_dump(king_client_websocket_get_status($second));
} finally {
    $capture = king_server_websocket_wire_stop_server($server);
}

var_dump($capture['server_class'] ?? '');
var_dump($capture['broadcast_exception_class'] ?? '');
var_dump($capture['broadcast_exception_message'] ?? '');
var_dump($capture['registry_count_before_broadcast'] ?? -1);
var_dump(($capture['first_info']['id'] ?? '') === $url);
var_dump(($capture['second_info']['id'] ?? '') === $url);
var_dump(($capture['first_info']['connection_id'] ?? '') !== ($capture['second_info']['connection_id'] ?? ''));
var_dump($capture['broadcast_ok'] ?? false);
var_dump($capture['broadcast_error'] ?? '');
var_dump($capture['broadcast_binary_ok'] ?? false);
var_dump($capture['broadcast_binary_error'] ?? '');
var_dump($capture['broadcast_batch_ok'] ?? false);
var_dump($capture['broadcast_batch_error'] ?? '');
var_dump($capture['stop_error'] ?? '');
var_dump($capture['registry_count_after_stop'] ?? -1);
var_dump(isset($capture['registry_after_stop'][$capture['first_info']['connection_id']]));
var_dump($capture['broadcast_after_stop_exception'] ?? '');
var_dump($capture['broadcast_after_stop_error'] ?? '');
var_dump($capture['send_after_stop_exception'] ?? '');
var_dump($capture['send_after_stop_error'] ?? '');
?>
--EXPECTF--
bool(true)
bool(true)
string(11) "fanout-text"
string(11) "fanout-text"
string(13) "fanout-binary"
string(13) "fanout-binary"
bool(false)
string(%d) "king_client_websocket_receive() cannot run on a closed WebSocket connection."
bool(false)
string(%d) "king_client_websocket_receive() cannot run on a closed WebSocket connection."
int(3)
int(3)
string(21) "King\WebSocket\Server"
string(0) ""
string(0) ""
int(2)
bool(true)
bool(true)
bool(true)
bool(true)
string(0) ""
bool(true)
string(0) ""
string(0) ""
int(0)
bool(false)
string(%d) "King\RuntimeException"
string(%d) "WebSocket\Server::broadcast() cannot run on a stopped WebSocket server."
string(%d) "King\RuntimeException"
string(%d) "WebSocket\Server::send() cannot run on a stopped WebSocket server."
