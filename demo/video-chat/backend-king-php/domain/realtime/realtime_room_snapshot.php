<?php

declare(strict_types=1);

function videochat_realtime_db_room_participants(callable $openDatabase, array $connection): array
{
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $callId = videochat_realtime_normalize_call_id(
        (string) (($connection['active_call_id'] ?? '') ?: ($connection['requested_call_id'] ?? '')),
        ''
    );
    if ($roomId === '' || $callId === '') {
        return [];
    }

    try {
        $pdo = $openDatabase();
        videochat_realtime_presence_db_bootstrap($pdo);
        videochat_realtime_presence_db_prune($pdo);
        $statement = $pdo->prepare(
            <<<'SQL'
SELECT
    rpc.connection_id,
    rpc.user_id,
    rpc.display_name AS presence_display_name,
    rpc.role AS presence_role,
    rpc.call_role AS presence_call_role,
    rpc.connected_at,
    cp.display_name AS participant_display_name,
    cp.call_role,
    users.display_name AS user_display_name,
    roles.slug AS role_slug
FROM realtime_presence_connections rpc
LEFT JOIN call_participants cp
  ON cp.call_id = rpc.call_id
 AND cp.user_id = rpc.user_id
 AND cp.source = 'internal'
LEFT JOIN users ON users.id = rpc.user_id
LEFT JOIN roles ON roles.id = users.role_id
WHERE rpc.call_id = :call_id
  AND rpc.room_id = :room_id
  AND rpc.last_seen_at_ms >= :cutoff_ms
ORDER BY
    rpc.display_name ASC,
    rpc.user_id ASC,
    rpc.connection_id ASC
SQL
        );
        $statement->execute([
            ':call_id' => $callId,
            ':room_id' => $roomId,
            ':cutoff_ms' => videochat_realtime_presence_db_now_ms() - videochat_realtime_presence_db_ttl_ms(),
        ]);
    } catch (Throwable) {
        return [];
    }

    $participants = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }
        $displayName = trim((string) ($row['presence_display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string) ($row['participant_display_name'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = trim((string) ($row['user_display_name'] ?? ''));
        }
        $callRole = videochat_normalize_call_participant_role(
            (string) (($row['presence_call_role'] ?? '') ?: ($row['call_role'] ?? 'participant'))
        );
        $participants[] = [
            'connection_id' => (string) ($row['connection_id'] ?? ('db:' . $callId . ':' . $userId)),
            'room_id' => $roomId,
            'user' => [
                'id' => $userId,
                'display_name' => $displayName !== '' ? $displayName : ('User ' . $userId),
                'role' => videochat_normalize_role_slug((string) (($row['presence_role'] ?? '') ?: ($row['role_slug'] ?? 'user'))),
                'call_role' => $callRole,
            ],
            'connected_at' => (string) ($row['connected_at'] ?? ''),
        ];
    }

    return $participants;
}

function videochat_realtime_db_room_has_joined_user(
    callable $openDatabase,
    array $connection,
    string $roomId,
    int $targetUserId
): bool {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    $callId = videochat_realtime_normalize_call_id(
        (string) (($connection['active_call_id'] ?? '') ?: ($connection['requested_call_id'] ?? '')),
        ''
    );
    if ($normalizedRoomId === '' || $callId === '' || $targetUserId <= 0) {
        return false;
    }

    try {
        $pdo = $openDatabase();
        if (videochat_realtime_presence_db_has_room_membership($pdo, $normalizedRoomId, $callId, $targetUserId)) {
            return true;
        }

        return false;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @param array<int, array<string, mixed>> $localParticipants
 * @param array<int, array<string, mixed>> $dbParticipants
 * @return array<int, array<string, mixed>>
 */
function videochat_realtime_merge_room_participants(array $localParticipants, array $dbParticipants): array
{
    $byUserId = [];
    foreach ($dbParticipants as $participant) {
        $userId = (int) (($participant['user'] ?? [])['id'] ?? 0);
        if ($userId > 0) {
            $byUserId[$userId] = $participant;
        }
    }
    foreach ($localParticipants as $participant) {
        $userId = (int) (($participant['user'] ?? [])['id'] ?? 0);
        if ($userId > 0) {
            $byUserId[$userId] = $participant;
        }
    }

    $participants = array_values($byUserId);
    usort(
        $participants,
        static function (array $left, array $right): int {
            $leftRoleRank = videochat_presence_role_rank((string) (($left['user'] ?? [])['role'] ?? ''));
            $rightRoleRank = videochat_presence_role_rank((string) (($right['user'] ?? [])['role'] ?? ''));
            if ($leftRoleRank !== $rightRoleRank) {
                return $leftRoleRank <=> $rightRoleRank;
            }
            $leftName = strtolower(trim((string) (($left['user'] ?? [])['display_name'] ?? '')));
            $rightName = strtolower(trim((string) (($right['user'] ?? [])['display_name'] ?? '')));
            if ($leftName !== $rightName) {
                return $leftName <=> $rightName;
            }
            return ((int) (($left['user'] ?? [])['id'] ?? 0)) <=> ((int) (($right['user'] ?? [])['id'] ?? 0));
        }
    );

    return $participants;
}

function videochat_realtime_room_snapshot_payload(
    array $presenceState,
    array $connection,
    callable $openDatabase,
    string $reason
): array {
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''));
    $callId = videochat_realtime_normalize_call_id(
        (string) (($connection['active_call_id'] ?? '') ?: ($connection['requested_call_id'] ?? '')),
        ''
    );
    $participants = videochat_realtime_merge_room_participants(
        videochat_presence_room_participants($presenceState, $roomId),
        videochat_realtime_db_room_participants($openDatabase, $connection)
    );
    $activityLayout = [
        'layout' => videochat_layout_default_state($callId, $roomId),
        'activity' => [],
    ];
    if ($callId !== '' && $roomId !== '') {
        try {
            $activityLayout = videochat_activity_layout_snapshot($openDatabase(), $callId, $roomId, $participants);
        } catch (Throwable) {
            $activityLayout = [
                'layout' => videochat_layout_default_state($callId, $roomId),
                'activity' => [],
            ];
        }
    }

    return [
        'type' => 'room/snapshot',
        'room_id' => $roomId,
        'participants' => $participants,
        'participant_count' => count($participants),
        'layout' => is_array($activityLayout['layout'] ?? null) ? $activityLayout['layout'] : videochat_layout_default_state($callId, $roomId),
        'activity' => is_array($activityLayout['activity'] ?? null) ? $activityLayout['activity'] : [],
        'viewer' => [
            'user_id' => (int) ($connection['user_id'] ?? 0),
            'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? '')),
            'call_id' => (string) ($connection['active_call_id'] ?? ''),
            'call_role' => videochat_normalize_call_participant_role((string) ($connection['call_role'] ?? 'participant')),
            'can_moderate' => (bool) ($connection['can_moderate_call'] ?? false),
        ],
        'reason' => trim($reason) === '' ? 'snapshot' : trim($reason),
        'time' => gmdate('c'),
    ];
}

function videochat_realtime_room_snapshot_signature(array $payload): string
{
    return hash('sha256', json_encode([
        'room_id' => (string) ($payload['room_id'] ?? ''),
        'participants' => $payload['participants'] ?? [],
        'layout' => $payload['layout'] ?? [],
        'activity' => $payload['activity'] ?? [],
        'viewer' => $payload['viewer'] ?? [],
    ], JSON_UNESCAPED_SLASHES) ?: '');
}

function videochat_realtime_send_room_snapshot(
    array $presenceState,
    array $connection,
    callable $openDatabase,
    string $reason
): array {
    $payload = videochat_realtime_room_snapshot_payload($presenceState, $connection, $openDatabase, $reason);
    videochat_presence_send_frame($connection['socket'] ?? null, $payload);
    return [
        'signature' => videochat_realtime_room_snapshot_signature($payload),
        'payload' => $payload,
    ];
}

function videochat_realtime_send_room_snapshot_if_changed(
    array $presenceState,
    array $connection,
    callable $openDatabase,
    string &$lastSignature,
    string $reason
): void {
    $payload = videochat_realtime_room_snapshot_payload($presenceState, $connection, $openDatabase, $reason);
    $signature = videochat_realtime_room_snapshot_signature($payload);
    if ($signature === $lastSignature) {
        return;
    }
    $lastSignature = $signature;
    videochat_presence_send_frame($connection['socket'] ?? null, $payload);
}
