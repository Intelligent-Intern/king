--TEST--
King WebSocket runtime keeps unrelated concurrent clients progressing while one noisy peer holds a deep queued backlog
--FILE--
<?php
require __DIR__ . '/websocket_backpressure_helper.inc';

function king_websocket_fairness_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$server = king_websocket_backpressure_start_server(12, 128, 9);
$capture = [];
$fairClients = [];
$noisyPayloads = [];

try {
    $noisy = king_websocket_backpressure_connect_retry(
        'ws://127.0.0.1:' . $server['port'] . '/noisy',
        [
            'max_payload_size' => 256,
            'max_queued_messages' => 16,
            'max_queued_bytes' => 8192,
            'handshake_timeout_ms' => 1000,
        ]
    );

    for ($i = 0; $i < 8; $i++) {
        $fairClients[$i] = king_websocket_backpressure_connect_retry(
            'ws://127.0.0.1:' . $server['port'] . '/fair-' . $i,
            [
                'max_payload_size' => 256,
                'max_queued_messages' => 8,
                'max_queued_bytes' => 4096,
                'handshake_timeout_ms' => 1000,
            ]
        );
    }

    king_websocket_fairness_assert(
        king_client_websocket_send($noisy, 'FLOOD') === true,
        'noisy flood trigger failed'
    );

    $firstNoisy = king_client_websocket_receive($noisy, 500);
    $noisyInfo = $noisy->getInfo();

    king_websocket_fairness_assert(is_string($firstNoisy) && str_starts_with($firstNoisy, 'slow-00-'), 'noisy first payload mismatch');
    king_websocket_fairness_assert($noisyInfo['closed'] === false, 'noisy connection should stay open');

    foreach ([1, 2] as $round) {
        foreach ($fairClients as $i => $client) {
            $payload = 'fair-' . $i . '-round-' . $round;

            king_websocket_fairness_assert(
                king_client_websocket_send($client, $payload) === true,
                'fair send failed for client ' . $i . ' round ' . $round
            );
            king_websocket_fairness_assert(
                king_client_websocket_receive($client, 1000) === $payload,
                'fair receive mismatch for client ' . $i . ' round ' . $round
            );
            king_websocket_fairness_assert(
                king_client_websocket_get_status($client) === 1,
                'fair client ' . $i . ' should remain open in round ' . $round
            );
        }
    }

    $noisyPayloads[] = $firstNoisy;
    for ($i = 1; $i < 12; $i++) {
        $noisyPayloads[] = king_client_websocket_receive($noisy, 500);
    }

    king_websocket_fairness_assert(count($noisyPayloads) === 12, 'noisy payload count mismatch');
    king_websocket_fairness_assert(king_client_websocket_get_status($noisy) === 1, 'noisy connection should remain open while others progress');
    foreach ($noisyPayloads as $i => $payload) {
        king_websocket_fairness_assert(
            is_string($payload) && str_starts_with($payload, sprintf('slow-%02d-', $i)),
            'noisy payload ordering mismatch at index ' . $i
        );
    }

    foreach ($fairClients as $i => $client) {
        king_websocket_fairness_assert(
            king_client_websocket_close($client, 1000, 'done') === true,
            'fair close failed for client ' . $i
        );
    }
} finally {
    $capture = king_websocket_backpressure_stop_server($server);
}

$byPath = [];
foreach ($capture['connections'] as $connection) {
    $byPath[$connection['path']] = $connection;
}

king_websocket_fairness_assert(count($byPath) === 9, 'server should capture 9 connections');
king_websocket_fairness_assert(
    count(
        array_filter(
            $byPath['/noisy']['sent_frames'],
            static fn(array $frame): bool => $frame['opcode'] === 0x1
        )
    ) === 12,
    'server should send 12 noisy text frames'
);
king_websocket_fairness_assert(count($byPath['/fair-0']['frames']) === 3, 'fair-0 should send two messages plus close');
king_websocket_fairness_assert($byPath['/fair-0']['frames'][0]['payload'] === 'fair-0-round-1', 'fair-0 round 1 mismatch');
king_websocket_fairness_assert($byPath['/fair-0']['frames'][1]['payload'] === 'fair-0-round-2', 'fair-0 round 2 mismatch');

echo "OK\n";
?>
--EXPECT--
OK
