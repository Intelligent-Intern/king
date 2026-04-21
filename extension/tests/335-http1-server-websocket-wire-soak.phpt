--TEST--
King HTTP/1 on-wire websocket upgrade stays stable across repeated close and drain cycles
--SKIPIF--
<?php
if (!function_exists('proc_open')) {
    echo "skip proc_open is required for websocket wire soak tests";
}
if (!function_exists('socket_create')) {
    echo "skip socket extension is required for websocket wire soak tests";
}
if (!extension_loaded('king')) {
    echo "skip king extension is required";
}
?>
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

$iterations = 16;
$server = king_server_websocket_wire_start_server('wire', $iterations);
$replies = [];
$finalStatus = null;
$capture = [];

try {
    for ($i = 0; $i < $iterations; $i++) {
        $websocket = king_server_websocket_wire_connect_retry(
            'ws://127.0.0.1:' . $server['port'] . '/chat?room=' . $i
        );

        if (!is_resource($websocket)) {
            throw new RuntimeException('websocket upgrade did not produce a resource');
        }

        if (king_client_websocket_send($websocket, 'alpha-' . $i) !== true) {
            throw new RuntimeException('client send failed at iteration ' . $i . ': ' . king_get_last_error());
        }

        $reply = king_client_websocket_receive($websocket, 1000);
        $afterClose = king_client_websocket_receive($websocket, 1000);
        $afterCloseError = king_get_last_error();
        $finalStatus = king_client_websocket_get_status($websocket);

        if ($reply !== 'beta-' . $i) {
            throw new RuntimeException('unexpected reply payload at iteration ' . $i);
        }
        if ($afterClose !== false) {
            throw new RuntimeException('expected closed websocket to return false at iteration ' . $i);
        }
        if ($afterCloseError !== 'king_client_websocket_receive() cannot run on a closed WebSocket connection.') {
            throw new RuntimeException('unexpected closed receive error at iteration ' . $i . ': ' . $afterCloseError);
        }
        if ($finalStatus !== 3) {
            throw new RuntimeException('expected closed websocket status at iteration ' . $i);
        }

        $replies[] = $reply;
    }
} finally {
    $capture = king_server_websocket_wire_stop_server($server);
}

$expectedReceived = [];
$expectedReplies = [];
for ($i = 0; $i < $iterations; $i++) {
    $expectedReceived[] = 'alpha-' . $i;
    $expectedReplies[] = 'beta-' . $i;
}

var_dump([
    'iterations' => $iterations,
    'reply_count' => count($replies),
    'unique_replies' => count(array_unique($replies)),
    'final_client_status' => $finalStatus,
    'listen_results_count' => count($capture['listen_results']),
    'all_listen_results_true' => count(array_filter($capture['listen_results'])) === $iterations,
    'upgrade_success_count' => $capture['upgrade_success_count'],
    'close_success_count' => $capture['close_success_count'],
    'received_payloads_match' => $capture['received_payloads'] === $expectedReceived,
    'reply_payloads_match' => $capture['reply_payloads'] === $expectedReplies,
    'last_transport_backend' => $capture['stats']['transport_backend'],
    'last_transport_has_socket' => $capture['stats']['transport_has_socket'],
    'last_upgrade_count' => $capture['stats']['server_websocket_upgrade_count'],
]);
?>
--EXPECT--
array(13) {
  ["iterations"]=>
  int(16)
  ["reply_count"]=>
  int(16)
  ["unique_replies"]=>
  int(16)
  ["final_client_status"]=>
  int(3)
  ["listen_results_count"]=>
  int(16)
  ["all_listen_results_true"]=>
  bool(true)
  ["upgrade_success_count"]=>
  int(16)
  ["close_success_count"]=>
  int(16)
  ["received_payloads_match"]=>
  bool(true)
  ["reply_payloads_match"]=>
  bool(true)
  ["last_transport_backend"]=>
  string(19) "server_http1_socket"
  ["last_transport_has_socket"]=>
  bool(true)
  ["last_upgrade_count"]=>
  int(1)
}
