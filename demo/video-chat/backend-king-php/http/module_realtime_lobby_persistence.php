<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/audit/audit_lobby_events.php';

function videochat_realtime_lobby_command_sender(array $lobbyCommand): ?callable
{
    $type = (string) ($lobbyCommand['type'] ?? '');
    if (!in_array($type, ['lobby/allow', 'lobby/allow_all'], true)) {
        return null;
    }

    return static fn (mixed $_socket, array $_payload): bool => false;
}

function videochat_realtime_lobby_audit_context(array $presenceConnection, string $roomId, string $attemptedAction = '', array $extra = []): array
{
    return [
        'room_id' => $roomId,
        'session_id' => (string) ($presenceConnection['session_id'] ?? ''),
        'actor_role' => (string) ($presenceConnection['role'] ?? 'user'),
        'actor_call_role' => (string) ($presenceConnection['effective_call_role'] ?? ($presenceConnection['call_role'] ?? 'participant')),
        'attempted_action' => $attemptedAction,
    ] + $extra;
}

function videochat_realtime_record_lobby_entry_audit(
    callable $openDatabase,
    array $presenceConnection,
    string $roomId,
    int $targetUserId,
    string $attemptedAction
): void {
    $callId = videochat_realtime_connection_call_id($presenceConnection);
    if ($callId === '' || $targetUserId <= 0) {
        return;
    }

    try {
        videochat_audit_record_call_lobby_entry(
            $openDatabase(),
            videochat_realtime_connection_tenant_id($presenceConnection),
            $callId,
            $targetUserId,
            videochat_realtime_lobby_audit_context($presenceConnection, $roomId, $attemptedAction)
        );
    } catch (Throwable) {
    }
}

function videochat_realtime_record_lobby_admission_audit(
    callable $openDatabase,
    array $presenceConnection,
    string $roomId,
    int $targetUserId,
    string $attemptedAction
): void {
    $callId = videochat_realtime_connection_call_id($presenceConnection);
    $actorUserId = (int) ($presenceConnection['user_id'] ?? 0);
    if ($callId === '' || $actorUserId <= 0 || $targetUserId <= 0) {
        return;
    }

    try {
        videochat_audit_record_call_lobby_admission(
            $openDatabase(),
            videochat_realtime_connection_tenant_id($presenceConnection),
            $callId,
            $actorUserId,
            $targetUserId,
            videochat_realtime_lobby_audit_context($presenceConnection, $roomId, $attemptedAction)
        );
    } catch (Throwable) {
    }
}

function videochat_realtime_record_lobby_rejection_audit(
    callable $openDatabase,
    array $presenceConnection,
    string $roomId,
    int $targetUserId,
    string $attemptedAction,
    string $previousState = 'pending'
): void {
    $callId = videochat_realtime_connection_call_id($presenceConnection);
    $actorUserId = (int) ($presenceConnection['user_id'] ?? 0);
    if ($callId === '' || $actorUserId <= 0 || $targetUserId <= 0) {
        return;
    }

    try {
        videochat_audit_record_call_lobby_rejection(
            $openDatabase(),
            videochat_realtime_connection_tenant_id($presenceConnection),
            $callId,
            $actorUserId,
            $targetUserId,
            videochat_realtime_lobby_audit_context($presenceConnection, $roomId, $attemptedAction, [
                'previous_state' => $previousState,
            ])
        );
    } catch (Throwable) {
    }
}

function videochat_realtime_apply_successful_lobby_command(
    array $lobbyResult,
    array &$lobbyState,
    array &$presenceState,
    array $presenceConnection,
    callable $openDatabase
): void {
    $lobbyAction = (string) ($lobbyResult['action'] ?? '');
    $requestedAction = (string) ($lobbyResult['requested_action'] ?? $lobbyAction);
    $lobbyStateName = (string) ($lobbyResult['state'] ?? '');
    $lobbyResultRoomId = videochat_presence_normalize_room_id(
        (string) ($lobbyResult['room_id'] ?? ($presenceConnection['room_id'] ?? 'lobby'))
    );

    if ($lobbyAction === 'lobby/queue/join' && in_array($lobbyStateName, ['queued', 'already_queued'], true)) {
        $entryPersisted = videochat_realtime_mark_call_participant_pending_for_queue($openDatabase, $presenceConnection);
        if ($entryPersisted && $lobbyStateName === 'queued') {
            videochat_realtime_record_lobby_entry_audit(
                $openDatabase,
                $presenceConnection,
                $lobbyResultRoomId,
                (int) ($lobbyResult['target_user_id'] ?? ($presenceConnection['user_id'] ?? 0)),
                $requestedAction
            );
        }
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
        $removeResult = videochat_realtime_apply_lobby_remove_result(
            $lobbyResult,
            $lobbyState,
            $presenceState,
            $presenceConnection,
            $openDatabase,
            $lobbyResultRoomId
        );
        if ($requestedAction === 'lobby/reject' && is_array($removeResult)) {
            $removedUserIds = is_array($removeResult['removed_user_ids'] ?? null) ? $removeResult['removed_user_ids'] : [];
            $activeTargetUserIds = is_array($removeResult['active_target_user_ids'] ?? null) ? $removeResult['active_target_user_ids'] : [];
            $activeTargetSet = array_fill_keys(array_map('intval', $activeTargetUserIds), true);
            foreach ($removedUserIds as $removedUserId) {
                $targetUserId = (int) $removedUserId;
                videochat_realtime_record_lobby_rejection_audit(
                    $openDatabase,
                    $presenceConnection,
                    $lobbyResultRoomId,
                    $targetUserId,
                    $requestedAction,
                    isset($activeTargetSet[$targetUserId]) ? 'admitted' : 'pending'
                );
            }
        }
    }

    if (in_array($lobbyAction, ['lobby/allow', 'lobby/allow_all'], true)) {
        videochat_realtime_apply_lobby_admission_result(
            $lobbyResult,
            $lobbyState,
            $presenceState,
            $presenceConnection,
            $openDatabase,
            $requestedAction
        );
    }
}

/**
 * @param array<int, int> $userIds
 */
function videochat_realtime_repair_unpersisted_lobby_admissions(
    array &$lobbyState,
    string $roomId,
    array $userIds,
    ?int $nowUnixMs = null
): void {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedRoomId === '' || $userIds === []) {
        return;
    }

    videochat_lobby_ensure_room_state($lobbyState, $normalizedRoomId);
    $queuedByUser = &$lobbyState['rooms'][$normalizedRoomId]['queued_by_user'];
    $admittedByUser = &$lobbyState['rooms'][$normalizedRoomId]['admitted_by_user'];
    $nowMs = videochat_lobby_now_ms($nowUnixMs);
    $nowIso = gmdate('c', (int) floor($nowMs / 1000));

    foreach ($userIds as $userId) {
        $normalizedUserId = (int) $userId;
        if ($normalizedUserId <= 0 || !isset($admittedByUser[$normalizedUserId])) {
            continue;
        }

        $admitted = is_array($admittedByUser[$normalizedUserId])
            ? $admittedByUser[$normalizedUserId]
            : [];
        unset($admittedByUser[$normalizedUserId]);

        if (isset($queuedByUser[$normalizedUserId]) && is_array($queuedByUser[$normalizedUserId])) {
            continue;
        }

        $queuedByUser[$normalizedUserId] = [
            'user_id' => $normalizedUserId,
            'display_name' => (string) ($admitted['display_name'] ?? ''),
            'role' => videochat_normalize_role_slug((string) ($admitted['role'] ?? 'user')),
            'requested_unix_ms' => $nowMs,
            'requested_at' => $nowIso,
        ];
    }

    videochat_lobby_prune_empty_room_state($lobbyState, $normalizedRoomId);
}

function videochat_realtime_apply_lobby_admission_result(
    array $lobbyResult,
    array &$lobbyState,
    array &$presenceState,
    array $presenceConnection,
    callable $openDatabase,
    string $requestedAction = ''
): void {
    $admittedRoomId = videochat_presence_normalize_room_id(
        (string) ($lobbyResult['room_id'] ?? ($presenceConnection['room_id'] ?? 'lobby')),
        ''
    );
    if ($admittedRoomId === '') {
        return;
    }

    $admittedUserIds = is_array($lobbyResult['affected_user_ids'] ?? null)
        ? array_values(array_filter(array_map('intval', (array) $lobbyResult['affected_user_ids']), static fn (int $id): bool => $id > 0))
        : [];
    $targetUserId = (int) ($lobbyResult['target_user_id'] ?? 0);
    $admittedCallId = videochat_realtime_connection_call_id($presenceConnection);
    $tenantId = videochat_realtime_connection_tenant_id($presenceConnection);

    if ($admittedUserIds === []) {
        videochat_realtime_sync_lobby_room_from_database(
            $lobbyState,
            $openDatabase,
            $admittedRoomId,
            $admittedCallId,
            null,
            $tenantId
        );
        videochat_lobby_broadcast_room_snapshot(
            $lobbyState,
            $presenceState,
            $admittedRoomId,
            (string) ($lobbyResult['state'] ?? 'admission_noop'),
            null,
            null,
            $tenantId
        );
        if ($targetUserId > 0) {
            videochat_realtime_send_lobby_snapshot_to_users($presenceState, $lobbyState, $admittedRoomId, [$targetUserId], 'already_admitted', null);
        }
        return;
    }

    $persistedUserIds = [];
    $auditPersistedUserIds = [];
    $unpersistedUserIds = [];
    if ($admittedCallId !== '') {
        foreach ($admittedUserIds as $admittedUserId) {
            $persisted = videochat_realtime_mark_call_participant_invite_state_by_user_id(
                $openDatabase,
                $admittedCallId,
                $admittedUserId,
                'allowed',
                ['pending']
            );
            if ($persisted) {
                $persistedUserIds[] = $admittedUserId;
                $auditPersistedUserIds[] = $admittedUserId;
            } else {
                $unpersistedUserIds[] = $admittedUserId;
            }
        }
    } else {
        $unpersistedUserIds = $admittedUserIds;
    }

    $sync = videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        $admittedRoomId,
        $admittedCallId,
        null,
        $tenantId
    );
    $syncOk = (bool) ($sync['ok'] ?? false);
    if ($syncOk && $unpersistedUserIds !== []) {
        $roomState = is_array($lobbyState['rooms'][$admittedRoomId] ?? null)
            ? $lobbyState['rooms'][$admittedRoomId]
            : [];
        $canonicalAdmitted = is_array($roomState['admitted_by_user'] ?? null)
            ? $roomState['admitted_by_user']
            : [];
        $stillUnpersisted = [];
        foreach ($unpersistedUserIds as $unpersistedUserId) {
            if (isset($canonicalAdmitted[$unpersistedUserId]) && is_array($canonicalAdmitted[$unpersistedUserId])) {
                $persistedUserIds[] = $unpersistedUserId;
                continue;
            }

            $stillUnpersisted[] = $unpersistedUserId;
        }
        $unpersistedUserIds = $stillUnpersisted;
        $persistedUserIds = array_values(array_unique($persistedUserIds));
    }
    if (!$syncOk && $unpersistedUserIds !== []) {
        videochat_realtime_repair_unpersisted_lobby_admissions($lobbyState, $admittedRoomId, $unpersistedUserIds);
    }
    foreach ($auditPersistedUserIds as $auditPersistedUserId) {
        videochat_realtime_record_lobby_admission_audit(
            $openDatabase,
            $presenceConnection,
            $admittedRoomId,
            $auditPersistedUserId,
            $requestedAction !== '' ? $requestedAction : (string) ($lobbyResult['action'] ?? 'lobby/allow')
        );
    }

    $reason = $unpersistedUserIds === []
        ? ((string) ($lobbyResult['state'] ?? '') === 'allow_all' ? 'allow_all' : 'allowed')
        : 'admission_pending';
    videochat_lobby_broadcast_room_snapshot(
        $lobbyState,
        $presenceState,
        $admittedRoomId,
        $reason,
        null,
        null,
        $tenantId
    );

    if ($persistedUserIds !== []) {
        videochat_realtime_send_lobby_snapshot_to_users($presenceState, $lobbyState, $admittedRoomId, $persistedUserIds, 'admitted', null);
    }
    if ($unpersistedUserIds !== []) {
        videochat_realtime_send_lobby_snapshot_to_users($presenceState, $lobbyState, $admittedRoomId, $unpersistedUserIds, 'admission_pending', null);
    }
}
