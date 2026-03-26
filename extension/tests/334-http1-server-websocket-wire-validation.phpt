--TEST--
King HTTP/1 one-shot listener refuses fake local websocket upgrades on non-upgrade wire requests
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

$server = king_server_websocket_wire_start_server('plain');
$capture = [];

try {
    $response = king_server_http1_wire_request_retry(
        $server['port'],
        "GET /plain HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n"
    );

    var_dump(strpos($response, "HTTP/1.1 426") === 0);
    var_dump(str_contains($response, "X-Mode: plain-http1\r\n"));
    var_dump(str_ends_with($response, "upgrade-required"));
} finally {
    $capture = king_server_websocket_wire_stop_server($server);
}

var_dump($capture['listen_result']);
var_dump($capture['listen_error']);
var_dump($capture['upgrade_result']);
var_dump($capture['upgrade_error']);
var_dump($capture['request_uri']);
var_dump($capture['stats']['transport_backend']);
var_dump($capture['stats']['transport_has_socket']);
?>
--EXPECTF--
bool(true)
bool(true)
bool(true)
bool(true)
string(0) ""
bool(false)
string(%d) "king_server_upgrade_to_websocket() requires an active HTTP/1 websocket upgrade request on on-wire server sessions."
string(6) "/plain"
string(19) "server_http1_socket"
bool(true)
