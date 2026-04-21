<?php

declare(strict_types=1);

function videochat_realtime_presence_has_room_membership(
    array $presenceState,
    string $roomId,
    int $userId,
    string $sessionId = ''
): bool {
    if ($userId <= 0) {
        return false;
    }

    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedRoomId === '') {
        return false;
    }

    $trimmedSessionId = trim($sessionId);
    foreach (($presenceState['connections'] ?? []) as $connection) {
        if (!is_array($connection)) {
            continue;
        }
        if ((int) ($connection['user_id'] ?? 0) !== $userId) {
            continue;
        }
        if (videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '') !== $normalizedRoomId) {
            continue;
        }
        if ($trimmedSessionId !== '' && trim((string) ($connection['session_id'] ?? '')) !== $trimmedSessionId) {
            continue;
        }

        return true;
    }

    return false;
}

function videochat_realtime_send_lobby_snapshot_to_users(
    array $presenceState,
    array $lobbyState,
    string $roomId,
    array $userIds,
    string $reason = 'admitted',
    ?callable $sender = null
): int {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $normalizedUserIds = [];
    foreach ($userIds as $userId) {
        $normalizedUserId = (int) $userId;
        if ($normalizedUserId > 0) {
            $normalizedUserIds[$normalizedUserId] = true;
        }
    }
    if ($normalizedUserIds === []) {
        return 0;
    }

    $payload = videochat_lobby_snapshot_payload($lobbyState, $normalizedRoomId, $reason);
    $sentCount = 0;
    foreach (($presenceState['connections'] ?? []) as $connection) {
        if (!is_array($connection)) {
            continue;
        }

        $connectionUserId = (int) ($connection['user_id'] ?? 0);
        if ($connectionUserId <= 0 || !isset($normalizedUserIds[$connectionUserId])) {
            continue;
        }

        $connectionPayload = videochat_lobby_snapshot_payload_for_connection($payload, $connection);
        if (videochat_presence_send_frame($connection['socket'] ?? null, $connectionPayload, $sender)) {
            $sentCount++;
        }
    }

    return $sentCount;
}

/**
 * @return array{room_id: string, call_id: string, queue_count: int, admitted_count: int, changed: bool, ok: bool}
 */
function videochat_realtime_sync_lobby_room_from_database(
    array &$lobbyState,
    callable $openDatabase,
    string $roomId,
    string $preferredCallId = '',
    ?int $nowUnixMs = null
): array {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    $normalizedPreferredCallId = videochat_realtime_normalize_call_id($preferredCallId, '');
    $fallback = [
        'room_id' => $normalizedRoomId,
        'call_id' => '',
        'queue_count' => 0,
        'admitted_count' => 0,
        'changed' => false,
        'ok' => false,
    ];
    if ($normalizedRoomId === '' || $normalizedRoomId === videochat_realtime_waiting_room_id()) {
        return $fallback;
    }

    try {
        $pdo = $openDatabase();

        $callWhere = 'WHERE calls.room_id = :room_id AND calls.status IN (\'active\', \'scheduled\')';
        $callParams = [':room_id' => $normalizedRoomId];
        if ($normalizedPreferredCallId !== '') {
            $callWhere .= ' AND calls.id = :call_id';
            $callParams[':call_id'] = $normalizedPreferredCallId;
        }

        $callQuery = $pdo->prepare(
            <<<SQL
SELECT calls.id
FROM calls
{$callWhere}
ORDER BY
    CASE calls.status
        WHEN 'active' THEN 0
        ELSE 1
    END ASC,
    calls.starts_at ASC,
    calls.created_at ASC
LIMIT 1
SQL
        );
        $callQuery->execute($callParams);
        $callId = (string) ($callQuery->fetchColumn() ?: '');
        if ($callId === '') {
            return $fallback;
        }

        $previousRoomState = is_array($lobbyState['rooms'][$normalizedRoomId] ?? null)
            ? $lobbyState['rooms'][$normalizedRoomId]
            : [];
        $previousQueuedByUser = is_array($previousRoomState['queued_by_user'] ?? null)
            ? $previousRoomState['queued_by_user']
            : [];
        $previousAdmittedByUser = is_array($previousRoomState['admitted_by_user'] ?? null)
            ? $previousRoomState['admitted_by_user']
            : [];

        $rowQuery = $pdo->prepare(
            <<<'SQL'
SELECT
    cp.user_id,
    cp.email,
    cp.display_name AS participant_display_name,
    cp.call_role,
    cp.invite_state,
    cp.joined_at,
    cp.left_at,
    users.display_name AS user_display_name,
    roles.slug AS role_slug
FROM call_participants cp
LEFT JOIN users ON users.id = cp.user_id
LEFT JOIN roles ON roles.id = users.role_id
WHERE cp.call_id = :call_id
  AND cp.source = 'internal'
  AND cp.user_id IS NOT NULL
  AND cp.invite_state IN ('pending', 'allowed', 'accepted')
ORDER BY
    cp.display_name ASC,
    cp.user_id ASC
SQL
        );
        $rowQuery->execute([':call_id' => $callId]);

        $nowMs = videochat_lobby_now_ms($nowUnixMs);
        $queuedByUser = [];
        $admittedByUser = [];
        while (($row = $rowQuery->fetch()) !== false) {
            if (!is_array($row)) {
                continue;
            }

            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $inviteState = videochat_realtime_normalize_call_invite_state($row['invite_state'] ?? 'invited');
            $displayName = trim((string) ($row['participant_display_name'] ?? ''));
            if ($displayName === '') {
                $displayName = trim((string) ($row['user_display_name'] ?? ''));
            }
            if ($displayName === '') {
                $displayName = trim((string) ($row['email'] ?? ''));
            }
            if ($displayName === '') {
                $displayName = 'User ' . $userId;
            }

            $role = videochat_normalize_role_slug((string) ($row['role_slug'] ?? 'user'));
            if ($inviteState === 'pending') {
                $previous = is_array($previousQueuedByUser[$userId] ?? null) ? $previousQueuedByUser[$userId] : [];
                $requestedMs = (int) ($previous['requested_unix_ms'] ?? 0);
                if ($requestedMs <= 0) {
                    $requestedMs = $nowMs;
                }
                $queuedByUser[$userId] = [
                    'user_id' => $userId,
                    'display_name' => $displayName,
                    'role' => $role,
                    'requested_unix_ms' => $requestedMs,
                    'requested_at' => (string) ($previous['requested_at'] ?? gmdate('c', (int) floor($requestedMs / 1000))),
                ];
                continue;
            }

            $joinedAt = trim((string) ($row['joined_at'] ?? ''));
            $callRole = videochat_normalize_call_participant_role((string) ($row['call_role'] ?? 'participant'));
            if ($joinedAt !== '' || $callRole === 'owner') {
                continue;
            }

            $previous = is_array($previousAdmittedByUser[$userId] ?? null) ? $previousAdmittedByUser[$userId] : [];
            $admittedMs = (int) ($previous['admitted_unix_ms'] ?? 0);
            if ($admittedMs <= 0) {
                $admittedMs = $nowMs;
            }
            $admittedByUser[$userId] = [
                'user_id' => $userId,
                'display_name' => $displayName,
                'role' => $role,
                'admitted_unix_ms' => $admittedMs,
                'admitted_at' => (string) ($previous['admitted_at'] ?? gmdate('c', (int) floor($admittedMs / 1000))),
                'admitted_by' => is_array($previous['admitted_by'] ?? null) ? $previous['admitted_by'] : [],
            ];
        }

        $nextRoomState = [
            'queued_by_user' => $queuedByUser,
            'admitted_by_user' => $admittedByUser,
        ];
        $changed = $previousRoomState !== $nextRoomState;
        if ($queuedByUser === [] && $admittedByUser === []) {
            unset($lobbyState['rooms'][$normalizedRoomId]);
        } else {
            $lobbyState['rooms'][$normalizedRoomId] = $nextRoomState;
        }

        return [
            'room_id' => $normalizedRoomId,
            'call_id' => $callId,
            'queue_count' => count($queuedByUser),
            'admitted_count' => count($admittedByUser),
            'changed' => $changed,
            'ok' => true,
        ];
    } catch (Throwable) {
        return $fallback;
    }
}

function videochat_realtime_lobby_room_id_for_connection(array $connection): string
{
    $currentRoomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $pendingRoomId = videochat_presence_normalize_room_id((string) ($connection['pending_room_id'] ?? ''), '');

    return $currentRoomId === videochat_realtime_waiting_room_id() && $pendingRoomId !== ''
        ? $pendingRoomId
        : videochat_presence_normalize_room_id($currentRoomId, 'lobby');
}

function videochat_realtime_lobby_snapshot_signature(array $payload): string
{
    $encoded = json_encode(
        [
            'room_id' => (string) ($payload['room_id'] ?? ''),
            'queue' => is_array($payload['queue'] ?? null) ? $payload['queue'] : [],
            'admitted' => is_array($payload['admitted'] ?? null) ? $payload['admitted'] : [],
        ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    return is_string($encoded) ? hash('sha1', $encoded) : '';
}

/**
 * @return array{sent: bool, room_id: string, signature: string}
 */
function videochat_realtime_send_synced_lobby_snapshot_to_connection(
    array &$lobbyState,
    array $connection,
    callable $openDatabase,
    string $reason = 'snapshot',
    ?callable $sender = null,
    ?int $nowUnixMs = null
): array {
    $roomId = videochat_realtime_lobby_room_id_for_connection($connection);
    videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        $roomId,
        videochat_realtime_connection_call_id($connection),
        $nowUnixMs
    );

    $payload = videochat_lobby_snapshot_payload($lobbyState, $roomId, $reason, $nowUnixMs);
    $payload = videochat_lobby_snapshot_payload_for_connection($payload, $connection);
    $signature = videochat_realtime_lobby_snapshot_signature($payload);
    $sent = videochat_presence_send_frame($connection['socket'] ?? null, $payload, $sender);

    return [
        'sent' => $sent,
        'room_id' => (string) ($payload['room_id'] ?? $roomId),
        'signature' => $signature,
    ];
}

function videochat_realtime_send_synced_lobby_snapshot_to_connection_if_changed(
    array &$lobbyState,
    array $connection,
    callable $openDatabase,
    string &$lastSignature,
    string $reason = 'snapshot',
    ?callable $sender = null,
    ?int $nowUnixMs = null
): bool {
    $roomId = videochat_realtime_lobby_room_id_for_connection($connection);
    videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        $roomId,
        videochat_realtime_connection_call_id($connection),
        $nowUnixMs
    );

    $payload = videochat_lobby_snapshot_payload($lobbyState, $roomId, $reason, $nowUnixMs);
    $payload = videochat_lobby_snapshot_payload_for_connection($payload, $connection);
    $signature = videochat_realtime_lobby_snapshot_signature($payload);
    if ($signature !== '' && $signature === $lastSignature) {
        return false;
    }

    $sent = videochat_presence_send_frame($connection['socket'] ?? null, $payload, $sender);
    if ($sent && $signature !== '') {
        $lastSignature = $signature;
    }

    return $sent;
}
