<?php

declare(strict_types=1);

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

function videochat_realtime_handle_chat_websocket_command(
    array $chatCommand,
    mixed $websocket,
    array &$presenceState,
    array $presenceConnection,
    ?PDO $chatBrokerDatabase,
    callable $openDatabase
): ?array {
    if (!(bool) ($chatCommand['ok'] ?? false)) {
        if ((string) ($chatCommand['error'] ?? '') === 'unsupported_type') {
            return null;
        }

        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/error',
                'code' => (string) ($chatCommand['error'] ?? 'chat_invalid_payload'),
                'message' => 'Chat message payload is invalid.',
                'details' => [
                    'type' => 'chat/send',
                    'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                ],
                'time' => gmdate('c'),
            ]
        );
        return videochat_realtime_secondary_handled_result();
    }

    $chatBroker = $chatBrokerDatabase instanceof PDO
        ? static function (string $roomId, array $event) use ($chatBrokerDatabase): bool {
            return videochat_chat_broker_insert_event($chatBrokerDatabase, $roomId, $event);
        }
        : null;
    $chatPublish = videochat_chat_publish(
        $presenceState,
        $presenceConnection,
        $chatCommand,
        null,
        null,
        $chatBroker,
        static function (array $attachmentIds, string $roomId, int $senderUserId, string $messageId, array $resolverConnection) use ($openDatabase): array {
            $callId = videochat_realtime_connection_call_id($resolverConnection);
            if ($callId === '') {
                return [
                    'ok' => false,
                    'error' => 'attachment_call_missing',
                    'attachments' => [],
                ];
            }

            return videochat_chat_attachment_resolve_for_message(
                $openDatabase(),
                $attachmentIds,
                $callId,
                $roomId,
                $senderUserId,
                $messageId
            );
        }
    );
    if (!(bool) ($chatPublish['ok'] ?? false)) {
        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/error',
                'code' => 'chat_publish_failed',
                'message' => 'Could not publish chat message.',
                'details' => [
                    'error' => (string) ($chatPublish['error'] ?? 'unknown'),
                    'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                ],
                'time' => gmdate('c'),
            ]
        );
        return videochat_realtime_secondary_handled_result();
    }

    $message = is_array($chatPublish['event']['message'] ?? null) ? $chatPublish['event']['message'] : [];
    $chatRoomId = (string) ($chatPublish['event']['room_id'] ?? ($presenceConnection['room_id'] ?? 'lobby'));
    $chatArchiveCallId = videochat_realtime_connection_call_id($presenceConnection);
    if ($chatArchiveCallId !== '') {
        try {
            $archiveResult = videochat_chat_archive_append_message(
                $openDatabase(),
                $chatArchiveCallId,
                $chatRoomId,
                is_array($chatPublish['event'] ?? null) ? $chatPublish['event'] : []
            );
        } catch (Throwable) {
            $archiveResult = [
                'ok' => false,
                'reason' => 'archive_exception',
            ];
        }

        if (!(bool) ($archiveResult['ok'] ?? false)) {
            videochat_presence_send_frame(
                $websocket,
                [
                    'type' => 'system/error',
                    'code' => 'chat_archive_failed',
                    'message' => 'Chat message was sent but could not be archived.',
                    'details' => [
                        'error' => (string) ($archiveResult['reason'] ?? 'unknown'),
                        'room_id' => $chatRoomId,
                    ],
                    'time' => gmdate('c'),
                ]
            );
        }
    }

    videochat_presence_send_frame(
        $websocket,
        videochat_chat_ack_payload($chatRoomId, $message, (int) ($chatPublish['sent_count'] ?? 0))
    );
    return videochat_realtime_secondary_handled_result();
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

function videochat_realtime_handle_lobby_websocket_command(
    array $lobbyCommand,
    mixed $websocket,
    array &$lobbyState,
    array &$presenceState,
    array $presenceConnection,
    callable $openDatabase
): ?array {
    if (!(bool) ($lobbyCommand['ok'] ?? false)) {
        return (string) ($lobbyCommand['error'] ?? '') === 'unsupported_type'
            ? null
            : videochat_realtime_secondary_invalid_result($lobbyCommand);
    }

    $lobbyCommandRoomId = videochat_presence_normalize_room_id((string) ($lobbyCommand['room_id'] ?? ''), '');
    if ($lobbyCommandRoomId === '') {
        $lobbyCommandRoomId = videochat_realtime_lobby_room_id_for_connection($presenceConnection);
    }
    videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        $lobbyCommandRoomId,
        videochat_realtime_connection_call_id($presenceConnection)
    );

    $lobbyResult = videochat_lobby_apply_command($lobbyState, $presenceState, $presenceConnection, $lobbyCommand);
    if (!(bool) ($lobbyResult['ok'] ?? false)) {
        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/error',
                'code' => 'lobby_command_failed',
                'message' => 'Could not apply lobby command.',
                'details' => [
                    'error' => (string) ($lobbyResult['error'] ?? 'unknown'),
                    'type' => (string) ($lobbyCommand['type'] ?? ''),
                    'target_user_id' => (int) ($lobbyCommand['target_user_id'] ?? 0),
                    'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                ],
                'time' => gmdate('c'),
            ]
        );
        return videochat_realtime_secondary_handled_result();
    }

    videochat_realtime_apply_successful_lobby_command(
        $lobbyResult,
        $lobbyState,
        $presenceState,
        $presenceConnection,
        $openDatabase
    );
    return videochat_realtime_secondary_handled_result();
}

function videochat_realtime_apply_successful_lobby_command(
    array $lobbyResult,
    array &$lobbyState,
    array &$presenceState,
    array $presenceConnection,
    callable $openDatabase
): void {
    $lobbyAction = (string) ($lobbyResult['action'] ?? '');
    $lobbyStateName = (string) ($lobbyResult['state'] ?? '');
    $lobbyResultRoomId = videochat_presence_normalize_room_id(
        (string) ($lobbyResult['room_id'] ?? ($presenceConnection['room_id'] ?? 'lobby'))
    );

    if ($lobbyAction === 'lobby/queue/join' && in_array($lobbyStateName, ['queued', 'already_queued'], true)) {
        videochat_realtime_mark_call_participant_pending_for_queue($openDatabase, $presenceConnection);
        videochat_realtime_sync_lobby_room_from_database(
            $lobbyState,
            $openDatabase,
            $lobbyResultRoomId,
            videochat_realtime_connection_call_id($presenceConnection)
        );
        videochat_lobby_broadcast_room_snapshot(
            $lobbyState,
            $presenceState,
            $lobbyResultRoomId,
            $lobbyStateName === 'already_queued' ? 'already_queued' : 'queued'
        );
    } elseif ($lobbyAction === 'lobby/queue/cancel') {
        videochat_realtime_mark_call_participant_invite_state($openDatabase, $presenceConnection, 'invited', ['pending']);
        videochat_realtime_sync_lobby_room_from_database(
            $lobbyState,
            $openDatabase,
            $lobbyResultRoomId,
            videochat_realtime_connection_call_id($presenceConnection)
        );
    }

    if ($lobbyAction === 'lobby/remove') {
        videochat_realtime_apply_lobby_remove_result($lobbyResult, $lobbyState, $presenceConnection, $openDatabase, $lobbyResultRoomId);
    }

    if (in_array($lobbyAction, ['lobby/allow', 'lobby/allow_all'], true)) {
        videochat_realtime_apply_lobby_admission_result($lobbyResult, $lobbyState, $presenceState, $presenceConnection, $openDatabase);
    }
}

function videochat_realtime_apply_lobby_remove_result(
    array $lobbyResult,
    array &$lobbyState,
    array $presenceConnection,
    callable $openDatabase,
    string $lobbyResultRoomId
): void {
    $removedCallId = videochat_realtime_connection_call_id($presenceConnection);
    $removedUserIds = is_array($lobbyResult['affected_user_ids'] ?? null)
        ? array_values(array_filter(array_map('intval', (array) $lobbyResult['affected_user_ids']), static fn (int $id): bool => $id > 0))
        : [];
    if ($removedCallId === '' || $removedUserIds === []) {
        return;
    }

    foreach ($removedUserIds as $removedUserId) {
        videochat_realtime_mark_call_participant_invite_state_by_user_id(
            $openDatabase,
            $removedCallId,
            $removedUserId,
            'invited',
            ['pending', 'allowed', 'accepted']
        );
    }
    videochat_realtime_sync_lobby_room_from_database($lobbyState, $openDatabase, $lobbyResultRoomId, $removedCallId);
}

function videochat_realtime_apply_lobby_admission_result(
    array $lobbyResult,
    array &$lobbyState,
    array &$presenceState,
    array $presenceConnection,
    callable $openDatabase
): void {
    $admittedRoomId = videochat_presence_normalize_room_id(
        (string) ($lobbyResult['room_id'] ?? ($presenceConnection['room_id'] ?? 'lobby'))
    );
    $admittedUserIds = is_array($lobbyResult['affected_user_ids'] ?? null)
        ? array_values(array_filter(array_map('intval', (array) $lobbyResult['affected_user_ids']), static fn (int $id): bool => $id > 0))
        : [];
    if ($admittedRoomId === '' || $admittedUserIds === []) {
        return;
    }

    $admittedCallId = videochat_realtime_connection_call_id($presenceConnection);
    if ($admittedCallId !== '') {
        foreach ($admittedUserIds as $admittedUserId) {
            videochat_realtime_mark_call_participant_invite_state_by_user_id(
                $openDatabase,
                $admittedCallId,
                $admittedUserId,
                'allowed',
                ['pending']
            );
        }
    }
    videochat_realtime_sync_lobby_room_from_database($lobbyState, $openDatabase, $admittedRoomId, $admittedCallId);
    videochat_realtime_send_lobby_snapshot_to_users($presenceState, $lobbyState, $admittedRoomId, $admittedUserIds, 'admitted', null);
}

function videochat_realtime_handle_admin_sync_websocket_command(
    array $adminSyncCommand,
    mixed $websocket,
    array &$presenceState,
    array $presenceConnection
): ?array {
    if (!(bool) ($adminSyncCommand['ok'] ?? false)) {
        return (string) ($adminSyncCommand['error'] ?? '') === 'unsupported_type'
            ? null
            : videochat_realtime_secondary_invalid_result($adminSyncCommand);
    }

    $adminSyncResult = videochat_admin_sync_publish($presenceState, $presenceConnection, $adminSyncCommand);
    if (!(bool) ($adminSyncResult['ok'] ?? false)) {
        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/error',
                'code' => 'admin_sync_publish_failed',
                'message' => 'Could not publish admin sync event.',
                'details' => [
                    'error' => (string) ($adminSyncResult['error'] ?? 'unknown'),
                    'topic' => (string) ($adminSyncCommand['topic'] ?? 'all'),
                    'reason' => (string) ($adminSyncCommand['reason'] ?? 'updated'),
                ],
                'time' => gmdate('c'),
            ]
        );
    }

    return videochat_realtime_secondary_handled_result();
}
