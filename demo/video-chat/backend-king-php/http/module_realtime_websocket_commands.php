<?php

declare(strict_types=1);

require_once __DIR__ . '/module_realtime_gossipmesh_recovery.php';
require_once __DIR__ . '/module_realtime_media_fanout_guard.php';
require_once __DIR__ . '/module_realtime_gossip_media_relay.php';
require_once __DIR__ . '/module_realtime_media_session_commands.php';
require_once __DIR__ . '/module_realtime_chat_commands.php';
require_once __DIR__ . '/module_realtime_websocket_connect.php';
require_once __DIR__ . '/module_realtime_websocket_admin_sync.php';
require_once __DIR__ . '/module_realtime_websocket_lobby.php';

function videochat_realtime_secondary_handled_result(): array
{
    return [
        'handled' => true,
        'command_type' => '',
        'command_error' => '',
    ];
}

function videochat_realtime_secondary_invalid_result(
    array $command,
    string $fallbackType = '',
    string $fallbackError = 'unsupported_type'
): array {
    return [
        'handled' => false,
        'command_type' => (string) ($command['type'] ?? $fallbackType),
        'command_error' => (string) ($command['error'] ?? $fallbackError),
    ];
}

function videochat_realtime_handle_secondary_websocket_command(
    string $frame,
    mixed $websocket,
    array &$presenceState,
    array &$lobbyState,
    array &$typingState,
    array &$reactionState,
    array &$presenceConnection,
    ?PDO $chatBrokerDatabase,
    ?PDO $signalingBrokerDatabase,
    ?PDO $reactionBrokerDatabase,
    callable $openDatabase
): array {
    $mediaFanoutGuardResult = videochat_realtime_guard_no_normal_media_fanout(
        $frame,
        $websocket,
        $presenceConnection
    );
    if ($mediaFanoutGuardResult !== null) {
        return $mediaFanoutGuardResult;
    }

    if (function_exists('videochat_sfu_binary_frame_has_magic') && videochat_sfu_binary_frame_has_magic($frame)) {
        $binaryGossipMediaRelayCommand = videochat_gossip_media_relay_decode_client_frame($frame, $presenceConnection);
        $binaryGossipMediaRelayResult = videochat_realtime_handle_gossip_media_relay_command(
            $binaryGossipMediaRelayCommand,
            $websocket,
            $presenceState,
            $presenceConnection,
            $signalingBrokerDatabase,
            $openDatabase
        );
        if ($binaryGossipMediaRelayResult !== null) {
            return $binaryGossipMediaRelayResult;
        }
    }

    $capabilitiesCommand = videochat_realtime_decode_client_capabilities_frame($frame);
    $capabilitiesResult = videochat_realtime_handle_media_capabilities_websocket_command(
        $capabilitiesCommand,
        $websocket,
        $presenceState,
        $presenceConnection,
        $openDatabase
    );
    if ($capabilitiesResult !== null) {
        return $capabilitiesResult;
    }

    $gossipMediaRelayCommand = videochat_gossip_media_relay_decode_client_frame($frame, $presenceConnection);
    $gossipMediaRelayResult = videochat_realtime_handle_gossip_media_relay_command(
        $gossipMediaRelayCommand,
        $websocket,
        $presenceState,
        $presenceConnection,
        $signalingBrokerDatabase,
        $openDatabase
    );
    if ($gossipMediaRelayResult !== null) {
        return $gossipMediaRelayResult;
    }

    $chatCommand = videochat_chat_decode_client_frame($frame);
    $chatResult = videochat_realtime_handle_chat_websocket_command(
        $chatCommand,
        $websocket,
        $presenceState,
        $presenceConnection,
        $chatBrokerDatabase,
        $openDatabase
    );
    if ($chatResult !== null) {
        return $chatResult;
    }

    $typingCommand = videochat_typing_decode_client_frame($frame);
    $typingResult = videochat_realtime_handle_typing_websocket_command(
        $typingCommand,
        $websocket,
        $typingState,
        $presenceState,
        $presenceConnection
    );
    if ($typingResult !== null) {
        return $typingResult;
    }

    $signalingCommand = videochat_signaling_decode_client_frame($frame);
    $signalingResult = videochat_realtime_handle_signaling_websocket_command(
        $signalingCommand,
        $websocket,
        $presenceState,
        $presenceConnection,
        $signalingBrokerDatabase,
        $openDatabase
    );
    if ($signalingResult !== null) {
        return $signalingResult;
    }

    $gossipRepairCommand = videochat_gossipmesh_decode_topology_repair_request($frame);
    $gossipRepairResult = videochat_realtime_handle_gossipmesh_topology_repair_command(
        $gossipRepairCommand,
        $websocket,
        $presenceState,
        $presenceConnection,
        $openDatabase
    );
    if ($gossipRepairResult !== null) {
        return $gossipRepairResult;
    }

    $gossipRecoveryCommand = videochat_gossipmesh_decode_recovery_request($frame);
    $gossipRecoveryResult = videochat_realtime_handle_gossipmesh_recovery_request_command(
        $gossipRecoveryCommand,
        $websocket,
        $presenceState,
        $presenceConnection,
        $openDatabase
    );
    if ($gossipRecoveryResult !== null) {
        return $gossipRecoveryResult;
    }

    $gossipTelemetryCommand = videochat_gossipmesh_decode_telemetry_snapshot($frame);
    $gossipTelemetryResult = videochat_realtime_handle_gossipmesh_telemetry_snapshot_command(
        $gossipTelemetryCommand,
        $websocket,
        $presenceState,
        $presenceConnection
    );
    if ($gossipTelemetryResult !== null) {
        return $gossipTelemetryResult;
    }

    $reactionCommand = videochat_reaction_decode_client_frame($frame);
    $reactionResult = videochat_realtime_handle_reaction_websocket_command(
        $reactionCommand,
        $websocket,
        $reactionState,
        $presenceState,
        $presenceConnection,
        $reactionBrokerDatabase
    );
    if ($reactionResult !== null) {
        return $reactionResult;
    }

    $activityCommand = videochat_activity_decode_client_frame($frame);
    $activityResult = videochat_realtime_handle_activity_websocket_command(
        $activityCommand,
        $websocket,
        $presenceState,
        $presenceConnection,
        $openDatabase
    );
    if ($activityResult !== null) {
        return $activityResult;
    }

    $layoutCommand = videochat_layout_decode_client_frame($frame);
    $layoutResult = videochat_realtime_handle_layout_websocket_command(
        $layoutCommand,
        $websocket,
        $presenceState,
        $presenceConnection,
        $openDatabase
    );
    if ($layoutResult !== null) {
        return $layoutResult;
    }

    $lobbyCommand = videochat_lobby_decode_client_frame($frame);
    $lobbyResult = videochat_realtime_handle_lobby_websocket_command(
        $lobbyCommand,
        $websocket,
        $lobbyState,
        $presenceState,
        $presenceConnection,
        $openDatabase
    );
    if ($lobbyResult !== null) {
        return $lobbyResult;
    }

    $adminSyncCommand = videochat_admin_sync_decode_client_frame($frame);
    return videochat_realtime_handle_admin_sync_websocket_command(
        $adminSyncCommand,
        $websocket,
        $presenceState,
        $presenceConnection
    ) ?? videochat_realtime_secondary_invalid_result($adminSyncCommand);
}

function videochat_realtime_handle_typing_websocket_command(
    array $typingCommand,
    mixed $websocket,
    array &$typingState,
    array &$presenceState,
    array $presenceConnection
): ?array {
    if (!(bool) ($typingCommand['ok'] ?? false)) {
        return (string) ($typingCommand['error'] ?? '') === 'unsupported_type'
            ? null
            : videochat_realtime_secondary_invalid_result($typingCommand);
    }

    $typingResult = videochat_typing_apply_command($typingState, $presenceState, $presenceConnection, $typingCommand);
    if (!(bool) ($typingResult['ok'] ?? false)) {
        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/error',
                'code' => 'typing_publish_failed',
                'message' => 'Could not publish typing state.',
                'details' => [
                    'error' => (string) ($typingResult['error'] ?? 'unknown'),
                    'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                ],
                'time' => gmdate('c'),
            ]
        );
    }

    return videochat_realtime_secondary_handled_result();
}

function videochat_realtime_handle_signaling_websocket_command(
    array $signalingCommand,
    mixed $websocket,
    array &$presenceState,
    array &$presenceConnection,
    ?PDO $signalingBrokerDatabase,
    callable $openDatabase
): ?array {
    if (!(bool) ($signalingCommand['ok'] ?? false)) {
        return (string) ($signalingCommand['error'] ?? '') === 'unsupported_type'
            ? null
            : videochat_realtime_secondary_invalid_result($signalingCommand);
    }

    $signalingBroker = $signalingBrokerDatabase instanceof PDO
        ? static function (string $roomId, int $targetUserId, array $event) use (
            $signalingBrokerDatabase,
            $openDatabase,
            &$presenceConnection
        ): bool {
            if (!videochat_realtime_db_room_has_joined_user($openDatabase, (array) $presenceConnection, $roomId, $targetUserId)) {
                return false;
            }

            return videochat_signaling_broker_insert_event($signalingBrokerDatabase, $roomId, $targetUserId, $event);
        }
        : null;
    $signalingPublish = videochat_signaling_publish(
        $presenceState,
        $presenceConnection,
        $signalingCommand,
        null,
        null,
        $signalingBroker
    );
    if (!(bool) ($signalingPublish['ok'] ?? false)) {
        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/error',
                'code' => 'signaling_publish_failed',
                'message' => 'Could not route signaling message.',
                'details' => [
                    'error' => (string) ($signalingPublish['error'] ?? 'unknown'),
                    'type' => (string) ($signalingCommand['type'] ?? ''),
                    'target_user_id' => (int) ($signalingCommand['target_user_id'] ?? 0),
                    'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                ],
                'time' => gmdate('c'),
            ]
        );
        return videochat_realtime_secondary_handled_result();
    }

    $eventSignal = is_array($signalingPublish['event']['signal'] ?? null) ? $signalingPublish['event']['signal'] : [];
    videochat_presence_send_frame(
        $websocket,
        [
            'type' => 'call/ack',
            'signal_type' => (string) ($signalingCommand['type'] ?? ''),
            'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
            'target_user_id' => (int) ($signalingCommand['target_user_id'] ?? 0),
            'signal_id' => (string) ($eventSignal['id'] ?? ''),
            'server_time' => (string) ($eventSignal['server_time'] ?? gmdate('c')),
            'sent_count' => (int) ($signalingPublish['sent_count'] ?? 0),
            'time' => gmdate('c'),
        ]
    );
    return videochat_realtime_secondary_handled_result();
}

function videochat_realtime_gossipmesh_repair_error_reason(string $errorCode): string
{
    return match ($errorCode) {
        'invalid_json', 'invalid_command', 'invalid_payload' => 'The topology repair request payload is invalid.',
        'invalid_lane' => 'Topology repair requests must use the ops lane.',
        'forbidden_media_or_signaling_field' => 'Topology repair requests must not carry media, SDP, ICE, socket, or secret fields.',
        'missing_context' => 'The topology repair request is missing call, room, or peer context.',
        'invalid_lost_neighbor' => 'The topology repair request must name a lost neighbor other than the authenticated peer.',
        'context_mismatch' => 'The topology repair request does not match the authenticated websocket call and room context.',
        'unauthenticated_peer' => 'The topology repair peer_id does not match the authenticated websocket user.',
        'sender_not_in_room' => 'The authenticated websocket user is not an active member of the requested room.',
        'topology_unavailable' => 'A replacement gossip topology could not be produced.',
        default => 'Topology repair failed.',
    };
}

function videochat_realtime_send_gossipmesh_repair_error(
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
            'code' => 'gossip_topology_repair_failed',
            'message' => 'Could not repair GossipMesh topology.',
            'details' => [
                'error' => $errorCode,
                'reason' => videochat_realtime_gossipmesh_repair_error_reason($errorCode),
                'type' => VIDEOCHAT_GOSSIPMESH_TOPOLOGY_REPAIR_TYPE,
                'room_id' => (string) ($command['room_id'] ?? ($presenceConnection['room_id'] ?? '')),
                'call_id' => (string) ($command['call_id'] ?? videochat_realtime_connection_call_id($presenceConnection)),
                'peer_id' => (string) ($command['peer_id'] ?? ''),
                'user_id' => (int) ($presenceConnection['user_id'] ?? 0),
            ],
            'time' => gmdate('c'),
        ],
        $sender
    );
}

function videochat_realtime_handle_gossipmesh_topology_repair_command(
    array $repairCommand,
    mixed $websocket,
    array &$presenceState,
    array $presenceConnection,
    callable $openDatabase,
    ?callable $sender = null
): ?array {
    if (!(bool) ($repairCommand['ok'] ?? false)) {
        if ((string) ($repairCommand['error'] ?? '') === 'unsupported_type') {
            return null;
        }

        videochat_realtime_send_gossipmesh_repair_error(
            $websocket,
            $presenceConnection,
            $repairCommand,
            (string) ($repairCommand['error'] ?? 'invalid_command'),
            $sender
        );
        return videochat_realtime_secondary_handled_result();
    }

    videochat_presence_send_frame(
        $websocket,
        videochat_realtime_websocket_gossip_ops_state_frame($presenceState, $presenceConnection, [
            'reason' => 'client_topology_repair_not_required',
        ]),
        $sender
    );

    return videochat_realtime_secondary_handled_result();
}

function videochat_realtime_gossipmesh_telemetry_error_reason(string $errorCode): string
{
    return match ($errorCode) {
        'invalid_json', 'invalid_command', 'invalid_payload' => 'The telemetry snapshot payload is invalid.',
        'invalid_lane' => 'Gossip telemetry snapshots must use the ops lane.',
        'forbidden_media_or_signaling_field' => 'Gossip telemetry snapshots must not carry media, SDP, ICE, socket, token, or secret fields.',
        'missing_context' => 'The telemetry snapshot is missing call, room, or peer context.',
        'context_mismatch' => 'The telemetry snapshot does not match the authenticated websocket call and room context.',
        'unauthenticated_peer' => 'The telemetry snapshot peer_id does not match the authenticated websocket user.',
        default => 'Gossip telemetry snapshot failed.',
    };
}

function videochat_realtime_send_gossipmesh_telemetry_error(
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
            'code' => 'gossip_telemetry_snapshot_failed',
            'message' => 'Could not accept GossipMesh telemetry snapshot.',
            'details' => [
                'error' => $errorCode,
                'reason' => videochat_realtime_gossipmesh_telemetry_error_reason($errorCode),
                'type' => VIDEOCHAT_GOSSIPMESH_TELEMETRY_SNAPSHOT_TYPE,
                'room_id' => (string) ($command['room_id'] ?? ($presenceConnection['room_id'] ?? '')),
                'call_id' => (string) ($command['call_id'] ?? videochat_realtime_connection_call_id($presenceConnection)),
                'peer_id' => (string) ($command['peer_id'] ?? ''),
                'user_id' => (int) ($presenceConnection['user_id'] ?? 0),
            ],
            'time' => gmdate('c'),
        ],
        $sender
    );
}

function videochat_realtime_handle_gossipmesh_telemetry_snapshot_command(
    array $telemetryCommand,
    mixed $websocket,
    array &$presenceState,
    array $presenceConnection,
    ?callable $sender = null
): ?array {
    if (!(bool) ($telemetryCommand['ok'] ?? false)) {
        if ((string) ($telemetryCommand['error'] ?? '') === 'unsupported_type') {
            return null;
        }

        videochat_realtime_send_gossipmesh_telemetry_error(
            $websocket,
            $presenceConnection,
            $telemetryCommand,
            (string) ($telemetryCommand['error'] ?? 'invalid_command'),
            $sender
        );
        return videochat_realtime_secondary_handled_result();
    }

    $roomId = videochat_presence_normalize_room_id((string) ($telemetryCommand['room_id'] ?? ''), '');
    $connectionRoomId = videochat_presence_normalize_room_id((string) ($presenceConnection['room_id'] ?? ''), '');
    $callId = videochat_realtime_normalize_call_id((string) ($telemetryCommand['call_id'] ?? ''), '');
    $connectionCallId = videochat_realtime_connection_call_id($presenceConnection);
    $userId = (int) ($presenceConnection['user_id'] ?? 0);
    $peerId = videochat_gossipmesh_safe_id($telemetryCommand['peer_id'] ?? '');

    if (
        $roomId === ''
        || $callId === ''
        || $connectionRoomId === ''
        || $connectionCallId === ''
        || $roomId !== $connectionRoomId
        || $callId !== $connectionCallId
    ) {
        videochat_realtime_send_gossipmesh_telemetry_error($websocket, $presenceConnection, $telemetryCommand, 'context_mismatch', $sender);
        return videochat_realtime_secondary_handled_result();
    }

    if ($userId <= 0 || $peerId !== (string) $userId) {
        videochat_realtime_send_gossipmesh_telemetry_error($websocket, $presenceConnection, $telemetryCommand, 'unauthenticated_peer', $sender);
        return videochat_realtime_secondary_handled_result();
    }

    $aggregate = videochat_gossipmesh_aggregate_telemetry_snapshot($presenceState, [
        ...$telemetryCommand,
        'room_id' => $roomId,
        'call_id' => $callId,
        'peer_id' => $peerId,
    ]);

    videochat_presence_send_frame($websocket, videochat_realtime_websocket_gossip_ops_state_frame($presenceState, $presenceConnection, [
        'room_id' => $roomId,
        'call_id' => $callId,
        'peer_id' => $peerId,
        'peer_count' => (int) ($aggregate['peer_count'] ?? 0),
        'transports' => is_array($aggregate['transports'] ?? null) ? (array) $aggregate['transports'] : [],
        'rollout_gate' => is_array($aggregate['rollout_gate'] ?? null) ? (array) $aggregate['rollout_gate'] : [],
        'reason' => 'telemetry_snapshot',
    ]), $sender);

    return videochat_realtime_secondary_handled_result();
}

function videochat_realtime_handle_reaction_websocket_command(
    array $reactionCommand,
    mixed $websocket,
    array &$reactionState,
    array &$presenceState,
    array $presenceConnection,
    ?PDO $reactionBrokerDatabase
): ?array {
    if (!(bool) ($reactionCommand['ok'] ?? false)) {
        return (string) ($reactionCommand['error'] ?? '') === 'unsupported_type'
            ? null
            : videochat_realtime_secondary_invalid_result($reactionCommand);
    }

    $reactionBroker = $reactionBrokerDatabase instanceof PDO
        ? static function (string $roomId, array $event) use ($reactionBrokerDatabase): bool {
            return videochat_reaction_broker_insert_event($reactionBrokerDatabase, $roomId, $event);
        }
        : null;
    $reactionPublish = videochat_reaction_publish(
        $reactionState,
        $presenceState,
        $presenceConnection,
        $reactionCommand,
        null,
        null,
        $reactionBroker
    );
    if (!(bool) ($reactionPublish['ok'] ?? false)) {
        $details = [
            'error' => (string) ($reactionPublish['error'] ?? 'unknown'),
            'type' => (string) ($reactionCommand['type'] ?? ''),
            'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
        ];
        $retryAfterMs = (int) ($reactionPublish['retry_after_ms'] ?? 0);
        if ($retryAfterMs > 0) {
            $details['retry_after_ms'] = $retryAfterMs;
        }

        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/error',
                'code' => 'reaction_publish_failed',
                'message' => 'Reaction could not be sent.',
                'details' => $details,
                'time' => gmdate('c'),
            ]
        );
    }

    return videochat_realtime_secondary_handled_result();
}

function videochat_realtime_activity_error_reason(string $errorCode): string
{
    return match ($errorCode) {
        'missing_call_context' => 'The websocket connection has no active call or room context.',
        'forged_activity_user' => 'The reported user_id does not match the authenticated websocket user.',
        'invalid_command' => 'The activity command payload is invalid.',
        'activity_backend_error' => 'The backend failed while storing the activity sample.',
        default => $errorCode !== '' ? 'Activity publishing failed with an unknown backend reason.' : 'Activity publishing failed.',
    };
}

function videochat_realtime_activity_error_details(
    array $activityResult,
    array $presenceConnection,
    array $activityCommand
): array {
    $errorCode = trim((string) ($activityResult['error'] ?? 'unknown'));
    $details = [
        'error' => $errorCode,
        'reason' => videochat_realtime_activity_error_reason($errorCode),
        'call_id' => videochat_realtime_connection_call_id($presenceConnection),
        'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
        'user_id' => (int) ($presenceConnection['user_id'] ?? 0),
        'reported_user_id' => (int) ($activityCommand['user_id'] ?? 0),
        'audio_level' => (float) ($activityCommand['audio_level'] ?? 0),
        'motion_score' => (float) ($activityCommand['motion_score'] ?? 0),
        'speaking' => (bool) ($activityCommand['speaking'] ?? false),
        'gesture' => (string) ($activityCommand['gesture'] ?? ''),
        'source' => (string) ($activityCommand['source'] ?? 'client_observed'),
    ];

    $exceptionClass = trim((string) ($activityResult['exception_class'] ?? ''));
    if ($exceptionClass !== '') {
        $details['exception_class'] = $exceptionClass;
    }

    $exceptionMessage = trim((string) ($activityResult['exception_message'] ?? ''));
    if ($exceptionMessage !== '') {
        $details['exception_message'] = substr($exceptionMessage, 0, 240);
    }

    return $details;
}

function videochat_realtime_handle_activity_websocket_command(
    array $activityCommand,
    mixed $websocket,
    array &$presenceState,
    array $presenceConnection,
    callable $openDatabase
): ?array {
    if (!(bool) ($activityCommand['ok'] ?? false)) {
        return (string) ($activityCommand['error'] ?? '') === 'unsupported_type'
            ? null
            : videochat_realtime_secondary_invalid_result($activityCommand);
    }

    try {
        $activityResult = videochat_activity_apply_command($openDatabase(), $presenceState, $presenceConnection, $activityCommand);
    } catch (Throwable $error) {
        if (videochat_activity_is_transient_database_lock($error)) {
            return videochat_realtime_secondary_handled_result();
        }

        $activityResult = [
            'ok' => false,
            'error' => 'activity_backend_error',
            'exception_class' => get_debug_type($error),
            'exception_message' => $error->getMessage(),
        ];
    }

    if (!(bool) ($activityResult['ok'] ?? false)) {
        $details = videochat_realtime_activity_error_details($activityResult, $presenceConnection, $activityCommand);
        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/error',
                'code' => 'activity_publish_failed',
                'message' => 'Could not publish participant activity: ' . (string) ($details['reason'] ?? 'Activity publishing failed.'),
                'details' => $details,
                'time' => gmdate('c'),
            ]
        );
    }

    return videochat_realtime_secondary_handled_result();
}

function videochat_realtime_handle_layout_websocket_command(
    array $layoutCommand,
    mixed $websocket,
    array &$presenceState,
    array $presenceConnection,
    callable $openDatabase
): ?array {
    if (!(bool) ($layoutCommand['ok'] ?? false)) {
        return (string) ($layoutCommand['error'] ?? '') === 'unsupported_type'
            ? null
            : videochat_realtime_secondary_invalid_result($layoutCommand);
    }

    try {
        $layoutResult = videochat_layout_apply_command($openDatabase(), $presenceState, $presenceConnection, $layoutCommand);
    } catch (Throwable) {
        $layoutResult = [
            'ok' => false,
            'error' => 'layout_backend_error',
        ];
    }

    if (!(bool) ($layoutResult['ok'] ?? false)) {
        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/error',
                'code' => 'layout_command_failed',
                'message' => 'Could not apply layout command.',
                'details' => [
                    'error' => (string) ($layoutResult['error'] ?? 'unknown'),
                    'type' => (string) ($layoutCommand['type'] ?? ''),
                    'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                ],
                'time' => gmdate('c'),
            ]
        );
    }

    return videochat_realtime_secondary_handled_result();
}
