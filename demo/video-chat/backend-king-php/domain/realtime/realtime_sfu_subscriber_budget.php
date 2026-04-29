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
    string $sendPath
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
    $staleDeltaCount = 0;
    $preKeyframeDeltaCount = 0;
    foreach ($frames as $index => $frame) {
        if (!is_array($frame)) {
            continue;
        }
        $isDelta = videochat_sfu_frame_is_delta($frame);
        $trackKey = videochat_sfu_frame_replay_track_key($frame);
        $ageMs = videochat_sfu_frame_replay_age_ms($frame);
        if ($isDelta && $ageMs > videochat_sfu_subscriber_replay_delta_max_age_ms()) {
            $staleDeltaCount++;
            continue;
        }
        if ($isDelta && isset($firstKeyframeIndexByTrack[$trackKey]) && $index < $firstKeyframeIndexByTrack[$trackKey]) {
            $preKeyframeDeltaCount++;
            continue;
        }

        $pruned[] = $frame;
        if (count($pruned) >= videochat_sfu_subscriber_replay_max_batch_frames()) {
            break;
        }
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
    array &$slowSubscriberBlockedUntilMs
): int {
    $sentCount = 0;
    $framesToSend = videochat_sfu_prune_replay_frames_for_subscriber($frames, $roomId, $subscriberId, $sendPath);
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
