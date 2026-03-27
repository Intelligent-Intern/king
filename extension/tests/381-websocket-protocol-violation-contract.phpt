--TEST--
King WebSocket runtime rejects on-wire opcode frame-shape and close-sequence violations with stable protocol-close behavior
--FILE--
<?php
require __DIR__ . '/websocket_violation_helper.inc';

$modes = [
    'bad_opcode' => 'king_client_websocket_receive() received an unsupported WebSocket opcode 11.',
    'bad_rsv_text' => 'king_client_websocket_receive() received a WebSocket frame with unsupported RSV bits set.',
    'bad_close_payload' => 'king_client_websocket_receive() received an invalid one-byte WebSocket close payload.',
];

$results = [];
$captures = [];

foreach ($modes as $mode => $expectedError) {
    $server = king_websocket_violation_start_server($mode);

    try {
        $websocket = king_client_websocket_connect(
            'ws://127.0.0.1:' . $server['port'] . '/violation?mode=' . $mode
        );

        $firstReceive = king_client_websocket_receive($websocket, 1000);
        $firstError = king_client_websocket_get_last_error();
        $statusAfterViolation = king_client_websocket_get_status($websocket);
        $secondReceive = king_client_websocket_receive($websocket, 0);
        $secondError = king_client_websocket_get_last_error();
        $sendAfterViolation = king_client_websocket_send($websocket, 'after');
        $sendError = king_client_websocket_get_last_error();

        $results[$mode] = [
            'first_receive' => $firstReceive,
            'first_error' => $firstError,
            'status' => $statusAfterViolation,
            'second_receive' => $secondReceive,
            'second_error' => $secondError,
            'send_after_violation' => $sendAfterViolation,
            'send_error' => $sendError,
            'expected_error' => $expectedError,
        ];
    } finally {
        $captures[$mode] = king_websocket_violation_stop_server($server);
    }
}

foreach (array_keys($modes) as $mode) {
    var_dump($results[$mode]['first_receive'] === false);
    var_dump($results[$mode]['first_error'] === $results[$mode]['expected_error']);
    var_dump($results[$mode]['status'] === 3);
    var_dump($results[$mode]['second_receive'] === false);
    var_dump($results[$mode]['second_error'] === 'king_client_websocket_receive() cannot run on a closed WebSocket connection.');
    var_dump($results[$mode]['send_after_violation'] === false);
    var_dump($results[$mode]['send_error'] === 'king_client_websocket_send() cannot run on a closed WebSocket connection.');
    var_dump($captures[$mode]['client_frames'][0]['opcode'] === 8);
    var_dump($captures[$mode]['client_frames'][0]['close_code'] === 1002);
    var_dump($captures[$mode]['client_frames'][0]['close_reason'] === '');
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
