<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/realtime_gossipmesh_recovery.php';
require_once __DIR__ . '/module_realtime_websocket_connect.php';

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

    videochat_presence_send_frame(
        $websocket,
        videochat_realtime_websocket_gossip_ops_state_frame($presenceState, $presenceConnection, [
            'reason' => 'client_recovery_request_not_required',
        ]),
        $sender
    );

    return videochat_realtime_secondary_handled_result();
}
