--TEST--
King HTTP/1 one-shot on-wire listener times out stalled accept and slowloris head paths instead of blocking forever
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

$server = king_server_websocket_wire_start_server('slowloris-accept');
$acceptCapture = king_server_websocket_wire_stop_server($server);

var_dump($acceptCapture['listen_result']);
var_dump($acceptCapture['listen_error']);
var_dump(!($acceptCapture['handler_called'] ?? false));

$server = king_server_websocket_wire_start_server('slowloris-head');
$headClient = king_server_http1_wire_connect_retry($server['port']);
stream_set_write_buffer($headClient, 0);
fwrite($headClient, "GET /slow HTTP/1.1\r\nHost: 127.0.0.1\r\n");
$headCapture = king_server_websocket_wire_stop_server($server);
fclose($headClient);

var_dump($headCapture['listen_result']);
var_dump($headCapture['listen_error']);
var_dump(!($headCapture['handler_called'] ?? false));
?>
--EXPECT--
bool(false)
string(84) "king_http1_server_listen_once() timed out while waiting for the HTTP/1 accept phase."
bool(true)
bool(false)
string(90) "king_http1_server_listen_once() timed out while waiting for the HTTP/1 request head phase."
bool(true)
