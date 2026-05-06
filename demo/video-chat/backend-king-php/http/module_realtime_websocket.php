<?php

declare(strict_types=1);

function videochat_realtime_reset_waiting_connection_invite(
    callable $openDatabase,
    array &$lobbyState,
    array $presenceState,
    array $connection,
    string $reason,
    bool $broadcastSnapshot
): bool {
    $currentRoomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $pendingRoomId = videochat_presence_normalize_room_id((string) ($connection['pending_room_id'] ?? ''), '');
    if ($currentRoomId !== videochat_realtime_waiting_room_id() || $pendingRoomId === '') {
        return false;
    }

    $updated = videochat_realtime_mark_call_participant_invite_state(
        $openDatabase,
        $connection,
        'invited',
        ['pending']
    );
    if (!$updated) {
        return false;
    }

    videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        $pendingRoomId,
        videochat_realtime_connection_call_id($connection)
    );
    if ($broadcastSnapshot) {
        videochat_lobby_broadcast_room_snapshot(
            $lobbyState,
            $presenceState,
            $pendingRoomId,
            trim($reason) === '' ? 'presence_left' : trim($reason)
        );
    }

    return true;
}

function videochat_handle_realtime_websocket_route(
    string $path,
    array $request,
    string $wsPath,
    array &$activeWebsocketsBySession,
    array &$presenceState,
    array &$lobbyState,
    array &$typingState,
    array &$reactionState,
    callable $authenticateRequest,
    callable $authFailureResponse,
    callable $rbacFailureResponse,
    callable $errorResponse,
    callable $openDatabase
): ?array {
    if ($path === $wsPath) {
        $handshakeValidation = videochat_realtime_validate_websocket_handshake($request, $wsPath);
        if (!(bool) ($handshakeValidation['ok'] ?? false)) {
            return $errorResponse(
                (int) ($handshakeValidation['status'] ?? 400),
                (string) ($handshakeValidation['code'] ?? 'websocket_handshake_invalid'),
                (string) ($handshakeValidation['message'] ?? 'WebSocket handshake is invalid.'),
                is_array($handshakeValidation['details'] ?? null) ? $handshakeValidation['details'] : []
            );
        }

        $websocketAuth = $authenticateRequest($request, 'websocket');
        if (!(bool) ($websocketAuth['ok'] ?? false)) {
            return $authFailureResponse('websocket', (string) ($websocketAuth['reason'] ?? 'invalid_session'));
        }
        $websocketRbacDecision = videochat_authorize_role_for_path((array) ($websocketAuth['user'] ?? []), $path, $wsPath);
        if (!(bool) ($websocketRbacDecision['ok'] ?? false)) {
            return $rbacFailureResponse('websocket', $websocketRbacDecision, $path);
        }

        $authSessionId = is_string($websocketAuth['token'] ?? null)
            ? trim((string) $websocketAuth['token'])
            : '';
        if ($authSessionId === '') {
            $authSessionId = is_string($websocketAuth['session']['id'] ?? null)
                ? trim((string) $websocketAuth['session']['id'])
                : '';
        }
        $signalingBrokerAttachedAtMs = videochat_signaling_broker_now_ms();

        $session = $request['session'] ?? null;
        $streamId = (int) ($request['stream_id'] ?? 0);
        $websocket = king_server_upgrade_to_websocket($session, $streamId);
        if ($websocket === false) {
            return $errorResponse(400, 'websocket_upgrade_failed', 'Could not upgrade request to websocket.');
        }

        $requestedRoomId = '';
        $requestedCallId = '';
        $queryParams = videochat_request_query_params($request);
        $clientAssetVersion = videochat_realtime_client_asset_version_from_query($queryParams);
        $disconnectStaleAssetClient = static function () use ($websocket, $clientAssetVersion): bool {
            return videochat_realtime_disconnect_stale_asset_client(
                $websocket,
                $clientAssetVersion,
                static function (array $frame) use ($websocket): void {
                    videochat_presence_send_frame($websocket, $frame);
                },
                'ws'
            );
        };
        if ($disconnectStaleAssetClient()) {
            return [
                'status' => 101,
                'headers' => [],
                'body' => '',
            ];
        }
        if (is_string($queryParams['room'] ?? null)) {
            $requestedRoomId = (string) $queryParams['room'];
        }
        if (is_string($queryParams['call_id'] ?? null)) {
            $requestedCallId = (string) $queryParams['call_id'];
        }
        $requestedCallId = videochat_realtime_normalize_call_id($requestedCallId, '');

        $roomResolution = videochat_realtime_resolve_connection_rooms(
            $websocketAuth,
            $requestedRoomId,
            $openDatabase,
            $requestedCallId
        );
        $initialRoomId = videochat_presence_normalize_room_id((string) ($roomResolution['initial_room_id'] ?? 'lobby'));
        $resolvedRequestedRoomId = videochat_presence_normalize_room_id(
            (string) ($roomResolution['requested_room_id'] ?? $initialRoomId)
        );
        $pendingRoomId = videochat_presence_normalize_room_id(
            (string) ($roomResolution['pending_room_id'] ?? ''),
            ''
        );

        $connectionId = videochat_register_active_websocket(
            $activeWebsocketsBySession,
            $authSessionId,
            $websocket
        );
        $presenceConnection = videochat_presence_connection_descriptor(
            (array) ($websocketAuth['user'] ?? []),
            $authSessionId,
            $connectionId,
            $websocket,
            $initialRoomId
        );
        $presenceConnection['requested_room_id'] = $resolvedRequestedRoomId;
        $presenceConnection['pending_room_id'] = $pendingRoomId;
        $presenceConnection['requested_call_id'] = $requestedCallId;
        $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
        $presenceJoin = videochat_presence_join_room(
            $presenceState,
            $presenceConnection,
            (string) ($presenceConnection['room_id'] ?? 'lobby')
        );
        $presenceConnection = (array) ($presenceJoin['connection'] ?? $presenceConnection);
        $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
        $presenceState['connections'][$connectionId] = $presenceConnection;
        videochat_realtime_mark_call_participant_joined($openDatabase, $presenceConnection);
        $presenceDetached = false;
        $detachWebsocket = static function () use (
            &$presenceDetached,
            &$activeWebsocketsBySession,
            &$presenceState,
            &$lobbyState,
            &$typingState,
            &$reactionState,
            &$presenceConnection,
            $authSessionId,
            $connectionId,
            $openDatabase
        ): void {
            if ($presenceDetached) {
                return;
            }
            $presenceDetached = true;
            $disconnectedConnection = (array) $presenceConnection;
            $disconnectedRoomId = videochat_presence_normalize_room_id((string) ($disconnectedConnection['room_id'] ?? ''), '');

            $lobbyClear = videochat_lobby_clear_for_connection(
                $lobbyState,
                $presenceState,
                (array) $presenceConnection,
                'disconnect'
            );
            videochat_realtime_reset_waiting_connection_invite(
                $openDatabase,
                $lobbyState,
                $presenceState,
                $disconnectedConnection,
                'disconnect',
                !(bool) ($lobbyClear['cleared'] ?? false)
            );
            videochat_typing_clear_for_connection(
                $typingState,
                $presenceState,
                (array) $presenceConnection,
                'disconnect'
            );
            videochat_reaction_clear_for_connection(
                $reactionState,
                (array) $presenceConnection
            );
            videochat_unregister_active_websocket($activeWebsocketsBySession, $authSessionId, $connectionId);
            videochat_presence_remove_connection($presenceState, $connectionId);
            videochat_realtime_remove_call_presence($openDatabase, $disconnectedConnection);
            videochat_realtime_mark_call_participant_left($openDatabase, $disconnectedConnection, $presenceState);
            videochat_realtime_broadcast_room_snapshot(
                $presenceState,
                $disconnectedRoomId,
                $openDatabase,
                'participant_disconnected',
                $connectionId
            );
        };

        if ($session !== null && $streamId > 0 && $authSessionId !== '' && $connectionId !== '') {
            king_server_on_cancel(
                $session,
                $streamId,
                static function () use ($detachWebsocket): void {
                    $detachWebsocket();
                }
            );
        }

        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/welcome',
                'message' => 'video-chat King websocket presence gateway connected',
                'connection_id' => $connectionId,
                'active_room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                    'call_context' => [
                        'requested_call_id' => (string) ($presenceConnection['requested_call_id'] ?? ''),
                        'call_id' => (string) ($presenceConnection['active_call_id'] ?? ''),
                        'call_role' => (string) ($presenceConnection['call_role'] ?? 'participant'),
                        'invite_state' => (string) ($presenceConnection['invite_state'] ?? 'invited'),
                        'can_moderate' => (bool) ($presenceConnection['can_moderate_call'] ?? false),
                    ],
                'admission' => [
                    'requested_call_id' => (string) ($presenceConnection['requested_call_id'] ?? ''),
                    'requested_room_id' => (string) ($presenceConnection['requested_room_id'] ?? ''),
                    'pending_room_id' => (string) ($presenceConnection['pending_room_id'] ?? ''),
                    'waiting_room_id' => videochat_realtime_waiting_room_id(),
                    'requires_admission' => trim((string) ($presenceConnection['pending_room_id'] ?? '')) !== '',
                ],
                'channels' => [
                    'presence' => [
                        'snapshot' => 'room/snapshot',
                        'joined' => 'room/joined',
                        'left' => 'room/left',
                    ],
                    'chat' => [
                        'send' => 'chat/send',
                        'message' => 'chat/message',
                        'ack' => 'chat/ack',
                    ],
                    'typing' => [
                        'start' => 'typing/start',
                        'stop' => 'typing/stop',
                    ],
                    'reaction' => [
                        'send' => 'reaction/send',
                        'send_batch' => 'reaction/send_batch',
                        'event' => 'reaction/event',
                        'batch' => 'reaction/batch',
                    ],
                    'activity' => [
                        'publish' => 'participant/activity',
                        'event' => 'participant/activity',
                    ],
                    'layout' => [
                        'mode' => 'layout/mode',
                        'strategy' => 'layout/strategy',
                        'selection' => 'layout/selection',
                    ],
                    'lobby' => [
                        'snapshot' => 'lobby/snapshot',
                        'request' => 'lobby/queue/request',
                        'join' => 'lobby/queue/join',
                        'cancel' => 'lobby/queue/cancel',
                        'allow' => 'lobby/allow',
                        'remove' => 'lobby/remove',
                        'allow_all' => 'lobby/allow_all',
                    ],
                    'signaling' => [
                        'offer' => 'call/offer',
                        'answer' => 'call/answer',
                        'ice' => 'call/ice',
                        'hangup' => 'call/hangup',
                        'control_state' => 'call/control-state',
                        'media_quality_pressure' => 'call/media-quality-pressure',
                        'moderation_state' => 'call/moderation-state',
                        'media_security_hello' => 'media-security/hello',
                        'media_security_sender_key' => 'media-security/sender-key',
                        'ack' => 'call/ack',
                    ],
                    'admin_sync' => [
                        'publish' => 'admin/sync/publish',
                        'event' => 'admin/sync',
                    ],
                ],
                'runtime' => videochat_realtime_runtime_descriptor(),
                'auth' => [
                    'session' => $websocketAuth['session'] ?? null,
                    'user' => $websocketAuth['user'] ?? null,
                ],
                'time' => gmdate('c'),
            ]
        );

        $initialLobbySnapshot = videochat_realtime_send_synced_lobby_snapshot_to_connection(
            $lobbyState,
            $presenceConnection,
            $openDatabase,
            'joined_room'
        );
        $lastLobbySnapshotSignature = (string) ($initialLobbySnapshot['signature'] ?? '');
        $initialRoomSnapshot = videochat_realtime_send_room_snapshot(
            $presenceState,
            $presenceConnection,
            $openDatabase,
            'db_sync'
        );
        $lastRoomSnapshotSignature = (string) ($initialRoomSnapshot['signature'] ?? '');
        $nextLobbySnapshotPollMs = videochat_lobby_now_ms() + 1000;
        $nextRoomSnapshotPollMs = videochat_lobby_now_ms() + 1000;
        $reactionBrokerDatabase = null;
        $chatBrokerDatabase = null;
        $lastReactionBrokerRoomId = videochat_presence_normalize_room_id((string) ($presenceConnection['room_id'] ?? 'lobby'));
        $lastReactionBrokerEventId = 0;
        $nextReactionBrokerPollMs = videochat_reaction_broker_now_ms() + 100;
        $nextReactionBrokerCleanupMs = videochat_reaction_broker_now_ms() + 5000;
        $lastChatBrokerRoomId = videochat_presence_normalize_room_id((string) ($presenceConnection['room_id'] ?? 'lobby'));
        $lastChatBrokerEventId = 0;
        $nextChatBrokerPollMs = videochat_chat_broker_now_ms() + 100;
        $nextChatBrokerCleanupMs = videochat_chat_broker_now_ms() + 5000;
        $signalingBrokerDatabase = null;
        $lastSignalingBrokerRoomId = videochat_signaling_room_key_for_connection($presenceConnection);
        $lastSignalingBrokerUserId = (int) ($presenceConnection['user_id'] ?? 0);
        $lastSignalingBrokerEventId = 0;
        $nextSignalingBrokerPollMs = videochat_signaling_broker_now_ms() + 100;
        $nextSignalingBrokerCleanupMs = videochat_signaling_broker_now_ms() + 5000;
        try {
            $reactionBrokerDatabase = $openDatabase();
            videochat_reaction_broker_bootstrap($reactionBrokerDatabase);
            videochat_chat_broker_bootstrap($reactionBrokerDatabase);
            videochat_signaling_broker_bootstrap($reactionBrokerDatabase);
            $chatBrokerDatabase = $reactionBrokerDatabase;
            $signalingBrokerDatabase = $reactionBrokerDatabase;
            $lastReactionBrokerEventId = videochat_reaction_broker_latest_event_id(
                $reactionBrokerDatabase,
                $lastReactionBrokerRoomId
            );
            $lastChatBrokerEventId = videochat_chat_broker_latest_event_id(
                $chatBrokerDatabase,
                $lastChatBrokerRoomId
            );
            $lastSignalingBrokerEventId = videochat_signaling_broker_latest_event_id_before(
                $signalingBrokerDatabase,
                $lastSignalingBrokerRoomId,
                $lastSignalingBrokerUserId,
                $signalingBrokerAttachedAtMs
            );
        } catch (Throwable) {
            $reactionBrokerDatabase = null;
            $chatBrokerDatabase = null;
            $signalingBrokerDatabase = null;
        }

        try {
            while (true) {
                if ($disconnectStaleAssetClient()) {
                    break;
                }

                $sessionLiveness = videochat_realtime_validate_session_liveness(
                    $authenticateRequest,
                    $authSessionId,
                    $wsPath
                );
                if (!(bool) ($sessionLiveness['ok'] ?? false)) {
                    $sessionLivenessReason = (string) ($sessionLiveness['reason'] ?? 'invalid_session');
                    $sessionCloseDescriptor = videochat_realtime_close_descriptor_for_reason(
                        $sessionLivenessReason
                    );
                    $transientAuthBackendError = strtolower(trim($sessionLivenessReason)) === 'auth_backend_error';
                    videochat_presence_send_frame(
                        $websocket,
                        [
                            'type' => 'system/error',
                            'code' => $transientAuthBackendError
                                ? 'websocket_auth_temporarily_unavailable'
                                : 'websocket_session_invalidated',
                            'message' => $transientAuthBackendError
                                ? 'Session validation is temporarily unavailable for realtime commands.'
                                : 'Session is no longer valid for realtime commands.',
                            'details' => [
                                'reason' => $sessionLivenessReason,
                                'close' => $sessionCloseDescriptor,
                            ],
                            'time' => gmdate('c'),
                        ]
                    );

                    try {
                        king_client_websocket_close(
                            $websocket,
                            (int) ($sessionCloseDescriptor['close_code'] ?? 1008),
                            (string) ($sessionCloseDescriptor['close_reason'] ?? 'session_invalidated')
                        );
                    } catch (Throwable) {
                        // Best-effort close; detach/cleanup runs in finally.
                    }
                    break;
                }

                $pollNowMs = videochat_lobby_now_ms();
                if ($pollNowMs >= $nextLobbySnapshotPollMs) {
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceState['connections'][$connectionId] = $presenceConnection;
                    videochat_realtime_send_synced_lobby_snapshot_to_connection_if_changed(
                        $lobbyState,
                        $presenceConnection,
                        $openDatabase,
                        $lastLobbySnapshotSignature,
                        'db_sync',
                        null,
                        $pollNowMs
                    );
                    $nextLobbySnapshotPollMs = $pollNowMs + 1000;
                }
                if ($pollNowMs >= $nextRoomSnapshotPollMs) {
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceState['connections'][$connectionId] = $presenceConnection;
                    videochat_realtime_touch_call_presence($openDatabase, $presenceConnection);
                    videochat_realtime_send_room_snapshot_if_changed(
                        $presenceState,
                        $presenceConnection,
                        $openDatabase,
                        $lastRoomSnapshotSignature,
                        'db_sync'
                    );
                    $nextRoomSnapshotPollMs = $pollNowMs + 1000;
                }
                videochat_realtime_poll_websocket_brokers(
                    $pollNowMs,
                    $websocket,
                    $presenceConnection,
                    $reactionBrokerDatabase,
                    $chatBrokerDatabase,
                    $signalingBrokerDatabase,
                    $lastReactionBrokerRoomId,
                    $lastReactionBrokerEventId,
                    $nextReactionBrokerPollMs,
                    $nextReactionBrokerCleanupMs,
                    $lastChatBrokerRoomId,
                    $lastChatBrokerEventId,
                    $nextChatBrokerPollMs,
                    $nextChatBrokerCleanupMs,
                    $lastSignalingBrokerRoomId,
                    $lastSignalingBrokerUserId,
                    $lastSignalingBrokerEventId,
                    $nextSignalingBrokerPollMs,
                    $nextSignalingBrokerCleanupMs,
                    $signalingBrokerAttachedAtMs
                );

                videochat_typing_sweep_expired($typingState, $presenceState);
                $frame = king_client_websocket_receive($websocket, 250);
                if ($frame === false) {
                    $status = function_exists('king_client_websocket_get_status')
                        ? (int) king_client_websocket_get_status($websocket)
                        : 3;
                    if ($status === 3) {
                        break;
                    }

                    continue;
                }

                if (!is_string($frame) || trim($frame) === '') {
                    continue;
                }

                $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                $presenceState['connections'][$connectionId] = $presenceConnection;

                $presenceCommand = videochat_presence_decode_client_frame($frame);
                $commandType = (string) ($presenceCommand['type'] ?? '');
                $commandError = (string) ($presenceCommand['error'] ?? 'invalid_command');

                if (!(bool) ($presenceCommand['ok'] ?? false) && $commandError === 'unsupported_type') {
                    $secondaryCommand = videochat_realtime_handle_secondary_websocket_command(
                        $frame,
                        $websocket,
                        $presenceState,
                        $lobbyState,
                        $typingState,
                        $reactionState,
                        $presenceConnection,
                        $chatBrokerDatabase,
                        $signalingBrokerDatabase,
                        $reactionBrokerDatabase,
                        $openDatabase
                    );
                    if ((bool) ($secondaryCommand['handled'] ?? false)) {
                        continue;
                    }

                    $commandType = (string) ($secondaryCommand['command_type'] ?? $commandType);
                    $commandError = (string) ($secondaryCommand['command_error'] ?? $commandError);
                }

                if (!(bool) ($presenceCommand['ok'] ?? false)) {
                    videochat_presence_send_frame(
                        $websocket,
                        [
                            'type' => 'system/error',
                            'code' => 'invalid_websocket_command',
                            'message' => 'WebSocket command is invalid.',
                            'details' => [
                                'error' => $commandError,
                                'type' => $commandType,
                            ],
                            'time' => gmdate('c'),
                        ]
                    );
                    continue;
                }

                $commandType = (string) ($presenceCommand['type'] ?? '');
                if ($commandType === 'ping') {
                    videochat_presence_send_frame(
                        $websocket,
                        [
                            'type' => 'system/pong',
                            'runtime' => videochat_realtime_runtime_descriptor(),
                            'time' => gmdate('c'),
                        ]
                    );
                    continue;
                }

                if ($commandType === 'room/snapshot/request') {
                    $requestedRoomSnapshot = videochat_realtime_send_room_snapshot(
                        $presenceState,
                        $presenceConnection,
                        $openDatabase,
                        'requested'
                    );
                    $lastRoomSnapshotSignature = (string) ($requestedRoomSnapshot['signature'] ?? $lastRoomSnapshotSignature);
                    $requestedLobbySnapshot = videochat_realtime_send_synced_lobby_snapshot_to_connection(
                        $lobbyState,
                        $presenceConnection,
                        $openDatabase,
                        'requested'
                    );
                    $lastLobbySnapshotSignature = (string) ($requestedLobbySnapshot['signature'] ?? $lastLobbySnapshotSignature);
                    continue;
                }

                if ($commandType === 'room/leave') {
                    $leavingConnection = (array) $presenceConnection;
                    $leavingRoomId = videochat_presence_normalize_room_id((string) ($leavingConnection['room_id'] ?? ''), '');
                    $lobbyClear = videochat_lobby_clear_for_connection(
                        $lobbyState,
                        $presenceState,
                        $presenceConnection,
                        'room_leave'
                    );
                    videochat_realtime_reset_waiting_connection_invite(
                        $openDatabase,
                        $lobbyState,
                        $presenceState,
                        $leavingConnection,
                        'room_leave',
                        !(bool) ($lobbyClear['cleared'] ?? false)
                    );
                    videochat_typing_clear_for_connection(
                        $typingState,
                        $presenceState,
                        $presenceConnection,
                        'room_leave'
                    );
                    videochat_reaction_clear_for_connection(
                        $reactionState,
                        $presenceConnection
                    );
                    $presenceConnection['room_id'] = videochat_realtime_waiting_room_id();
                    $presenceConnection['requested_room_id'] = videochat_realtime_waiting_room_id();
                    $presenceConnection['pending_room_id'] = '';
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceJoin = videochat_presence_join_room(
                        $presenceState,
                        $presenceConnection,
                        videochat_realtime_waiting_room_id()
                    );
                    $presenceConnection = (array) ($presenceJoin['connection'] ?? $presenceConnection);
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceState['connections'][$connectionId] = $presenceConnection;
                    videochat_realtime_remove_call_presence($openDatabase, $leavingConnection);
                    videochat_realtime_mark_call_participant_left($openDatabase, $leavingConnection, $presenceState);
                    videochat_realtime_broadcast_room_snapshot(
                        $presenceState,
                        $leavingRoomId,
                        $openDatabase,
                        'participant_left',
                        $connectionId
                    );
                    continue;
                }

                if ($commandType === 'room/join') {
                    $targetRoomId = videochat_presence_normalize_room_id((string) ($presenceCommand['room_id'] ?? ''));
                    try {
                        $pdo = $openDatabase();
                        $targetRoom = videochat_fetch_active_room_context($pdo, $targetRoomId);
                    } catch (Throwable) {
                        $targetRoom = null;
                    }

                    if (!is_array($targetRoom)) {
                        videochat_presence_send_frame(
                            $websocket,
                            [
                                'type' => 'system/error',
                                'code' => 'room_not_found',
                                'message' => 'Requested room is not active.',
                                'details' => [
                                    'room_id' => $targetRoomId,
                                ],
                                'time' => gmdate('c'),
                            ]
                        );
                        continue;
                    }

                    $pendingRoomId = videochat_presence_normalize_room_id(
                        (string) ($presenceConnection['pending_room_id'] ?? ''),
                        ''
                    );
                    $pendingGateActive = $pendingRoomId !== '';
                    $canBypassAdmissionForTargetRoom = videochat_realtime_connection_can_bypass_admission_for_room(
                        $presenceConnection,
                        $targetRoomId,
                        $openDatabase
                    );
                    if ($pendingGateActive && $canBypassAdmissionForTargetRoom) {
                        $presenceConnection['pending_room_id'] = '';
                        $pendingRoomId = '';
                        $pendingGateActive = false;
                    }
                    if ($pendingGateActive && $targetRoomId === $pendingRoomId) {
                        videochat_realtime_sync_lobby_room_from_database(
                            $lobbyState,
                            $openDatabase,
                            $targetRoomId,
                            videochat_realtime_connection_call_id($presenceConnection)
                        );
                        $isAdmitted = videochat_lobby_is_user_admitted_for_room(
                            $lobbyState,
                            $targetRoomId,
                            (int) ($presenceConnection['user_id'] ?? 0)
                        );
                        if (!$isAdmitted) {
                            videochat_presence_send_frame(
                                $websocket,
                                [
                                    'type' => 'system/error',
                                    'code' => 'room_join_requires_admission',
                                    'message' => 'Call admission is still pending approval.',
                                    'details' => [
                                        'room_id' => $targetRoomId,
                                        'pending_room_id' => $pendingRoomId,
                                    ],
                                    'time' => gmdate('c'),
                                ]
                            );
                            continue;
                        }
                    } elseif (
                        $pendingGateActive
                        && $targetRoomId !== $pendingRoomId
                        && $targetRoomId !== videochat_realtime_waiting_room_id()
                    ) {
                        videochat_presence_send_frame(
                            $websocket,
                            [
                                'type' => 'system/error',
                                'code' => 'room_join_not_allowed',
                                'message' => 'You cannot join this room while admission is pending.',
                                'details' => [
                                    'room_id' => $targetRoomId,
                                    'pending_room_id' => $pendingRoomId,
                                ],
                                'time' => gmdate('c'),
                            ]
                        );
                        continue;
                    }

                    $currentRoomId = videochat_presence_normalize_room_id((string) ($presenceConnection['room_id'] ?? 'lobby'));
                    $previousConnection = (array) $presenceConnection;
                    if ($currentRoomId !== $targetRoomId) {
                        $lobbyClear = videochat_lobby_clear_for_connection(
                            $lobbyState,
                            $presenceState,
                            $presenceConnection,
                            'room_change'
                        );
                        if (
                            $currentRoomId === videochat_realtime_waiting_room_id()
                            && $targetRoomId !== $pendingRoomId
                        ) {
                            videochat_realtime_reset_waiting_connection_invite(
                                $openDatabase,
                                $lobbyState,
                                $presenceState,
                                $previousConnection,
                                'room_change',
                                !(bool) ($lobbyClear['cleared'] ?? false)
                            );
                        }
                        videochat_typing_clear_for_connection(
                            $typingState,
                            $presenceState,
                            $presenceConnection,
                            'room_change'
                        );
                        videochat_reaction_clear_for_connection(
                            $reactionState,
                            $presenceConnection
                        );
                    }
                    $presenceConnection['room_id'] = $targetRoomId;
                    $presenceConnection['requested_room_id'] = $targetRoomId;
                    if ($pendingGateActive && $targetRoomId === $pendingRoomId) {
                        $presenceConnection['pending_room_id'] = '';
                    }
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceJoin = videochat_presence_join_room($presenceState, $presenceConnection, $targetRoomId);
                    $presenceConnection = (array) ($presenceJoin['connection'] ?? $presenceConnection);
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceState['connections'][$connectionId] = $presenceConnection;
                    videochat_realtime_mark_call_participant_joined($openDatabase, $presenceConnection);
                    if ($currentRoomId !== $targetRoomId) {
                        videochat_realtime_mark_call_participant_left($openDatabase, $previousConnection, $presenceState);
                    }
                    if ($pendingGateActive && $targetRoomId === $pendingRoomId) {
                        $removedFromLobby = videochat_lobby_remove_user_from_room(
                            $lobbyState,
                            $targetRoomId,
                            (int) ($presenceConnection['user_id'] ?? 0)
                        );
                        if ($removedFromLobby) {
                            videochat_lobby_broadcast_room_snapshot(
                                $lobbyState,
                                $presenceState,
                                $targetRoomId,
                                'admission_consumed'
                            );
                        }
                    }
                    $joinedLobbySnapshot = videochat_realtime_send_synced_lobby_snapshot_to_connection(
                        $lobbyState,
                        $presenceConnection,
                        $openDatabase,
                        'joined_room'
                    );
                    $lastLobbySnapshotSignature = (string) ($joinedLobbySnapshot['signature'] ?? $lastLobbySnapshotSignature);
                    $joinedRoomSnapshot = videochat_realtime_send_room_snapshot(
                        $presenceState,
                        $presenceConnection,
                        $openDatabase,
                        'joined_room'
                    );
                    $lastRoomSnapshotSignature = (string) ($joinedRoomSnapshot['signature'] ?? $lastRoomSnapshotSignature);
                    continue;
                }
            }
        } finally {
            $detachWebsocket();
        }

        return [
            'status' => 101,
            'headers' => [],
            'body' => '',
        ];
    }

    return null;
}
