<?php

declare(strict_types=1);

function videochat_realtime_lobby_remove_result_for_active_call_target(
    array $lobbyResult,
    array $lobbyCommand,
    array $presenceConnection,
    string $roomId,
    callable $openDatabase
): array {
    if (
        (bool) ($lobbyResult['ok'] ?? false)
        || (string) ($lobbyResult['error'] ?? '') !== 'target_not_found'
        || !in_array((string) ($lobbyCommand['type'] ?? ''), ['lobby/remove', 'lobby/kick'], true)
    ) {
        return $lobbyResult;
    }

    $targetUserId = (int) ($lobbyCommand['target_user_id'] ?? 0);
    if (
        $targetUserId <= 0
        || !videochat_realtime_db_room_has_joined_user($openDatabase, $presenceConnection, $roomId, $targetUserId)
    ) {
        return $lobbyResult;
    }

    return [
        'ok' => true,
        'error' => '',
        'changed' => true,
        'sent_count' => 0,
        'action' => 'lobby/remove',
        'state' => 'removed',
        'target_user_id' => $targetUserId,
        'room_id' => $roomId,
        'affected_user_ids' => [$targetUserId],
        'active_target_user_ids' => [$targetUserId],
    ];
}

/**
 * @return array<int, int>
 */
function videochat_realtime_lobby_result_user_ids(array $lobbyResult, string $key): array
{
    if (!is_array($lobbyResult[$key] ?? null)) {
        return [];
    }

    return array_values(array_filter(
        array_map('intval', (array) $lobbyResult[$key]),
        static fn (int $id): bool => $id > 0
    ));
}

function videochat_realtime_apply_lobby_remove_result(
    array $lobbyResult,
    array &$lobbyState,
    array &$presenceState,
    array $presenceConnection,
    callable $openDatabase,
    string $lobbyResultRoomId
): array {
    $removedCallId = videochat_realtime_connection_call_id($presenceConnection);
    $removedUserIds = videochat_realtime_lobby_result_user_ids($lobbyResult, 'affected_user_ids');
    if ($removedCallId === '' || $removedUserIds === []) {
        return [
            'removed_user_ids' => [],
            'active_target_user_ids' => [],
        ];
    }
    $activeTargetUserIds = videochat_realtime_lobby_result_user_ids($lobbyResult, 'active_target_user_ids');
    $activeTargetSet = array_fill_keys($activeTargetUserIds, true);
    $persistedRemovedUserIds = [];
    $persistedActiveTargetUserIds = [];

    foreach ($removedUserIds as $removedUserId) {
        if (isset($activeTargetSet[$removedUserId])) {
            if (videochat_realtime_mark_call_participant_removed_from_active_call($openDatabase, $removedCallId, $removedUserId)) {
                $persistedRemovedUserIds[] = $removedUserId;
                $persistedActiveTargetUserIds[] = $removedUserId;
            }
        } else {
            if (videochat_realtime_mark_call_participant_invite_state_by_user_id(
                $openDatabase,
                $removedCallId,
                $removedUserId,
                'invited',
                ['pending', 'allowed', 'accepted']
            )) {
                $persistedRemovedUserIds[] = $removedUserId;
            }
        }
    }

    if ($activeTargetUserIds !== []) {
        videochat_realtime_disconnect_removed_call_participants(
            $presenceState,
            $openDatabase,
            $lobbyResultRoomId,
            $removedCallId,
            $activeTargetUserIds,
            videochat_realtime_connection_tenant_id($presenceConnection)
        );
        videochat_realtime_broadcast_room_snapshot(
            $presenceState,
            $lobbyResultRoomId,
            $openDatabase,
            'participant_kicked',
            '',
            null,
            videochat_realtime_connection_tenant_id($presenceConnection)
        );
    }

    videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        $lobbyResultRoomId,
        $removedCallId,
        null,
        videochat_realtime_connection_tenant_id($presenceConnection)
    );

    return [
        'removed_user_ids' => $persistedRemovedUserIds,
        'active_target_user_ids' => $persistedActiveTargetUserIds,
    ];
}

function videochat_realtime_disconnect_removed_call_participants(
    array &$presenceState,
    callable $openDatabase,
    string $roomId,
    string $callId,
    array $targetUserIds,
    ?int $tenantId = null
): int {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    $normalizedCallId = videochat_realtime_normalize_call_id($callId, '');
    if ($normalizedRoomId === '' || $normalizedCallId === '' || $targetUserIds === []) {
        return 0;
    }

    $targetSet = [];
    foreach ($targetUserIds as $targetUserId) {
        $normalizedUserId = (int) $targetUserId;
        if ($normalizedUserId > 0) {
            $targetSet[$normalizedUserId] = true;
        }
    }
    if ($targetSet === []) {
        return 0;
    }

    $roomConnections = $presenceState['rooms'][videochat_presence_room_key($normalizedRoomId, $tenantId)] ?? [];
    $connectionsToRemove = [];
    if (is_array($roomConnections)) {
        foreach ($roomConnections as $connectionId => $_socket) {
            if (!is_string($connectionId) || $connectionId === '') {
                continue;
            }
            $connection = $presenceState['connections'][$connectionId] ?? null;
            if (!is_array($connection)) {
                continue;
            }
            $userId = (int) ($connection['user_id'] ?? 0);
            if ($userId <= 0 || !isset($targetSet[$userId])) {
                continue;
            }
            $connectionsToRemove[$connectionId] = $connection;
        }
    }

    foreach (array_keys($targetSet) as $targetUserId) {
        videochat_realtime_remove_call_presence_for_room_user($openDatabase, $normalizedCallId, $normalizedRoomId, (int) $targetUserId);
    }

    $removedCount = 0;
    foreach ($connectionsToRemove as $connectionId => $connection) {
        videochat_realtime_send_removed_from_call_notice($connection, $normalizedRoomId, $normalizedCallId);
        videochat_presence_remove_connection($presenceState, (string) $connectionId);
        videochat_realtime_remove_call_presence($openDatabase, $connection);
        $removedCount++;
    }

    return $removedCount;
}

function videochat_realtime_send_removed_from_call_notice(array $connection, string $roomId, string $callId): void
{
    $socket = $connection['socket'] ?? null;
    videochat_presence_send_frame(
        $socket,
        [
            'type' => 'system/error',
            'code' => 'websocket_forbidden',
            'message' => 'You were removed from the call.',
            'details' => [
                'reason' => 'kicked_from_call',
                'room_id' => $roomId,
                'call_id' => $callId,
            ],
            'time' => gmdate('c'),
        ]
    );

    if (!function_exists('king_client_websocket_close')) {
        return;
    }

    try {
        king_client_websocket_close($socket, 1008, 'kicked_from_call');
    } catch (Throwable) {
        return;
    }
}
