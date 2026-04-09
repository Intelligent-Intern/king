--TEST--
King WebSocket endpoint keeps fanout and upgrade continuity across rolling multi-node listener handoff under sustained load
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

function king_websocket_644_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_websocket_644_pick_port(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve websocket contract port: $errstr");
    }

    $name = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $name, 2);
    return (int) $port;
}

function king_websocket_644_run_node_phase(string $nodeId, int $port, int $peerCount): array
{
    $server = king_server_websocket_wire_start_server('oo-scheduling-load', $peerCount, $port);
    $clients = [];
    $capture = [];
    $rounds = 4;

    try {
        for ($i = 0; $i < $peerCount; $i++) {
            $url = 'ws://127.0.0.1:' . $port . '/cluster?node=' . $nodeId . '&peer=' . $i;
            $clients[$i] = king_server_websocket_wire_connect_retry($url, 5000);
            king_websocket_644_assert(
                king_client_websocket_send($clients[$i], $nodeId . '-ready-' . $i) === true,
                $nodeId . ': ready send failed for peer ' . $i
            );
        }

        foreach ($clients as $i => $client) {
            for ($round = 0; $round < $rounds; $round++) {
                king_websocket_644_assert(
                    king_client_websocket_receive($client, 1500) === 'slot-' . $round . '-peer-' . $i,
                    $nodeId . ': targeted payload mismatch for peer ' . $i . ' round ' . $round
                );
                king_websocket_644_assert(
                    king_client_websocket_receive($client, 1500) === 'fanout-' . $round,
                    $nodeId . ': fanout payload mismatch for peer ' . $i . ' round ' . $round
                );
            }

            $afterClose = king_client_websocket_receive($client, 1000);
            $afterCloseError = king_get_last_error();
            king_websocket_644_assert(
                $afterClose === false,
                $nodeId . ': peer ' . $i . ' should close after all scheduling rounds'
            );
            king_websocket_644_assert(
                str_contains($afterCloseError, 'cannot run on a closed WebSocket connection'),
                $nodeId . ': close error drifted for peer ' . $i . ': ' . $afterCloseError
            );
            king_websocket_644_assert(
                king_client_websocket_get_status($client) === 3,
                $nodeId . ': peer ' . $i . ' should report closed state'
            );
        }
    } finally {
        $capture = king_server_websocket_wire_stop_server($server);
    }

    $accepted = $capture['accepted_info'] ?? [];
    $connectionIds = array_map(
        static fn(array $info): string => (string) ($info['connection_id'] ?? ''),
        $accepted
    );

    king_websocket_644_assert(
        ($capture['server_class'] ?? '') === 'King\\WebSocket\\Server',
        $nodeId . ': server class drifted'
    );
    king_websocket_644_assert(
        ($capture['scheduling_exception_class'] ?? '') === '',
        $nodeId . ': scheduling exception class drifted'
    );
    king_websocket_644_assert(
        ($capture['scheduling_exception_message'] ?? '') === '',
        $nodeId . ': scheduling exception message drifted'
    );
    king_websocket_644_assert(
        ($capture['registry_count_after_accept'] ?? -1) === $peerCount,
        $nodeId . ': registry count after accept mismatch'
    );
    king_websocket_644_assert(
        ($capture['registry_count_after_send'] ?? -1) === $peerCount,
        $nodeId . ': registry count after send mismatch'
    );
    king_websocket_644_assert(
        ($capture['registry_count_after_close'] ?? -1) === 0,
        $nodeId . ': registry count after close should be zero'
    );
    king_websocket_644_assert(
        count($accepted) === $peerCount,
        $nodeId . ': accepted peer count mismatch'
    );
    king_websocket_644_assert(
        count(array_unique($connectionIds)) === $peerCount,
        $nodeId . ': connection ids must remain unique in one node phase'
    );
    king_websocket_644_assert(
        count($capture['scheduled_payloads'] ?? []) === $peerCount * $rounds,
        $nodeId . ': scheduled payload count mismatch'
    );
    king_websocket_644_assert(
        count($capture['broadcast_payloads'] ?? []) === $rounds,
        $nodeId . ': broadcast payload count mismatch'
    );
    king_websocket_644_assert(
        ($capture['stop_error'] ?? '') === '',
        $nodeId . ': stop error drifted'
    );

    foreach (range(0, $peerCount - 1) as $i) {
        $expectedUrl = 'ws://127.0.0.1:' . $port . '/cluster?node=' . $nodeId . '&peer=' . $i;
        king_websocket_644_assert(
            ($accepted[$i]['id'] ?? '') === $expectedUrl,
            $nodeId . ': accepted URL drifted for peer ' . $i
        );
    }

    return [
        'capture' => $capture,
        'connection_ids' => $connectionIds,
    ];
}

$peerCount = 5;
$sharedPort = king_websocket_644_pick_port();

$nodeA = king_websocket_644_run_node_phase('node-a', $sharedPort, $peerCount);
usleep(200000);
$nodeB = king_websocket_644_run_node_phase('node-b', $sharedPort, $peerCount);

$intersection = array_values(array_intersect($nodeA['connection_ids'], $nodeB['connection_ids']));
king_websocket_644_assert(
    count($intersection) === 0,
    'rolling node handoff must not reuse connection ids across node phases'
);

echo "OK\n";
?>
--EXPECT--
OK
