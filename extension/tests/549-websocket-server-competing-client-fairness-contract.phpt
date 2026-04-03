--TEST--
King WebSocket Server keeps fair peers progressing while one competing peer carries a deeper queued backlog
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

function king_websocket_server_fairness_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$peerCount = 5;
$server = king_server_websocket_wire_start_server('oo-competitive-fairness', $peerCount);
$capture = [];
$clients = [];

try {
    $clients[0] = king_server_websocket_wire_connect_retry(
        'ws://127.0.0.1:' . $server['port'] . '/noisy'
    );
    king_websocket_server_fairness_assert(
        king_client_websocket_send($clients[0], 'ready-0') === true,
        'ready send failed for noisy peer'
    );

    for ($i = 1; $i < $peerCount; $i++) {
        $clients[$i] = king_server_websocket_wire_connect_retry(
            'ws://127.0.0.1:' . $server['port'] . '/fair-' . $i
        );
        king_websocket_server_fairness_assert(
            king_client_websocket_send($clients[$i], 'ready-' . $i) === true,
            'ready send failed for fair peer ' . $i
        );
    }

    king_websocket_server_fairness_assert(
        king_client_websocket_receive($clients[0], 1000) === 'noisy-00',
        'noisy peer did not receive the first backlog payload'
    );

    for ($i = 1; $i < $peerCount; $i++) {
        king_websocket_server_fairness_assert(
            king_client_websocket_receive($clients[$i], 1000) === 'fair-' . $i . '-round-1',
            'fair peer ' . $i . ' missed round 1 while noisy backlog was active'
        );
        king_websocket_server_fairness_assert(
            king_client_websocket_receive($clients[$i], 1000) === 'fair-' . $i . '-round-2',
            'fair peer ' . $i . ' missed round 2 while noisy backlog was active'
        );
    }

    foreach (range(1, 11) as $suffix) {
        king_websocket_server_fairness_assert(
            king_client_websocket_receive($clients[0], 1000) === sprintf('noisy-%02d', $suffix),
            'noisy backlog ordering drifted at suffix ' . $suffix
        );
    }

    foreach ($clients as $i => $client) {
        $afterClose = king_client_websocket_receive($client, 1000);
        $afterCloseError = king_get_last_error();

        king_websocket_server_fairness_assert($afterClose === false, 'peer ' . $i . ' should close after the fairness run');
        king_websocket_server_fairness_assert(
            str_contains($afterCloseError, 'cannot run on a closed WebSocket connection'),
            'peer ' . $i . ' close error drifted: ' . $afterCloseError
        );
        king_websocket_server_fairness_assert(
            king_client_websocket_get_status($client) === 3,
            'peer ' . $i . ' should report closed after the fairness run'
        );
    }
} finally {
    $capture = king_server_websocket_wire_stop_server($server);
}

$acceptedInfo = $capture['accepted_info'] ?? [];
$connectionIds = array_map(
    static fn(array $info): string => (string) ($info['connection_id'] ?? ''),
    $acceptedInfo
);

king_websocket_server_fairness_assert(($capture['server_class'] ?? '') === 'King\\WebSocket\\Server', 'server class drifted');
king_websocket_server_fairness_assert(($capture['fairness_exception_class'] ?? '') === '', 'server threw ' . ($capture['fairness_exception_class'] ?? 'unknown'));
king_websocket_server_fairness_assert(($capture['fairness_exception_message'] ?? '') === '', 'server fairness error drifted');
king_websocket_server_fairness_assert(($capture['registry_count_after_accept'] ?? -1) === $peerCount, 'registry_after_accept count mismatch');
king_websocket_server_fairness_assert(($capture['registry_count_after_send'] ?? -1) === $peerCount, 'registry_after_send count mismatch');
king_websocket_server_fairness_assert(($capture['registry_count_after_close'] ?? -1) === 0, 'registry_after_close should be empty');
king_websocket_server_fairness_assert(count($acceptedInfo) === $peerCount, 'accepted info count mismatch');
king_websocket_server_fairness_assert(count(array_unique($connectionIds)) === $peerCount, 'connection ids should stay unique');
king_websocket_server_fairness_assert(count($capture['noisy_payloads'] ?? []) === 12, 'noisy payload count mismatch');
king_websocket_server_fairness_assert(count($capture['fair_payloads'] ?? []) === ($peerCount - 1) * 2, 'fair payload count mismatch');
king_websocket_server_fairness_assert(($capture['stop_error'] ?? '') === '', 'server stop error drifted');

foreach (range(0, $peerCount - 1) as $i) {
    $connectionId = $connectionIds[$i] ?? '';

    king_websocket_server_fairness_assert(($capture['ready_messages'][$i] ?? null) === 'ready-' . $i, 'ready message mismatch for peer ' . $i);
    king_websocket_server_fairness_assert(($capture['ready_errors'][$i] ?? '') === '', 'ready error drifted for peer ' . $i);
    king_websocket_server_fairness_assert(
        ($capture['registry_after_accept'][$connectionId]['connection_id'] ?? '') === $connectionId,
        'registry_after_accept lost peer ' . $i
    );
    king_websocket_server_fairness_assert(
        ($capture['registry_after_send'][$connectionId]['connection_id'] ?? '') === $connectionId,
        'registry_after_send lost peer ' . $i
    );
}

echo "OK\n";
?>
--EXPECT--
OK
