<?php

declare(strict_types=1);

require_once __DIR__ . '/../call_apps/call_app_sessions.php';
require_once __DIR__ . '/realtime_activity_layout.php';
require_once __DIR__ . '/realtime_gossipmesh_room_state.php';
require_once __DIR__ . '/realtime_owner_absence.php';
require_once __DIR__ . '/realtime_presence.php';

function videochat_realtime_db_room_participants(callable $openDatabase, array $connection, ?int $nowMs = null): array
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
        $effectiveNowMs = is_int($nowMs) && $nowMs > 0 ? $nowMs : videochat_realtime_presence_db_now_ms();
        $pdo = $openDatabase();
        videochat_realtime_presence_db_bootstrap($pdo);
        videochat_realtime_presence_db_prune($pdo, $effectiveNowMs);
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
            ':cutoff_ms' => $effectiveNowMs - videochat_realtime_presence_db_ttl_ms(),
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
    $normalizedRoomId = videochat_presence_external_room_id_from_key($roomId, '');
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

        $context = videochat_realtime_call_role_context_for_room_user(
            $pdo,
            $normalizedRoomId,
            $targetUserId,
            $callId,
            (string) ($connection['role'] ?? 'user'),
            videochat_realtime_connection_tenant_id($connection)
        );
        if ((bool) ($context['can_moderate'] ?? false)) {
            return true;
        }

        $inviteState = videochat_realtime_normalize_call_invite_state($context['invite_state'] ?? 'invited');
        $joinedAt = trim((string) ($context['joined_at'] ?? ''));
        $leftAt = trim((string) ($context['left_at'] ?? ''));
        return in_array($inviteState, ['allowed', 'accepted'], true)
            && $joinedAt !== ''
            && $leftAt === '';
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
    string $reason,
    ?int $nowMs = null
): array {
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''));
    $callId = videochat_realtime_normalize_call_id(
        (string) (($connection['active_call_id'] ?? '') ?: ($connection['requested_call_id'] ?? '')),
        ''
    );
    $tenantId = is_numeric($connection['tenant_id'] ?? null) ? (int) $connection['tenant_id'] : 0;
    $participants = videochat_realtime_merge_room_participants(
        videochat_presence_room_participants($presenceState, $roomId, $tenantId > 0 ? $tenantId : null),
        videochat_realtime_db_room_participants($openDatabase, $connection, $nowMs)
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
    $callApps = ['active_sessions' => [], 'active_session_count' => 0, 'has_active_session' => false];
    if ($callId !== '' && $tenantId > 0) {
        try {
            $callApps = videochat_call_app_room_snapshot($openDatabase(), $tenantId, $callId);
        } catch (Throwable) {
            $callApps = ['active_sessions' => [], 'active_session_count' => 0, 'has_active_session' => false];
        }
    }
    $gossipTopology = [];
    if ($callId !== '' && $roomId !== '') {
        $gossipTopology = videochat_gossipmesh_room_state_payload(
            $callId,
            $roomId,
            $participants,
            (string) ((int) ($connection['user_id'] ?? 0)),
            trim($reason) === '' ? 'snapshot' : trim($reason)
        );
    }
    $ownerAbsence = videochat_realtime_owner_absence_disabled_payload();
    if ($callId !== '' && $roomId !== '') {
        try {
            $ownerAbsence = videochat_realtime_apply_owner_absence_timeout($openDatabase(), $callId, $roomId, $nowMs);
        } catch (Throwable) {
            $ownerAbsence = videochat_realtime_owner_absence_disabled_payload('error');
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
            'effective_call_role' => videochat_normalize_call_participant_role(
                (string) ($connection['effective_call_role'] ?? ($connection['call_role'] ?? 'participant'))
            ),
            'can_moderate' => (bool) ($connection['can_moderate_call'] ?? false),
            'can_manage_owner' => (bool) ($connection['can_manage_call_owner'] ?? false),
        ],
        'call_apps' => $callApps,
        'gossip_topology' => $gossipTopology,
        'call_lifecycle' => [
            'status' => (string) ($ownerAbsence['call_status'] ?? ''),
            'owner_absence' => $ownerAbsence,
        ],
        'reason' => trim($reason) === '' ? 'snapshot' : trim($reason),
        'time' => is_int($nowMs) && $nowMs > 0 ? gmdate('c', (int) floor($nowMs / 1000)) : gmdate('c'),
    ];
}

function videochat_realtime_room_snapshot_signature(array $payload): string
{
    return hash('sha256', json_encode([
        'room_id' => (string) ($payload['room_id'] ?? ''),
        'participants' => $payload['participants'] ?? [],
        'layout' => $payload['layout'] ?? [],
        'activity' => $payload['activity'] ?? [],
        'call_apps' => $payload['call_apps'] ?? [],
        'gossip_topology' => $payload['gossip_topology'] ?? [],
        'call_lifecycle' => $payload['call_lifecycle'] ?? [],
        'viewer' => $payload['viewer'] ?? [],
    ], JSON_UNESCAPED_SLASHES) ?: '');
}

function videochat_realtime_gossipmesh_room_allows_topology(string $roomId): bool
{
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedRoomId === '' || $normalizedRoomId === 'lobby') {
        return false;
    }
    if (
        function_exists('videochat_realtime_waiting_room_id')
        && $normalizedRoomId === videochat_realtime_waiting_room_id()
    ) {
        return false;
    }

    return true;
}

function videochat_realtime_send_gossipmesh_topology_hint(
    array $presenceState,
    array $connection,
    callable $openDatabase,
    string $reason,
    ?int $epochMs = null,
    ?callable $sender = null
): bool {
    if (
        !function_exists('videochat_gossipmesh_members_from_room_participants')
        || !function_exists('videochat_gossipmesh_plan_topology')
        || !function_exists('videochat_gossipmesh_call_topology_payload')
        || !function_exists('videochat_gossipmesh_safe_id')
    ) {
        return false;
    }

    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $callId = videochat_realtime_normalize_call_id(
        (string) (($connection['active_call_id'] ?? '') ?: ($connection['requested_call_id'] ?? '')),
        ''
    );
    $peerId = videochat_gossipmesh_safe_id((string) ($connection['user_id'] ?? ''));
    if (!videochat_realtime_gossipmesh_room_allows_topology($roomId) || $callId === '' || $peerId === '') {
        return false;
    }

    $participants = videochat_realtime_merge_room_participants(
        videochat_presence_room_participants($presenceState, $roomId),
        videochat_realtime_db_room_participants($openDatabase, $connection)
    );
    $members = videochat_gossipmesh_members_from_room_participants($participants);
    try {
        $topologyPlan = videochat_gossipmesh_plan_topology($callId, $roomId, $members, [
            'seed' => 'room_lifecycle',
            'max_neighbors' => VIDEOCHAT_GOSSIPMESH_DEFAULT_NEIGHBORS,
            'forward_count' => VIDEOCHAT_GOSSIPMESH_DEFAULT_FORWARD_COUNT,
        ]);
        if (!is_array($topologyPlan['topology'][$peerId] ?? null)) {
            return false;
        }
        $payload = videochat_gossipmesh_call_topology_payload(
            $topologyPlan,
            $peerId,
            trim($reason) === '' ? 'room_snapshot' : trim($reason),
            $epochMs
        );
    } catch (Throwable) {
        return false;
    }

    return videochat_presence_send_frame($connection['socket'] ?? null, $payload, $sender);
}

function videochat_realtime_send_room_snapshot(
    array $presenceState,
    array $connection,
    callable $openDatabase,
    string $reason,
    ?callable $sender = null
): array {
    $payload = videochat_realtime_room_snapshot_payload($presenceState, $connection, $openDatabase, $reason);
    videochat_presence_send_frame($connection['socket'] ?? null, $payload, $sender);
    videochat_realtime_send_gossipmesh_topology_hint($presenceState, $connection, $openDatabase, $reason, null, $sender);
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
    string $reason,
    ?callable $sender = null
): void {
    $payload = videochat_realtime_room_snapshot_payload($presenceState, $connection, $openDatabase, $reason);
    $signature = videochat_realtime_room_snapshot_signature($payload);
    if ($signature === $lastSignature) {
        return;
    }
    $lastSignature = $signature;
    videochat_presence_send_frame($connection['socket'] ?? null, $payload, $sender);
    videochat_realtime_send_gossipmesh_topology_hint($presenceState, $connection, $openDatabase, $reason, null, $sender);
}

function videochat_realtime_broadcast_room_snapshot(
    array $presenceState,
    string $roomId,
    callable $openDatabase,
    string $reason,
    string $excludeConnectionId = '',
    ?callable $sender = null,
    ?int $tenantId = null
): int {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedRoomId === '') {
        return 0;
    }

    $roomConnections = $presenceState['rooms'][videochat_presence_room_key($normalizedRoomId, $tenantId)] ?? null;
    if (!is_array($roomConnections) || $roomConnections === []) {
        return 0;
    }

    $sentCount = 0;
    $excludedId = trim($excludeConnectionId);
    $topologyEpochMs = (int) floor(microtime(true) * 1000);
    foreach ($roomConnections as $connectionId => $_socket) {
        if (!is_string($connectionId) || $connectionId === '') {
            continue;
        }
        if ($excludedId !== '' && $connectionId === $excludedId) {
            continue;
        }

        $connection = $presenceState['connections'][$connectionId] ?? null;
        if (!is_array($connection)) {
            continue;
        }

        $payload = videochat_realtime_room_snapshot_payload($presenceState, $connection, $openDatabase, $reason);
        if (videochat_presence_send_frame($connection['socket'] ?? null, $payload, $sender)) {
            videochat_realtime_send_gossipmesh_topology_hint(
                $presenceState,
                $connection,
                $openDatabase,
                $reason,
                $topologyEpochMs,
                $sender
            );
            $sentCount++;
        }
    }

    return $sentCount;
}
