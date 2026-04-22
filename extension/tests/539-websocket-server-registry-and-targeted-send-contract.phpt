--TEST--
King WebSocket Server keeps a live connection registry and targets frames to one accepted peer without colliding same-URL connections
--SKIPIF--
<?php
if (!function_exists('proc_open')) {
    echo "skip proc_open required";
}
if (!extension_loaded('pcntl')) {
    echo "skip pcntl extension required for websocket server tests";
}
?>
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

$server = king_server_websocket_wire_start_server('oo-registry');
$capture = [];
$url = 'ws://127.0.0.1:' . $server['port'] . '/chat?room=alpha';

try {
    $first = king_server_websocket_wire_connect_retry($url);
    $second = king_server_websocket_wire_connect_retry($url);

    var_dump(is_resource($first));
    var_dump(is_resource($second));
    var_dump(king_client_websocket_receive($first, 1000));
    var_dump(king_client_websocket_receive($second, 1000));

    $firstAfterClose = king_client_websocket_receive($first, 1000);
    $firstAfterCloseError = king_get_last_error();
    $secondAfterClose = king_client_websocket_receive($second, 1000);
    $secondAfterCloseError = king_get_last_error();

    var_dump($firstAfterClose);
    var_dump($firstAfterCloseError);
    var_dump($secondAfterClose);
    var_dump($secondAfterCloseError);
    var_dump(king_client_websocket_get_status($first));
    var_dump(king_client_websocket_get_status($second));
} finally {
    $capture = king_server_websocket_wire_stop_server($server);
}

var_dump($capture['server_class']);
var_dump($capture['first_class'] ?? '');
var_dump($capture['second_class'] ?? '');
var_dump($capture['registry_exception_class'] ?? '');
var_dump($capture['registry_exception_message'] ?? '');
var_dump($capture['registry_count_after_accept'] ?? -1);
var_dump(count($capture['registry_ids'] ?? []) === 2);
var_dump(($capture['registry_ids'][0] ?? '') !== ($capture['registry_ids'][1] ?? ''));
var_dump(($capture['first_info']['connection_id'] ?? '') === ($capture['registry_ids'][0] ?? ''));
var_dump(($capture['second_info']['connection_id'] ?? '') === ($capture['registry_ids'][1] ?? ''));
var_dump(($capture['first_info']['id'] ?? '') === $url);
var_dump(($capture['second_info']['id'] ?? '') === $url);
var_dump(($capture['registry_after_accept'][$capture['first_info']['connection_id']]['id'] ?? '') === $url);
var_dump(($capture['registry_after_accept'][$capture['second_info']['connection_id']]['connection_id'] ?? '') === ($capture['second_info']['connection_id'] ?? ''));
var_dump($capture['send_first_ok'] ?? false);
var_dump($capture['send_first_error'] ?? '');
var_dump($capture['send_second_ok'] ?? false);
var_dump($capture['send_second_error'] ?? '');
var_dump($capture['missing_send_exception'] ?? '');
var_dump(str_contains($capture['missing_send_error'] ?? '', 'missing-connection'));
var_dump($capture['registry_count_after_first_close'] ?? -1);
var_dump(!isset($capture['registry_after_first_close'][$capture['first_info']['connection_id']]));
var_dump(($capture['registry_after_first_close'][$capture['second_info']['connection_id']]['connection_id'] ?? '') === ($capture['second_info']['connection_id'] ?? ''));
var_dump($capture['registry_count_after_second_close'] ?? -1);
?>
--EXPECTF--
bool(true)
bool(true)
string(8) "to-first"
string(9) "to-second"
bool(false)
string(%d) "king_client_websocket_receive() cannot run on a closed WebSocket connection."
bool(false)
string(%d) "king_client_websocket_receive() cannot run on a closed WebSocket connection."
int(3)
int(3)
string(21) "King\WebSocket\Server"
string(25) "King\WebSocket\Connection"
string(25) "King\WebSocket\Connection"
string(0) ""
string(0) ""
int(2)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(0) ""
bool(true)
string(0) ""
string(%d) "King\RuntimeException"
bool(true)
int(1)
bool(true)
bool(true)
int(0)
