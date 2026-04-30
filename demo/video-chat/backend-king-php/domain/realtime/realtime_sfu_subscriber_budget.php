<?php

declare(strict_types=1);

function videochat_sfu_subscriber_video_send_budget_ms(): int
{
    return 35;
}

function videochat_sfu_subscriber_video_send_cooldown_ms(): int
{
    return 750;
}

function videochat_sfu_subscriber_replay_video_send_budget_ms(): int
{
    return 25;
}

function videochat_sfu_subscriber_replay_delta_max_age_ms(): int
{
    return 900;
}

function videochat_sfu_subscriber_replay_max_batch_frames(): int
{
    return 4;
}

function videochat_sfu_frame_latency_budget_ms(array $frame): int
{
    $queueBudgetMs = max(0, (int) ($frame['budget_max_queue_age_ms'] ?? 0));
    if ($queueBudgetMs > 0) {
        return max(450, min(1200, $queueBudgetMs * 2));
    }

    return 900;
}

function videochat_sfu_drop_stale_ingress_frame_if_needed(mixed $websocket, array $frame, string $roomId, string $publisherId): bool
{
    $receiveLatencyMs = max(0, (int) ($frame['king_receive_latency_ms'] ?? 0));
    $latencyBudgetMs = videochat_sfu_frame_latency_budget_ms($frame);
    if ($receiveLatencyMs <= $latencyBudgetMs) {
        return false;
    }

    $trackId = (string) ($frame['track_id'] ?? '');
    videochat_sfu_log_runtime_event('sfu_frame_ingress_stale_dropped', [
        'room_id' => $roomId,
        'publisher_id' => $publisherId,
        'track_id' => $trackId,
        'frame_type' => (string) ($frame['frame_type'] ?? 'delta'),
        'king_receive_latency_ms' => $receiveLatencyMs,
        'ingress_latency_budget_ms' => $latencyBudgetMs,
        'sfu_send_path' => 'ingress_latency_guard',
        ...videochat_sfu_transport_metric_fields($frame, 0),
    ], 1000);
    videochat_presence_send_frame($websocket, [
        'type' => 'sfu/publisher-pressure',
        'reason' => 'sfu_ingress_latency_budget_exceeded',
        'track_id' => $trackId,
        'frame_sequence' => max(0, (int) ($frame['frame_sequence'] ?? 0)),
        'king_receive_latency_ms' => $receiveLatencyMs,
        'queue_age_ms' => $receiveLatencyMs,
        'budget_max_queue_age_ms' => max(0, (int) ($frame['budget_max_queue_age_ms'] ?? 0)),
        'ingress_latency_budget_ms' => $latencyBudgetMs,
        'payload_bytes' => max(0, (int) ($frame['payload_bytes'] ?? 0)),
        'retry_after_ms' => 300,
    ]);

    return true;
}

function videochat_sfu_normalize_video_layer_preference(mixed $value, string $fallback = 'primary'): string
{
    $normalized = strtolower(trim((string) $value));
    if ($normalized === 'thumbnail' || $normalized === 'thumb' || $normalized === 'mini') {
        return 'thumbnail';
    }
    if ($normalized === 'primary' || $normalized === 'main' || $normalized === 'fullscreen') {
        return 'primary';
    }
    return $fallback === 'thumbnail' ? 'thumbnail' : 'primary';
}

/**
 * @param array<string, mixed> $roomState
 * @return array<int, array<string, mixed>>
 */
function videochat_sfu_room_subscriber_targets(array $roomState, string $excludeClientId = ''): array
{
    $targets = [];
    foreach (($roomState['subscribers'] ?? []) as $subscriberClientId => $subscriber) {
        if ((string) $subscriberClientId === $excludeClientId) {
            continue;
        }
        if (!is_array($subscriber) || !array_key_exists('websocket', $subscriber)) {
            continue;
        }
        $subscriber['client_id'] = (string) $subscriberClientId;
        $targets[] = $subscriber;
    }

    return $targets;
}

function videochat_sfu_subscriber_layer_preference(array $subscriber, array $frame): string
{
    $preferences = is_array($subscriber['layer_preferences'] ?? null) ? $subscriber['layer_preferences'] : [];
    $publisherId = trim((string) ($frame['publisher_id'] ?? ''));
    $trackId = trim((string) ($frame['track_id'] ?? ''));
    $candidateKeys = [];
    if ($publisherId !== '' && $trackId !== '') {
        $candidateKeys[] = $publisherId . ':' . $trackId;
    }
    if ($publisherId !== '') {
        $candidateKeys[] = $publisherId;
    }
    $candidateKeys[] = '*';

    foreach ($candidateKeys as $key) {
        $preference = $preferences[$key] ?? null;
        if (!is_array($preference)) {
            continue;
        }
        return videochat_sfu_normalize_video_layer_preference($preference['requested_video_layer'] ?? 'primary');
    }

    return 'primary';
}

/**
 * @return array<string, mixed>
 */
function videochat_sfu_apply_subscriber_layer_preference(array &$subscriber, array $msg): array
{
    $publisherId = trim((string) ($msg['publisher_id'] ?? ($msg['publisherId'] ?? '')));
    if ($publisherId === '') {
        return [];
    }
    $trackId = trim((string) ($msg['track_id'] ?? ($msg['trackId'] ?? '')));
    $requestedVideoLayer = videochat_sfu_normalize_video_layer_preference(
        $msg['requested_video_layer'] ?? ($msg['requestedVideoLayer'] ?? 'primary')
    );
    $preferenceKey = $trackId !== '' ? $publisherId . ':' . $trackId : $publisherId;
    $subscriber['layer_preferences'][$preferenceKey] = [
        'requested_video_layer' => $requestedVideoLayer,
        'updated_at_ms' => videochat_sfu_now_ms(),
        'reason' => (string) ($msg['reason'] ?? ''),
        'render_surface_role' => (string) ($msg['render_surface_role'] ?? ($msg['renderSurfaceRole'] ?? '')),
    ];

    return [
        'type' => 'sfu/layer-preference-ack',
        'publisher_id' => $publisherId,
        'track_id' => $trackId,
        'requested_video_layer' => $requestedVideoLayer,
        'server_time' => time(),
    ];
}

/**
 * @return array{send: bool, layer_preference: string, drop_reason: string}
 */
function videochat_sfu_subscriber_frame_route_decision(array $subscriber, array $frame): array
{
    $layerPreference = videochat_sfu_subscriber_layer_preference($subscriber, $frame);
    if ($layerPreference !== 'thumbnail') {
        return ['send' => true, 'layer_preference' => $layerPreference, 'drop_reason' => ''];
    }

    $layoutMode = strtolower(trim((string) ($frame['layout_mode'] ?? 'full_frame')));
    if ($layoutMode === 'tile_foreground' || $layoutMode === 'background_snapshot') {
        return ['send' => true, 'layer_preference' => $layerPreference, 'drop_reason' => ''];
    }
    if (!videochat_sfu_frame_is_delta($frame)) {
        return ['send' => true, 'layer_preference' => $layerPreference, 'drop_reason' => ''];
    }

    $sequence = max(0, (int) ($frame['frame_sequence'] ?? 0));
    if ($sequence <= 0) {
        return ['send' => true, 'layer_preference' => $layerPreference, 'drop_reason' => ''];
    }
    $profile = strtolower(trim((string) ($frame['outgoing_video_quality_profile'] ?? '')));
    $cadence = in_array($profile, ['quality', 'balanced'], true) ? 3 : 2;
    if (($sequence % $cadence) === 0) {
        return ['send' => true, 'layer_preference' => $layerPreference, 'drop_reason' => ''];
    }

    return [
        'send' => false,
        'layer_preference' => $layerPreference,
        'drop_reason' => 'thumbnail_subscriber_delta_cadence',
    ];
}

function videochat_sfu_frame_replay_age_ms(array $frame): int
{
    return max(
        0,
        (int) ($frame['live_relay_age_ms'] ?? 0),
        (int) ($frame['sqlite_buffer_age_ms'] ?? 0)
    );
}

function videochat_sfu_frame_replay_track_key(array $frame): string
{
    $publisherId = trim((string) ($frame['publisher_id'] ?? ''));
    $trackId = trim((string) ($frame['track_id'] ?? ''));
    return $publisherId . ':' . $trackId;
}

function videochat_sfu_frame_is_delta(array $frame): bool
{
    return strtolower(trim((string) ($frame['frame_type'] ?? 'delta'))) !== 'keyframe';
}

/**
 * @param array<int, array<string, mixed>> $frames
 * @return array<int, array<string, mixed>>
 */
function videochat_sfu_prune_replay_frames_for_subscriber(
    array $frames,
    string $roomId,
    string $subscriberId,
    string $sendPath,
    array $subscriber = []
): array {
    $firstKeyframeIndexByTrack = [];
    foreach ($frames as $index => $frame) {
        if (!is_array($frame) || videochat_sfu_frame_is_delta($frame)) {
            continue;
        }
        $trackKey = videochat_sfu_frame_replay_track_key($frame);
        if ($trackKey !== ':' && !isset($firstKeyframeIndexByTrack[$trackKey])) {
            $firstKeyframeIndexByTrack[$trackKey] = (int) $index;
        }
    }

    $pruned = [];
    $staleFrameCount = 0;
    $staleDeltaCount = 0;
    $preKeyframeDeltaCount = 0;
    $layerPrunedCount = 0;
    foreach ($frames as $index => $frame) {
        if (!is_array($frame)) {
            continue;
        }
        $isDelta = videochat_sfu_frame_is_delta($frame);
        $trackKey = videochat_sfu_frame_replay_track_key($frame);
        $ageMs = videochat_sfu_frame_replay_age_ms($frame);
        if ($ageMs > videochat_sfu_frame_latency_budget_ms($frame)) {
            $staleFrameCount++;
            continue;
        }
        if ($isDelta && $ageMs > videochat_sfu_subscriber_replay_delta_max_age_ms()) {
            $staleDeltaCount++;
            continue;
        }
        if ($isDelta && isset($firstKeyframeIndexByTrack[$trackKey]) && $index < $firstKeyframeIndexByTrack[$trackKey]) {
            $preKeyframeDeltaCount++;
            continue;
        }
        $routeDecision = videochat_sfu_subscriber_frame_route_decision($subscriber, $frame);
        if (!(bool) ($routeDecision['send'] ?? true)) {
            $layerPrunedCount++;
            continue;
        }

        $pruned[] = $frame;
        if (count($pruned) >= videochat_sfu_subscriber_replay_max_batch_frames()) {
            break;
        }
    }

    if ($staleFrameCount > 0) {
        videochat_sfu_log_runtime_event('sfu_frame_replay_stale_frame_pruned', [
            'room_id' => $roomId,
            'subscriber_id' => $subscriberId,
            'sfu_send_path' => $sendPath,
            'stale_frame_count' => $staleFrameCount,
        ], 1000);
    }
    if ($staleDeltaCount > 0) {
        videochat_sfu_log_runtime_event('sfu_frame_replay_stale_delta_pruned', [
            'room_id' => $roomId,
            'subscriber_id' => $subscriberId,
            'sfu_send_path' => $sendPath,
            'stale_delta_count' => $staleDeltaCount,
            'replay_delta_max_age_ms' => videochat_sfu_subscriber_replay_delta_max_age_ms(),
        ], 1000);
    }
    if ($preKeyframeDeltaCount > 0) {
        videochat_sfu_log_runtime_event('sfu_frame_replay_pre_keyframe_delta_pruned', [
            'room_id' => $roomId,
            'subscriber_id' => $subscriberId,
            'sfu_send_path' => $sendPath,
            'pre_keyframe_delta_count' => $preKeyframeDeltaCount,
        ], 1000);
    }
    if ($layerPrunedCount > 0) {
        videochat_sfu_log_runtime_event('sfu_frame_replay_layer_preference_pruned', [
            'room_id' => $roomId,
            'subscriber_id' => $subscriberId,
            'sfu_send_path' => $sendPath,
            'layer_pruned_count' => $layerPrunedCount,
        ], 1000);
    }

    return $pruned;
}

/**
 * @param array<int, array<string, mixed>> $frames
 * @param array<string, int> $slowSubscriberBlockedUntilMs
 */
function videochat_sfu_send_replay_frames_to_subscriber(
    mixed $websocket,
    array $frames,
    string $roomId,
    string $subscriberId,
    string $sendPath,
    array &$slowSubscriberBlockedUntilMs,
    array $subscriber = []
): int {
    $sentCount = 0;
    $framesToSend = videochat_sfu_prune_replay_frames_for_subscriber($frames, $roomId, $subscriberId, $sendPath, $subscriber);
    foreach ($framesToSend as $frame) {
        $nowMs = videochat_sfu_now_ms();
        $blockedUntilMs = (int) ($slowSubscriberBlockedUntilMs[$subscriberId] ?? 0);
        if ($blockedUntilMs > $nowMs) {
            videochat_sfu_log_runtime_event('sfu_frame_replay_slow_subscriber_skipped', [
                'room_id' => $roomId,
                'subscriber_id' => $subscriberId,
                'blocked_for_ms' => max(0, $blockedUntilMs - $nowMs),
                'sfu_send_path' => $sendPath,
                'replay_frame_count' => count($framesToSend),
                ...videochat_sfu_transport_metric_fields($frame, 0),
            ], 1000);
            break;
        }
        unset($slowSubscriberBlockedUntilMs[$subscriberId]);

        $subscriberSendStartedAtMs = videochat_sfu_now_ms();
        $sendOk = videochat_sfu_send_outbound_message($websocket, $frame, [
            'sfu_send_path' => $sendPath,
            'room_id' => $roomId,
            'subscriber_id' => $subscriberId,
            'worker_pid' => getmypid(),
            'subscriber_send_latency_ms' => (float) ($frame['subscriber_send_latency_ms'] ?? 0),
            'live_relay_age_ms' => (float) ($frame['live_relay_age_ms'] ?? 0),
            'sqlite_buffer_age_ms' => (float) ($frame['sqlite_buffer_age_ms'] ?? 0),
        ]);
        $subscriberSendMs = max(0, videochat_sfu_now_ms() - $subscriberSendStartedAtMs);
        if ($sendOk) {
            $sentCount++;
        }
        if (!$sendOk || $subscriberSendMs > videochat_sfu_subscriber_replay_video_send_budget_ms()) {
            $slowSubscriberBlockedUntilMs[$subscriberId] = videochat_sfu_now_ms()
                + videochat_sfu_subscriber_video_send_cooldown_ms();
            videochat_sfu_log_runtime_event('sfu_frame_replay_slow_subscriber_isolated', [
                'room_id' => $roomId,
                'subscriber_id' => $subscriberId,
                'subscriber_send_ms' => $subscriberSendMs,
                'subscriber_send_budget_ms' => videochat_sfu_subscriber_replay_video_send_budget_ms(),
                'subscriber_video_cooldown_ms' => videochat_sfu_subscriber_video_send_cooldown_ms(),
                'sfu_send_path' => $sendPath,
                ...videochat_sfu_transport_metric_fields($frame, 0),
            ], 1000);
            break;
        }
    }

    return $sentCount;
}

/**
 * @param array<int, array<string, mixed>> $subscriberTargets
 * @param array<string, mixed> $outboundFrame
 * @param array<string, int> $slowSubscriberBlockedUntilMs
 */
function videochat_sfu_direct_fanout_frame(
    array $subscriberTargets,
    array $outboundFrame,
    int $fanoutStartedAtMs,
    string $roomId,
    string $publisherId,
    array &$slowSubscriberBlockedUntilMs
): void {
    foreach ($subscriberTargets as $subClient) {
        $subscriberId = (string) ($subClient['client_id'] ?? '');
        $nowMs = videochat_sfu_now_ms();
        $blockedUntilMs = (int) ($slowSubscriberBlockedUntilMs[$subscriberId] ?? 0);
        if ($blockedUntilMs > $nowMs) {
            videochat_sfu_log_runtime_event('sfu_frame_direct_fanout_slow_subscriber_skipped', [
                'room_id' => $roomId,
                'publisher_id' => $publisherId,
                'subscriber_id' => $subscriberId,
                'blocked_for_ms' => max(0, $blockedUntilMs - $nowMs),
                'sfu_send_path' => 'direct_fanout',
                ...videochat_sfu_transport_metric_fields($outboundFrame, 0),
            ], 1000);
            continue;
        }
        unset($slowSubscriberBlockedUntilMs[$subscriberId]);

        $frameForSubscriber = $outboundFrame;
        $routeDecision = videochat_sfu_subscriber_frame_route_decision($subClient, $frameForSubscriber);
        if (!(bool) ($routeDecision['send'] ?? true)) {
            videochat_sfu_log_runtime_event('sfu_frame_direct_fanout_layer_preference_pruned', [
                'room_id' => $roomId,
                'publisher_id' => $publisherId,
                'subscriber_id' => $subscriberId,
                'sfu_send_path' => 'direct_fanout',
                'requested_video_layer' => (string) ($routeDecision['layer_preference'] ?? ''),
                'drop_reason' => (string) ($routeDecision['drop_reason'] ?? ''),
                ...videochat_sfu_transport_metric_fields($frameForSubscriber, 0),
            ], 1000);
            continue;
        }
        $subscriberSendStartedAtMs = videochat_sfu_now_ms();
        $frameForSubscriber['subscriber_send_latency_ms'] = max(0, $subscriberSendStartedAtMs - $fanoutStartedAtMs);
        $sendOk = videochat_sfu_send_outbound_message($subClient['websocket'], $frameForSubscriber, [
            'sfu_send_path' => 'direct_fanout',
            'room_id' => $roomId,
            'subscriber_id' => $subscriberId,
            'worker_pid' => getmypid(),
            'subscriber_send_latency_ms' => $frameForSubscriber['subscriber_send_latency_ms'],
        ]);
        $subscriberSendMs = max(0, videochat_sfu_now_ms() - $subscriberSendStartedAtMs);
        if (!$sendOk || $subscriberSendMs > videochat_sfu_subscriber_video_send_budget_ms()) {
            $slowSubscriberBlockedUntilMs[$subscriberId] = videochat_sfu_now_ms()
                + videochat_sfu_subscriber_video_send_cooldown_ms();
            videochat_sfu_log_runtime_event('sfu_direct_fanout_slow_subscriber_video_isolated', [
                'room_id' => $roomId,
                'publisher_id' => $publisherId,
                'subscriber_id' => $subscriberId,
                'subscriber_send_ms' => $subscriberSendMs,
                'subscriber_send_budget_ms' => videochat_sfu_subscriber_video_send_budget_ms(),
                'subscriber_video_cooldown_ms' => videochat_sfu_subscriber_video_send_cooldown_ms(),
                'sfu_send_path' => 'direct_fanout',
                ...videochat_sfu_transport_metric_fields($frameForSubscriber, 0),
            ], 1000);
        }
        if (!$sendOk) {
            videochat_sfu_log_runtime_event('sfu_frame_direct_fanout_binary_required_failed', [
                'room_id' => $roomId,
                'publisher_id' => $publisherId,
                'subscriber_id' => $subscriberId,
                'track_id' => (string) ($frameForSubscriber['track_id'] ?? ''),
                'frame_type' => (string) ($frameForSubscriber['frame_type'] ?? 'delta'),
                'protection_mode' => (string) ($frameForSubscriber['protection_mode'] ?? 'transport_only'),
                'sfu_send_path' => 'direct_fanout',
                'worker_pid' => getmypid(),
                ...videochat_sfu_transport_metric_fields($frameForSubscriber, 0),
            ]);
        }
    }
}
