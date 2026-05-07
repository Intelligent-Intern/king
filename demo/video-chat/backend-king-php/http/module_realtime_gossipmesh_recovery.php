<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/realtime_gossipmesh_recovery.php';
require_once __DIR__ . '/../domain/realtime/realtime_signaling.php';

function videochat_realtime_gossipmesh_recovery_error_reason(string $errorCode): string
{
    return match ($errorCode) {
        'invalid_json', 'invalid_command', 'invalid_payload' => 'The Gossip recovery request payload is invalid.',
        'invalid_lane' => 'Gossip recovery requests must use the ops lane.',
        'forbidden_media_or_signaling_field' => 'Gossip recovery requests must not carry media, SDP, ICE, socket, token, or secret fields.',
        'missing_context' => 'The Gossip recovery request is missing call, room, or requester context.',
        'missing_publisher_track' => 'The Gossip recovery request is missing publisher or track context.',
        'context_mismatch' => 'The Gossip recovery request does not match the authenticated websocket call and room context.',
        'unauthenticated_peer' => 'The Gossip recovery requester does not match the authenticated websocket user.',
        'sender_not_in_room' => 'The Gossip recovery requester is not an admitted room member.',
        'publisher_unavailable' => 'The requested publisher is not connected in the current room.',
        default => 'Gossip recovery request failed.',
    };
}

function videochat_realtime_send_gossipmesh_recovery_error(
    mixed $websocket,
    array $presenceConnection,
    array $command,
    string $errorCode,
    ?callable $sender = null
): void {
    videochat_presence_send_frame(
        $websocket,
        [
            'type' => 'system/error',
            'code' => 'gossip_recovery_request_failed',
            'message' => 'Could not route Gossip-native recovery request.',
            'details' => [
                'error' => $errorCode,
                'reason' => videochat_realtime_gossipmesh_recovery_error_reason($errorCode),
                'type' => VIDEOCHAT_GOSSIPMESH_RECOVERY_REQUEST_TYPE,
                'room_id' => (string) ($command['room_id'] ?? ($presenceConnection['room_id'] ?? '')),
                'call_id' => (string) ($command['call_id'] ?? videochat_realtime_connection_call_id($presenceConnection)),
                'requester_peer_id' => (string) ($command['requester_peer_id'] ?? ''),
                'publisher_id' => (string) ($command['publisher_id'] ?? ''),
                'user_id' => (int) ($presenceConnection['user_id'] ?? 0),
            ],
            'time' => gmdate('c'),
        ],
        $sender
    );
}

function videochat_realtime_gossipmesh_recovery_target_user_id(array $command): int
{
    foreach (['publisher_user_id', 'publisher_id'] as $field) {
        $candidate = trim((string) ($command[$field] ?? ''));
        if ($candidate !== '' && preg_match('/^[0-9]+$/', $candidate) === 1) {
            return (int) $candidate;
        }
    }

    return 0;
}

function videochat_realtime_gossipmesh_send_recovery_frame(
    array $targetConnection,
    array $presenceConnection,
    array $command,
    ?callable $sender = null
): bool {
    $opsPayload = videochat_gossipmesh_recovery_ops_payload($command);
    $sentRecovery = videochat_presence_send_frame(
        $targetConnection['socket'] ?? null,
        [
            'type' => VIDEOCHAT_GOSSIPMESH_CALL_RECOVERY_TYPE,
            'lane' => 'ops',
            'room_id' => (string) ($command['room_id'] ?? ''),
            'target_user_id' => (int) ($targetConnection['user_id'] ?? 0),
            'sender' => videochat_signaling_sender_payload($presenceConnection),
            'payload' => $opsPayload,
            'time' => gmdate('c'),
        ],
        $sender
    );

    $sentKeyframeRequest = videochat_presence_send_frame(
        $targetConnection['socket'] ?? null,
        [
            'type' => 'call/media-quality-pressure',
            'lane' => 'ops',
            'room_id' => (string) ($command['room_id'] ?? ''),
            'target_user_id' => (int) ($targetConnection['user_id'] ?? 0),
            'sender' => videochat_signaling_sender_payload($presenceConnection),
            'payload' => [
                'kind' => 'gossip_native_recovery_keyframe_request',
                'requested_action' => 'force_full_keyframe',
                'request_full_keyframe' => true,
                'keyframe_only' => true,
                'source_reason' => (string) ($command['reason'] ?? 'gossip_native_recovery'),
                'publisher_id' => (string) ($command['publisher_id'] ?? ''),
                'track_id' => (string) ($command['track_id'] ?? ''),
                'request_id' => (string) ($command['request_id'] ?? ''),
            ],
            'time' => gmdate('c'),
        ],
        $sender
    );

    return $sentRecovery || $sentKeyframeRequest;
}

function videochat_realtime_handle_gossipmesh_recovery_request_command(
    array $recoveryCommand,
    mixed $websocket,
    array &$presenceState,
    array $presenceConnection,
    callable $openDatabase,
    ?callable $sender = null
): ?array {
    if (!(bool) ($recoveryCommand['ok'] ?? false)) {
        if ((string) ($recoveryCommand['error'] ?? '') === 'unsupported_type') {
            return null;
        }
        videochat_realtime_send_gossipmesh_recovery_error($websocket, $presenceConnection, $recoveryCommand, (string) ($recoveryCommand['error'] ?? 'invalid_command'), $sender);
        return videochat_realtime_secondary_handled_result();
    }

    $roomId = videochat_presence_normalize_room_id((string) ($recoveryCommand['room_id'] ?? ''), '');
    $connectionRoomId = videochat_presence_normalize_room_id((string) ($presenceConnection['room_id'] ?? ''), '');
    $callId = videochat_realtime_normalize_call_id((string) ($recoveryCommand['call_id'] ?? ''), '');
    $connectionCallId = videochat_realtime_connection_call_id($presenceConnection);
    $userId = (int) ($presenceConnection['user_id'] ?? 0);
    $requesterPeerId = videochat_gossipmesh_safe_id($recoveryCommand['requester_peer_id'] ?? '');
    if ($roomId === '' || $callId === '' || $connectionRoomId === '' || $connectionCallId === '' || $roomId !== $connectionRoomId || $callId !== $connectionCallId) {
        videochat_realtime_send_gossipmesh_recovery_error($websocket, $presenceConnection, $recoveryCommand, 'context_mismatch', $sender);
        return videochat_realtime_secondary_handled_result();
    }
    if ($userId <= 0 || $requesterPeerId !== (string) $userId) {
        videochat_realtime_send_gossipmesh_recovery_error($websocket, $presenceConnection, $recoveryCommand, 'unauthenticated_peer', $sender);
        return videochat_realtime_secondary_handled_result();
    }

    $sessionId = trim((string) ($presenceConnection['session_id'] ?? ''));
    $isLocalMember = videochat_realtime_presence_has_room_membership($presenceState, $roomId, $userId, $sessionId);
    $isDbMember = $isLocalMember ? true : videochat_realtime_db_room_has_joined_user($openDatabase, $presenceConnection, $roomId, $userId);
    if (!$isDbMember) {
        videochat_realtime_send_gossipmesh_recovery_error($websocket, $presenceConnection, $recoveryCommand, 'sender_not_in_room', $sender);
        return videochat_realtime_secondary_handled_result();
    }

    $targetUserId = videochat_realtime_gossipmesh_recovery_target_user_id($recoveryCommand);
    if ($targetUserId <= 0 || $targetUserId === $userId) {
        videochat_realtime_send_gossipmesh_recovery_error($websocket, $presenceConnection, $recoveryCommand, 'publisher_unavailable', $sender);
        return videochat_realtime_secondary_handled_result();
    }

    $tenantId = is_numeric($presenceConnection['tenant_id'] ?? null) ? (int) $presenceConnection['tenant_id'] : null;
    $roomConnections = $presenceState['rooms'][videochat_presence_room_key($roomId, $tenantId)] ?? [];
    $sentCount = 0;
    foreach (is_array($roomConnections) ? $roomConnections : [] as $connectionId => $_socket) {
        $targetConnection = is_string($connectionId) ? ($presenceState['connections'][$connectionId] ?? null) : null;
        if (!is_array($targetConnection) || (int) ($targetConnection['user_id'] ?? 0) !== $targetUserId) {
            continue;
        }
        if (videochat_realtime_gossipmesh_send_recovery_frame($targetConnection, $presenceConnection, $recoveryCommand, $sender)) {
            $sentCount++;
        }
    }

    if ($sentCount <= 0) {
        videochat_realtime_send_gossipmesh_recovery_error($websocket, $presenceConnection, $recoveryCommand, 'publisher_unavailable', $sender);
    } else {
        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'gossip/recovery/ack',
                'lane' => 'ops',
                'room_id' => $roomId,
                'call_id' => $callId,
                'request_id' => (string) ($recoveryCommand['request_id'] ?? ''),
                'sent_count' => $sentCount,
                'time' => gmdate('c'),
            ],
            $sender
        );
    }

    return videochat_realtime_secondary_handled_result();
}
