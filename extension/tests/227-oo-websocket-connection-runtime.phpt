--TEST--
King WebSocket Connection object shares the active on-wire WebSocket client runtime
--FILE--
<?php
require __DIR__ . '/websocket_test_helper.inc';

$server = king_websocket_test_start_server();
$capture = [];

try {
    $websocket = new King\WebSocket\Connection(
        'ws://127.0.0.1:' . $server['port'] . '/socket?room=alpha',
        [
            'X-Debug' => '1',
            'X-Multi' => ['a', 'b'],
        ],
        [
            'max_payload_size' => 8,
            'ping_interval_ms' => 9000,
            'handshake_timeout_ms' => 7000,
        ]
    );

    var_dump($websocket instanceof King\WebSocket\Connection);
    var_dump(king_client_websocket_get_status($websocket));

    $info = $websocket->getInfo();
    var_dump($info['id']);
    var_dump($info['remote_addr']);
    var_dump($info['protocol']);
    var_dump($info['headers']);

    var_dump(king_client_websocket_send($websocket, 'alpha'));
    $websocket->sendBinary('01');

    var_dump(king_client_websocket_receive($websocket, 500));
    var_dump(king_client_websocket_receive($websocket, 500));

    $websocket->ping('ok');
    $websocket->close(1001, 'done');

    var_dump(king_client_websocket_get_status($websocket));
    var_dump(king_client_websocket_receive($websocket, 0));
    var_dump(king_client_websocket_get_last_error());
} finally {
    $capture = king_websocket_test_stop_server($server);
}

var_dump($capture[0]['request_line'] === 'GET /socket?room=alpha HTTP/1.1');
var_dump($capture[0]['headers']['X-Debug'][0] === '1');
var_dump($capture[0]['headers']['X-Multi'] === ['a', 'b']);
var_dump(array_column($capture[0]['frames'], 'opcode') === [1, 2, 9, 8]);
?>
--EXPECTF--
bool(true)
int(1)
string(%d) "ws://127.0.0.1:%d/socket?room=alpha"
string(%d) "127.0.0.1:%d"
string(2) "ws"
array(2) {
  ["X-Debug"]=>
  array(1) {
    [0]=>
    string(1) "1"
  }
  ["X-Multi"]=>
  array(2) {
    [0]=>
    string(1) "a"
    [1]=>
    string(1) "b"
  }
}
bool(true)
string(5) "alpha"
string(2) "01"
int(3)
bool(false)
string(%d) "king_client_websocket_receive() cannot run on a closed WebSocket connection."
bool(true)
bool(true)
bool(true)
bool(true)
