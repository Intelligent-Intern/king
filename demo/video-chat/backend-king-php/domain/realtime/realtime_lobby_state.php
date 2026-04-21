<?php

declare(strict_types=1);

function videochat_lobby_state_init(): array
{
    return [
        'rooms' => [],
    ];
}

function videochat_lobby_can_moderate(array $connection): bool
{
    $rawRole = strtolower(trim((string) ($connection['raw_role'] ?? $connection['role'] ?? '')));
    $globalRole = videochat_normalize_role_slug((string) ($connection['role'] ?? ''));
    if ($globalRole === 'admin' || $rawRole === 'moderator') {
        return true;
    }

    $callRole = strtolower(trim((string) ($connection['call_role'] ?? 'participant')));
    return in_array($callRole, ['owner', 'moderator'], true);
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

function videochat_lobby_snapshot_payload_for_connection(array $payload, array $connection): array
{
    if (videochat_lobby_can_moderate($connection)) {
        return $payload;
    }

    $viewerUserId = (int) ($connection['user_id'] ?? 0);
    $filterOwnEntry = static function (array $entries) use ($viewerUserId): array {
        if ($viewerUserId <= 0) {
            return [];
        }

        return array_values(array_filter(
            $entries,
            static fn (mixed $entry): bool => is_array($entry) && (int) ($entry['user_id'] ?? 0) === $viewerUserId
        ));
    };

    $queue = $filterOwnEntry(is_array($payload['queue'] ?? null) ? $payload['queue'] : []);
    $admitted = $filterOwnEntry(is_array($payload['admitted'] ?? null) ? $payload['admitted'] : []);

    $payload['queue'] = $queue;
    $payload['queue_count'] = count($queue);
    $payload['admitted'] = $admitted;
    $payload['admitted_count'] = count($admitted);

    return $payload;
}

function videochat_lobby_send_snapshot_to_connection(
    array $lobbyState,
    array $connection,
    string $reason = 'snapshot',
    ?callable $sender = null,
    ?int $nowUnixMs = null
): bool {
    $currentRoomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $pendingRoomId = videochat_presence_normalize_room_id((string) ($connection['pending_room_id'] ?? ''), '');
    $roomId = $currentRoomId === 'waiting-room' && $pendingRoomId !== ''
        ? $pendingRoomId
        : videochat_presence_normalize_room_id($currentRoomId, 'lobby');
    $payload = videochat_lobby_snapshot_payload($lobbyState, $roomId, $reason, $nowUnixMs);
    $payload = videochat_lobby_snapshot_payload_for_connection($payload, $connection);

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

        $connectionPayload = videochat_lobby_snapshot_payload_for_connection($payload, $connection);
        if (videochat_presence_send_frame($connection['socket'] ?? null, $connectionPayload, $sender)) {
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

function videochat_lobby_is_user_admitted_for_room(array $lobbyState, string $roomId, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $roomState = $lobbyState['rooms'][$normalizedRoomId] ?? null;
    if (!is_array($roomState)) {
        return false;
    }

    return isset($roomState['admitted_by_user'][$userId]) && is_array($roomState['admitted_by_user'][$userId]);
}

function videochat_lobby_remove_user_from_room(
    array &$lobbyState,
    string $roomId,
    int $userId
): bool {
    if ($userId <= 0) {
        return false;
    }

    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $roomState = $lobbyState['rooms'][$normalizedRoomId] ?? null;
    if (!is_array($roomState)) {
        return false;
    }

    videochat_lobby_ensure_room_state($lobbyState, $normalizedRoomId);
    $queuedByUser = &$lobbyState['rooms'][$normalizedRoomId]['queued_by_user'];
    $admittedByUser = &$lobbyState['rooms'][$normalizedRoomId]['admitted_by_user'];

    $changed = false;
    if (isset($queuedByUser[$userId])) {
        unset($queuedByUser[$userId]);
        $changed = true;
    }
    if (isset($admittedByUser[$userId])) {
        unset($admittedByUser[$userId]);
        $changed = true;
    }

    videochat_lobby_prune_empty_room_state($lobbyState, $normalizedRoomId);
    return $changed;
}

/**
 * @return array{
 *   ok: bool,
 *   error: string,
 *   changed: bool,
 *   sent_count: int,
 *   room_id: string,
 *   target_user_id: int
 * }
 */
function videochat_lobby_queue_connection_for_room(
    array &$lobbyState,
    array $presenceState,
    array $connection,
    string $roomId,
    ?callable $sender = null,
    ?int $nowUnixMs = null
): array {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $userId = (int) ($connection['user_id'] ?? 0);
    if ($userId <= 0) {
        return [
            'ok' => false,
            'error' => 'invalid_sender',
            'changed' => false,
            'sent_count' => 0,
            'room_id' => $normalizedRoomId,
            'target_user_id' => 0,
        ];
    }

    $connectionId = trim((string) ($connection['connection_id'] ?? ''));
    if (
        $connectionId === ''
        || !isset($presenceState['connections'][$connectionId])
        || !is_array($presenceState['connections'][$connectionId])
    ) {
        return [
            'ok' => false,
            'error' => 'sender_not_connected',
            'changed' => false,
            'sent_count' => 0,
            'room_id' => $normalizedRoomId,
            'target_user_id' => $userId,
        ];
    }

    videochat_lobby_ensure_room_state($lobbyState, $normalizedRoomId);
    $nowMs = videochat_lobby_now_ms($nowUnixMs);
    $nowIso = gmdate('c', (int) floor($nowMs / 1000));
    $queuedByUser = &$lobbyState['rooms'][$normalizedRoomId]['queued_by_user'];
    $admittedByUser = &$lobbyState['rooms'][$normalizedRoomId]['admitted_by_user'];

    if (isset($admittedByUser[$userId]) && is_array($admittedByUser[$userId])) {
        return [
            'ok' => true,
            'error' => '',
            'changed' => false,
            'sent_count' => 0,
            'room_id' => $normalizedRoomId,
            'target_user_id' => $userId,
        ];
    }

    if (isset($queuedByUser[$userId]) && is_array($queuedByUser[$userId])) {
        return [
            'ok' => true,
            'error' => '',
            'changed' => false,
            'sent_count' => 0,
            'room_id' => $normalizedRoomId,
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
        $normalizedRoomId,
        'queued',
        $sender,
        $nowMs
    );

    return [
        'ok' => true,
        'error' => '',
        'changed' => true,
        'sent_count' => $sentCount,
        'room_id' => $normalizedRoomId,
        'target_user_id' => $userId,
    ];
}
