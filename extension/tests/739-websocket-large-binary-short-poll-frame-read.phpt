--TEST--
King WebSocket receive does not abort a large in-progress binary frame on a short poll timeout
--FILE--
<?php
require __DIR__ . '/websocket_fragment_helper.inc';

$server = king_websocket_fragment_start_server('slow_large_binary');

try {
    $websocket = king_client_websocket_connect(
        'ws://127.0.0.1:' . $server['port'] . '/large-binary',
        null,
        ['max_payload_size' => 131072]
    );

    $payload = king_client_websocket_receive($websocket, 15);
    $receiveError = king_client_websocket_get_last_error();
    $close = king_client_websocket_close($websocket, 1000, 'done');
    $closeError = king_client_websocket_get_last_error();
} finally {
    $capture = king_websocket_fragment_stop_server($server);
}

var_dump(is_string($payload));
var_dump(strlen((string) $payload) === 70000);
var_dump(substr((string) $payload, 0, 4) === 'KRT0');
var_dump($receiveError === '');
var_dump($close === true);
var_dump($closeError === '');
var_dump(($capture['client_frames'][0]['opcode'] ?? null) === 8);
var_dump(($capture['client_frames'][0]['close_code'] ?? null) === 1000);
var_dump(($capture['client_frames'][0]['close_reason'] ?? null) === 'done');
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
