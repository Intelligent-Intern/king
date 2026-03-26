--TEST--
King HTTP/1 server-side websocket upgrade stays an honest local-only marker slice in v1
--FILE--
<?php
$captured = [];
$session = null;

var_dump(king_http1_server_listen(
    '127.0.0.1',
    8080,
    null,
    static function (array $request) use (&$captured, &$session): array {
        $session = $request['session'];

        $websocket = king_server_upgrade_to_websocket($session, $request['stream_id']);
        var_dump(is_resource($websocket));
        var_dump(king_client_websocket_get_status($websocket));
        var_dump(king_websocket_send($websocket, 'http1-upgrade'));
        $sendError = king_get_last_error();
        var_dump(king_client_websocket_receive($websocket));
        $receiveError = king_get_last_error();
        var_dump(king_client_websocket_ping($websocket, 'ok'));
        $pingError = king_get_last_error();
        var_dump(king_client_websocket_close($websocket, 1000, 'done'));
        var_dump(king_get_last_error());

        $stats = king_get_stats($session);
        $captured = [
            'send_error' => $sendError,
            'receive_error' => $receiveError,
            'ping_error' => $pingError,
            'server_last_websocket_url' => $stats['server_last_websocket_url'],
            'server_last_websocket_secure' => $stats['server_last_websocket_secure'],
            'server_websocket_upgrade_count' => $stats['server_websocket_upgrade_count'],
            'server_last_websocket_stream_id' => $stats['server_last_websocket_stream_id'],
        ];

        return ['status' => 204, 'body' => ''];
    }
));
var_dump(king_get_last_error());
var_dump($captured);

$stats = king_get_stats($session);
var_dump([
    'state' => $stats['state'],
    'transport_backend' => $stats['transport_backend'],
    'server_websocket_upgrade_count' => $stats['server_websocket_upgrade_count'],
]);
?>
--EXPECT--
bool(true)
int(1)
bool(false)
bool(false)
bool(false)
bool(true)
string(0) ""
bool(true)
string(0) ""
array(7) {
  ["send_error"]=>
  string(97) "king_websocket_send() cannot exchange frames on a local-only server-side WebSocket upgrade in v1."
  ["receive_error"]=>
  string(107) "king_client_websocket_receive() cannot exchange frames on a local-only server-side WebSocket upgrade in v1."
  ["ping_error"]=>
  string(104) "king_client_websocket_ping() cannot exchange frames on a local-only server-side WebSocket upgrade in v1."
  ["server_last_websocket_url"]=>
  string(28) "ws://127.0.0.1:8080/stream/0"
  ["server_last_websocket_secure"]=>
  bool(false)
  ["server_websocket_upgrade_count"]=>
  int(1)
  ["server_last_websocket_stream_id"]=>
  int(0)
}
array(3) {
  ["state"]=>
  string(6) "closed"
  ["transport_backend"]=>
  string(18) "server_http1_local"
  ["server_websocket_upgrade_count"]=>
  int(1)
}
