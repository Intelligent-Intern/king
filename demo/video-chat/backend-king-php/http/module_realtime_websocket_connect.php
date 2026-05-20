<?php

declare(strict_types=1);

function videochat_realtime_websocket_connect_cycle_max_ms(): int
{
    return 5 * 60 * 1000;
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_websocket_connect_cycle_policy(): array
{
    return [
        'auto_reconnect' => false,
        'max_ms' => videochat_realtime_websocket_connect_cycle_max_ms(),
        'restart_policy' => 'new_participant_only',
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_websocket_gossip_ops_state_policy(): array
{
    return [
        'schema_version' => 'king.video.gossip_ops_state.v1',
        'kind' => 'gossip_server_head_ops_state',
        'authority' => 'server_head',
        'server_head_authoritative' => true,
        'health_authority' => 'server_head',
        'topology_repair_authority' => 'server_head',
        'recovery_authority' => 'server_head',
        'client_health_gate' => false,
        'client_topology_repair' => false,
        'client_recovery_request' => false,
        'client_repair_request_required' => false,
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_websocket_default_gossip_rollout_gate(): array
{
    if (function_exists('videochat_gossipmesh_derive_telemetry_rollout_gate') && function_exists('videochat_gossipmesh_sanitize_telemetry_counters')) {
        return videochat_gossipmesh_derive_telemetry_rollout_gate([
            'peer_count' => 0,
            'peers' => [],
            'transports' => [],
            'totals' => videochat_gossipmesh_sanitize_telemetry_counters([]),
        ]);
    }

    return [
        'kind' => 'gossip_rollout_gate_state',
        'decision' => 'sfu_first_explicit',
        'active_allowed' => false,
        'observational_only' => true,
        'sfu_first' => true,
        'rtc_ready' => false,
        'telemetry_ready' => false,
        'peer_count' => 0,
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_websocket_gossip_ops_state_frame(
    array $presenceState,
    array $connection,
    array $context = []
): array {
    $roomId = videochat_presence_normalize_room_id((string) ($context['room_id'] ?? ($connection['room_id'] ?? '')), '');
    $callId = videochat_realtime_normalize_call_id((string) ($context['call_id'] ?? videochat_realtime_connection_call_id($connection)), '');
    $peerId = (string) ($context['peer_id'] ?? ((int) ($connection['user_id'] ?? 0) > 0 ? (int) ($connection['user_id'] ?? 0) : ''));
    $aggregate = is_array($presenceState['gossipmesh_telemetry'][$roomId] ?? null) ? (array) $presenceState['gossipmesh_telemetry'][$roomId] : [];
    $rolloutGate = is_array($context['rollout_gate'] ?? null)
        ? (array) $context['rollout_gate']
        : (is_array($aggregate['rollout_gate'] ?? null) ? (array) $aggregate['rollout_gate'] : videochat_realtime_websocket_default_gossip_rollout_gate());
    $transports = is_array($context['transports'] ?? null)
        ? (array) $context['transports']
        : (is_array($aggregate['transports'] ?? null) ? (array) $aggregate['transports'] : []);
    $peerCount = (int) ($context['peer_count'] ?? ($aggregate['peer_count'] ?? ($rolloutGate['peer_count'] ?? 0)));

    return [
        'type' => 'gossip/telemetry/ack',
        'lane' => 'ops',
        'room_id' => $roomId,
        'call_id' => $callId,
        'peer_id' => $peerId,
        'peer_count' => max(0, $peerCount),
        'transports' => $transports,
        'rollout_gate' => $rolloutGate,
        'decision' => (string) ($rolloutGate['decision'] ?? 'sfu_first_explicit'),
        'active_allowed' => (bool) ($rolloutGate['active_allowed'] ?? false),
        'reason' => trim((string) ($context['reason'] ?? 'server_head_ops_state')) ?: 'server_head_ops_state',
        ...videochat_realtime_websocket_gossip_ops_state_policy(),
        'time' => gmdate('c'),
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_websocket_error_details(array $details = [], string $phase = 'connect'): array
{
    $policy = videochat_realtime_websocket_connect_cycle_policy();
    if (!array_key_exists('retryable', $details)) {
        $details['retryable'] = false;
    }

    return [
        ...$details,
        'phase' => trim($phase) === '' ? 'connect' : trim($phase),
        'auto_reconnect' => false,
        'connect_cycle_max_ms' => $policy['max_ms'],
        'connect_cycle_restart_policy' => $policy['restart_policy'],
        'connect_cycle' => $policy,
    ];
}

/**
 * @param array<string, mixed> $response
 * @return array<string, mixed>
 */
function videochat_realtime_websocket_attach_error_policy(array $response, string $phase): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload) || !is_array($payload['error'] ?? null)) {
        return $response;
    }

    $error = (array) $payload['error'];
    $details = is_array($error['details'] ?? null) ? (array) $error['details'] : [];
    $payload['error']['details'] = videochat_realtime_websocket_error_details($details, $phase);
    $response['body'] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: (string) ($response['body'] ?? '');

    return $response;
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_websocket_backend_failure_response(
    callable $errorResponse,
    string $phase,
    string $reason,
    int $status,
    string $code,
    string $message
): array {
    return $errorResponse($status, $code, $message, videochat_realtime_websocket_error_details([
        'reason' => trim($reason) === '' ? 'backend_unavailable' : trim($reason),
    ], $phase));
}

/**
 * @return array<int, bool>
 */
function videochat_realtime_websocket_normalize_user_id_set(array $userIds): array
{
    $normalized = [];
    foreach ($userIds as $key => $value) {
        $candidate = is_int($key) && $key > 0 ? $key : (int) $value;
        if ($candidate > 0) {
            $normalized[$candidate] = true;
        }
    }

    ksort($normalized);
    return $normalized;
}

/**
 * @return array<int, bool>
 */
function videochat_realtime_websocket_room_user_ids(
    array $presenceState,
    string $roomId,
    ?int $tenantId = null,
    ?callable $openDatabase = null,
    array $connection = []
): array {
    $userIds = [];
    $participants = videochat_presence_room_participants($presenceState, $roomId, $tenantId);
    if ($openDatabase !== null && $connection !== [] && function_exists('videochat_realtime_db_room_participants')) {
        $lookupConnection = [...$connection, 'room_id' => $roomId];
        try {
            $participants = array_merge($participants, videochat_realtime_db_room_participants($openDatabase, $lookupConnection));
        } catch (Throwable) {
            // Local presence still gives a correct single-worker quorum view.
        }
    }

    foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $userId = (int) (($participant['user'] ?? [])['id'] ?? 0);
        if ($userId > 0) {
            $userIds[$userId] = true;
        }
    }

    ksort($userIds);
    return $userIds;
}

/**
 * @param array<int, bool> $roomUserIdsBeforeJoin
 * @return array<string, mixed>
 */
function videochat_realtime_websocket_connect_quorum(
    array $presenceState,
    array $connection,
    array $roomUserIdsBeforeJoin = [],
    ?callable $openDatabase = null
): array {
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $callId = videochat_realtime_connection_call_id($connection);
    $tenantId = is_numeric($connection['tenant_id'] ?? null) ? (int) $connection['tenant_id'] : null;
    $userId = (int) ($connection['user_id'] ?? 0);
    $beforeUserIds = videochat_realtime_websocket_normalize_user_id_set($roomUserIdsBeforeJoin);
    $afterUserIds = videochat_realtime_websocket_room_user_ids($presenceState, $roomId, $tenantId, $openDatabase, $connection);
    if ($userId > 0) {
        $afterUserIds[$userId] = true;
    }
    ksort($afterUserIds);

    $requiredParticipantCount = 2;
    $participantCount = count($afterUserIds);
    $quorumMet = $participantCount >= $requiredParticipantCount;
    $isCallRoom = $callId !== ''
        && $roomId !== ''
        && $roomId !== 'lobby'
        && $roomId !== videochat_realtime_waiting_room_id();
    $newParticipant = $userId > 0 && !(bool) ($beforeUserIds[$userId] ?? false);
    $cycleAllowed = $isCallRoom && $newParticipant && $quorumMet;
    $reason = 'not_call_room';
    if ($isCallRoom && !$quorumMet) {
        $reason = 'waiting_for_connect_quorum';
    } elseif ($isCallRoom && $cycleAllowed) {
        $reason = 'new_participant_quorum_met';
    } elseif ($isCallRoom) {
        $reason = 'existing_participant_no_new_cycle';
    }

    return [
        'schema_version' => 'king.video.connect_quorum.v1',
        'room_id' => $roomId,
        'call_id' => $callId,
        'participant_count' => $participantCount,
        'required_participant_count' => $requiredParticipantCount,
        'quorum_met' => $quorumMet,
        'new_participant' => $newParticipant,
        'connect_cycle' => [
            ...videochat_realtime_websocket_connect_cycle_policy(),
            'allowed' => $cycleAllowed,
            'reason' => $reason,
            'trigger' => $cycleAllowed ? 'new_participant' : 'none',
        ],
        'gossip_ops_state' => videochat_realtime_websocket_gossip_ops_state_policy(),
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_websocket_connect_quorum_frame(array $connectQuorum): array
{
    return [
        'type' => 'system/connect-quorum',
        ...$connectQuorum,
        'time' => gmdate('c'),
    ];
}

function videochat_realtime_websocket_broadcast_connect_quorum(
    array &$presenceState,
    array $connection,
    array $connectQuorum
): int {
    if (!(bool) (($connectQuorum['connect_cycle'] ?? [])['allowed'] ?? false)) {
        return 0;
    }

    return videochat_presence_broadcast_room_event(
        $presenceState,
        (string) ($connectQuorum['room_id'] ?? ''),
        videochat_realtime_websocket_connect_quorum_frame($connectQuorum),
        null,
        null,
        is_numeric($connection['tenant_id'] ?? null) ? (int) $connection['tenant_id'] : null
    );
}

/**
 * @return array<string, array<string, string>>
 */
function videochat_realtime_websocket_channel_catalog(): array
{
    return [
        'presence' => ['snapshot' => 'room/snapshot', 'joined' => 'room/joined', 'left' => 'room/left'],
        'chat' => ['send' => 'chat/send', 'message' => 'chat/message', 'ack' => 'chat/ack'],
        'typing' => ['start' => 'typing/start', 'stop' => 'typing/stop'],
        'reaction' => ['send' => 'reaction/send', 'send_batch' => 'reaction/send_batch', 'event' => 'reaction/event', 'batch' => 'reaction/batch'],
        'activity' => ['publish' => 'participant/activity', 'event' => 'participant/activity'],
        'layout' => ['mode' => 'layout/mode', 'strategy' => 'layout/strategy', 'selection' => 'layout/selection'],
        'lobby' => ['snapshot' => 'lobby/snapshot', 'request' => 'lobby/queue/request', 'join' => 'lobby/queue/join', 'cancel' => 'lobby/queue/cancel', 'allow' => 'lobby/allow', 'remove' => 'lobby/remove', 'allow_all' => 'lobby/allow_all'],
        'signaling' => ['offer' => 'call/offer', 'answer' => 'call/answer', 'ice' => 'call/ice', 'hangup' => 'call/hangup', 'control_state' => 'call/control-state', 'call_app_presence' => 'call-app/presence', 'media_quality_pressure' => 'call/media-quality-pressure', 'moderation_state' => 'call/moderation-state', 'media_security_sync_request' => 'call/media-security-sync-request', 'media_security_hello' => 'media-security/hello', 'media_security_sender_key' => 'media-security/sender-key', 'ack' => 'call/ack'],
        'admin_sync' => ['publish' => 'admin/sync/publish', 'event' => 'admin/sync'],
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_websocket_welcome_frame(
    array $websocketAuth,
    array $presenceConnection,
    string $connectionId,
    array $connectQuorum
): array {
    return [
        'type' => 'system/welcome',
        'message' => 'video-chat King websocket presence gateway connected',
        'connection_id' => $connectionId,
        'active_room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
        'call_context' => [
            'requested_call_id' => (string) ($presenceConnection['requested_call_id'] ?? ''),
            'call_id' => (string) ($presenceConnection['active_call_id'] ?? ''),
            'call_role' => (string) ($presenceConnection['call_role'] ?? 'participant'),
            'invite_state' => (string) ($presenceConnection['invite_state'] ?? 'invited'),
            'can_moderate' => (bool) ($presenceConnection['can_moderate_call'] ?? false),
        ],
        'admission' => [
            'requested_call_id' => (string) ($presenceConnection['requested_call_id'] ?? ''),
            'requested_room_id' => (string) ($presenceConnection['requested_room_id'] ?? ''),
            'pending_room_id' => (string) ($presenceConnection['pending_room_id'] ?? ''),
            'waiting_room_id' => videochat_realtime_waiting_room_id(),
            'requires_admission' => trim((string) ($presenceConnection['pending_room_id'] ?? '')) !== '',
        ],
        'connect_quorum' => $connectQuorum,
        'gossip_ops_state' => videochat_realtime_websocket_gossip_ops_state_policy(),
        'channels' => videochat_realtime_websocket_channel_catalog(),
        'runtime' => videochat_realtime_runtime_descriptor(),
        'auth' => ['session' => $websocketAuth['session'] ?? null, 'user' => $websocketAuth['user'] ?? null],
        'time' => gmdate('c'),
    ];
}
