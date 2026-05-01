<?php

declare(strict_types=1);

require_once __DIR__ . '/realtime_lobby_state.php';

/**
 * @return array{
 *   ok: bool,
 *   type: string,
 *   target_user_id: int,
 *   error: string,
 *   room_id: string
 * }
 */
function videochat_lobby_decode_client_frame(string $frame): array
{
    $decoded = json_decode($frame, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'type' => '',
            'target_user_id' => 0,
            'error' => 'invalid_json',
            'room_id' => '',
        ];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type === '') {
        return [
            'ok' => false,
            'type' => '',
            'target_user_id' => 0,
            'error' => 'missing_type',
            'room_id' => '',
        ];
    }

    if (!in_array($type, ['lobby/queue/request', 'lobby/queue/join', 'lobby/queue/cancel', 'lobby/allow', 'lobby/remove', 'lobby/allow_all'], true)) {
        return [
            'ok' => false,
            'type' => $type,
            'target_user_id' => 0,
            'error' => 'unsupported_type',
            'room_id' => '',
        ];
    }

    $roomId = '';
    if (array_key_exists('room_id', $decoded) || array_key_exists('roomId', $decoded)) {
        $roomIdRaw = (string) ($decoded['room_id'] ?? $decoded['roomId'] ?? '');
        $roomId = videochat_presence_normalize_room_id($roomIdRaw, '');
        if ($roomId === '') {
            return [
                'ok' => false,
                'type' => $type,
                'target_user_id' => 0,
                'error' => 'invalid_room_id',
                'room_id' => '',
            ];
        }
    }

    if (!in_array($type, ['lobby/allow', 'lobby/remove'], true)) {
        return [
            'ok' => true,
            'type' => $type,
            'target_user_id' => 0,
            'error' => '',
            'room_id' => $roomId,
        ];
    }

    $rawTargetUserId = $decoded['target_user_id'] ?? ($decoded['targetUserId'] ?? null);
    if ($rawTargetUserId === null) {
        return [
            'ok' => false,
            'type' => $type,
            'target_user_id' => 0,
            'error' => 'missing_target_user_id',
            'room_id' => $roomId,
        ];
    }

    $targetUserId = 0;
    if (is_int($rawTargetUserId)) {
        $targetUserId = $rawTargetUserId;
    } elseif (is_string($rawTargetUserId)) {
        $candidate = trim($rawTargetUserId);
        if ($candidate !== '' && preg_match('/^[0-9]+$/', $candidate) === 1) {
            $targetUserId = (int) $candidate;
        }
    }

    if ($targetUserId <= 0) {
        return [
            'ok' => false,
            'type' => $type,
            'target_user_id' => 0,
            'error' => 'invalid_target_user_id',
            'room_id' => $roomId,
        ];
    }

    return [
        'ok' => true,
        'type' => $type,
        'target_user_id' => $targetUserId,
        'error' => '',
        'room_id' => $roomId,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   error: string,
 *   changed: bool,
 *   sent_count: int,
 *   action: string,
 *   target_user_id: int,
 *   room_id: string,
 *   affected_user_ids: array<int, int>
 * }
 */
function videochat_lobby_apply_command(
    array &$lobbyState,
    array $presenceState,
    array $connection,
    array $command,
    ?callable $sender = null,
    ?int $nowUnixMs = null
): array {
    if (!(bool) ($command['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'invalid_command',
            'changed' => false,
            'sent_count' => 0,
            'action' => '',
            'target_user_id' => 0,
            'room_id' => '',
            'affected_user_ids' => [],
        ];
    }

    $commandRoomId = videochat_presence_normalize_room_id((string) ($command['room_id'] ?? ''), '');
    $roomId = $commandRoomId !== ''
        ? $commandRoomId
        : videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? 'lobby'));
    $userId = (int) ($connection['user_id'] ?? 0);
    if ($userId <= 0) {
        return [
            'ok' => false,
            'error' => 'invalid_sender',
            'changed' => false,
            'sent_count' => 0,
            'action' => '',
            'target_user_id' => 0,
            'room_id' => $roomId,
            'affected_user_ids' => [],
        ];
    }

    $connectionId = trim((string) ($connection['connection_id'] ?? ''));
    $activeConnection = $presenceState['connections'][$connectionId] ?? null;
    $roomConnections = $presenceState['rooms'][$roomId] ?? null;
    $senderCurrentRoomId = is_array($activeConnection)
        ? videochat_presence_normalize_room_id((string) ($activeConnection['room_id'] ?? 'lobby'))
        : videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? 'lobby'));
    $senderPendingRoomId = is_array($activeConnection)
        ? videochat_presence_normalize_room_id((string) ($activeConnection['pending_room_id'] ?? ''), '')
        : videochat_presence_normalize_room_id((string) ($connection['pending_room_id'] ?? ''), '');
    $isQueueCommand = in_array((string) ($command['type'] ?? ''), ['lobby/queue/join', 'lobby/queue/request', 'lobby/queue/cancel'], true);
    $senderCanQueueTargetRoom = $isQueueCommand && (
        $senderCurrentRoomId === $roomId
        || ($senderCurrentRoomId === 'lobby' && $roomId === 'lobby')
        || ($senderCurrentRoomId === 'waiting-room' && $senderPendingRoomId === $roomId)
    );
    if (
        $connectionId === ''
        || !is_array($activeConnection)
        || (
            !is_array($roomConnections)
            || !array_key_exists($connectionId, $roomConnections)
        ) && !$senderCanQueueTargetRoom
    ) {
        return [
            'ok' => false,
            'error' => 'sender_not_in_room',
            'changed' => false,
            'sent_count' => 0,
            'action' => (string) ($command['type'] ?? ''),
            'target_user_id' => (int) ($command['target_user_id'] ?? 0),
            'room_id' => $roomId,
            'affected_user_ids' => [],
        ];
    }

    videochat_lobby_ensure_room_state($lobbyState, $roomId);
    $nowMs = videochat_lobby_now_ms($nowUnixMs);
    $nowIso = gmdate('c', (int) floor($nowMs / 1000));
    $action = (string) ($command['type'] ?? '');
    $targetUserId = (int) ($command['target_user_id'] ?? 0);
    $queuedByUser = &$lobbyState['rooms'][$roomId]['queued_by_user'];
    $admittedByUser = &$lobbyState['rooms'][$roomId]['admitted_by_user'];

    if ($action === 'lobby/queue/request') {
        $sent = videochat_lobby_send_snapshot_to_connection($lobbyState, $connection, 'queue_requested', $sender, $nowMs);
        return [
            'ok' => true,
            'error' => '',
            'changed' => false,
            'sent_count' => $sent ? 1 : 0,
            'action' => $action,
            'state' => 'queue_requested',
            'target_user_id' => 0,
            'room_id' => $roomId,
            'affected_user_ids' => [],
        ];
    }

    if ($action === 'lobby/queue/join') {
        if (isset($admittedByUser[$userId]) && is_array($admittedByUser[$userId])) {
            $sent = videochat_lobby_send_snapshot_to_connection($lobbyState, $connection, 'already_admitted', $sender, $nowMs);
            return [
                'ok' => true,
                'error' => '',
                'changed' => false,
                'sent_count' => $sent ? 1 : 0,
                'action' => $action,
                'state' => 'already_admitted',
                'target_user_id' => $userId,
                'room_id' => $roomId,
                'affected_user_ids' => [],
            ];
        }

        if (isset($queuedByUser[$userId]) && is_array($queuedByUser[$userId])) {
            $sentCount = videochat_lobby_broadcast_room_snapshot(
                $lobbyState,
                $presenceState,
                $roomId,
                'already_queued',
                $sender,
                $nowMs
            );
            if ($senderCurrentRoomId !== $roomId) {
                $sentCount += videochat_lobby_send_snapshot_to_connection(
                    $lobbyState,
                    $connection,
                    'already_queued',
                    $sender,
                    $nowMs
                ) ? 1 : 0;
            }
            return [
                'ok' => true,
                'error' => '',
                'changed' => false,
                'sent_count' => $sentCount,
                'action' => $action,
                'state' => 'already_queued',
                'target_user_id' => $userId,
                'room_id' => $roomId,
                'affected_user_ids' => [],
            ];
        }

        $queuedByUser[$userId] = [
            'user_id' => $userId,
            'display_name' => (string) ($connection['display_name'] ?? ''),
            'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? 'user')),
            'requested_unix_ms' => $nowMs,
            'requested_at' => $nowIso,
        ];
        unset($admittedByUser[$userId]);

        $sentCount = videochat_lobby_broadcast_room_snapshot(
            $lobbyState,
            $presenceState,
            $roomId,
            'queued',
            $sender,
            $nowMs
        );
        if ($senderCurrentRoomId !== $roomId) {
            $sentCount += videochat_lobby_send_snapshot_to_connection(
                $lobbyState,
                $connection,
                'queued',
                $sender,
                $nowMs
            ) ? 1 : 0;
        }

        return [
            'ok' => true,
            'error' => '',
            'changed' => true,
            'sent_count' => $sentCount,
            'action' => $action,
            'state' => 'queued',
            'target_user_id' => $userId,
            'room_id' => $roomId,
            'affected_user_ids' => [$userId],
        ];
    }

    if ($action === 'lobby/queue/cancel') {
        $changed = false;
        if (isset($queuedByUser[$userId])) {
            unset($queuedByUser[$userId]);
            $changed = true;
        }
        if (isset($admittedByUser[$userId])) {
            unset($admittedByUser[$userId]);
            $changed = true;
        }

        if (!$changed) {
            $sent = videochat_lobby_send_snapshot_to_connection($lobbyState, $connection, 'cancel_noop', $sender, $nowMs);
            return [
                'ok' => true,
                'error' => '',
                'changed' => false,
                'sent_count' => $sent ? 1 : 0,
                'action' => $action,
                'state' => 'cancel_noop',
                'target_user_id' => $userId,
                'room_id' => $roomId,
                'affected_user_ids' => [],
            ];
        }

        $sentCount = videochat_lobby_broadcast_room_snapshot(
            $lobbyState,
            $presenceState,
            $roomId,
            'cancelled',
            $sender,
            $nowMs
        );
        videochat_lobby_prune_empty_room_state($lobbyState, $roomId);

        return [
            'ok' => true,
            'error' => '',
            'changed' => true,
            'sent_count' => $sentCount,
            'action' => $action,
            'state' => 'cancelled',
            'target_user_id' => $userId,
            'room_id' => $roomId,
            'affected_user_ids' => [$userId],
        ];
    }

    if (!videochat_lobby_can_moderate($connection)) {
        return [
            'ok' => false,
            'error' => 'forbidden',
            'changed' => false,
            'sent_count' => 0,
            'action' => $action,
            'target_user_id' => $targetUserId,
            'room_id' => $roomId,
            'affected_user_ids' => [],
        ];
    }

    if ($action === 'lobby/allow') {
        $target = $queuedByUser[$targetUserId] ?? null;
        if (!is_array($target)) {
            return [
                'ok' => false,
                'error' => 'target_not_queued',
                'changed' => false,
                'sent_count' => 0,
                'action' => $action,
                'target_user_id' => $targetUserId,
                'room_id' => $roomId,
                'affected_user_ids' => [],
            ];
        }

        unset($queuedByUser[$targetUserId]);
        $admittedByUser[$targetUserId] = [
            'user_id' => (int) ($target['user_id'] ?? $targetUserId),
            'display_name' => (string) ($target['display_name'] ?? ''),
            'role' => videochat_normalize_role_slug((string) ($target['role'] ?? 'user')),
            'admitted_unix_ms' => $nowMs,
            'admitted_at' => $nowIso,
            'admitted_by' => [
                'user_id' => $userId,
                'display_name' => (string) ($connection['display_name'] ?? ''),
                'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? 'user')),
            ],
        ];

        $sentCount = videochat_lobby_broadcast_room_snapshot(
            $lobbyState,
            $presenceState,
            $roomId,
            'allowed',
            $sender,
            $nowMs
        );

        return [
            'ok' => true,
            'error' => '',
            'changed' => true,
            'sent_count' => $sentCount,
            'action' => $action,
            'state' => 'allowed',
            'target_user_id' => $targetUserId,
            'room_id' => $roomId,
            'affected_user_ids' => [$targetUserId],
        ];
    }

    if ($action === 'lobby/remove') {
        $changed = false;
        if (isset($queuedByUser[$targetUserId])) {
            unset($queuedByUser[$targetUserId]);
            $changed = true;
        }
        if (isset($admittedByUser[$targetUserId])) {
            unset($admittedByUser[$targetUserId]);
            $changed = true;
        }

        if (!$changed) {
            return [
                'ok' => false,
                'error' => 'target_not_found',
                'changed' => false,
                'sent_count' => 0,
                'action' => $action,
                'target_user_id' => $targetUserId,
                'room_id' => $roomId,
                'affected_user_ids' => [],
            ];
        }

        $sentCount = videochat_lobby_broadcast_room_snapshot(
            $lobbyState,
            $presenceState,
            $roomId,
            'removed',
            $sender,
            $nowMs
        );

        videochat_lobby_prune_empty_room_state($lobbyState, $roomId);

        return [
            'ok' => true,
            'error' => '',
            'changed' => true,
            'sent_count' => $sentCount,
            'action' => $action,
            'target_user_id' => $targetUserId,
            'room_id' => $roomId,
            'affected_user_ids' => [$targetUserId],
        ];
    }

    if ($action === 'lobby/allow_all') {
        if ($queuedByUser === []) {
            return [
                'ok' => true,
                'error' => '',
                'changed' => false,
                'sent_count' => 0,
                'action' => $action,
                'state' => 'allow_all_noop',
                'target_user_id' => 0,
                'room_id' => $roomId,
                'affected_user_ids' => [],
            ];
        }

        $affectedUserIds = [];
        foreach ($queuedByUser as $queuedUserId => $queuedEntry) {
            $normalizedUserId = (int) $queuedUserId;
            if ($normalizedUserId <= 0 || !is_array($queuedEntry)) {
                continue;
            }
            $affectedUserIds[] = $normalizedUserId;
            $admittedByUser[$normalizedUserId] = [
                'user_id' => (int) ($queuedEntry['user_id'] ?? $normalizedUserId),
                'display_name' => (string) ($queuedEntry['display_name'] ?? ''),
                'role' => videochat_normalize_role_slug((string) ($queuedEntry['role'] ?? 'user')),
                'admitted_unix_ms' => $nowMs,
                'admitted_at' => $nowIso,
                'admitted_by' => [
                    'user_id' => $userId,
                    'display_name' => (string) ($connection['display_name'] ?? ''),
                    'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? 'user')),
                ],
            ];
        }
        $queuedByUser = [];

        $sentCount = videochat_lobby_broadcast_room_snapshot(
            $lobbyState,
            $presenceState,
            $roomId,
            'allow_all',
            $sender,
            $nowMs
        );

        return [
            'ok' => true,
            'error' => '',
            'changed' => true,
            'sent_count' => $sentCount,
            'action' => $action,
            'state' => 'allow_all',
            'target_user_id' => 0,
            'room_id' => $roomId,
            'affected_user_ids' => $affectedUserIds,
        ];
    }

    return [
        'ok' => false,
        'error' => 'unsupported_type',
        'changed' => false,
        'sent_count' => 0,
        'action' => $action,
        'target_user_id' => $targetUserId,
        'room_id' => $roomId,
        'affected_user_ids' => [],
    ];
}

/**
 * @return array{
 *   cleared: bool,
 *   sent_count: int,
 *   room_id: string,
 *   affected_user_ids: array<int, int>
 * }
 */
function videochat_lobby_clear_for_connection(
    array &$lobbyState,
    array $presenceState,
    array $connection,
    string $reason = 'presence_left',
    ?callable $sender = null,
    ?int $nowUnixMs = null
): array {
    $currentRoomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $pendingRoomId = videochat_presence_normalize_room_id((string) ($connection['pending_room_id'] ?? ''), '');
    $roomId = $currentRoomId === 'waiting-room' && $pendingRoomId !== ''
        ? $pendingRoomId
        : $currentRoomId;
    $userId = (int) ($connection['user_id'] ?? 0);
    $connectionId = trim((string) ($connection['connection_id'] ?? ''));
    if ($roomId === '' || $userId <= 0) {
        return ['cleared' => false, 'sent_count' => 0, 'room_id' => $roomId, 'affected_user_ids' => []];
    }

    $roomState = $lobbyState['rooms'][$roomId] ?? null;
    if (!is_array($roomState)) {
        return ['cleared' => false, 'sent_count' => 0, 'room_id' => $roomId, 'affected_user_ids' => []];
    }

    if (videochat_lobby_user_present_in_room($presenceState, $roomId, $userId, $connectionId)) {
        return ['cleared' => false, 'sent_count' => 0, 'room_id' => $roomId, 'affected_user_ids' => []];
    }

    videochat_lobby_ensure_room_state($lobbyState, $roomId);
    $queuedByUser = &$lobbyState['rooms'][$roomId]['queued_by_user'];
    $admittedByUser = &$lobbyState['rooms'][$roomId]['admitted_by_user'];

    $changed = false;
    $affectedUserIds = [];
    if (isset($queuedByUser[$userId])) {
        unset($queuedByUser[$userId]);
        $changed = true;
        $affectedUserIds[$userId] = $userId;
    }
    if ($currentRoomId !== 'waiting-room' && isset($admittedByUser[$userId])) {
        unset($admittedByUser[$userId]);
        $changed = true;
        $affectedUserIds[$userId] = $userId;
    }

    if (!$changed) {
        videochat_lobby_prune_empty_room_state($lobbyState, $roomId);
        return ['cleared' => false, 'sent_count' => 0, 'room_id' => $roomId, 'affected_user_ids' => []];
    }

    $sentCount = videochat_lobby_broadcast_room_snapshot(
        $lobbyState,
        $presenceState,
        $roomId,
        trim($reason) === '' ? 'presence_left' : trim($reason),
        $sender,
        $nowUnixMs
    );
    videochat_lobby_prune_empty_room_state($lobbyState, $roomId);

    return [
        'cleared' => true,
        'sent_count' => $sentCount,
        'room_id' => $roomId,
        'affected_user_ids' => array_values($affectedUserIds),
    ];
}
