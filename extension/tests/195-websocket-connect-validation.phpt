--TEST--
King WebSocket connect validates URLs, config handles, and positive options
--FILE--
<?php
var_dump(king_client_websocket_connect('http://example.test/socket'));
var_dump(king_client_websocket_get_last_error());

var_dump(king_client_websocket_connect('/socket'));
var_dump(king_client_websocket_get_last_error());

var_dump(king_client_websocket_connect('wss://user:pass@example.test/socket'));
var_dump(king_client_websocket_get_last_error());

var_dump(king_client_websocket_connect(
    'ws://example.test/socket',
    null,
    ['connection_config' => 'invalid']
));
var_dump(king_client_websocket_get_last_error());

var_dump(king_client_websocket_connect(
    'ws://example.test/socket',
    null,
    ['max_payload_size' => '2048']
));
var_dump(king_client_websocket_get_last_error());

var_dump(king_client_websocket_connect(
    'ws://example.test/socket',
    null,
    ['handshake_timeout_ms' => 0]
));
var_dump(king_client_websocket_get_last_error());
?>
--EXPECTF--
bool(false)
string(%d) "king_client_websocket_connect() currently supports only absolute ws:// and wss:// URLs."
bool(false)
string(%d) "king_client_websocket_connect() requires a valid absolute ws:// or wss:// URL."
bool(false)
string(%d) "king_client_websocket_connect() does not support embedding credentials in the connection URL."
bool(false)
string(%d) "king_client_websocket_connect() option 'connection_config' must be a King\Config resource or object."
bool(false)
string(%d) "king_client_websocket_connect() option 'max_payload_size' must be provided as an integer."
bool(false)
string(%d) "king_client_websocket_connect() option 'handshake_timeout_ms' must be > 0."
