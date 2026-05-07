<?php

declare(strict_types=1);

const VIDEOCHAT_REALTIME_MEDIA_FANOUT_GUARD_CODE = 'normal_media_fanout_forbidden';

/**
 * @return array<int, string>
 */
function videochat_realtime_normal_media_fanout_types(): array
{
    return [
        'gossip/data-frame',
        'gossip/frame',
        'gossip/media',
        'gossip/media-frame',
        'sfu/frame',
        'sfu/frame-chunk',
    ];
}

/**
 * @return array<int, string>
 */
function videochat_realtime_normal_media_fanout_fields(): array
{
    return [
        'audio_frame',
        'data_base64',
        'data_base64_chunk',
        'data_binary',
        'dataBase64',
        'dataBinary',
        'encoded_frame',
        'encodedFrame',
        'media_frame',
        'mediaFrame',
        'plaintext_frame',
        'protectedFrame',
        'protected_frame',
        'protected_frame_chunk',
        'video_frame',
    ];
}

function videochat_realtime_payload_contains_normal_media_field(mixed $payload): bool
{
    if (!is_array($payload)) {
        return false;
    }

    foreach ($payload as $key => $value) {
        if (is_string($key) && in_array($key, videochat_realtime_normal_media_fanout_fields(), true)) {
            return true;
        }
        if (is_array($value) && videochat_realtime_payload_contains_normal_media_field($value)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{blocked: bool, type: string, reason: string}
 */
function videochat_realtime_classify_normal_media_fanout_frame(string $frame): array
{
    try {
        $decoded = json_decode($frame, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['blocked' => false, 'type' => '', 'reason' => 'not_json'];
    }

    if (!is_array($decoded)) {
        return ['blocked' => false, 'type' => '', 'reason' => 'not_command'];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if (in_array($type, videochat_realtime_normal_media_fanout_types(), true)) {
        return ['blocked' => true, 'type' => $type, 'reason' => 'normal_media_command_on_control_socket'];
    }

    if (videochat_realtime_payload_contains_normal_media_field($decoded)) {
        return ['blocked' => true, 'type' => $type, 'reason' => 'media_payload_on_control_socket'];
    }

    return ['blocked' => false, 'type' => $type, 'reason' => 'control_command'];
}

function videochat_realtime_guard_no_normal_media_fanout(
    string $frame,
    mixed $websocket,
    array $presenceConnection,
    ?callable $sender = null
): ?array {
    $classification = videochat_realtime_classify_normal_media_fanout_frame($frame);
    if (($classification['blocked'] ?? false) !== true) {
        return null;
    }

    videochat_presence_send_frame(
        $websocket,
        [
            'type' => 'system/error',
            'code' => VIDEOCHAT_REALTIME_MEDIA_FANOUT_GUARD_CODE,
            'message' => 'Normal media frames are not accepted on the realtime control websocket.',
            'details' => [
                'error' => VIDEOCHAT_REALTIME_MEDIA_FANOUT_GUARD_CODE,
                'reason' => (string) ($classification['reason'] ?? 'normal_media_forbidden'),
                'type' => (string) ($classification['type'] ?? ''),
                'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                'call_id' => videochat_realtime_connection_call_id($presenceConnection),
                'allowed_media_paths' => ['sfu_gateway_fallback_relay_recording', 'bounded_gossip_peer_links'],
            ],
            'time' => gmdate('c'),
        ],
        $sender
    );

    return videochat_realtime_secondary_handled_result();
}
