--TEST--
King WebSocket frames validate resource handles payload limits and close semantics
--FILE--
<?php
$typeErrors = [];

foreach ([
    'send' => static fn() => king_client_websocket_send(fopen('php://memory', 'r'), 'ping'),
    'receive' => static fn() => king_client_websocket_receive(fopen('php://memory', 'r')),
    'status' => static fn() => king_client_websocket_get_status(fopen('php://memory', 'r')),
    'close' => static fn() => king_client_websocket_close(fopen('php://memory', 'r')),
] as $name => $callable) {
    try {
        $callable();
        $typeErrors[$name] = 'no-exception';
    } catch (Throwable $e) {
        $typeErrors[$name] = get_class($e);
    }
}

var_export($typeErrors);
echo "\n";

$websocket = king_client_websocket_connect(
    'ws://127.0.0.1/chat',
    null,
    ['max_payload_size' => 4]
);

var_dump(king_client_websocket_send($websocket, 'hello'));
var_dump(king_client_websocket_get_last_error());

var_dump(king_client_websocket_ping($websocket, str_repeat('a', 126)));
var_dump(king_client_websocket_get_last_error());

var_dump(king_client_websocket_receive($websocket, -2));
var_dump(king_client_websocket_get_last_error());

var_dump(king_client_websocket_close($websocket, 999));
var_dump(king_client_websocket_get_last_error());

var_dump(king_client_websocket_close($websocket, 1000, str_repeat('b', 124)));
var_dump(king_client_websocket_get_last_error());

var_dump(king_client_websocket_close($websocket, 1000, 'done'));
var_dump(king_client_websocket_send($websocket, 'ok'));
var_dump(king_client_websocket_get_last_error());

var_dump(king_client_websocket_ping($websocket, 'ok'));
var_dump(king_client_websocket_get_last_error());
?>
--EXPECTF--
array (
  'send' => 'TypeError',
  'receive' => 'TypeError',
  'status' => 'TypeError',
  'close' => 'TypeError',
)
bool(false)
string(%d) "king_client_websocket_send() payload size 5 exceeds max_payload_size 4."
bool(false)
string(%d) "king_client_websocket_ping() ping payload cannot exceed 125 bytes."
bool(false)
string(%d) "king_client_websocket_receive() timeout_ms must be -1 or >= 0."
bool(false)
string(%d) "king_client_websocket_close() close status code must be between 1000 and 4999."
bool(false)
string(%d) "king_client_websocket_close() close reason cannot exceed 123 bytes."
bool(true)
bool(false)
string(%d) "king_client_websocket_send() cannot run on a closed WebSocket connection."
bool(false)
string(%d) "king_client_websocket_ping() cannot run on a closed WebSocket connection."
