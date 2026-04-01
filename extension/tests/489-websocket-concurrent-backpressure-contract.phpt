--TEST--
King WebSocket runtime bounds queued receive pressure under many concurrent connections without poisoning unrelated peers
--FILE--
<?php
require __DIR__ . '/websocket_backpressure_helper.inc';

function king_websocket_backpressure_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$server = king_websocket_backpressure_start_server(12, 128, 9);
$capture = [];
$fastClients = [];
$slowInfo = [];
$slowReceive = null;
$slowError = null;
$slowStatus = null;

try {
    $slow = king_websocket_backpressure_connect_retry(
        'ws://127.0.0.1:' . $server['port'] . '/slow',
        [
            'max_payload_size' => 256,
            'max_queued_messages' => 4,
            'max_queued_bytes' => 2048,
            'handshake_timeout_ms' => 1000,
        ]
    );

    for ($i = 0; $i < 8; $i++) {
        $fastClients[$i] = king_websocket_backpressure_connect_retry(
            'ws://127.0.0.1:' . $server['port'] . '/fast-' . $i,
            [
                'max_payload_size' => 256,
                'max_queued_messages' => 8,
                'max_queued_bytes' => 4096,
                'handshake_timeout_ms' => 1000,
            ]
        );
    }

    foreach ($fastClients as $i => $client) {
        king_websocket_backpressure_assert(
            king_client_websocket_send($client, 'warm-' . $i) === true,
            'warm send failed for fast client ' . $i
        );
        king_websocket_backpressure_assert(
            king_client_websocket_receive($client, 1000) === 'warm-' . $i,
            'warm receive mismatch for fast client ' . $i
        );
    }

    king_websocket_backpressure_assert(
        king_client_websocket_send($slow, 'FLOOD') === true,
        'slow flood trigger send failed'
    );
    usleep(150000);

    $slowReceive = king_client_websocket_receive($slow, 500);
    $slowError = king_client_websocket_get_last_error();
    $slowStatus = king_client_websocket_get_status($slow);
    $slowInfo = $slow->getInfo();

    king_websocket_backpressure_assert($slowReceive === false, 'slow receive should fail closed on queue overflow');
    king_websocket_backpressure_assert(
        $slowError === 'king_client_websocket_receive() pending WebSocket messages exceeded max_queued_messages 4 or max_queued_bytes 2048.',
        'unexpected slow overflow error: ' . $slowError
    );
    king_websocket_backpressure_assert($slowStatus === 3, 'slow connection should be closed after overflow');
    king_websocket_backpressure_assert($slowInfo['max_queued_messages'] === 4, 'slow info max_queued_messages mismatch');
    king_websocket_backpressure_assert($slowInfo['max_queued_bytes'] === 2048, 'slow info max_queued_bytes mismatch');
    king_websocket_backpressure_assert($slowInfo['queued_message_count'] === 4, 'slow queued_message_count should stay capped');
    king_websocket_backpressure_assert(
        $slowInfo['queued_bytes'] > 0 && $slowInfo['queued_bytes'] <= 2048,
        'slow queued_bytes should remain bounded'
    );
    king_websocket_backpressure_assert($slowInfo['closed'] === true, 'slow info should report closed');

    foreach ($fastClients as $i => $client) {
        king_websocket_backpressure_assert(
            king_client_websocket_send($client, 'steady-' . $i) === true,
            'steady send failed for fast client ' . $i
        );
        king_websocket_backpressure_assert(
            king_client_websocket_receive($client, 1000) === 'steady-' . $i,
            'steady receive mismatch for fast client ' . $i
        );
        king_websocket_backpressure_assert(
            king_client_websocket_get_status($client) === 1,
            'fast client ' . $i . ' should remain open'
        );
        king_websocket_backpressure_assert(
            king_client_websocket_close($client, 1000, 'done') === true,
            'fast client close failed for ' . $i
        );
    }
} finally {
    $capture = king_websocket_backpressure_stop_server($server);
}

$byPath = [];
foreach ($capture['connections'] as $connection) {
    $byPath[$connection['path']] = $connection;
}

king_websocket_backpressure_assert(count($byPath) === 9, 'server should capture 9 connections');
king_websocket_backpressure_assert(
    count(
        array_filter(
            $byPath['/slow']['sent_frames'],
            static fn(array $frame): bool => $frame['opcode'] === 0x1
        )
    ) === 12,
    'slow path should receive 12 flood text frames'
);
king_websocket_backpressure_assert($byPath['/slow']['frames'][0]['payload'] === 'FLOOD', 'slow trigger payload mismatch');
king_websocket_backpressure_assert($byPath['/slow']['frames'][1]['opcode'] === 8, 'slow path should close');
king_websocket_backpressure_assert($byPath['/slow']['frames'][1]['close_code'] === 1008, 'slow close code mismatch');
king_websocket_backpressure_assert(count($byPath['/fast-0']['frames']) === 3, 'fast-0 frame count mismatch');
king_websocket_backpressure_assert($byPath['/fast-0']['frames'][0]['payload'] === 'warm-0', 'fast-0 warm payload mismatch');
king_websocket_backpressure_assert($byPath['/fast-0']['frames'][1]['payload'] === 'steady-0', 'fast-0 steady payload mismatch');

echo "OK\n";
?>
--EXPECT--
OK
