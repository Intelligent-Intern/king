--TEST--
King HTTP/1 server-side websocket upgrade keeps an honest in-process local frame runtime in v1
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
        var_dump(king_client_websocket_send($websocket, 'client-upgrade'));
        $clientSendError = king_get_last_error();
        var_dump(king_client_websocket_receive($websocket));
        $receiveOneError = king_get_last_error();
        var_dump(king_client_websocket_receive($websocket));
        $receiveTwoError = king_get_last_error();
        var_dump(king_client_websocket_receive($websocket));
        $receiveEmptyError = king_get_last_error();
        var_dump(king_client_websocket_ping($websocket, 'ok'));
        $pingError = king_get_last_error();
        var_dump(king_client_websocket_close($websocket, 1000, 'done'));
        $closeError = king_get_last_error();
        var_dump(king_client_websocket_get_status($websocket));
        var_dump(king_websocket_send($websocket, 'after-close'));
        $sendAfterCloseError = king_get_last_error();

        $stats = king_get_stats($session);
        $captured = [
            'send_error' => $sendError,
            'client_send_error' => $clientSendError,
            'receive_one_error' => $receiveOneError,
            'receive_two_error' => $receiveTwoError,
            'receive_empty_error' => $receiveEmptyError,
            'ping_error' => $pingError,
            'close_error' => $closeError,
            'send_after_close_error' => $sendAfterCloseError,
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
bool(true)
bool(true)
string(13) "http1-upgrade"
string(14) "client-upgrade"
string(0) ""
bool(true)
bool(true)
int(3)
bool(false)
bool(true)
string(0) ""
array(12) {
  ["send_error"]=>
  string(0) ""
  ["client_send_error"]=>
  string(0) ""
  ["receive_one_error"]=>
  string(0) ""
  ["receive_two_error"]=>
  string(0) ""
  ["receive_empty_error"]=>
  string(0) ""
  ["ping_error"]=>
  string(0) ""
  ["close_error"]=>
  string(0) ""
  ["send_after_close_error"]=>
  string(66) "king_websocket_send() cannot run on a closed WebSocket connection."
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
