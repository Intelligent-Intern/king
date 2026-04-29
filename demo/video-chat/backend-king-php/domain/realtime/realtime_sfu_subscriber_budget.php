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
