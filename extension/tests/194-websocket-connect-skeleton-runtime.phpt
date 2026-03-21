--TEST--
King WebSocket connect exposes a local validated connection-state resource
--FILE--
<?php
$plain = king_client_websocket_connect('ws://127.0.0.1/chat');
var_dump(is_resource($plain));
var_dump(get_resource_type($plain));

$cfg = new King\Config();

$secure = king_client_websocket_connect(
    'wss://example.test/socket?room=alpha',
    ['X-Debug' => '1'],
    [
        'connection_config' => $cfg,
        'max_payload_size' => 2048,
        'ping_interval_ms' => 9000,
        'handshake_timeout_ms' => 7000,
    ]
);

var_dump(is_resource($secure));
var_dump(get_resource_type($secure));
var_dump(king_client_websocket_get_last_error());
?>
--EXPECT--
bool(true)
string(14) "King\WebSocket"
bool(true)
string(14) "King\WebSocket"
string(0) ""
