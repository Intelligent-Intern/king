<?php

declare(strict_types=1);

require_once __DIR__ . '/../call_apps/call_app_sessions.php';
require_once __DIR__ . '/realtime_activity_layout.php';
require_once __DIR__ . '/realtime_call_context.php';
require_once __DIR__ . '/realtime_gossipmesh_room_state.php';
require_once __DIR__ . '/realtime_media_session_plan.php';
require_once __DIR__ . '/realtime_owner_absence.php';
require_once __DIR__ . '/realtime_presence.php';

function videochat_realtime_db_room_participants(callable $openDatabase, array $connection, ?int $nowMs = null): array
{
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $callId = videochat_realtime_normalize_call_id((string) ($connection['active_call_id'] ?? ''), '');
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
    $callId = videochat_realtime_normalize_call_id((string) ($connection['active_call_id'] ?? ''), '');
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

/**
 * @param array<int, array<string, mixed>> $participants
 * @return array<string, array<string, mixed>>
 */
function videochat_realtime_room_snapshot_capabilities_by_connection_id(
    array $presenceState,
    array $participants,
    array $persistedCapabilities
): array {
    $connectionCapabilities = is_array($presenceState['client_capabilities'] ?? null)
        ? (array) $presenceState['client_capabilities']
        : [];
    $capabilitiesByConnectionId = [];

    foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $connectionId = trim((string) ($participant['connection_id'] ?? ''));
        if ($connectionId === '') {
            continue;
        }

        $capabilities = [];
        if (is_array($connectionCapabilities[$connectionId] ?? null)) {
            $capabilities = (array) $connectionCapabilities[$connectionId];
        } elseif (is_array($persistedCapabilities[$connectionId] ?? null)) {
            $capabilities = (array) $persistedCapabilities[$connectionId];
        } elseif (is_array($participant['client_capabilities'] ?? null)) {
            $capabilities = (array) $participant['client_capabilities'];
        }

        if ($capabilities !== []) {
            $capabilitiesByConnectionId[$connectionId] = videochat_client_capabilities_public_projection($capabilities);
        }
    }

    ksort($capabilitiesByConnectionId);
    return $capabilitiesByConnectionId;
}

/**
 * @param array<int, array<string, mixed>> $participants
 * @return array<int, array<string, mixed>>
 */
function videochat_realtime_room_snapshot_participants_without_legacy_media(array $participants): array
{
    $sanitized = [];
    foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        unset($participant['client_capabilities']);
        $sanitized[] = $participant;
    }

    return $sanitized;
}

/**
 * @return array<string, array<string, mixed>>
 */
function videochat_realtime_room_snapshot_plan_participants_by_session_id(array $mediaSessionPlan): array
{
    $bySessionId = [];
    foreach ((array) ($mediaSessionPlan['participants'] ?? []) as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $sessionId = trim((string) ($participant['participant_session_id'] ?? ''));
        if ($sessionId !== '') {
            $bySessionId[$sessionId] = $participant;
        }
    }

    return $bySessionId;
}

/**
 * @param array<int, array<string, mixed>> $participants
 * @return array<int, array<string, mixed>>
 */
function videochat_realtime_room_snapshot_participant_media_state(array $participants, array $mediaSessionPlan): array
{
    $planBySessionId = videochat_realtime_room_snapshot_plan_participants_by_session_id($mediaSessionPlan);
    $participantMediaState = [];

    foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $connectionId = trim((string) ($participant['connection_id'] ?? ''));
        if ($connectionId === '') {
            continue;
        }
        $planned = is_array($planBySessionId[$connectionId] ?? null) ? $planBySessionId[$connectionId] : [];
        $participantMediaState[] = [
            'connection_id' => $connectionId,
            'participant_session_id' => (string) ($planned['participant_session_id'] ?? $connectionId),
            'user_id' => (int) (($participant['user'] ?? [])['id'] ?? 0),
            'media_state' => (string) ($planned['media_state'] ?? 'waiting_for_capabilities'),
            'profile' => (string) ($planned['profile'] ?? ''),
            'transport' => (string) ($planned['transport'] ?? ''),
            'security_policy' => (string) ($planned['security_policy'] ?? 'required'),
            'stuck_reason' => (string) ($planned['stuck_reason'] ?? ''),
        ];
    }

    return $participantMediaState;
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_room_snapshot_gossip_readiness(array $gossipTopology, array $presenceState, string $roomId): array
{
    $roomTelemetry = is_array($presenceState['gossipmesh_telemetry'][$roomId] ?? null)
        ? (array) $presenceState['gossipmesh_telemetry'][$roomId]
        : [];
    $rolloutGate = is_array($roomTelemetry['rollout_gate'] ?? null) ? (array) $roomTelemetry['rollout_gate'] : [];
    $admittedPeers = is_array($gossipTopology['admitted_peers'] ?? null) ? $gossipTopology['admitted_peers'] : [];
    $assignedNeighbors = is_array($gossipTopology['assigned_neighbors'] ?? null) ? $gossipTopology['assigned_neighbors'] : [];
    $peerCount = (int) ($roomTelemetry['peer_count'] ?? count($admittedPeers));
    $assignedNeighborCount = count($assignedNeighbors);
    $peerReady = $peerCount > 1 && $assignedNeighborCount > 0;
    $topologyReady = $gossipTopology !== []
        && trim((string) ($gossipTopology['peer_id'] ?? '')) !== ''
        && (int) ($gossipTopology['topology_epoch'] ?? 0) > 0
        && count($admittedPeers) > 1
        && $peerReady;

    return [
        'topology_ready' => $topologyReady,
        'peer_ready' => $peerReady,
        'telemetry_ready' => (bool) ($rolloutGate['telemetry_ready'] ?? false),
        'active_allowed' => (bool) ($rolloutGate['active_allowed'] ?? false),
        'sfu_first' => (bool) ($rolloutGate['sfu_first'] ?? true),
        'peer_count' => $peerCount,
        'assigned_neighbor_count' => $assignedNeighborCount,
        'topology_epoch' => (int) ($gossipTopology['topology_epoch'] ?? 0),
        'readiness_timeout_ms' => videochat_media_session_plan_gossip_readiness_timeout_ms(),
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_room_snapshot_diagnostics(array $presenceState, string $roomId): array
{
    $roomTelemetry = is_array($presenceState['gossipmesh_telemetry'][$roomId] ?? null)
        ? (array) $presenceState['gossipmesh_telemetry'][$roomId]
        : [];
    $gossipCounters = videochat_gossipmesh_sanitize_telemetry_counters($roomTelemetry['totals'] ?? []);
    if (isset($gossipCounters['missing_frame_requests'])) {
        $gossipCounters['missing_media_requests'] = (int) $gossipCounters['missing_frame_requests'];
        unset($gossipCounters['missing_frame_requests']);
    }

    return [
        'gossip_counters' => $gossipCounters,
        'gossip_peer_count' => (int) ($roomTelemetry['peer_count'] ?? 0),
        'gossip_transports' => is_array($roomTelemetry['transports'] ?? null) ? (array) $roomTelemetry['transports'] : [],
        'updated_at_ms' => (int) ($roomTelemetry['updated_at_ms'] ?? 0),
    ];
}

/**
 * @param array<int, array<string, mixed>> $participants
 * @return array<string, mixed>
 */
function videochat_realtime_room_snapshot_authoritative_media_session_plan(
    array $mediaSessionPlan,
    array $presenceState,
    string $roomId,
    array $participants,
    array $capabilitiesByConnectionId,
    array $gossipTopology
): array {
    return [
        ...$mediaSessionPlan,
        'authoritative' => true,
        'authority' => 'room_snapshot',
        'capabilities' => [
            'schema_version' => videochat_client_capabilities_schema_version(),
            'by_connection_id' => $capabilitiesByConnectionId,
        ],
        'participant_media_state' => videochat_realtime_room_snapshot_participant_media_state(
            $participants,
            $mediaSessionPlan
        ),
        'gossip' => [
            'topology' => $gossipTopology,
            'readiness' => videochat_realtime_room_snapshot_gossip_readiness($gossipTopology, $presenceState, $roomId),
        ],
        'diagnostics' => videochat_realtime_room_snapshot_diagnostics($presenceState, $roomId),
    ];
}

function videochat_realtime_room_snapshot_payload(
    array $presenceState,
    array $connection,
    callable $openDatabase,
    string $reason,
    ?int $nowMs = null
): array {
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''));
    $callId = videochat_realtime_normalize_call_id((string) ($connection['active_call_id'] ?? ''), '');
    $tenantId = is_numeric($connection['tenant_id'] ?? null) ? (int) $connection['tenant_id'] : 0;
    $participants = videochat_realtime_merge_room_participants(
        videochat_presence_room_participants($presenceState, $roomId, $tenantId > 0 ? $tenantId : null),
        videochat_realtime_db_room_participants($openDatabase, $connection, $nowMs)
    );
    $persistedClientCapabilities = [];
    if ($callId !== '' && $roomId !== '') {
        try {
            $persistedClientCapabilities = videochat_client_capabilities_fetch_room($openDatabase(), $callId, $roomId);
        } catch (Throwable) {
            $persistedClientCapabilities = [];
        }
    }
    $ownerAbsence = videochat_realtime_owner_absence_disabled_payload();
    if ($callId !== '' && $roomId !== '') {
        try {
            $ownerAbsence = videochat_realtime_apply_owner_absence_timeout($openDatabase(), $callId, $roomId, $nowMs);
        } catch (Throwable) {
            $ownerAbsence = videochat_realtime_owner_absence_disabled_payload('error');
        }
    }
    if (
        (bool) ($ownerAbsence['enabled'] ?? false)
        && !(bool) ($ownerAbsence['owner_present'] ?? false)
        && (int) ($ownerAbsence['owner_user_id'] ?? 0) > 0
    ) {
        $absentOwnerUserId = (int) ($ownerAbsence['owner_user_id'] ?? 0);
        $participants = array_values(array_filter(
            $participants,
            static fn (array $participant): bool => (int) (($participant['user'] ?? [])['id'] ?? 0) !== $absentOwnerUserId
        ));
    }
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
    $gossipReadinessByConnectionId = [];
    if ($callId !== '' && $roomId !== '') {
        $topologyEpochMs = is_int($nowMs) && $nowMs > 0 ? $nowMs : null;
        $gossipTopology = videochat_gossipmesh_room_state_payload(
            $callId,
            $roomId,
            $participants,
            (string) ((int) ($connection['user_id'] ?? 0)),
            trim($reason) === '' ? 'snapshot' : trim($reason),
            $topologyEpochMs
        );
        $gossipReadinessByConnectionId = videochat_gossipmesh_room_readiness_by_connection_id(
            $callId,
            $roomId,
            $participants,
            $topologyEpochMs
        );
    }
    $mediaSessionPlan = videochat_media_session_plan_for_snapshot(
        $presenceState,
        $connection,
        $participants,
        $persistedClientCapabilities,
        $gossipReadinessByConnectionId,
        $nowMs
    );
    $mediaSessionPlan = videochat_realtime_room_snapshot_authoritative_media_session_plan(
        $mediaSessionPlan,
        $presenceState,
        $roomId,
        $participants,
        videochat_realtime_room_snapshot_capabilities_by_connection_id(
            $presenceState,
            $participants,
            $persistedClientCapabilities
        ),
        $gossipTopology
    );
    $participants = videochat_realtime_room_snapshot_participants_without_legacy_media($participants);
    $viewerConnection = $connection;
    try {
        $viewerConnection = videochat_realtime_connection_with_call_context($connection, $openDatabase);
        $viewerConnection = videochat_realtime_owner_absence_downgrade_absent_owner_connection(
            $openDatabase(),
            $viewerConnection,
            $nowMs
        );
    } catch (Throwable) {
        $viewerConnection = [
            ...$connection,
            'call_role' => 'participant',
            'effective_call_role' => 'participant',
            'can_moderate_call' => false,
            'can_manage_call_owner' => false,
        ];
    }

    return [
        'type' => 'room/snapshot',
        'room_id' => $roomId,
        'participants' => $participants,
        'participant_count' => count($participants),
        'layout' => is_array($activityLayout['layout'] ?? null) ? $activityLayout['layout'] : videochat_layout_default_state($callId, $roomId),
        'activity' => is_array($activityLayout['activity'] ?? null) ? $activityLayout['activity'] : [],
        'viewer' => [
            'user_id' => (int) ($viewerConnection['user_id'] ?? 0),
            'role' => videochat_normalize_role_slug((string) ($viewerConnection['role'] ?? '')),
            'call_id' => (string) ($viewerConnection['active_call_id'] ?? ''),
            'call_role' => videochat_normalize_call_participant_role((string) ($viewerConnection['call_role'] ?? 'participant')),
            'effective_call_role' => videochat_normalize_call_participant_role(
                (string) ($viewerConnection['effective_call_role'] ?? ($viewerConnection['call_role'] ?? 'participant'))
            ),
            'can_moderate' => (bool) ($viewerConnection['can_moderate_call'] ?? false),
            'can_manage_owner' => (bool) ($viewerConnection['can_manage_call_owner'] ?? false),
        ],
        'call_lifecycle' => [
            'status' => (string) ($ownerAbsence['call_status'] ?? ''),
            'owner_absence' => $ownerAbsence,
        ],
        'media_session_plan' => $mediaSessionPlan,
        'call_apps' => $callApps,
        'gossip_topology' => $gossipTopology,
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
        'call_lifecycle' => $payload['call_lifecycle'] ?? [],
        'media_session_plan' => $payload['media_session_plan'] ?? [],
        'call_apps' => $payload['call_apps'] ?? [],
        'gossip_topology' => $payload['gossip_topology'] ?? [],
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
    $callId = videochat_realtime_normalize_call_id((string) ($connection['active_call_id'] ?? ''), '');
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

function videochat_realtime_broadcast_call_room_snapshots(
    array $presenceState,
    string $callId,
    int $tenantId,
    callable $openDatabase,
    string $reason,
    string $excludeConnectionId = '',
    ?callable $sender = null
): int {
    $normalizedCallId = videochat_realtime_normalize_call_id($callId, '');
    if ($normalizedCallId === '') {
        return 0;
    }

    $rooms = [];
    foreach (($presenceState['connections'] ?? []) as $connection) {
        if (!is_array($connection)) {
            continue;
        }
        if ($tenantId > 0 && (int) ($connection['tenant_id'] ?? 0) !== $tenantId) {
            continue;
        }

        $connectionCallId = videochat_realtime_normalize_call_id(
            (string) (($connection['active_call_id'] ?? '') ?: ($connection['requested_call_id'] ?? '')),
            ''
        );
        if ($connectionCallId !== $normalizedCallId) {
            continue;
        }

        $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
        if ($roomId === '') {
            continue;
        }
        $rooms[$roomId] = true;
    }

    $sentCount = 0;
    foreach (array_keys($rooms) as $roomId) {
        $sentCount += videochat_realtime_broadcast_room_snapshot(
            $presenceState,
            $roomId,
            $openDatabase,
            $reason,
            $excludeConnectionId,
            $sender,
            $tenantId > 0 ? $tenantId : null
        );
    }

    return $sentCount;
}
