--TEST--
King WebSocket Server cleans up aborted peers and keeps surviving peers schedulable after a crash-style disconnect
--SKIPIF--
<?php
if (!extension_loaded('pcntl')) {
    echo "skip pcntl extension required";
}
?>
--FILE--
<?php
require __DIR__ . '/server_websocket_wire_helper.inc';

function king_websocket_server_abort_cleanup_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$server = king_server_websocket_wire_start_server('oo-abort-cleanup');
$capture = [];
$abortingSocket = null;

try {
    $first = king_server_websocket_wire_connect_retry(
        'ws://127.0.0.1:' . $server['port'] . '/survivor-a'
    );
    $abortingSocket = king_server_websocket_wire_raw_client_connect(
        $server['port'],
        '/aborting'
    );
    $second = king_server_websocket_wire_connect_retry(
        'ws://127.0.0.1:' . $server['port'] . '/survivor-b'
    );

    king_websocket_server_abort_cleanup_assert(
        king_client_websocket_send($first, 'ready-0') === true,
        'ready send failed for survivor A'
    );
    king_server_websocket_wire_raw_client_send_text($abortingSocket, 'ready-1');
    king_websocket_server_abort_cleanup_assert(
        king_client_websocket_send($second, 'ready-2') === true,
        'ready send failed for survivor B'
    );

    usleep(50000);
    king_server_websocket_wire_raw_client_abort($abortingSocket);
    $abortingSocket = null;

    king_websocket_server_abort_cleanup_assert(
        king_client_websocket_receive($first, 1000) === 'survivor-broadcast',
        'survivor A missed the post-abort broadcast'
    );
    king_websocket_server_abort_cleanup_assert(
        king_client_websocket_receive($second, 1000) === 'survivor-broadcast',
        'survivor B missed the post-abort broadcast'
    );
    king_websocket_server_abort_cleanup_assert(
        king_client_websocket_receive($first, 1000) === 'survivor-target-0',
        'survivor A missed the targeted post-abort frame'
    );
    king_websocket_server_abort_cleanup_assert(
        king_client_websocket_receive($second, 1000) === 'survivor-target-2',
        'survivor B missed the targeted post-abort frame'
    );

    foreach ([$first, $second] as $index => $client) {
        $afterClose = king_client_websocket_receive($client, 1000);
        $afterCloseError = king_get_last_error();

        king_websocket_server_abort_cleanup_assert($afterClose === false, 'survivor ' . $index . ' should close after cleanup');
        king_websocket_server_abort_cleanup_assert(
            str_contains($afterCloseError, 'cannot run on a closed WebSocket connection'),
            'survivor ' . $index . ' close error drifted: ' . $afterCloseError
        );
        king_websocket_server_abort_cleanup_assert(
            king_client_websocket_get_status($client) === 3,
            'survivor ' . $index . ' should report closed after cleanup'
        );
    }
} finally {
    if ($abortingSocket !== null) {
        king_server_websocket_wire_raw_client_abort($abortingSocket);
    }

    $capture = king_server_websocket_wire_stop_server($server);
}

$acceptedInfo = $capture['accepted_info'] ?? [];
$connectionIds = array_map(
    static fn(array $info): string => (string) ($info['connection_id'] ?? ''),
    $acceptedInfo
);

king_websocket_server_abort_cleanup_assert(($capture['server_class'] ?? '') === 'King\\WebSocket\\Server', 'server class drifted');
king_websocket_server_abort_cleanup_assert(($capture['abort_cleanup_exception_class'] ?? '') === '', 'server threw ' . ($capture['abort_cleanup_exception_class'] ?? 'unknown'));
king_websocket_server_abort_cleanup_assert(($capture['abort_cleanup_exception_message'] ?? '') === '', 'server abort cleanup error drifted');
king_websocket_server_abort_cleanup_assert(($capture['registry_count_after_accept'] ?? -1) === 3, 'registry_after_accept count mismatch');
king_websocket_server_abort_cleanup_assert(($capture['broadcast_ok'] ?? false) === true, 'post-abort broadcast should stay available');
king_websocket_server_abort_cleanup_assert(($capture['broadcast_error'] ?? '') === '', 'broadcast error drifted');
king_websocket_server_abort_cleanup_assert(($capture['registry_count_after_abort'] ?? -1) === 2, 'registry_after_abort should prune the aborted peer');
king_websocket_server_abort_cleanup_assert(($capture['registry_count_after_close'] ?? -1) === 0, 'registry_after_close should be empty');
king_websocket_server_abort_cleanup_assert(($capture['survivor_send_ok'][0] ?? false) === true, 'survivor A targeted send failed');
king_websocket_server_abort_cleanup_assert(($capture['survivor_send_error'][0] ?? '') === '', 'survivor A send error drifted');
king_websocket_server_abort_cleanup_assert(($capture['survivor_send_ok'][2] ?? false) === true, 'survivor B targeted send failed');
king_websocket_server_abort_cleanup_assert(($capture['survivor_send_error'][2] ?? '') === '', 'survivor B send error drifted');
king_websocket_server_abort_cleanup_assert(($capture['aborted_send_exception'] ?? '') === 'King\\RuntimeException', 'aborted peer should reject post-cleanup sends');
king_websocket_server_abort_cleanup_assert(
    str_contains($capture['aborted_send_error'] ?? '', $connectionIds[1] ?? ''),
    'aborted peer error should include the removed connection id'
);
king_websocket_server_abort_cleanup_assert(($capture['stop_error'] ?? '') === '', 'server stop error drifted');

foreach ([0, 1, 2] as $i) {
    king_websocket_server_abort_cleanup_assert(($capture['ready_messages'][$i] ?? null) === 'ready-' . $i, 'ready message mismatch for peer ' . $i);
    king_websocket_server_abort_cleanup_assert(($capture['ready_errors'][$i] ?? '') === '', 'ready error drifted for peer ' . $i);
}

king_websocket_server_abort_cleanup_assert(
    ($capture['registry_after_accept'][$connectionIds[0]]['connection_id'] ?? '') === ($connectionIds[0] ?? ''),
    'registry_after_accept lost survivor A'
);
king_websocket_server_abort_cleanup_assert(
    !isset($capture['registry_after_abort'][$connectionIds[1] ?? '']),
    'registry_after_abort should remove the aborted peer'
);
king_websocket_server_abort_cleanup_assert(
    ($capture['registry_after_abort'][$connectionIds[2]]['connection_id'] ?? '') === ($connectionIds[2] ?? ''),
    'registry_after_abort lost survivor B'
);
king_websocket_server_abort_cleanup_assert(($capture['close_ok'][0] ?? false) === true, 'survivor A close failed');
king_websocket_server_abort_cleanup_assert(($capture['close_ok'][2] ?? false) === true, 'survivor B close failed');

echo "OK\n";
?>
--EXPECT--
OK
