<?php

declare(strict_types=1);

require_once __DIR__ . '/realtime_client_capabilities.php';

const VIDEOCHAT_MEDIA_SESSION_GOSSIP_READINESS_TIMEOUT_MS = 300_000;

function videochat_media_session_plan_schema_version(): string
{
    return 'king.video.media_session_plan.v1';
}

function videochat_call_media_state_event_schema_version(): string
{
    return 'king.video.call_media_state.v1';
}

/**
 * @return list<string>
 */
function videochat_media_session_plan_allowed_states(): array
{
    return [
        'waiting_for_capabilities',
        'waiting_for_gossip',
        'streaming_720p30',
        'throttled_50',
        'throttled_25',
        'stuck_not_sending',
        'blocked_capability',
        'left',
    ];
}

function videochat_media_session_plan_gossip_readiness_timeout_ms(): int
{
    return VIDEOCHAT_MEDIA_SESSION_GOSSIP_READINESS_TIMEOUT_MS;
}

function videochat_media_session_plan_now_ms(array $input = []): int
{
    $nowMs = $input['now_ms'] ?? null;
    if (is_int($nowMs) || (is_string($nowMs) && preg_match('/^\d+$/', $nowMs) === 1)) {
        return max(1, (int) $nowMs);
    }

    return (int) floor(microtime(true) * 1000);
}

function videochat_media_session_plan_timestamp_ms(mixed $value): int
{
    if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
        $numeric = (int) $value;
        return $numeric > 0 && $numeric < 10_000_000_000 ? $numeric * 1000 : max(0, $numeric);
    }

    $candidate = trim((string) $value);
    if ($candidate === '' || strlen($candidate) > 64) {
        return 0;
    }

    $timestamp = strtotime($candidate);
    return $timestamp === false ? 0 : max(0, $timestamp * 1000);
}

function videochat_media_session_plan_gossip_ready(array $readiness): bool
{
    if (array_key_exists('gossip_ready', $readiness)) {
        return (bool) $readiness['gossip_ready'];
    }

    $peerCount = max(0, (int) ($readiness['peer_count'] ?? 0));
    $assignedNeighborCount = max(0, (int) ($readiness['assigned_neighbor_count'] ?? 0));
    $topologyReady = (bool) ($readiness['topology_ready'] ?? false);
    $peerReady = array_key_exists('peer_ready', $readiness)
        ? (bool) $readiness['peer_ready']
        : ($peerCount > 1 && $assignedNeighborCount > 0);

    return $topologyReady && $peerReady;
}

function videochat_media_session_plan_participant_gossip_readiness(array $participant): array
{
    $readiness = is_array($participant['gossip_readiness'] ?? null) ? (array) $participant['gossip_readiness'] : [];
    if (array_key_exists('gossip_ready', $participant)) {
        $readiness['gossip_ready'] = (bool) $participant['gossip_ready'];
    }

    return $readiness;
}

function videochat_media_session_plan_wait_started_at_ms(array $participant, array $capabilities, int $nowMs): int
{
    foreach ([
        $participant['gossip_wait_started_at_ms'] ?? null,
        $participant['readiness_wait_started_at_ms'] ?? null,
        $capabilities['received_at'] ?? null,
        $participant['connected_at'] ?? null,
    ] as $candidate) {
        $timestampMs = videochat_media_session_plan_timestamp_ms($candidate);
        if ($timestampMs > 0) {
            return $timestampMs;
        }
    }

    return $nowMs;
}

function videochat_media_session_plan_stream_candidate(array $capabilities): bool
{
    if ($capabilities === [] || !(bool) ($capabilities['schema_valid'] ?? true)) {
        return false;
    }

    $media = is_array($capabilities['media'] ?? null) ? (array) $capabilities['media'] : [];
    $runtime = is_array($capabilities['runtime'] ?? null) ? (array) $capabilities['runtime'] : [];
    $constraints = is_array($capabilities['constraints'] ?? null) ? (array) $capabilities['constraints'] : [];
    $camera720p30 = (bool) ($media['camera_720p30'] ?? false)
        && (int) ($constraints['video_width'] ?? 0) === 1280
        && (int) ($constraints['video_height'] ?? 0) === 720
        && (int) ($constraints['video_fps'] ?? 0) === 30;

    return (bool) ($media['camera'] ?? false)
        && $camera720p30
        && (bool) ($runtime['websocket'] ?? false)
        && (bool) ($runtime['wlvc_encoder'] ?? false);
}

function videochat_media_session_plan_state(array $capabilities, bool $left = false, array $gossipReadiness = []): string
{
    if ($left) {
        return 'left';
    }
    if ($capabilities === [] || !(bool) ($capabilities['schema_valid'] ?? true)) {
        return $capabilities === [] ? 'waiting_for_capabilities' : 'blocked_capability';
    }

    if (videochat_media_session_plan_stream_candidate($capabilities)) {
        return videochat_media_session_plan_gossip_ready($gossipReadiness) ? 'streaming_720p30' : 'waiting_for_gossip';
    }

    return 'blocked_capability';
}

function videochat_media_session_plan_is_sending_state(string $state): bool
{
    return in_array($state, ['streaming_720p30', 'throttled_50', 'throttled_25'], true);
}

function videochat_media_session_plan_epoch(array $input): int
{
    $explicitEpoch = (int) ($input['plan_epoch'] ?? 0);
    $previousEpoch = (int) ($input['previous_plan_epoch'] ?? 0);
    $participantCount = count((array) ($input['participants'] ?? []));

    return max(1, $explicitEpoch, $previousEpoch + 1, $participantCount);
}

function videochat_media_session_plan_barrier_state(
    string $state,
    array $participant,
    array $capabilities,
    int $nowMs
): array {
    if ($state !== 'waiting_for_gossip') {
        return ['media_state' => $state, 'stuck_reason' => ''];
    }
    if (!videochat_media_session_plan_stream_candidate($capabilities)) {
        return ['media_state' => 'blocked_capability', 'stuck_reason' => ''];
    }
    if (videochat_media_session_plan_gossip_ready(videochat_media_session_plan_participant_gossip_readiness($participant))) {
        return ['media_state' => $state, 'stuck_reason' => ''];
    }

    $waitStartedAtMs = videochat_media_session_plan_wait_started_at_ms($participant, $capabilities, $nowMs);
    if (($nowMs - $waitStartedAtMs) >= videochat_media_session_plan_gossip_readiness_timeout_ms()) {
        return ['media_state' => 'stuck_not_sending', 'stuck_reason' => 'gossip_readiness_timeout'];
    }

    return ['media_state' => $state, 'stuck_reason' => ''];
}

/**
 * @return array<string, mixed>
 */
function videochat_media_session_plan_build(array $input): array
{
    $nowMs = videochat_media_session_plan_now_ms($input);
    $participants = [];
    foreach ((array) ($input['participants'] ?? []) as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $rawCapabilities = $participant['client_capabilities'] ?? null;
        $capabilities = is_array($rawCapabilities) && $rawCapabilities !== []
            ? videochat_client_capabilities_public_projection((array) $participant['client_capabilities'])
            : [];
        $gossipReadiness = videochat_media_session_plan_participant_gossip_readiness($participant);
        $computedState = videochat_media_session_plan_state($capabilities, (bool) ($participant['left'] ?? false), $gossipReadiness);
        $explicitState = (string) ($participant['media_state'] ?? '');
        $state = in_array($explicitState, videochat_media_session_plan_allowed_states(), true) ? $explicitState : $computedState;
        if ($state === 'streaming_720p30' && !videochat_media_session_plan_gossip_ready($gossipReadiness)) {
            $state = $computedState;
        }
        $barrier = videochat_media_session_plan_barrier_state($state, $participant, $capabilities, $nowMs);
        $state = (string) ($barrier['media_state'] ?? $state);
        $barrierStuckReason = (string) ($barrier['stuck_reason'] ?? '');
        $participants[] = [
            'participant_session_id' => (string) (
                $participant['participant_session_id']
                ?? ($capabilities['participant_session_id'] ?? ($participant['connection_id'] ?? ''))
            ),
            'media_state' => $state,
            'profile' => videochat_media_session_plan_is_sending_state($state) ? '720p30' : '',
            'transport' => videochat_media_session_plan_is_sending_state($state) ? 'gossip' : '',
            'security_policy' => $state === 'blocked_capability' ? 'blocked' : 'required',
            'stuck_reason' => $state === 'stuck_not_sending'
                ? (string) ($participant['stuck_reason'] ?? ($barrierStuckReason === '' ? 'not_sending' : $barrierStuckReason))
                : '',
        ];
    }

    return [
        'schema_version' => videochat_media_session_plan_schema_version(),
        'call_id' => (string) ($input['call_id'] ?? ''),
        'room_id' => (string) ($input['room_id'] ?? ''),
        'plan_epoch' => videochat_media_session_plan_epoch([
            'plan_epoch' => $input['plan_epoch'] ?? 0,
            'previous_plan_epoch' => $input['previous_plan_epoch'] ?? 0,
            'participants' => $participants,
        ]),
        'participants' => $participants,
        'state_catalog' => videochat_media_session_plan_allowed_states(),
        'generated_at' => gmdate('c'),
        'redacted' => true,
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_media_session_plan_public_projection(array $plan): array
{
    $participants = [];
    foreach ((array) ($plan['participants'] ?? []) as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $state = (string) ($participant['media_state'] ?? 'waiting_for_capabilities');
        if (!in_array($state, videochat_media_session_plan_allowed_states(), true)) {
            $state = 'blocked_capability';
        }
        $participants[] = [
            'participant_session_id' => (string) ($participant['participant_session_id'] ?? ''),
            'media_state' => $state,
            'profile' => (string) ($participant['profile'] ?? ''),
            'transport' => (string) ($participant['transport'] ?? ''),
            'security_policy' => (string) ($participant['security_policy'] ?? 'required'),
            'stuck_reason' => $state === 'stuck_not_sending' ? (string) ($participant['stuck_reason'] ?? 'not_sending') : '',
        ];
    }

    return [
        'schema_version' => videochat_media_session_plan_schema_version(),
        'call_id' => (string) ($plan['call_id'] ?? ''),
        'room_id' => (string) ($plan['room_id'] ?? ''),
        'plan_epoch' => (int) ($plan['plan_epoch'] ?? 1),
        'participants' => $participants,
        'state_catalog' => videochat_media_session_plan_allowed_states(),
        'generated_at' => (string) ($plan['generated_at'] ?? ''),
        'redacted' => true,
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_call_media_state_event(array $connection, array $capabilities, string $reason = 'client_capabilities'): array
{
    $publicCapabilities = videochat_client_capabilities_public_projection($capabilities);
    $allowedStates = videochat_media_session_plan_allowed_states();
    $state = videochat_media_session_plan_state($publicCapabilities);
    if (!in_array($state, $allowedStates, true)) {
        $state = 'blocked_capability';
    }

    $participantSessionId = (string) ($publicCapabilities['participant_session_id'] ?? '');
    if ($participantSessionId === '') {
        $participantSessionId = trim((string) ($connection['connection_id'] ?? ''));
    }
    $callId = function_exists('videochat_realtime_connection_call_id')
        ? videochat_realtime_connection_call_id($connection)
        : strtolower(trim((string) (($connection['active_call_id'] ?? '') ?: ($connection['requested_call_id'] ?? ''))));
    $roomId = function_exists('videochat_presence_normalize_room_id')
        ? videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '')
        : strtolower(trim((string) ($connection['room_id'] ?? '')));

    return [
        'type' => 'call/media-state.v1',
        'schema_version' => videochat_call_media_state_event_schema_version(),
        'call_id' => $callId,
        'room_id' => $roomId,
        'participant' => [
            'participant_session_id' => $participantSessionId,
            'media_state' => $state,
            'profile' => videochat_media_session_plan_is_sending_state($state) ? '720p30' : '',
            'transport' => videochat_media_session_plan_is_sending_state($state) ? 'gossip' : '',
            'security_policy' => $state === 'blocked_capability' ? 'blocked' : 'required',
            'stuck_reason' => $state === 'stuck_not_sending' ? 'not_sending' : '',
        ],
        'state_catalog' => $allowedStates,
        'reason' => trim($reason) === '' ? 'client_capabilities' : trim($reason),
        'redacted' => true,
        'time' => gmdate('c'),
    ];
}

/**
 * @param array<int, array<string, mixed>> $participants
 * @return array<string, mixed>
 */
function videochat_media_session_plan_for_snapshot(
    array $presenceState,
    array $connection,
    array $participants,
    array $persistedCapabilities = [],
    array $gossipReadinessByConnectionId = [],
    ?int $nowMs = null
): array {
    $callId = function_exists('videochat_realtime_connection_call_id')
        ? videochat_realtime_connection_call_id($connection)
        : strtolower(trim((string) (($connection['active_call_id'] ?? '') ?: ($connection['requested_call_id'] ?? ''))));
    $roomId = function_exists('videochat_presence_normalize_room_id')
        ? videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '')
        : strtolower(trim((string) ($connection['room_id'] ?? '')));
    $connectionCapabilities = is_array($presenceState['client_capabilities'] ?? null)
        ? (array) $presenceState['client_capabilities']
        : [];

    $planParticipants = [];
    foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $connectionId = trim((string) ($participant['connection_id'] ?? ''));
        $capabilities = [];
        if ($connectionId !== '' && is_array($connectionCapabilities[$connectionId] ?? null)) {
            $capabilities = (array) $connectionCapabilities[$connectionId];
        } elseif ($connectionId !== '' && is_array($persistedCapabilities[$connectionId] ?? null)) {
            $capabilities = (array) $persistedCapabilities[$connectionId];
        } elseif (is_array($participant['client_capabilities'] ?? null)) {
            $capabilities = (array) $participant['client_capabilities'];
        }
        $planParticipants[] = [
            'connection_id' => $connectionId,
            'participant_session_id' => $connectionId,
            'client_capabilities' => $capabilities,
            'connected_at' => (string) ($participant['connected_at'] ?? ''),
            'gossip_readiness' => is_array($gossipReadinessByConnectionId[$connectionId] ?? null)
                ? (array) $gossipReadinessByConnectionId[$connectionId]
                : [],
        ];
    }

    return videochat_media_session_plan_public_projection(videochat_media_session_plan_build([
        'call_id' => $callId,
        'room_id' => $roomId,
        'plan_epoch' => videochat_media_session_plan_epoch([
            'previous_plan_epoch' => (int) ($presenceState['media_session_plan_epoch'] ?? 0),
            'participants' => $planParticipants,
        ]),
        'participants' => $planParticipants,
        'now_ms' => $nowMs,
    ]));
}
