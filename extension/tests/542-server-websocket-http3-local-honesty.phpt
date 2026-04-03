--TEST--
King HTTP/3 server-side websocket upgrade keeps an honest in-process local frame runtime in v1
--FILE--
<?php
$captured = [];
$session = null;

var_dump(king_http3_server_listen(
    '127.0.0.1',
    9443,
    null,
    static function (array $request) use (&$captured, &$session): array {
        $session = $request['session'];

        $websocket = king_server_upgrade_to_websocket($session, $request['stream_id']);
        var_dump(is_resource($websocket));
        var_dump(king_client_websocket_get_status($websocket));
        var_dump(king_websocket_send($websocket, 'http3-upgrade'));
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
            'request_stream_id' => $request['stream_id'],
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
            'transport_backend' => $stats['transport_backend'],
            'transport_socket_family' => $stats['transport_socket_family'],
            'alpn' => $stats['alpn'],
        ];

        return ['status' => 204, 'body' => ''];
    }
));
var_dump(king_get_last_error());
var_dump($captured);

$stats = king_get_stats($session);
var_dump([
    'state' => $stats['state'],
    'alpn' => $stats['alpn'],
    'transport_backend' => $stats['transport_backend'],
    'transport_socket_family' => $stats['transport_socket_family'],
    'server_websocket_upgrade_count' => $stats['server_websocket_upgrade_count'],
]);
?>
--EXPECT--
bool(true)
int(1)
bool(true)
bool(true)
string(13) "http3-upgrade"
string(14) "client-upgrade"
string(0) ""
bool(true)
bool(true)
int(3)
bool(false)
bool(true)
string(0) ""
array(16) {
  ["request_stream_id"]=>
  int(0)
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
  string(29) "wss://127.0.0.1:9443/stream/0"
  ["server_last_websocket_secure"]=>
  bool(true)
  ["server_websocket_upgrade_count"]=>
  int(1)
  ["server_last_websocket_stream_id"]=>
  int(0)
  ["transport_backend"]=>
  string(18) "server_http3_local"
  ["transport_socket_family"]=>
  string(3) "udp"
  ["alpn"]=>
  string(2) "h3"
}
array(5) {
  ["state"]=>
  string(6) "closed"
  ["alpn"]=>
  string(2) "h3"
  ["transport_backend"]=>
  string(18) "server_http3_local"
  ["transport_socket_family"]=>
  string(3) "udp"
  ["server_websocket_upgrade_count"]=>
  int(1)
}
