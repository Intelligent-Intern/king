--TEST--
King WebSocket runtime reassembles fragmented data frames and handles interleaved control frames
--FILE--
<?php
require __DIR__ . '/websocket_fragment_helper.inc';

$server = king_websocket_fragment_start_server('interleaved_ping');

try {
    $websocket = king_client_websocket_connect(
        'ws://127.0.0.1:' . $server['port'] . '/fragment',
        null,
        ['max_payload_size' => 16]
    );

    $text = king_client_websocket_receive($websocket, 1000);
    $binary = king_client_websocket_receive($websocket, 1000);
    $close = king_client_websocket_close($websocket, 1000, 'done');
    $error = king_client_websocket_get_last_error();
} finally {
    $capture = king_websocket_fragment_stop_server($server);
}

var_dump($text === 'hello');
var_dump($binary === "a\0b");
var_dump($close === true);
var_dump($error === '');
var_dump(($capture['client_frames'][0]['opcode'] ?? null) === 10);
var_dump(($capture['client_frames'][0]['payload'] ?? null) === 'ok');
var_dump(($capture['client_frames'][1]['opcode'] ?? null) === 8);
var_dump(($capture['client_frames'][1]['close_code'] ?? null) === 1000);
var_dump(($capture['client_frames'][1]['close_reason'] ?? null) === 'done');
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
