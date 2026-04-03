--TEST--
King server-side websocket upgrade resources are cleaned up across request boundaries and same-process worker reuse
--FILE--
<?php
function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . ' expected=' . var_export($expected, true)
            . ' actual=' . var_export($actual, true)
        );
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$cases = [
    [
        'label' => 'http1',
        'listen' => 'king_http1_server_listen',
        'port' => 8080,
        'backend' => 'server_http1_local',
    ],
    [
        'label' => 'http2',
        'listen' => 'king_http2_server_listen',
        'port' => 8443,
        'backend' => 'server_http2_local',
    ],
    [
        'label' => 'http3',
        'listen' => 'king_http3_server_listen',
        'port' => 9443,
        'backend' => 'server_http3_local',
    ],
];

$summary = [
    'protocols' => count($cases),
    'boundary_cleanups' => 0,
    'worker_reuse_cleanups' => 0,
    'fresh_reuse_requests' => 0,
];

foreach ($cases as $case) {
    $listen = $case['listen'];
    $firstWebsocket = null;
    $firstSession = null;
    $firstStreamId = null;
    $secondWebsocket = null;
    $secondSession = null;
    $secondStreamId = null;
    $secondReceive = null;
    $firstStatsBeforeClose = null;
    $secondStatsBeforeClose = null;

    $result = $listen(
        '127.0.0.1',
        $case['port'],
        null,
        static function (array $request) use (
            &$firstWebsocket,
            &$firstSession,
            &$firstStreamId,
            &$firstStatsBeforeClose,
            $case
        ): array {
            $firstSession = $request['session'];
            $firstStreamId = $request['stream_id'];
            $firstWebsocket = king_server_upgrade_to_websocket($firstSession, $firstStreamId);

            assert_true(is_resource($firstWebsocket), $case['label'] . ' first websocket did not materialize.');
            assert_same(1, king_client_websocket_get_status($firstWebsocket), $case['label'] . ' first websocket did not start open.');
            assert_true(
                king_websocket_send($firstWebsocket, 'stale-' . $case['label']),
                $case['label'] . ' first websocket could not queue a local payload.'
            );

            $firstStatsBeforeClose = king_get_stats($firstSession);

            return ['status' => 204, 'body' => ''];
        }
    );

    assert_true($result === true, $case['label'] . ' first listener call failed: ' . king_get_last_error());
    assert_true(is_resource($firstWebsocket), $case['label'] . ' first websocket handle was lost.');
    assert_same(3, king_client_websocket_get_status($firstWebsocket), $case['label'] . ' first websocket stayed open after the request boundary.');

    assert_same(false, king_client_websocket_receive($firstWebsocket), $case['label'] . ' first websocket leaked queued payloads across the request boundary.');
    assert_same(
        'king_client_websocket_receive() cannot run on a closed WebSocket connection.',
        king_get_last_error(),
        $case['label'] . ' first websocket close error drifted on receive.'
    );
    assert_same(false, king_websocket_send($firstWebsocket, 'after-boundary'), $case['label'] . ' first websocket still accepted sends after the request boundary.');
    assert_same(
        'king_websocket_send() cannot run on a closed WebSocket connection.',
        king_get_last_error(),
        $case['label'] . ' first websocket close error drifted on send.'
    );
    assert_same(false, king_client_websocket_ping($firstWebsocket, 'p'), $case['label'] . ' first websocket still accepted pings after the request boundary.');
    assert_same(
        'king_client_websocket_ping() cannot run on a closed WebSocket connection.',
        king_get_last_error(),
        $case['label'] . ' first websocket close error drifted on ping.'
    );

    $firstStatsAfterClose = king_get_stats($firstSession);
    assert_same('closed', $firstStatsAfterClose['state'], $case['label'] . ' first session did not close at the request boundary.');
    assert_same(1, $firstStatsBeforeClose['server_websocket_upgrade_count'], $case['label'] . ' first request upgrade count drifted.');
    assert_same($firstStreamId, $firstStatsBeforeClose['server_last_websocket_stream_id'], $case['label'] . ' first request stream id drifted.');
    assert_same($case['backend'], $firstStatsBeforeClose['transport_backend'], $case['label'] . ' first request backend drifted.');
    assert_same(
        ($firstStatsBeforeClose['server_last_websocket_secure'] ? 'wss://' : 'ws://')
            . '127.0.0.1:' . $case['port'] . '/stream/' . $firstStreamId,
        $firstStatsBeforeClose['server_last_websocket_url'],
        $case['label'] . ' first request websocket URL drifted.'
    );

    $result = $listen(
        '127.0.0.1',
        $case['port'],
        null,
        static function (array $request) use (
            &$secondWebsocket,
            &$secondSession,
            &$secondStreamId,
            &$secondReceive,
            &$secondStatsBeforeClose,
            $case
        ): array {
            $secondSession = $request['session'];
            $secondStreamId = $request['stream_id'];
            $secondWebsocket = king_server_upgrade_to_websocket($secondSession, $secondStreamId);

            assert_true(is_resource($secondWebsocket), $case['label'] . ' second websocket did not materialize.');
            assert_same(1, king_client_websocket_get_status($secondWebsocket), $case['label'] . ' second websocket did not start open.');
            assert_true(
                king_websocket_send($secondWebsocket, 'fresh-' . $case['label']),
                $case['label'] . ' second websocket could not queue a fresh local payload.'
            );
            $secondReceive = king_client_websocket_receive($secondWebsocket);
            assert_same(
                'fresh-' . $case['label'],
                $secondReceive,
                $case['label'] . ' second request did not receive its own fresh local payload.'
            );

            $secondStatsBeforeClose = king_get_stats($secondSession);

            return ['status' => 204, 'body' => ''];
        }
    );

    assert_true($result === true, $case['label'] . ' second listener call failed: ' . king_get_last_error());
    assert_true(is_resource($secondWebsocket), $case['label'] . ' second websocket handle was lost.');
    assert_same(3, king_client_websocket_get_status($secondWebsocket), $case['label'] . ' second websocket stayed open after same-process worker reuse.');
    assert_same(false, king_client_websocket_receive($secondWebsocket), $case['label'] . ' second websocket leaked queued payloads after same-process worker reuse.');
    assert_same(
        'king_client_websocket_receive() cannot run on a closed WebSocket connection.',
        king_get_last_error(),
        $case['label'] . ' second websocket close error drifted on receive.'
    );

    $secondStatsAfterClose = king_get_stats($secondSession);
    assert_same('closed', $secondStatsAfterClose['state'], $case['label'] . ' second session did not close after same-process worker reuse.');
    assert_same(1, $secondStatsBeforeClose['server_websocket_upgrade_count'], $case['label'] . ' second request upgrade count drifted.');
    assert_same($secondStreamId, $secondStatsBeforeClose['server_last_websocket_stream_id'], $case['label'] . ' second request stream id drifted.');
    assert_same($case['backend'], $secondStatsBeforeClose['transport_backend'], $case['label'] . ' second request backend drifted.');
    assert_same(
        ($secondStatsBeforeClose['server_last_websocket_secure'] ? 'wss://' : 'ws://')
            . '127.0.0.1:' . $case['port'] . '/stream/' . $secondStreamId,
        $secondStatsBeforeClose['server_last_websocket_url'],
        $case['label'] . ' second request websocket URL drifted.'
    );

    $summary['boundary_cleanups']++;
    $summary['worker_reuse_cleanups']++;
    $summary['fresh_reuse_requests']++;
}

var_dump($summary);
?>
--EXPECT--
array(4) {
  ["protocols"]=>
  int(3)
  ["boundary_cleanups"]=>
  int(3)
  ["worker_reuse_cleanups"]=>
  int(3)
  ["fresh_reuse_requests"]=>
  int(3)
}
