--TEST--
King WebSocket Server accepts real on-wire websocket peers and returns OO connection handles over the shared runtime
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

$server = king_server_websocket_wire_start_server('oo-runtime');
$capture = [];

try {
    $websocket = king_server_websocket_wire_connect_retry(
        'ws://127.0.0.1:' . $server['port'] . '/chat?room=alpha'
    );

    var_dump(is_resource($websocket));
    var_dump(get_resource_type($websocket));
    var_dump(king_client_websocket_send($websocket, 'alpha'));
    var_dump(king_client_websocket_receive($websocket, 1000));
    $afterClose = king_client_websocket_receive($websocket, 1000);
    $afterCloseError = king_get_last_error();
    var_dump($afterClose);
    var_dump($afterCloseError);
    var_dump(king_client_websocket_get_status($websocket));
} finally {
    $capture = king_server_websocket_wire_stop_server($server);
}

var_dump($capture['server_class']);
var_dump($capture['accept_is_object']);
var_dump($capture['accept_class']);
var_dump($capture['accept_exception_class'] ?? '');
var_dump($capture['accept_exception_message'] ?? '');
var_dump($capture['accept_info']['protocol'] ?? '');
var_dump($capture['accept_info']['id'] === 'ws://127.0.0.1:' . $server['port'] . '/chat?room=alpha');
var_dump(count($capture['accept_info']['headers'] ?? []) > 0);
var_dump($capture['received'] ?? '');
var_dump($capture['receive_error'] ?? '');
var_dump($capture['send_ok'] ?? false);
var_dump($capture['send_error'] ?? '');
var_dump($capture['close_ok'] ?? false);
var_dump($capture['close_error'] ?? '');
var_dump($capture['stop_error'] ?? '');
var_dump($capture['accept_after_stop_exception'] ?? '');
var_dump($capture['accept_after_stop_error'] ?? '');
?>
--EXPECTF--
bool(true)
string(14) "King\WebSocket"
bool(true)
string(4) "beta"
bool(false)
string(%d) "king_client_websocket_receive() cannot run on a closed WebSocket connection."
int(3)
string(21) "King\WebSocket\Server"
bool(true)
string(25) "King\WebSocket\Connection"
string(0) ""
string(0) ""
string(2) "ws"
bool(true)
bool(true)
string(5) "alpha"
string(0) ""
bool(true)
string(0) ""
bool(true)
string(0) ""
string(0) ""
string(%d) "King\RuntimeException"
string(%d) "WebSocket\Server::accept() cannot run on a stopped WebSocket server."
