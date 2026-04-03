--TEST--
King WebSocket Server keeps repeated targeted and broadcast scheduling stable across many live accepted peers under load
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

function king_websocket_server_load_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$peerCount = 6;
$rounds = 4;
$server = king_server_websocket_wire_start_server('oo-scheduling-load', $peerCount);
$capture = [];
$clients = [];

try {
    for ($i = 0; $i < $peerCount; $i++) {
        $clients[$i] = king_server_websocket_wire_connect_retry(
            'ws://127.0.0.1:' . $server['port'] . '/load?peer=' . $i
        );
        king_websocket_server_load_assert(
            king_client_websocket_send($clients[$i], 'ready-' . $i) === true,
            'ready send failed for peer ' . $i
        );
    }

    foreach ($clients as $i => $client) {
        for ($round = 0; $round < $rounds; $round++) {
            king_websocket_server_load_assert(
                king_client_websocket_receive($client, 1000) === 'slot-' . $round . '-peer-' . $i,
                'targeted payload mismatch for peer ' . $i . ' round ' . $round
            );
            king_websocket_server_load_assert(
                king_client_websocket_receive($client, 1000) === 'fanout-' . $round,
                'broadcast payload mismatch for peer ' . $i . ' round ' . $round
            );
        }

        $afterClose = king_client_websocket_receive($client, 1000);
        $afterCloseError = king_get_last_error();

        king_websocket_server_load_assert($afterClose === false, 'peer ' . $i . ' should close after the scheduled rounds');
        king_websocket_server_load_assert(
            str_contains($afterCloseError, 'cannot run on a closed WebSocket connection'),
            'peer ' . $i . ' close error drifted: ' . $afterCloseError
        );
        king_websocket_server_load_assert(
            king_client_websocket_get_status($client) === 3,
            'peer ' . $i . ' should report closed after the load rounds'
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

king_websocket_server_load_assert(($capture['server_class'] ?? '') === 'King\\WebSocket\\Server', 'server class drifted');
king_websocket_server_load_assert(($capture['scheduling_exception_class'] ?? '') === '', 'server threw ' . ($capture['scheduling_exception_class'] ?? 'unknown'));
king_websocket_server_load_assert(($capture['scheduling_exception_message'] ?? '') === '', 'server scheduling error drifted');
king_websocket_server_load_assert(($capture['registry_count_after_accept'] ?? -1) === $peerCount, 'registry_after_accept count mismatch');
king_websocket_server_load_assert(($capture['registry_count_after_send'] ?? -1) === $peerCount, 'registry_after_send count mismatch');
king_websocket_server_load_assert(($capture['registry_count_after_close'] ?? -1) === 0, 'registry_after_close should be empty');
king_websocket_server_load_assert(count($acceptedInfo) === $peerCount, 'accepted info count mismatch');
king_websocket_server_load_assert(count(array_unique($connectionIds)) === $peerCount, 'connection ids should stay unique under load');
king_websocket_server_load_assert(count($capture['scheduled_payloads'] ?? []) === $peerCount * $rounds, 'scheduled payload count mismatch');
king_websocket_server_load_assert(count($capture['broadcast_payloads'] ?? []) === $rounds, 'broadcast payload count mismatch');
king_websocket_server_load_assert(($capture['stop_error'] ?? '') === '', 'server stop error drifted');

foreach (range(0, $peerCount - 1) as $i) {
    $connectionId = $connectionIds[$i] ?? '';

    king_websocket_server_load_assert(($capture['ready_messages'][$i] ?? null) === 'ready-' . $i, 'ready message mismatch for peer ' . $i);
    king_websocket_server_load_assert(($capture['ready_errors'][$i] ?? '') === '', 'ready error drifted for peer ' . $i);
    king_websocket_server_load_assert(($acceptedInfo[$i]['id'] ?? '') === 'ws://127.0.0.1:' . $server['port'] . '/load?peer=' . $i, 'accepted id mismatch for peer ' . $i);
    king_websocket_server_load_assert(($acceptedInfo[$i]['connection_id'] ?? '') === $connectionId, 'accepted connection_id mismatch for peer ' . $i);
    king_websocket_server_load_assert(
        ($capture['registry_after_accept'][$connectionId]['connection_id'] ?? '') === $connectionId,
        'registry_after_accept lost peer ' . $i
    );
    king_websocket_server_load_assert(
        ($capture['registry_after_send'][$connectionId]['connection_id'] ?? '') === $connectionId,
        'registry_after_send lost peer ' . $i
    );
}

echo "OK\n";
?>
--EXPECT--
OK
