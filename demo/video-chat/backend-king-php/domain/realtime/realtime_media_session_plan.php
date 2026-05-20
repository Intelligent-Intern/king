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
        'audio_only',
        'video_unavailable',
        'blocked_capability',
        'left',
    ];
}

/**
 * @return list<string>
 */
function videochat_media_session_plan_session_states(): array
{
    return [
        'pending',
        'connecting',
        'gossip_720p30',
        'gossip_360p30',
        'gossip_360p5',
        'sfu_720p30',
        'sfu_320p30',
        'ready',
        'failed',
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function videochat_media_session_plan_ladder(): array
{
    return [
        ['order' => 1, 'plan_id' => 'gossip_720p30', 'transport' => 'gossip', 'profile' => '720p30', 'codec_path' => 'wlvc', 'width' => 1280, 'height' => 720, 'fps' => 30, 'render_window_ms' => 30_000, 'selected_by' => 'server_head', 'selection_gate' => 'initial'],
        ['order' => 2, 'plan_id' => 'gossip_360p30', 'transport' => 'gossip', 'profile' => '360p30', 'codec_path' => 'wlvc', 'width' => 640, 'height' => 360, 'fps' => 30, 'render_window_ms' => 30_000, 'selected_by' => 'server_head', 'selection_gate' => 'after_gossip_720_render_failure'],
        ['order' => 3, 'plan_id' => 'gossip_360p5', 'transport' => 'gossip', 'profile' => '360p5', 'codec_path' => 'wlvc', 'width' => 640, 'height' => 360, 'fps' => 5, 'render_window_ms' => 30_000, 'selected_by' => 'server_head', 'selection_gate' => 'after_gossip_360p30_render_failure'],
        ['order' => 4, 'plan_id' => 'sfu_720p30', 'transport' => 'sfu', 'profile' => '720p30', 'codec_path' => 'webrtc_sfu', 'width' => 1280, 'height' => 720, 'fps' => 30, 'render_window_ms' => 30_000, 'selected_by' => 'orchestrator', 'selection_gate' => 'after_gossip_render_failure'],
        ['order' => 5, 'plan_id' => 'sfu_320p30', 'transport' => 'sfu', 'profile' => '320p30', 'codec_path' => 'webrtc_sfu', 'width' => 320, 'height' => 180, 'fps' => 30, 'render_window_ms' => 30_000, 'selected_by' => 'orchestrator', 'selection_gate' => 'after_sfu_720p30_render_failure'],
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function videochat_media_session_plan_ladder_by_id(): array
{
    $byId = [];
    foreach (videochat_media_session_plan_ladder() as $entry) {
        $planId = (string) ($entry['plan_id'] ?? '');
        if ($planId !== '') {
            $byId[$planId] = $entry;
        }
    }

    return $byId;
}

/**
 * @return array<string, mixed>
 */
function videochat_media_session_plan_next_ladder_entry(string $planId): array
{
    $normalizedPlanId = strtolower(trim($planId));
    $ladder = videochat_media_session_plan_ladder();
    foreach ($ladder as $index => $entry) {
        if ((string) ($entry['plan_id'] ?? '') !== $normalizedPlanId) {
            continue;
        }
        $next = $ladder[$index + 1] ?? null;
        return is_array($next) ? $next : [];
    }

    return [];
}

/**
 * @return array<string, mixed>
 */
function videochat_media_session_plan_receiver_render_evidence(array $input): array
{
    $raw = is_array($input['receiver_render_evidence'] ?? null)
        ? (array) $input['receiver_render_evidence']
        : (is_array($input['render_evidence'] ?? null) ? (array) $input['render_evidence'] : []);
    $lastRenderedAtMs = 0;
    foreach ([
        $raw['last_rendered_at_ms'] ?? null,
        $raw['last_rendered_at'] ?? null,
        $raw['rendered_at_ms'] ?? null,
        $raw['rendered_at'] ?? null,
    ] as $candidate) {
        $lastRenderedAtMs = videochat_media_session_plan_timestamp_ms($candidate);
        if ($lastRenderedAtMs > 0) {
            break;
        }
    }

    return [
        'last_rendered_at_ms' => $lastRenderedAtMs,
        'sample_count' => max(0, (int) ($raw['sample_count'] ?? ($raw['render_count'] ?? 0))),
    ];
}

/**
 * @param list<array<string, mixed>> $participants
 * @return array<string, mixed>
 */
function videochat_media_session_plan_transition(
    array $entry,
    int $selectedAtMs,
    array $input,
    array $participants,
    int $nowMs
): array {
    $participantSessionIds = videochat_media_session_plan_participant_session_ids($participants);
    if (count($participantSessionIds) < 2) {
        return ['advance' => false];
    }

    $renderWindowMs = max(1, (int) ($entry['render_window_ms'] ?? 0));
    $evidence = videochat_media_session_plan_receiver_render_evidence($input);
    $lastRenderedAtMs = max(0, min($nowMs, (int) ($evidence['last_rendered_at_ms'] ?? 0)));
    $windowStartedAtMs = max(1, $selectedAtMs);
    if ($lastRenderedAtMs > $windowStartedAtMs) {
        $windowStartedAtMs = $lastRenderedAtMs;
    }

    $noReceiverRenderForMs = max(0, $nowMs - $windowStartedAtMs);
    if ($noReceiverRenderForMs < $renderWindowMs) {
        return ['advance' => false];
    }

    $nextEntry = videochat_media_session_plan_next_ladder_entry((string) ($entry['plan_id'] ?? ''));
    if ($nextEntry === []) {
        return ['advance' => false];
    }

    $previousPlanId = (string) ($entry['plan_id'] ?? '');
    $nextPlanId = (string) ($nextEntry['plan_id'] ?? '');
    return [
        'advance' => true,
        'entry' => $nextEntry,
        'reason' => (string) ($nextEntry['selection_gate'] ?? 'receiver_render_missing'),
        'transition' => [
            'previous_plan_id' => $previousPlanId,
            'next_plan_id' => $nextPlanId,
            'reason' => (string) ($nextEntry['selection_gate'] ?? 'receiver_render_missing'),
            'no_receiver_render_for_ms' => $noReceiverRenderForMs,
            'render_window_ms' => $renderWindowMs,
            'last_receiver_render_at_ms' => $lastRenderedAtMs,
            'idempotency_key' => hash('sha256', implode('|', [
                $previousPlanId,
                $nextPlanId,
                (string) $selectedAtMs,
                implode(',', $participantSessionIds),
            ])),
        ],
    ];
}

function videochat_media_session_plan_iso_time(int $timestampMs): string
{
    return gmdate('c', (int) floor(max(1, $timestampMs) / 1000));
}

/**
 * @param list<array<string, mixed>> $participants
 * @return list<string>
 */
function videochat_media_session_plan_participant_session_ids(array $participants): array
{
    $sessionIds = [];
    foreach ($participants as $participant) {
        $sessionId = trim((string) ($participant['participant_session_id'] ?? ''));
        if ($sessionId !== '') {
            $sessionIds[$sessionId] = true;
        }
    }

    return array_keys($sessionIds);
}

/**
 * @param list<array<string, mixed>> $participants
 * @return array<string, mixed>
 */
function videochat_media_session_plan_selected_plan(array $input, array $participants, int $nowMs): array
{
    $ladderById = videochat_media_session_plan_ladder_by_id();
    $defaultPlanId = 'gossip_720p30';
    $raw = is_array($input['selected_plan'] ?? null) ? (array) $input['selected_plan'] : [];
    $rawTransition = is_array($raw['transition'] ?? null) ? (array) $raw['transition'] : [];
    $planId = strtolower(trim((string) ($raw['plan_id'] ?? ($raw['id'] ?? ($input['selected_plan_id'] ?? '')))));
    if (!isset($ladderById[$planId])) {
        $planId = $defaultPlanId;
    }
    $entry = $ladderById[$planId];
    $selectedAtMs = videochat_media_session_plan_timestamp_ms($raw['selected_at_ms'] ?? ($raw['selected_at'] ?? ($input['selected_at_ms'] ?? null)));
    if ($selectedAtMs <= 0) {
        $selectedAtMs = $nowMs;
    }
    $updatedAtMs = videochat_media_session_plan_timestamp_ms($raw['updated_at_ms'] ?? ($raw['updated_at'] ?? null));
    if ($updatedAtMs <= 0) {
        $updatedAtMs = $selectedAtMs;
    }
    $reason = strtolower(trim((string) ($raw['reason'] ?? ($input['selected_reason'] ?? ''))));
    $reason = preg_replace('/[^a-z0-9_.:-]+/', '_', $reason) ?? '';
    if ($reason === '') {
        $reason = $planId === $defaultPlanId ? 'initial_gossip_720p30' : 'orchestrator_selected';
    }
    $transition = videochat_media_session_plan_transition($entry, $selectedAtMs, $input, $participants, $nowMs);
    $transitionPayload = [];
    if ((bool) ($transition['advance'] ?? false) && is_array($transition['entry'] ?? null)) {
        $entry = (array) $transition['entry'];
        $planId = (string) ($entry['plan_id'] ?? $planId);
        $selectedAtMs = $nowMs;
        $updatedAtMs = $nowMs;
        $reason = (string) ($transition['reason'] ?? 'receiver_render_missing');
        $transitionPayload = is_array($transition['transition'] ?? null) ? (array) $transition['transition'] : [];
    } elseif ($rawTransition !== []) {
        $transitionPayload = $rawTransition;
    }

    $selectedPlan = [
        ...$entry,
        'authority' => 'server_head',
        'reason' => $reason,
        'selected_at_ms' => $selectedAtMs,
        'selected_at' => videochat_media_session_plan_iso_time($selectedAtMs),
        'updated_at_ms' => $updatedAtMs,
        'updated_at' => videochat_media_session_plan_iso_time($updatedAtMs),
        'participant_session_ids' => videochat_media_session_plan_participant_session_ids($participants),
        'redacted' => true,
    ];
    if ($transitionPayload !== []) {
        $selectedPlan['transition'] = $transitionPayload;
    }

    return $selectedPlan;
}

function videochat_media_session_plan_session_state(array $input, array $selectedPlan, array $participants): string
{
    $allowed = videochat_media_session_plan_session_states();
    $explicit = strtolower(trim((string) ($input['session_state'] ?? ($input['state'] ?? ''))));
    if (in_array($explicit, $allowed, true)) {
        return $explicit;
    }

    if ($participants === []) {
        return 'pending';
    }

    $hasPending = false;
    $hasConnecting = false;
    $hasFailure = false;
    $hasSending = false;
    foreach ($participants as $participant) {
        if (!is_array($participant)) {
            continue;
        }
        $state = (string) ($participant['media_state'] ?? '');
        if ($state === 'waiting_for_capabilities') {
            $hasPending = true;
        } elseif ($state === 'waiting_for_gossip') {
            $hasConnecting = true;
        } elseif (in_array($state, ['stuck_not_sending', 'blocked_capability'], true)) {
            $hasFailure = true;
        } elseif (videochat_media_session_plan_is_sending_state($state)) {
            $hasSending = true;
        }
    }

    if ($hasFailure && !$hasSending) {
        return 'failed';
    }
    if ($hasPending) {
        return 'pending';
    }
    if ($hasConnecting) {
        return 'connecting';
    }

    $planId = (string) ($selectedPlan['plan_id'] ?? '');
    return in_array($planId, $allowed, true) ? $planId : ($hasSending ? 'ready' : 'failed');
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

function videochat_media_session_plan_has_audio_candidate(array $capabilities): bool
{
    if ($capabilities === [] || !(bool) ($capabilities['schema_valid'] ?? true)) {
        return false;
    }

    $media = is_array($capabilities['media'] ?? null) ? (array) $capabilities['media'] : [];
    $runtime = is_array($capabilities['runtime'] ?? null) ? (array) $capabilities['runtime'] : [];

    return (bool) ($media['microphone'] ?? false)
        && (bool) ($runtime['webrtc'] ?? false);
}

function videochat_media_session_plan_has_video_candidate(array $capabilities): bool
{
    if ($capabilities === [] || !(bool) ($capabilities['schema_valid'] ?? true)) {
        return false;
    }

    $media = is_array($capabilities['media'] ?? null) ? (array) $capabilities['media'] : [];
    $runtime = is_array($capabilities['runtime'] ?? null) ? (array) $capabilities['runtime'] : [];

    return (bool) ($media['camera'] ?? false)
        && ((bool) ($runtime['websocket'] ?? false) || (bool) ($runtime['webrtc'] ?? false));
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
    if (videochat_media_session_plan_has_audio_candidate($capabilities)) {
        return 'audio_only';
    }
    if (videochat_media_session_plan_has_video_candidate($capabilities)) {
        return 'video_unavailable';
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
 * @return list<array<string, mixed>>
 */
function videochat_media_session_plan_build_participants(array $input, array $selectedPlan, int $nowMs): array
{
    $selectedProfile = (string) ($selectedPlan['profile'] ?? '720p30');
    $selectedTransport = (string) ($selectedPlan['transport'] ?? 'gossip');
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
        if (
            $selectedTransport === 'sfu'
            && $state === 'waiting_for_gossip'
            && videochat_media_session_plan_stream_candidate($capabilities)
        ) {
            $state = 'streaming_720p30';
        }
        if (
            $selectedTransport === 'gossip'
            && $state === 'streaming_720p30'
            && !videochat_media_session_plan_gossip_ready($gossipReadiness)
        ) {
            $state = $computedState;
        }
        $barrier = $selectedTransport === 'gossip'
            ? videochat_media_session_plan_barrier_state($state, $participant, $capabilities, $nowMs)
            : ['media_state' => $state, 'stuck_reason' => ''];
        $state = (string) ($barrier['media_state'] ?? $state);
        $barrierStuckReason = (string) ($barrier['stuck_reason'] ?? '');
        $participants[] = [
            'participant_session_id' => (string) (
                $participant['participant_session_id']
                ?? ($capabilities['participant_session_id'] ?? ($participant['connection_id'] ?? ''))
            ),
            'media_state' => $state,
            'profile' => videochat_media_session_plan_is_sending_state($state) ? $selectedProfile : '',
            'transport' => videochat_media_session_plan_is_sending_state($state) ? $selectedTransport : '',
            'security_policy' => $state === 'blocked_capability' ? 'blocked' : 'transport_only',
            'stuck_reason' => $state === 'stuck_not_sending'
                ? (string) ($participant['stuck_reason'] ?? ($barrierStuckReason === '' ? 'not_sending' : $barrierStuckReason))
                : '',
        ];
    }

    return $participants;
}

/**
 * @return array<string, mixed>
 */
function videochat_media_session_plan_build(array $input): array
{
    $nowMs = videochat_media_session_plan_now_ms($input);
    $selectedPlan = videochat_media_session_plan_selected_plan($input, [], $nowMs);
    $participants = videochat_media_session_plan_build_participants($input, $selectedPlan, $nowMs);
    $selectedPlan = videochat_media_session_plan_selected_plan($input, $participants, $nowMs);
    $participants = videochat_media_session_plan_build_participants($input, $selectedPlan, $nowMs);
    $sessionState = videochat_media_session_plan_session_state($input, $selectedPlan, $participants);

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
        'session_state_catalog' => videochat_media_session_plan_session_states(),
        'session_state' => $sessionState,
        'ladder' => videochat_media_session_plan_ladder(),
        'selected_plan' => [
            ...$selectedPlan,
            'session_state' => $sessionState,
        ],
        'receiver_render_evidence' => videochat_media_session_plan_receiver_render_evidence($input),
        'generated_at_ms' => $nowMs,
        'generated_at' => videochat_media_session_plan_iso_time($nowMs),
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
            'security_policy' => (string) ($participant['security_policy'] ?? 'transport_only'),
            'stuck_reason' => $state === 'stuck_not_sending' ? (string) ($participant['stuck_reason'] ?? 'not_sending') : '',
        ];
    }

    $projectionNowMs = videochat_media_session_plan_timestamp_ms($plan['generated_at_ms'] ?? ($plan['generated_at'] ?? null));
    if ($projectionNowMs <= 0) {
        $projectionNowMs = videochat_media_session_plan_now_ms($plan);
    }
    $selectedPlan = videochat_media_session_plan_selected_plan($plan, $participants, $projectionNowMs);
    $sessionState = videochat_media_session_plan_session_state($plan, $selectedPlan, $participants);

    return [
        'schema_version' => videochat_media_session_plan_schema_version(),
        'call_id' => (string) ($plan['call_id'] ?? ''),
        'room_id' => (string) ($plan['room_id'] ?? ''),
        'plan_epoch' => (int) ($plan['plan_epoch'] ?? 1),
        'participants' => $participants,
        'state_catalog' => videochat_media_session_plan_allowed_states(),
        'session_state_catalog' => videochat_media_session_plan_session_states(),
        'session_state' => $sessionState,
        'ladder' => videochat_media_session_plan_ladder(),
        'selected_plan' => [
            ...$selectedPlan,
            'session_state' => $sessionState,
        ],
        'receiver_render_evidence' => videochat_media_session_plan_receiver_render_evidence($plan),
        'generated_at_ms' => (int) ($plan['generated_at_ms'] ?? $projectionNowMs),
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
            'security_policy' => $state === 'blocked_capability' ? 'blocked' : 'transport_only',
            'stuck_reason' => $state === 'stuck_not_sending' ? 'not_sending' : '',
        ],
        'state_catalog' => $allowedStates,
        'session_state_catalog' => videochat_media_session_plan_session_states(),
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
    ?int $nowMs = null,
    string $reason = 'room_snapshot',
    array $receiverRenderEvidence = []
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
    $storedPlan = is_array($presenceState['media_session_plan'] ?? null) ? (array) $presenceState['media_session_plan'] : [];
    $storedSelection = is_array($storedPlan['selected_plan'] ?? null) ? (array) $storedPlan['selected_plan'] : [];
    if ($storedSelection === [] && is_array($presenceState['media_session_selected_plan'] ?? null)) {
        $storedSelection = (array) $presenceState['media_session_selected_plan'];
    }
    if ($receiverRenderEvidence === [] && is_array($storedPlan['receiver_render_evidence'] ?? null)) {
        $receiverRenderEvidence = (array) $storedPlan['receiver_render_evidence'];
    }

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
        'selected_plan' => $storedSelection,
        'receiver_render_evidence' => $receiverRenderEvidence,
        'reason' => $reason,
    ]));
}
