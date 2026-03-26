--TEST--
King WebSocket connect performs an on-wire client handshake against a real local peer
--FILE--
<?php
require __DIR__ . '/websocket_test_helper.inc';

$server = king_websocket_test_start_server();
$capture = [];

try {
    $url = 'ws://127.0.0.1:' . $server['port'] . '/chat?room=alpha';
    $plain = king_client_websocket_connect($url, [
        'X-Debug' => '1',
        'X-Multi' => ['a', 'b'],
    ]);

    var_dump(is_resource($plain));
    var_dump(get_resource_type($plain));

    var_dump(king_client_websocket_close($plain, 1000, 'done'));
    var_dump(king_client_websocket_get_last_error());
} finally {
    $capture = king_websocket_test_stop_server($server);
}

var_dump($capture[0]['request_line'] === 'GET /chat?room=alpha HTTP/1.1');
var_dump($capture[0]['headers']['Host'][0] === '127.0.0.1:' . $server['port']);
var_dump($capture[0]['headers']['Upgrade'][0] === 'websocket');
var_dump($capture[0]['headers']['Connection'][0] === 'Upgrade');
var_dump($capture[0]['headers']['Sec-WebSocket-Version'][0] === '13');
var_dump($capture[0]['headers']['X-Debug'][0] === '1');
var_dump($capture[0]['headers']['X-Multi'] === ['a', 'b']);
var_dump($capture[0]['frames'][0]['opcode'] === 8);
var_dump($capture[0]['frames'][0]['close_code'] === 1000);
var_dump($capture[0]['frames'][0]['close_reason'] === 'done');
?>
--EXPECTF--
bool(true)
string(14) "King\WebSocket"
bool(true)
string(0) ""
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
