<?php

declare(strict_types=1);

require_once __DIR__ . '/module_realtime_lobby_security.php';

function videochat_realtime_handle_lobby_websocket_command(
    array $lobbyCommand,
    mixed $websocket,
    array &$lobbyState,
    array &$presenceState,
    array $presenceConnection,
    callable $openDatabase
): ?array {
    if (!(bool) ($lobbyCommand['ok'] ?? false)) {
        return (string) ($lobbyCommand['error'] ?? '') === 'unsupported_type'
            ? null
            : videochat_realtime_secondary_invalid_result($lobbyCommand);
    }

    $lobbyCommandRoomId = videochat_presence_normalize_room_id((string) ($lobbyCommand['room_id'] ?? ''), '');
    if ($lobbyCommandRoomId === '') {
        $lobbyCommandRoomId = videochat_realtime_lobby_room_id_for_connection($presenceConnection);
    }
    if (($lobbySecurityResult = videochat_realtime_reject_unauthorized_lobby_moderation_command(
        $presenceConnection, $lobbyCommand, $lobbyCommandRoomId, $websocket, $openDatabase
    )) !== null) {
        return $lobbySecurityResult;
    }
    videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        $lobbyCommandRoomId,
        videochat_realtime_connection_call_id($presenceConnection),
        null,
        videochat_realtime_connection_tenant_id($presenceConnection)
    );

    $deferredLobbyFrames = [];
    $deferredLobbySender = static function (mixed $socket, array $payload) use (&$deferredLobbyFrames): bool {
        $deferredLobbyFrames[] = ['socket' => $socket, 'payload' => $payload];
        return true;
    };

    $lobbyResult = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $presenceConnection,
        $lobbyCommand,
        $deferredLobbySender
    );
    if (!(bool) ($lobbyResult['ok'] ?? false)) {
        videochat_realtime_send_lobby_command_error($websocket, $lobbyCommand, $lobbyResult, (string) ($presenceConnection['room_id'] ?? 'lobby'));
        return videochat_realtime_secondary_handled_result();
    }

    $applyResult = videochat_realtime_apply_successful_lobby_command(
        $lobbyResult,
        $lobbyState,
        $presenceState,
        $presenceConnection,
        $openDatabase
    );
    if (!(bool) ($applyResult['ok'] ?? false)) {
        videochat_realtime_send_lobby_command_error($websocket, $lobbyCommand, $applyResult, $lobbyCommandRoomId);
        return videochat_realtime_secondary_handled_result();
    }

    foreach ($deferredLobbyFrames as $frame) {
        if (is_array($frame)) {
            videochat_presence_send_frame($frame['socket'] ?? null, is_array($frame['payload'] ?? null) ? $frame['payload'] : []);
        }
    }
    return videochat_realtime_secondary_handled_result();
}

function videochat_realtime_send_lobby_command_error(mixed $websocket, array $command, array $result, string $roomId): void
{
    videochat_presence_send_frame(
        $websocket,
        [
            'type' => 'system/error',
            'code' => 'lobby_command_failed',
            'message' => 'Could not apply lobby command.',
            'details' => [
                'error' => (string) ($result['error'] ?? 'unknown'),
                'type' => (string) ($command['type'] ?? ''),
                'target_user_id' => (int) ($command['target_user_id'] ?? 0),
                'room_id' => $roomId,
            ],
            'time' => gmdate('c'),
        ]
    );
}

function videochat_realtime_apply_successful_lobby_command(
    array $lobbyResult,
    array &$lobbyState,
    array &$presenceState,
    array $presenceConnection,
    callable $openDatabase
): array {
    $lobbyAction = (string) ($lobbyResult['action'] ?? '');
    $lobbyStateName = (string) ($lobbyResult['state'] ?? '');
    $lobbyResultRoomId = videochat_presence_normalize_room_id(
        (string) ($lobbyResult['room_id'] ?? ($presenceConnection['room_id'] ?? 'lobby'))
    );

    if ($lobbyAction === 'lobby/queue/join' && in_array($lobbyStateName, ['queued', 'already_queued'], true)) {
        videochat_realtime_mark_call_participant_pending_for_queue($openDatabase, $presenceConnection);
        videochat_realtime_sync_lobby_room_from_database(
            $lobbyState,
            $openDatabase,
            $lobbyResultRoomId,
            videochat_realtime_connection_call_id($presenceConnection),
            null,
            videochat_realtime_connection_tenant_id($presenceConnection)
        );
        videochat_lobby_broadcast_room_snapshot(
            $lobbyState,
            $presenceState,
            $lobbyResultRoomId,
            $lobbyStateName === 'already_queued' ? 'already_queued' : 'queued',
            null,
            null,
            is_numeric($presenceConnection['tenant_id'] ?? null) ? (int) $presenceConnection['tenant_id'] : null
        );
    } elseif ($lobbyAction === 'lobby/queue/cancel') {
        videochat_realtime_mark_call_participant_invite_state($openDatabase, $presenceConnection, 'invited', ['pending']);
        videochat_realtime_sync_lobby_room_from_database(
            $lobbyState,
            $openDatabase,
            $lobbyResultRoomId,
            videochat_realtime_connection_call_id($presenceConnection),
            null,
            videochat_realtime_connection_tenant_id($presenceConnection)
        );
    }

    if ($lobbyAction === 'lobby/remove') {
        videochat_realtime_apply_lobby_remove_result($lobbyResult, $lobbyState, $presenceConnection, $openDatabase, $lobbyResultRoomId);
    }

    if (in_array($lobbyAction, ['lobby/allow', 'lobby/allow_all'], true)) {
        return videochat_realtime_apply_lobby_admission_result($lobbyResult, $lobbyState, $presenceState, $presenceConnection, $openDatabase);
    }

    return ['ok' => true, 'error' => ''];
}

function videochat_realtime_apply_lobby_remove_result(
    array $lobbyResult,
    array &$lobbyState,
    array $presenceConnection,
    callable $openDatabase,
    string $lobbyResultRoomId
): void {
    $removedCallId = videochat_realtime_connection_call_id($presenceConnection);
    $removedUserIds = is_array($lobbyResult['affected_user_ids'] ?? null)
        ? array_values(array_filter(array_map('intval', (array) $lobbyResult['affected_user_ids']), static fn (int $id): bool => $id > 0))
        : [];
    if ($removedCallId === '' || $removedUserIds === []) {
        return;
    }

    foreach ($removedUserIds as $removedUserId) {
        videochat_realtime_mark_call_participant_invite_state_by_user_id(
            $openDatabase,
            $removedCallId,
            $removedUserId,
            'cancelled',
            ['pending', 'allowed', 'accepted']
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
}

function videochat_realtime_apply_lobby_admission_result(
    array $lobbyResult,
    array &$lobbyState,
    array &$presenceState,
    array $presenceConnection,
    callable $openDatabase
): array {
    $admittedRoomId = videochat_presence_normalize_room_id(
        (string) ($lobbyResult['room_id'] ?? ($presenceConnection['room_id'] ?? 'lobby'))
    );
    $admittedUserIds = is_array($lobbyResult['affected_user_ids'] ?? null)
        ? array_values(array_filter(array_map('intval', (array) $lobbyResult['affected_user_ids']), static fn (int $id): bool => $id > 0))
        : [];
    if ($admittedRoomId === '' || $admittedUserIds === []) {
        return ['ok' => true, 'error' => ''];
    }

    $admittedCallId = videochat_realtime_connection_call_id($presenceConnection);
    $persistedUserIds = [];
    if ($admittedCallId !== '') {
        foreach ($admittedUserIds as $admittedUserId) {
            if (videochat_realtime_mark_call_participant_invite_state_by_user_id(
                $openDatabase,
                $admittedCallId,
                $admittedUserId,
                'allowed',
                ['pending']
            )) {
                $persistedUserIds[] = $admittedUserId;
            }
        }
    }

    $sync = videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        $admittedRoomId,
        $admittedCallId,
        null,
        videochat_realtime_connection_tenant_id($presenceConnection)
    );
    if (count($persistedUserIds) !== count($admittedUserIds)) {
        if (!(bool) ($sync['ok'] ?? false)) {
            videochat_realtime_restore_failed_lobby_admission($lobbyState, $admittedRoomId, $admittedUserIds);
        }
        return ['ok' => false, 'error' => 'lobby_admission_persist_failed'];
    }

    videochat_realtime_send_lobby_snapshot_to_users($presenceState, $lobbyState, $admittedRoomId, $admittedUserIds, 'admitted', null);
    return ['ok' => true, 'error' => ''];
}

function videochat_realtime_restore_failed_lobby_admission(array &$lobbyState, string $roomId, array $userIds): void
{
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedRoomId === '') {
        return;
    }

    videochat_lobby_ensure_room_state($lobbyState, $normalizedRoomId);
    $nowMs = videochat_lobby_now_ms();
    $nowIso = gmdate('c', (int) floor($nowMs / 1000));
    $queuedByUser = &$lobbyState['rooms'][$normalizedRoomId]['queued_by_user'];
    $admittedByUser = &$lobbyState['rooms'][$normalizedRoomId]['admitted_by_user'];
    foreach ($userIds as $userId) {
        $normalizedUserId = (int) $userId;
        if ($normalizedUserId <= 0) {
            continue;
        }

        $admitted = is_array($admittedByUser[$normalizedUserId] ?? null) ? $admittedByUser[$normalizedUserId] : [];
        unset($admittedByUser[$normalizedUserId]);
        if (!isset($queuedByUser[$normalizedUserId]) || !is_array($queuedByUser[$normalizedUserId])) {
            $queuedByUser[$normalizedUserId] = [
                'user_id' => $normalizedUserId,
                'display_name' => (string) ($admitted['display_name'] ?? ''),
                'role' => videochat_normalize_role_slug((string) ($admitted['role'] ?? 'user')),
                'requested_unix_ms' => $nowMs,
                'requested_at' => $nowIso,
            ];
        }
    }
}
