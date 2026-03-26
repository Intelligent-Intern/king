--TEST--
King server control flows stay stable across repeated listener-owned sessions
--FILE--
<?php
$iterations = 24;
$summary = [
    'iterations' => $iterations,
    'listener_successes' => 0,
    'early_hints_successes' => 0,
    'upgrade_successes' => 0,
    'websocket_close_successes' => 0,
    'cancel_invocations' => 0,
    'admin_listens' => 0,
    'admin_reloads' => 0,
    'tls_applies' => 0,
    'tls_reloads' => 0,
    'closed_sessions' => 0,
];

for ($i = 0; $i < $iterations; $i++) {
    $captured = [];
    $session = null;

    $result = king_http3_server_listen(
        '127.0.0.1',
        9443 + $i,
        null,
        function (array $request) use (&$captured, &$session, $i): array {
            $session = $request['session'];
            $streamId = $request['stream_id'];
            $captured['cancelled_stream'] = null;

            $captured['cancel_handler_ok'] = king_server_on_cancel(
                $session,
                $streamId,
                function (int $cancelledStreamId) use (&$captured): void {
                    $captured['cancelled_stream'] = $cancelledStreamId;
                }
            );
            $captured['cancel_handler_error'] = king_get_last_error();

            $captured['early_hints_ok'] = king_server_send_early_hints($session, $streamId, [
                'Link' => '</soak-' . $i . '.css>; rel=preload; as=style',
                'X-Iteration' => ['loop-' . $i],
            ]);
            $captured['early_hints_error'] = king_get_last_error();

            $websocket = king_server_upgrade_to_websocket($session, $streamId);
            $captured['upgrade_is_resource'] = is_resource($websocket);
            $captured['upgrade_error'] = king_get_last_error();
            $captured['upgrade_status_before'] = is_resource($websocket)
                ? king_client_websocket_get_status($websocket)
                : null;
            $captured['websocket_close_ok'] = is_resource($websocket)
                ? king_client_websocket_close($websocket, 1000, 'soak-' . $i)
                : false;
            $captured['websocket_close_error'] = king_get_last_error();
            $captured['upgrade_status_after'] = is_resource($websocket)
                ? king_client_websocket_get_status($websocket)
                : null;

            $captured['cancel_ok'] = king_cancel_stream($streamId, 'both', $session);
            $captured['cancel_error'] = king_get_last_error();

            $captured['admin_one_ok'] = king_admin_api_listen($session, [
                'enable' => true,
                'bind_host' => '127.0.0.1',
                'port' => 2100 + $i,
                'auth_mode' => 'mtls',
                'ca_file' => __FILE__,
                'cert_file' => __FILE__,
                'key_file' => __FILE__,
            ]);
            $captured['admin_one_error'] = king_get_last_error();

            $captured['admin_two_ok'] = king_admin_api_listen($session, [
                'enable' => true,
                'bind_host' => '127.0.0.1',
                'port' => 3100 + $i,
                'auth_mode' => 'mtls',
                'ca_file' => __FILE__,
                'cert_file' => __FILE__,
                'key_file' => __FILE__,
            ]);
            $captured['admin_two_error'] = king_get_last_error();

            $captured['tls_one_ok'] = king_server_reload_tls_config($session, __FILE__, __FILE__);
            $captured['tls_one_error'] = king_get_last_error();
            $captured['tls_two_ok'] = king_server_reload_tls_config($session, __FILE__, __FILE__);
            $captured['tls_two_error'] = king_get_last_error();

            $captured['stats_before_close'] = king_get_stats($session);

            return ['status' => 204, 'body' => ''];
        }
    );

    if ($result !== true) {
        throw new RuntimeException('listener dispatch failed at iteration ' . $i . ': ' . king_get_last_error());
    }
    if ($session === null) {
        throw new RuntimeException('listener did not materialize a session at iteration ' . $i);
    }

    $statsAfter = king_get_stats($session);

    foreach ([
        'cancel_handler_ok',
        'early_hints_ok',
        'upgrade_is_resource',
        'websocket_close_ok',
        'cancel_ok',
        'admin_one_ok',
        'admin_two_ok',
        'tls_one_ok',
        'tls_two_ok',
    ] as $key) {
        if (empty($captured[$key])) {
            throw new RuntimeException('server control step failed for ' . $key . ' at iteration ' . $i);
        }
    }

    if ($captured['cancelled_stream'] !== $captured['stats_before_close']['server_last_early_hints_stream_id']) {
        throw new RuntimeException('cancelled stream mismatch at iteration ' . $i);
    }
    if ($captured['upgrade_status_before'] !== 1 || $captured['upgrade_status_after'] !== 3) {
        throw new RuntimeException('unexpected websocket status transition at iteration ' . $i);
    }
    if ($captured['stats_before_close']['server_cancel_handler_invocations'] !== 1) {
        throw new RuntimeException('cancel handler invocation drift at iteration ' . $i);
    }
    if ($captured['stats_before_close']['server_early_hints_count'] !== 1) {
        throw new RuntimeException('early hints count drift at iteration ' . $i);
    }
    if ($captured['stats_before_close']['server_websocket_upgrade_count'] !== 1) {
        throw new RuntimeException('websocket upgrade count drift at iteration ' . $i);
    }
    if ($captured['stats_before_close']['server_admin_api_listen_count'] !== 2) {
        throw new RuntimeException('admin listen count drift at iteration ' . $i);
    }
    if ($captured['stats_before_close']['server_admin_api_reload_count'] !== 1) {
        throw new RuntimeException('admin reload count drift at iteration ' . $i);
    }
    if ($captured['stats_before_close']['server_tls_apply_count'] !== 2) {
        throw new RuntimeException('tls apply count drift at iteration ' . $i);
    }
    if ($captured['stats_before_close']['server_tls_reload_count'] !== 1) {
        throw new RuntimeException('tls reload count drift at iteration ' . $i);
    }
    if ($statsAfter['state'] !== 'closed') {
        throw new RuntimeException('listener session did not close at iteration ' . $i);
    }

    $summary['listener_successes']++;
    $summary['early_hints_successes']++;
    $summary['upgrade_successes']++;
    $summary['websocket_close_successes']++;
    $summary['cancel_invocations'] += $captured['stats_before_close']['server_cancel_handler_invocations'];
    $summary['admin_listens'] += $captured['stats_before_close']['server_admin_api_listen_count'];
    $summary['admin_reloads'] += $captured['stats_before_close']['server_admin_api_reload_count'];
    $summary['tls_applies'] += $captured['stats_before_close']['server_tls_apply_count'];
    $summary['tls_reloads'] += $captured['stats_before_close']['server_tls_reload_count'];
    $summary['closed_sessions']++;
}

var_dump($summary);
?>
--EXPECT--
array(11) {
  ["iterations"]=>
  int(24)
  ["listener_successes"]=>
  int(24)
  ["early_hints_successes"]=>
  int(24)
  ["upgrade_successes"]=>
  int(24)
  ["websocket_close_successes"]=>
  int(24)
  ["cancel_invocations"]=>
  int(24)
  ["admin_listens"]=>
  int(48)
  ["admin_reloads"]=>
  int(24)
  ["tls_applies"]=>
  int(48)
  ["tls_reloads"]=>
  int(24)
  ["closed_sessions"]=>
  int(24)
}
