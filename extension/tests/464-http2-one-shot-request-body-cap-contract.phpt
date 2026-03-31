--TEST--
King HTTP/2 one-shot listener rejects cumulative request bodies above the active one-shot limit
--FILE--
<?php
require __DIR__ . '/http2_server_wire_helper.inc';

$server = king_http2_server_wire_start_server();
$clientError = null;
$capture = [];

try {
    try {
        king_http2_server_wire_request_retry($server['port'], [
            'method' => 'POST',
            'path' => '/wire?room=oversized',
            'headers' => [
                'x-mode' => 'oversized-h2c-body',
            ],
            'body_frames' => [
                str_repeat('a', 600000),
                str_repeat('b', 500000),
            ],
        ]);
    } catch (RuntimeException $e) {
        $clientError = $e->getMessage();
    }
} finally {
    $capture = king_http2_server_wire_stop_server($server);
}

var_dump($clientError !== null);
var_dump($capture['listen_result']);
var_dump($capture['listen_error']);
var_dump(!isset($capture['request']));
?>
--EXPECT--
bool(true)
bool(false)
string(103) "king_http2_server_listen_once() received an HTTP/2 request body that exceeds the active one-shot limit."
bool(true)
