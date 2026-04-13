<?php

declare(strict_types=1);

function videochat_lobby_state_init(): array
{
    return [
        'rooms' => [],
    ];
}

function videochat_lobby_is_moderator(array $connection): bool
{
    $role = videochat_normalize_role_slug((string) ($connection['role'] ?? ''));
    return in_array($role, ['admin', 'moderator'], true);
}

function videochat_lobby_ensure_room_state(array &$lobbyState, string $roomId): void
{
    if (!isset($lobbyState['rooms'][$roomId]) || !is_array($lobbyState['rooms'][$roomId])) {
        $lobbyState['rooms'][$roomId] = [
            'queued_by_user' => [],
            'admitted_by_user' => [],
        ];
        return;
    }

    if (!isset($lobbyState['rooms'][$roomId]['queued_by_user']) || !is_array($lobbyState['rooms'][$roomId]['queued_by_user'])) {
        $lobbyState['rooms'][$roomId]['queued_by_user'] = [];
    }
    if (!isset($lobbyState['rooms'][$roomId]['admitted_by_user']) || !is_array($lobbyState['rooms'][$roomId]['admitted_by_user'])) {
        $lobbyState['rooms'][$roomId]['admitted_by_user'] = [];
    }
}

function videochat_lobby_prune_empty_room_state(array &$lobbyState, string $roomId): void
{
    $roomState = $lobbyState['rooms'][$roomId] ?? null;
    if (!is_array($roomState)) {
        unset($lobbyState['rooms'][$roomId]);
        return;
    }

    $queued = $roomState['queued_by_user'] ?? [];
    $admitted = $roomState['admitted_by_user'] ?? [];
    if (is_array($queued) && is_array($admitted) && $queued === [] && $admitted === []) {
        unset($lobbyState['rooms'][$roomId]);
    }
}

function videochat_lobby_now_ms(?int $nowUnixMs = null): int
{
    return is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);
}

function videochat_lobby_sort_entries(array $entries, string $timestampKey): array
{
    usort(
        $entries,
        static function (array $left, array $right) use ($timestampKey): int {
            $leftTs = (int) ($left[$timestampKey] ?? 0);
            $rightTs = (int) ($right[$timestampKey] ?? 0);
            if ($leftTs !== $rightTs) {
                return $leftTs <=> $rightTs;
            }

            $leftName = strtolower(trim((string) ($left['display_name'] ?? '')));
            $rightName = strtolower(trim((string) ($right['display_name'] ?? '')));
            $nameCompare = $leftName <=> $rightName;
            if ($nameCompare !== 0) {
                return $nameCompare;
            }

            return ((int) ($left['user_id'] ?? 0)) <=> ((int) ($right['user_id'] ?? 0));
        }
    );

    return $entries;
}

function videochat_lobby_snapshot_payload(
    array $lobbyState,
    string $roomId,
    string $reason = 'snapshot',
    ?int $nowUnixMs = null
): array {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $roomState = $lobbyState['rooms'][$normalizedRoomId] ?? null;
    $queued = is_array($roomState['queued_by_user'] ?? null)
        ? array_values($roomState['queued_by_user'])
        : [];
    $admitted = is_array($roomState['admitted_by_user'] ?? null)
        ? array_values($roomState['admitted_by_user'])
        : [];

    $queued = videochat_lobby_sort_entries($queued, 'requested_unix_ms');
    $admitted = videochat_lobby_sort_entries($admitted, 'admitted_unix_ms');

    $nowMs = videochat_lobby_now_ms($nowUnixMs);
    $nowIso = gmdate('c', (int) floor($nowMs / 1000));

    return [
        'type' => 'lobby/snapshot',
        'room_id' => $normalizedRoomId,
        'queue' => $queued,
        'queue_count' => count($queued),
        'admitted' => $admitted,
        'admitted_count' => count($admitted),
        'reason' => trim($reason) === '' ? 'snapshot' : trim($reason),
        'server_unix_ms' => $nowMs,
        'server_time' => $nowIso,
        'time' => $nowIso,
    ];
}

function videochat_lobby_send_snapshot_to_connection(
    array $lobbyState,
    array $connection,
    string $reason = 'snapshot',
    ?callable $sender = null,
    ?int $nowUnixMs = null
): bool {
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? 'lobby'));
    $payload = videochat_lobby_snapshot_payload($lobbyState, $roomId, $reason, $nowUnixMs);

    return videochat_presence_send_frame($connection['socket'] ?? null, $payload, $sender);
}

function videochat_lobby_broadcast_room_snapshot(
    array $lobbyState,
    array $presenceState,
    string $roomId,
    string $reason = 'updated',
    ?callable $sender = null,
    ?int $nowUnixMs = null
): int {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $roomConnections = $presenceState['rooms'][$normalizedRoomId] ?? null;
    if (!is_array($roomConnections) || $roomConnections === []) {
        return 0;
    }

    $payload = videochat_lobby_snapshot_payload($lobbyState, $normalizedRoomId, $reason, $nowUnixMs);
    $sentCount = 0;
    foreach ($roomConnections as $connectionId => $_socket) {
        if (!is_string($connectionId) || $connectionId === '') {
            continue;
        }

        $connection = $presenceState['connections'][$connectionId] ?? null;
        if (!is_array($connection)) {
            continue;
        }

        if (videochat_presence_send_frame($connection['socket'] ?? null, $payload, $sender)) {
            $sentCount++;
        }
    }

    return $sentCount;
}

function videochat_lobby_user_present_in_room(
    array $presenceState,
    string $roomId,
    int $userId,
    ?string $excludeConnectionId = null
): bool {
    if ($userId <= 0) {
        return false;
    }

    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $roomConnections = $presenceState['rooms'][$normalizedRoomId] ?? null;
    if (!is_array($roomConnections) || $roomConnections === []) {
        return false;
    }

    $excluded = trim((string) ($excludeConnectionId ?? ''));
    foreach ($roomConnections as $connectionId => $_socket) {
        if (!is_string($connectionId) || $connectionId === '') {
            continue;
        }
        if ($excluded !== '' && $connectionId === $excluded) {
            continue;
        }

        $connection = $presenceState['connections'][$connectionId] ?? null;
        if (!is_array($connection)) {
            continue;
        }
        if ((int) ($connection['user_id'] ?? 0) === $userId) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{
 *   ok: bool,
 *   type: string,
 *   target_user_id: int,
 *   error: string
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
        ];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type === '') {
        return [
            'ok' => false,
            'type' => '',
            'target_user_id' => 0,
            'error' => 'missing_type',
        ];
    }

    if (!in_array($type, ['lobby/queue/request', 'lobby/queue/join', 'lobby/allow', 'lobby/remove', 'lobby/allow_all'], true)) {
        return [
            'ok' => false,
            'type' => $type,
            'target_user_id' => 0,
            'error' => 'unsupported_type',
        ];
    }

    if (!in_array($type, ['lobby/allow', 'lobby/remove'], true)) {
        return [
            'ok' => true,
            'type' => $type,
            'target_user_id' => 0,
            'error' => '',
        ];
    }

    $rawTargetUserId = $decoded['target_user_id'] ?? ($decoded['targetUserId'] ?? null);
    if ($rawTargetUserId === null) {
        return [
            'ok' => false,
            'type' => $type,
            'target_user_id' => 0,
            'error' => 'missing_target_user_id',
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
        ];
    }

    return [
        'ok' => true,
        'type' => $type,
        'target_user_id' => $targetUserId,
        'error' => '',
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   error: string,
 *   changed: bool,
 *   sent_count: int,
 *   action: string,
 *   target_user_id: int
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
        ];
    }

    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? 'lobby'));
    $userId = (int) ($connection['user_id'] ?? 0);
    if ($userId <= 0) {
        return [
            'ok' => false,
            'error' => 'invalid_sender',
            'changed' => false,
            'sent_count' => 0,
            'action' => '',
            'target_user_id' => 0,
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
            'target_user_id' => 0,
        ];
    }

    if ($action === 'lobby/queue/join') {
        if (isset($queuedByUser[$userId]) && is_array($queuedByUser[$userId])) {
            $sent = videochat_lobby_send_snapshot_to_connection($lobbyState, $connection, 'already_queued', $sender, $nowMs);
            return [
                'ok' => true,
                'error' => '',
                'changed' => false,
                'sent_count' => $sent ? 1 : 0,
                'action' => $action,
                'target_user_id' => $userId,
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

        return [
            'ok' => true,
            'error' => '',
            'changed' => true,
            'sent_count' => $sentCount,
            'action' => $action,
            'target_user_id' => $userId,
        ];
    }

    if (!videochat_lobby_is_moderator($connection)) {
        return [
            'ok' => false,
            'error' => 'forbidden',
            'changed' => false,
            'sent_count' => 0,
            'action' => $action,
            'target_user_id' => $targetUserId,
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
                'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? 'moderator')),
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
            'target_user_id' => $targetUserId,
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
                'target_user_id' => 0,
            ];
        }

        foreach ($queuedByUser as $queuedUserId => $queuedEntry) {
            $normalizedUserId = (int) $queuedUserId;
            if ($normalizedUserId <= 0 || !is_array($queuedEntry)) {
                continue;
            }
            $admittedByUser[$normalizedUserId] = [
                'user_id' => (int) ($queuedEntry['user_id'] ?? $normalizedUserId),
                'display_name' => (string) ($queuedEntry['display_name'] ?? ''),
                'role' => videochat_normalize_role_slug((string) ($queuedEntry['role'] ?? 'user')),
                'admitted_unix_ms' => $nowMs,
                'admitted_at' => $nowIso,
                'admitted_by' => [
                    'user_id' => $userId,
                    'display_name' => (string) ($connection['display_name'] ?? ''),
                    'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? 'moderator')),
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
            'target_user_id' => 0,
        ];
    }

    return [
        'ok' => false,
        'error' => 'unsupported_type',
        'changed' => false,
        'sent_count' => 0,
        'action' => $action,
        'target_user_id' => $targetUserId,
    ];
}

/**
 * @return array{
 *   cleared: bool,
 *   sent_count: int
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
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $userId = (int) ($connection['user_id'] ?? 0);
    $connectionId = trim((string) ($connection['connection_id'] ?? ''));
    if ($roomId === '' || $userId <= 0) {
        return ['cleared' => false, 'sent_count' => 0];
    }

    $roomState = $lobbyState['rooms'][$roomId] ?? null;
    if (!is_array($roomState)) {
        return ['cleared' => false, 'sent_count' => 0];
    }

    if (videochat_lobby_user_present_in_room($presenceState, $roomId, $userId, $connectionId)) {
        return ['cleared' => false, 'sent_count' => 0];
    }

    videochat_lobby_ensure_room_state($lobbyState, $roomId);
    $queuedByUser = &$lobbyState['rooms'][$roomId]['queued_by_user'];
    $admittedByUser = &$lobbyState['rooms'][$roomId]['admitted_by_user'];

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
        videochat_lobby_prune_empty_room_state($lobbyState, $roomId);
        return ['cleared' => false, 'sent_count' => 0];
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
    ];
}
