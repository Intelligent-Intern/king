--TEST--
King WebSocket Connection object shares the active local WebSocket runtime
--FILE--
<?php
$websocket = new King\WebSocket\Connection(
    'wss://example.test/socket?room=alpha',
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

var_dump(king_client_websocket_receive($websocket, 0));
var_dump(king_client_websocket_receive($websocket, 0));

$websocket->ping('ok');
$websocket->close(1001, 'done');

var_dump(king_client_websocket_get_status($websocket));
var_dump(king_client_websocket_receive($websocket, 0));
var_dump(king_client_websocket_get_last_error());
?>
--EXPECTF--
bool(true)
int(1)
string(%d) "wss://example.test/socket?room=alpha"
string(16) "example.test:443"
string(3) "wss"
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
