--TEST--
King server cancel, early-hints, and websocket-upgrade helpers keep local listener WebSocket frame I/O live
--FILE--
<?php
$captured = [];
$session = null;
$GLOBALS['king_server_listener_cancelled_stream'] = null;

var_dump(king_http3_server_listen(
    '127.0.0.1',
    9443,
    null,
    static function (array $request) use (&$captured, &$session): array {
        $session = $request['session'];

        var_dump(king_server_on_cancel(
            $session,
            $request['stream_id'],
            static function (int $streamId): void {
                $GLOBALS['king_server_listener_cancelled_stream'] = $streamId;
            }
        ));
        var_dump(king_server_send_early_hints($session, $request['stream_id'], [
            'Link' => '</style.css>; rel=preload; as=style',
            ['X-Trace', 'edge-1'],
        ]));

        $websocket = king_server_upgrade_to_websocket($session, 4);
        var_dump(is_resource($websocket));
        var_dump(king_websocket_send($websocket, 'h3-push'));
        $sendError = king_get_last_error();
        $payload = king_client_websocket_receive($websocket);
        $receiveError = king_get_last_error();
        $emptyPayload = king_client_websocket_receive($websocket);
        $emptyReceiveError = king_get_last_error();
        var_dump(king_client_websocket_ping($websocket, 'ok'));
        $pingError = king_get_last_error();
        var_dump(king_client_websocket_close($websocket, 1000, 'listener-done'));
        $closeError = king_get_last_error();
        var_dump(king_client_websocket_get_status($websocket));

        var_dump(king_cancel_stream($request['stream_id'], 'both', $session));

        $stats = king_get_stats($session);
        $captured = [
            'payload' => $payload,
            'empty_payload' => $emptyPayload,
            'send_error' => $sendError,
            'receive_error' => $receiveError,
            'empty_receive_error' => $emptyReceiveError,
            'ping_error' => $pingError,
            'close_error' => $closeError,
            'cancelled_stream' => $GLOBALS['king_server_listener_cancelled_stream'],
            'server_last_early_hints' => $stats['server_last_early_hints'],
            'server_last_websocket_url' => $stats['server_last_websocket_url'],
            'server_last_websocket_secure' => $stats['server_last_websocket_secure'],
            'config_websocket_default_max_payload_size' => $stats['config_websocket_default_max_payload_size'],
        ];

        return ['status' => 204, 'body' => ''];
    }
));
var_dump(king_get_last_error());
var_dump($captured);

$stats = king_get_stats($session);
var_dump([
    'state' => $stats['state'],
    'server_cancel_handler_invocations' => $stats['server_cancel_handler_invocations'],
    'server_early_hints_count' => $stats['server_early_hints_count'],
    'server_last_early_hints_stream_id' => $stats['server_last_early_hints_stream_id'],
    'server_websocket_upgrade_count' => $stats['server_websocket_upgrade_count'],
    'server_last_websocket_stream_id' => $stats['server_last_websocket_stream_id'],
]);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(3)
bool(true)
bool(true)
string(0) ""
array(12) {
  ["payload"]=>
  string(7) "h3-push"
  ["empty_payload"]=>
  string(0) ""
  ["send_error"]=>
  string(0) ""
  ["receive_error"]=>
  string(0) ""
  ["empty_receive_error"]=>
  string(0) ""
  ["ping_error"]=>
  string(0) ""
  ["close_error"]=>
  string(0) ""
  ["cancelled_stream"]=>
  int(0)
  ["server_last_early_hints"]=>
  array(2) {
    [0]=>
    array(2) {
      ["name"]=>
      string(4) "Link"
      ["value"]=>
      string(35) "</style.css>; rel=preload; as=style"
    }
    [1]=>
    array(2) {
      ["name"]=>
      string(7) "X-Trace"
      ["value"]=>
      string(6) "edge-1"
    }
  }
  ["server_last_websocket_url"]=>
  string(29) "wss://127.0.0.1:9443/stream/4"
  ["server_last_websocket_secure"]=>
  bool(true)
  ["config_websocket_default_max_payload_size"]=>
  int(16777216)
}
array(6) {
  ["state"]=>
  string(6) "closed"
  ["server_cancel_handler_invocations"]=>
  int(1)
  ["server_early_hints_count"]=>
  int(1)
  ["server_last_early_hints_stream_id"]=>
  int(0)
  ["server_websocket_upgrade_count"]=>
  int(1)
  ["server_last_websocket_stream_id"]=>
  int(4)
}
