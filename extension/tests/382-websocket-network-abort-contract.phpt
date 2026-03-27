--TEST--
King WebSocket runtime handles peer disconnect half-close and abrupt socket-loss without corrupting follow-up sessions
--FILE--
<?php
require __DIR__ . '/websocket_abort_helper.inc';
require __DIR__ . '/websocket_test_helper.inc';

$modes = [
    'peer_disconnect' => 'king_client_websocket_receive() lost the active WebSocket socket before the frame was fully read.',
    'half_close_partial_frame' => 'king_client_websocket_receive() received a partial WebSocket frame from the socket.',
    'abrupt_reset' => 'king_client_websocket_receive() lost the active WebSocket socket before the frame was fully read.',
];

$results = [];
$captures = [];
$healthy = [];

foreach ($modes as $mode => $expectedError) {
    $server = king_websocket_abort_start_server($mode);

    try {
        $websocket = king_client_websocket_connect(
            'ws://127.0.0.1:' . $server['port'] . '/abort?mode=' . $mode
        );

        $primeSend = null;
        if ($mode === 'abrupt_reset') {
            $primeSend = king_client_websocket_send($websocket, 'prime');
            usleep(150000);
        }

        $firstReceive = king_client_websocket_receive($websocket, 1000);
        $firstError = king_client_websocket_get_last_error();
        $statusAfterAbort = king_client_websocket_get_status($websocket);
        $secondReceive = king_client_websocket_receive($websocket, 0);
        $secondError = king_client_websocket_get_last_error();
        $sendAfterAbort = king_client_websocket_send($websocket, 'after');
        $sendError = king_client_websocket_get_last_error();

        $results[$mode] = [
            'prime_send' => $primeSend,
            'first_receive' => $firstReceive,
            'first_error' => $firstError,
            'status' => $statusAfterAbort,
            'second_receive' => $secondReceive,
            'second_error' => $secondError,
            'send_after_abort' => $sendAfterAbort,
            'send_error' => $sendError,
            'expected_error' => $expectedError,
        ];
    } finally {
        $captures[$mode] = king_websocket_abort_stop_server($server);
    }

    $healthyServer = king_websocket_test_start_server();
    try {
        $healthySocket = king_client_websocket_connect(
            'ws://127.0.0.1:' . $healthyServer['port'] . '/healthy-' . $mode
        );
        $healthySend = king_client_websocket_send($healthySocket, 'ok-' . $mode);
        $healthyReply = king_client_websocket_receive($healthySocket, 1000);
        $healthyClose = king_client_websocket_close($healthySocket, 1000, 'done');
        $healthy[$mode] = [
            'send' => $healthySend,
            'reply' => $healthyReply,
            'close' => $healthyClose,
        ];
    } finally {
        king_websocket_test_stop_server($healthyServer);
    }
}

foreach (array_keys($modes) as $mode) {
    if ($mode === 'abrupt_reset') {
        var_dump($results[$mode]['prime_send'] === true);
        var_dump($captures[$mode]['client_frames'][0]['opcode'] === 1);
        var_dump($captures[$mode]['client_frames'][0]['payload'] === 'prime');
    }

    var_dump($results[$mode]['first_receive'] === false);
    var_dump($results[$mode]['first_error'] === $results[$mode]['expected_error']);
    var_dump($results[$mode]['status'] === 3);
    var_dump($results[$mode]['second_receive'] === false);
    var_dump($results[$mode]['second_error'] === 'king_client_websocket_receive() cannot run on a closed WebSocket connection.');
    var_dump($results[$mode]['send_after_abort'] === false);
    var_dump($results[$mode]['send_error'] === 'king_client_websocket_send() cannot run on a closed WebSocket connection.');
    var_dump($healthy[$mode]['send'] === true);
    var_dump($healthy[$mode]['reply'] === 'ok-' . $mode);
    var_dump($healthy[$mode]['close'] === true);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
