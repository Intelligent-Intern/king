<?php

declare(strict_types=1);

const VIDEOCHAT_GOSSIPMESH_RECOVERY_REQUEST_TYPE = 'gossip/recovery/request';
const VIDEOCHAT_GOSSIPMESH_CALL_RECOVERY_TYPE = 'call/gossip-recovery';
const VIDEOCHAT_GOSSIPMESH_RECOVERY_MAX_REASON_BYTES = 160;

/**
 * @return array<int, string>
 */
function videochat_gossipmesh_recovery_forbidden_fields(): array
{
    return array_values(array_unique([
        ...videochat_gossipmesh_telemetry_forbidden_fields(),
        'data_base64',
        'protectedFrame',
        'protected_frame',
        'payload_base64',
        'media_frame',
        'encoded_frame',
        'audio_frame',
        'video_frame',
        'sender_key',
        'envelope_contract',
    ]));
}

function videochat_gossipmesh_recovery_request_type(mixed $value): string
{
    $requestType = strtolower(trim((string) $value));
    return in_array($requestType, ['missing_frame', 'keyframe', 'retransmit'], true)
        ? $requestType
        : 'keyframe';
}

/**
 * @return array<string, mixed>
 */
function videochat_gossipmesh_decode_recovery_request(string $frame): array
{
    try {
        $decoded = json_decode($frame, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return ['ok' => false, 'type' => '', 'error' => 'invalid_json'];
    }

    if (!is_array($decoded)) {
        return ['ok' => false, 'type' => '', 'error' => 'invalid_command'];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type !== VIDEOCHAT_GOSSIPMESH_RECOVERY_REQUEST_TYPE) {
        return ['ok' => false, 'type' => $type, 'error' => 'unsupported_type'];
    }

    $lane = strtolower(trim((string) ($decoded['lane'] ?? '')));
    if ($lane !== 'ops') {
        return ['ok' => false, 'type' => $type, 'error' => 'invalid_lane'];
    }

    $forbiddenFields = videochat_gossipmesh_recovery_forbidden_fields();
    $wrapperProbe = $decoded;
    unset($wrapperProbe['payload']);
    if (videochat_gossipmesh_payload_contains_forbidden_field($wrapperProbe, $forbiddenFields)) {
        return ['ok' => false, 'type' => $type, 'error' => 'forbidden_media_or_signaling_field'];
    }

    $payload = $decoded['payload'] ?? null;
    if (!is_array($payload)) {
        return ['ok' => false, 'type' => $type, 'error' => 'invalid_payload'];
    }
    if (videochat_gossipmesh_payload_contains_forbidden_field($payload, $forbiddenFields)) {
        return ['ok' => false, 'type' => $type, 'error' => 'forbidden_media_or_signaling_field'];
    }

    $kind = strtolower(trim((string) ($payload['kind'] ?? '')));
    if ($kind !== '' && $kind !== 'gossip_recovery_request') {
        return ['ok' => false, 'type' => $type, 'error' => 'invalid_payload_kind'];
    }

    $roomId = videochat_gossipmesh_safe_id($payload['room_id'] ?? '');
    $callId = videochat_gossipmesh_safe_id($payload['call_id'] ?? '');
    $requesterPeerId = videochat_gossipmesh_safe_id($payload['requester_peer_id'] ?? ($payload['peer_id'] ?? ''));
    $publisherId = videochat_gossipmesh_safe_id($payload['publisher_id'] ?? '');
    $publisherUserId = videochat_gossipmesh_safe_id($payload['publisher_user_id'] ?? $publisherId);
    $trackId = videochat_gossipmesh_safe_id($payload['track_id'] ?? '');
    $reason = trim((string) ($payload['reason'] ?? 'gossip_native_recovery'));
    if (strlen($reason) > VIDEOCHAT_GOSSIPMESH_RECOVERY_MAX_REASON_BYTES) {
        $reason = substr($reason, 0, VIDEOCHAT_GOSSIPMESH_RECOVERY_MAX_REASON_BYTES);
    }

    if ($roomId === '' || $callId === '' || $requesterPeerId === '') {
        return ['ok' => false, 'type' => $type, 'error' => 'missing_context'];
    }
    if ($publisherId === '' || $trackId === '') {
        return ['ok' => false, 'type' => $type, 'error' => 'missing_publisher_track'];
    }

    return [
        'ok' => true,
        'type' => $type,
        'lane' => 'ops',
        'request_id' => videochat_gossipmesh_safe_object_key($payload['request_id'] ?? '') ?: 'ggr_' . substr(hash('sha256', $frame), 0, 20),
        'request_type' => videochat_gossipmesh_recovery_request_type($payload['request_type'] ?? ''),
        'room_id' => $roomId,
        'call_id' => $callId,
        'requester_peer_id' => $requesterPeerId,
        'publisher_id' => $publisherId,
        'publisher_user_id' => $publisherUserId,
        'track_id' => $trackId,
        'media_generation' => videochat_gossipmesh_clamp_int($payload['media_generation'] ?? 0, 0, 0, 1_000_000_000),
        'missing_from_sequence' => videochat_gossipmesh_clamp_int($payload['missing_from_sequence'] ?? 0, 0, 0, 1_000_000_000),
        'missing_to_sequence' => videochat_gossipmesh_clamp_int($payload['missing_to_sequence'] ?? 0, 0, 0, 1_000_000_000),
        'last_received_sequence' => videochat_gossipmesh_clamp_int($payload['last_received_sequence'] ?? 0, 0, 0, 1_000_000_000),
        'observed_frame_sequence' => videochat_gossipmesh_clamp_int($payload['observed_frame_sequence'] ?? 0, 0, 0, 1_000_000_000),
        'prefer_keyframe' => ($payload['prefer_keyframe'] ?? false) === true,
        'reason' => $reason,
        'payload' => $payload,
        'error' => '',
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_gossipmesh_recovery_ops_payload(array $command): array
{
    return [
        'kind' => 'gossip_recovery_request',
        'request_id' => (string) ($command['request_id'] ?? ''),
        'request_type' => (string) ($command['request_type'] ?? 'keyframe'),
        'room_id' => (string) ($command['room_id'] ?? ''),
        'call_id' => (string) ($command['call_id'] ?? ''),
        'requester_peer_id' => (string) ($command['requester_peer_id'] ?? ''),
        'publisher_id' => (string) ($command['publisher_id'] ?? ''),
        'publisher_user_id' => (string) ($command['publisher_user_id'] ?? ''),
        'track_id' => (string) ($command['track_id'] ?? ''),
        'media_generation' => (int) ($command['media_generation'] ?? 0),
        'missing_from_sequence' => (int) ($command['missing_from_sequence'] ?? 0),
        'missing_to_sequence' => (int) ($command['missing_to_sequence'] ?? 0),
        'last_received_sequence' => (int) ($command['last_received_sequence'] ?? 0),
        'observed_frame_sequence' => (int) ($command['observed_frame_sequence'] ?? 0),
        'prefer_keyframe' => (bool) ($command['prefer_keyframe'] ?? false),
        'reason' => (string) ($command['reason'] ?? 'gossip_native_recovery'),
    ];
}
