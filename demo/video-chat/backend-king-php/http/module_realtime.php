<?php

declare(strict_types=1);

function videochat_realtime_normalize_ws_path(string $wsPath): string
{
    $normalized = trim($wsPath);
    if ($normalized === '') {
        return '/ws';
    }

    return str_starts_with($normalized, '/') ? $normalized : ('/' . $normalized);
}

function videochat_realtime_connection_has_upgrade_token(string $connectionHeader): bool
{
    $tokens = preg_split('/\s*,\s*/', strtolower(trim($connectionHeader))) ?: [];
    foreach ($tokens as $token) {
        if ($token === 'upgrade') {
            return true;
        }
    }

    return false;
}

function videochat_realtime_connection_with_call_context(array $connection, callable $openDatabase): array
{
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? 'lobby'));
    $userId = (int) ($connection['user_id'] ?? 0);
    $fallbackContext = [
        'call_id' => '',
        'call_role' => 'participant',
        'can_moderate' => false,
    ];

    try {
        $pdo = $openDatabase();
        $resolved = videochat_call_role_context_for_room_user($pdo, $roomId, $userId);
        if (!is_array($resolved)) {
            $resolved = $fallbackContext;
        }
    } catch (Throwable) {
        $resolved = $fallbackContext;
    }

    $connection['active_call_id'] = (string) ($resolved['call_id'] ?? '');
    $connection['call_role'] = videochat_normalize_call_participant_role((string) ($resolved['call_role'] ?? 'participant'));
    $connection['can_moderate_call'] = (bool) ($resolved['can_moderate'] ?? false);

    return $connection;
}

/**
 * @param array<string, mixed> $request
 * @return array{
 *   ok: bool,
 *   status: int,
 *   code: string,
 *   message: string,
 *   details: array<string, mixed>
 * }
 */
function videochat_realtime_validate_websocket_handshake(array $request, string $wsPath): array
{
    $normalizedPath = videochat_realtime_normalize_ws_path($wsPath);
    $requestPath = $request['path'] ?? null;
    if (!is_string($requestPath) || $requestPath === '') {
        $uri = is_string($request['uri'] ?? null) ? (string) $request['uri'] : '';
        $requestPath = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
    }
    $requestPath = trim((string) $requestPath);
    if ($requestPath === '') {
        $requestPath = '/';
    }

    if ($requestPath !== $normalizedPath) {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake request path is invalid.',
            'details' => [
                'reason' => 'ws_path_mismatch',
                'expected_path' => $normalizedPath,
                'actual_path' => $requestPath,
            ],
        ];
    }

    $method = strtoupper(trim((string) ($request['method'] ?? 'GET')));
    if ($method !== 'GET') {
        return [
            'ok' => false,
            'status' => 405,
            'code' => 'websocket_invalid_method',
            'message' => 'WebSocket handshake requires GET.',
            'details' => [
                'reason' => 'invalid_method',
                'allowed_methods' => ['GET'],
                'method' => $method,
            ],
        ];
    }

    $upgrade = strtolower(videochat_request_header_value($request, 'upgrade'));
    if ($upgrade === '') {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake is missing Upgrade header.',
            'details' => [
                'reason' => 'missing_upgrade_header',
                'required_upgrade' => 'websocket',
            ],
        ];
    }
    if ($upgrade !== 'websocket') {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake Upgrade header is invalid.',
            'details' => [
                'reason' => 'invalid_upgrade_header',
                'required_upgrade' => 'websocket',
                'upgrade' => $upgrade,
            ],
        ];
    }

    $connection = videochat_request_header_value($request, 'connection');
    if (!videochat_realtime_connection_has_upgrade_token($connection)) {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake Connection header must contain Upgrade.',
            'details' => [
                'reason' => 'missing_connection_upgrade_token',
            ],
        ];
    }

    $websocketKey = videochat_request_header_value($request, 'sec-websocket-key');
    if ($websocketKey === '') {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake is missing Sec-WebSocket-Key header.',
            'details' => [
                'reason' => 'missing_sec_websocket_key',
            ],
        ];
    }
    $decodedKey = base64_decode($websocketKey, true);
    if (!is_string($decodedKey) || strlen($decodedKey) !== 16) {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake Sec-WebSocket-Key is invalid.',
            'details' => [
                'reason' => 'invalid_sec_websocket_key',
            ],
        ];
    }

    $websocketVersion = videochat_request_header_value($request, 'sec-websocket-version');
    if ($websocketVersion === '') {
        return [
            'ok' => false,
            'status' => 400,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake is missing Sec-WebSocket-Version header.',
            'details' => [
                'reason' => 'missing_sec_websocket_version',
                'supported_versions' => ['13'],
            ],
        ];
    }
    if ($websocketVersion !== '13') {
        return [
            'ok' => false,
            'status' => 426,
            'code' => 'websocket_handshake_invalid',
            'message' => 'WebSocket handshake version is not supported.',
            'details' => [
                'reason' => 'unsupported_sec_websocket_version',
                'supported_versions' => ['13'],
                'version' => $websocketVersion,
            ],
        ];
    }

    return [
        'ok' => true,
        'status' => 0,
        'code' => '',
        'message' => '',
        'details' => [],
    ];
}

/**
 * @return array{close_code: int, close_reason: string, close_category: string}
 */
function videochat_realtime_close_descriptor_for_reason(string $reason): array
{
    $normalizedReason = strtolower(trim($reason));
    if ($normalizedReason === 'auth_backend_error') {
        return [
            'close_code' => 1011,
            'close_reason' => 'auth_backend_error',
            'close_category' => 'internal',
        ];
    }

    return [
        'close_code' => 1008,
        'close_reason' => 'session_invalidated',
        'close_category' => 'policy',
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_session_probe_request(string $sessionId, string $wsPath): array
{
    $trimmedSessionId = trim($sessionId);
    $path = trim($wsPath) !== '' ? trim($wsPath) : '/ws';

    return [
        'method' => 'GET',
        'uri' => $path,
        'headers' => [
            'Authorization' => 'Bearer ' . $trimmedSessionId,
        ],
    ];
}

/**
 * @return array{ok: bool, reason: string}
 */
function videochat_realtime_validate_session_liveness(
    callable $authenticateRequest,
    string $sessionId,
    string $wsPath
): array {
    $trimmedSessionId = trim($sessionId);
    if ($trimmedSessionId === '') {
        return [
            'ok' => false,
            'reason' => 'missing_session',
        ];
    }

    $auth = $authenticateRequest(
        videochat_realtime_session_probe_request($trimmedSessionId, $wsPath),
        'websocket'
    );
    if (!is_array($auth)) {
        return [
            'ok' => false,
            'reason' => 'auth_backend_error',
        ];
    }

    return [
        'ok' => (bool) ($auth['ok'] ?? false),
        'reason' => (string) ($auth['reason'] ?? 'invalid_session'),
    ];
}

function videochat_handle_realtime_routes(
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
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase
): ?array {
    if ($path === '/sfu') {
        return videochat_handle_sfu_routes(
            $path,
            $request,
            $authenticateRequest,
            $authFailureResponse,
            $rbacFailureResponse,
            $errorResponse,
            $openDatabase
        );
    }

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

        $session = $request['session'] ?? null;
        $streamId = (int) ($request['stream_id'] ?? 0);
        $websocket = king_server_upgrade_to_websocket($session, $streamId);
        if ($websocket === false) {
            return $errorResponse(400, 'websocket_upgrade_failed', 'Could not upgrade request to websocket.');
        }

        $requestedRoomId = '';
        $queryParams = videochat_request_query_params($request);
        if (is_string($queryParams['room'] ?? null)) {
            $requestedRoomId = (string) $queryParams['room'];
        }

        $initialRoomId = videochat_presence_normalize_room_id($requestedRoomId);
        try {
            $pdo = $openDatabase();
            $resolvedRoom = videochat_fetch_active_room_context($pdo, $initialRoomId);
            if ($resolvedRoom === null) {
                $resolvedRoom = videochat_fetch_active_room_context($pdo, 'lobby');
            }
            if (is_array($resolvedRoom) && is_string($resolvedRoom['id'] ?? null)) {
                $initialRoomId = videochat_presence_normalize_room_id((string) $resolvedRoom['id']);
            }
        } catch (Throwable) {
            $initialRoomId = 'lobby';
        }

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
        $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
        $presenceJoin = videochat_presence_join_room(
            $presenceState,
            $presenceConnection,
            (string) ($presenceConnection['room_id'] ?? 'lobby')
        );
        $presenceConnection = (array) ($presenceJoin['connection'] ?? $presenceConnection);
        $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
        $presenceState['connections'][$connectionId] = $presenceConnection;
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
            $connectionId
        ): void {
            if ($presenceDetached) {
                return;
            }
            $presenceDetached = true;

            videochat_lobby_clear_for_connection(
                $lobbyState,
                $presenceState,
                (array) $presenceConnection,
                'disconnect'
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
                    'call_id' => (string) ($presenceConnection['active_call_id'] ?? ''),
                    'call_role' => (string) ($presenceConnection['call_role'] ?? 'participant'),
                    'can_moderate' => (bool) ($presenceConnection['can_moderate_call'] ?? false),
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
                    'lobby' => [
                        'snapshot' => 'lobby/snapshot',
                        'request' => 'lobby/queue/request',
                        'join' => 'lobby/queue/join',
                        'allow' => 'lobby/allow',
                        'remove' => 'lobby/remove',
                        'allow_all' => 'lobby/allow_all',
                    ],
                    'signaling' => [
                        'offer' => 'call/offer',
                        'answer' => 'call/answer',
                        'ice' => 'call/ice',
                        'hangup' => 'call/hangup',
                        'ack' => 'call/ack',
                    ],
                ],
                'auth' => [
                    'session' => $websocketAuth['session'] ?? null,
                    'user' => $websocketAuth['user'] ?? null,
                ],
                'time' => gmdate('c'),
            ]
        );
        videochat_lobby_send_snapshot_to_connection($lobbyState, $presenceConnection, 'joined_room');

        try {
            while (true) {
                $sessionLiveness = videochat_realtime_validate_session_liveness(
                    $authenticateRequest,
                    $authSessionId,
                    $wsPath
                );
                if (!(bool) ($sessionLiveness['ok'] ?? false)) {
                    $sessionCloseDescriptor = videochat_realtime_close_descriptor_for_reason(
                        (string) ($sessionLiveness['reason'] ?? 'invalid_session')
                    );
                    videochat_presence_send_frame(
                        $websocket,
                        [
                            'type' => 'system/error',
                            'code' => 'websocket_session_invalidated',
                            'message' => 'Session is no longer valid for realtime commands.',
                            'details' => [
                                'reason' => (string) ($sessionLiveness['reason'] ?? 'invalid_session'),
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

                $chatCommand = null;
                $typingCommand = null;
                $signalingCommand = null;
                $reactionCommand = null;
                $lobbyCommand = null;
                if (!(bool) ($presenceCommand['ok'] ?? false) && $commandError === 'unsupported_type') {
                    $chatCommand = videochat_chat_decode_client_frame($frame);
                    if ((bool) ($chatCommand['ok'] ?? false)) {
                        $chatPublish = videochat_chat_publish(
                            $presenceState,
                            $presenceConnection,
                            $chatCommand
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
                            continue;
                        }

                        $message = is_array($chatPublish['event']['message'] ?? null)
                            ? $chatPublish['event']['message']
                            : [];
                        $chatRoomId = (string) ($chatPublish['event']['room_id'] ?? ($presenceConnection['room_id'] ?? 'lobby'));
                        videochat_presence_send_frame(
                            $websocket,
                            videochat_chat_ack_payload(
                                $chatRoomId,
                                $message,
                                (int) ($chatPublish['sent_count'] ?? 0)
                            )
                        );
                        continue;
                    }

                    if ((string) ($chatCommand['error'] ?? '') === 'unsupported_type') {
                        $typingCommand = videochat_typing_decode_client_frame($frame);
                        if ((bool) ($typingCommand['ok'] ?? false)) {
                            $typingResult = videochat_typing_apply_command(
                                $typingState,
                                $presenceState,
                                $presenceConnection,
                                $typingCommand
                            );
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
                            continue;
                        }

                        if ((string) ($typingCommand['error'] ?? '') === 'unsupported_type') {
                            $signalingCommand = videochat_signaling_decode_client_frame($frame);
                            if ((bool) ($signalingCommand['ok'] ?? false)) {
                                $signalingPublish = videochat_signaling_publish(
                                    $presenceState,
                                    $presenceConnection,
                                    $signalingCommand
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
                                    continue;
                                }

                                $eventSignal = is_array($signalingPublish['event']['signal'] ?? null)
                                    ? $signalingPublish['event']['signal']
                                    : [];
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
                                continue;
                            }

                            if ((string) ($signalingCommand['error'] ?? '') === 'unsupported_type') {
                                $reactionCommand = videochat_reaction_decode_client_frame($frame);
                                if ((bool) ($reactionCommand['ok'] ?? false)) {
                                    $reactionPublish = videochat_reaction_publish(
                                        $reactionState,
                                        $presenceState,
                                        $presenceConnection,
                                        $reactionCommand
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
                                    continue;
                                }

                                if ((string) ($reactionCommand['error'] ?? '') === 'unsupported_type') {
                                    $lobbyCommand = videochat_lobby_decode_client_frame($frame);
                                    if ((bool) ($lobbyCommand['ok'] ?? false)) {
                                        $lobbyResult = videochat_lobby_apply_command(
                                            $lobbyState,
                                            $presenceState,
                                            $presenceConnection,
                                            $lobbyCommand
                                        );
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
                                        }
                                        continue;
                                    }

                                    $commandType = (string) ($lobbyCommand['type'] ?? $commandType);
                                    $commandError = (string) ($lobbyCommand['error'] ?? $commandError);
                                } else {
                                    $commandType = (string) ($reactionCommand['type'] ?? $commandType);
                                    $commandError = (string) ($reactionCommand['error'] ?? $commandError);
                                }
                            } else {
                                $commandType = (string) ($signalingCommand['type'] ?? $commandType);
                                $commandError = (string) ($signalingCommand['error'] ?? $commandError);
                            }
                        } else {
                            $commandType = (string) ($typingCommand['type'] ?? $commandType);
                            $commandError = (string) ($typingCommand['error'] ?? $commandError);
                        }
                    } else {
                        $commandType = (string) ($chatCommand['type'] ?? $commandType);
                        $commandError = (string) ($chatCommand['error'] ?? $commandError);
                    }
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
                            'time' => gmdate('c'),
                        ]
                    );
                    continue;
                }

                if ($commandType === 'room/snapshot/request') {
                    videochat_presence_send_room_snapshot($presenceState, $presenceConnection, 'requested');
                    videochat_lobby_send_snapshot_to_connection($lobbyState, $presenceConnection, 'requested');
                    continue;
                }

                if ($commandType === 'room/leave') {
                    videochat_lobby_clear_for_connection(
                        $lobbyState,
                        $presenceState,
                        $presenceConnection,
                        'room_leave'
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
                    $presenceConnection['room_id'] = 'lobby';
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceJoin = videochat_presence_join_room($presenceState, $presenceConnection, 'lobby');
                    $presenceConnection = (array) ($presenceJoin['connection'] ?? $presenceConnection);
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceState['connections'][$connectionId] = $presenceConnection;
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

                    $currentRoomId = videochat_presence_normalize_room_id((string) ($presenceConnection['room_id'] ?? 'lobby'));
                    if ($currentRoomId !== $targetRoomId) {
                        videochat_lobby_clear_for_connection(
                            $lobbyState,
                            $presenceState,
                            $presenceConnection,
                            'room_change'
                        );
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
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceJoin = videochat_presence_join_room($presenceState, $presenceConnection, $targetRoomId);
                    $presenceConnection = (array) ($presenceJoin['connection'] ?? $presenceConnection);
                    $presenceConnection = videochat_realtime_connection_with_call_context($presenceConnection, $openDatabase);
                    $presenceState['connections'][$connectionId] = $presenceConnection;
                    videochat_lobby_send_snapshot_to_connection($lobbyState, $presenceConnection, 'joined_room');
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

function videochat_handle_sfu_routes(
    string $path,
    array $request,
    callable $authenticateRequest,
    callable $authFailureResponse,
    callable $rbacFailureResponse,
    callable $errorResponse,
    callable $openDatabase
): array {
    $handshakeValidation = videochat_realtime_validate_websocket_handshake($request, '/sfu');
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

    $websocketRbacDecision = videochat_authorize_role_for_path((array) ($websocketAuth['user'] ?? []), $path, '/sfu');
    if (!(bool) ($websocketRbacDecision['ok'] ?? false)) {
        return $rbacFailureResponse('websocket', $websocketRbacDecision, $path);
    }

    $session = $request['session'] ?? null;
    $streamId = (int) ($request['stream_id'] ?? 0);
    $websocket = king_server_upgrade_to_websocket($session, $streamId);
    if ($websocket === false) {
        return $errorResponse(400, 'websocket_upgrade_failed', 'Could not upgrade request to websocket.');
    }

    $queryParams = videochat_request_query_params($request);
    $roomId = is_string($queryParams['room'] ?? null) ? (string) $queryParams['room'] : 'lobby';
    $userId = (string) ($websocketAuth['user']['id'] ?? '');
    $userNameCandidate = $websocketAuth['user']['name'] ?? $websocketAuth['user']['display_name'] ?? null;
    $userName = is_string($userNameCandidate) && trim($userNameCandidate) !== ''
        ? trim($userNameCandidate)
        : 'Anonymous';
    $role = is_string($queryParams['role'] ?? null) ? (string) $queryParams['role'] : 'publisher';

    static $sfuClients = [];
    static $sfuRooms = [];

    if (!isset($sfuRooms[$roomId])) {
        $sfuRooms[$roomId] = [
            'publishers' => [],
            'subscribers' => [],
        ];
    }

    if (is_object($websocket)) {
        $clientId = spl_object_id($websocket);
    } elseif (is_resource($websocket)) {
        $clientId = get_resource_id($websocket);
    } else {
        $clientId = 'sfu_' . bin2hex(random_bytes(8));
    }
    $sfuClients[$clientId] = [
        'websocket' => $websocket,
        'user_id' => $userId,
        'user_name' => $userName,
        'room_id' => $roomId,
        'role' => $role,
        'tracks' => [],
    ];

    if ($role === 'publisher') {
        $sfuRooms[$roomId]['publishers'][$clientId] = &$sfuClients[$clientId];
    } else {
        $sfuRooms[$roomId]['subscribers'][$clientId] = &$sfuClients[$clientId];
    }

    king_websocket_send($websocket, json_encode([
        'type' => 'sfu/welcome',
        'user_id' => $userId,
        'name' => $userName,
        'room_id' => $roomId,
        'server_time' => time(),
    ]));

    $publishersInRoom = array_keys($sfuRooms[$roomId]['publishers'] ?? []);
    if (!empty($publishersInRoom)) {
        king_websocket_send($websocket, json_encode([
            'type' => 'sfu/joined',
            'room_id' => $roomId,
            'publishers' => $publishersInRoom,
        ]));
    }

    foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
        if ($subClientId !== $clientId) {
            king_websocket_send($subClient['websocket'], json_encode([
                'type' => 'sfu/joined',
                'room_id' => $roomId,
                'publishers' => $publishersInRoom,
            ]));
        }
    }

    $buffer = '';
    $firstFrame = true;

    while (true) {
        $frame = @king_client_websocket_receive($websocket, 5000);
        if ($frame === null || $frame === false) {
            break;
        }

        if ($firstFrame) {
            $firstFrame = false;
            continue;
        }

        $buffer .= $frame;
        $messages = explode("\n", $buffer);
        $buffer = array_pop($messages);

        foreach ($messages as $msgJson) {
            if (trim($msgJson) === '') {
                continue;
            }

            $msg = json_decode($msgJson, true);
            if (!is_array($msg)) {
                continue;
            }

            $msgType = $msg['type'] ?? '';

            switch ($msgType) {
                case 'ping':
                    $sfuClients[$clientId]['last_seen'] = time();
                    king_websocket_send($websocket, json_encode([
                        'type' => 'pong',
                        'server_time' => time(),
                    ]));
                    break;

                case 'neighbors':
                    $neighbors = $msg['neighbors'] ?? [];
                    $sfuClients[$clientId]['neighbors'] = $neighbors;
                    break;

                case 'offer':
                    $targetPeer = (int) ($msg['target_peer_id'] ?? 0);
                    if ($targetPeer && isset($sfuClients[$targetPeer])) {
                        king_websocket_send($sfuClients[$targetPeer]['websocket'], json_encode([
                            'type' => 'offer',
                            'from_peer_id' => $clientId,
                            'sdp' => $msg['sdp'] ?? null,
                        ]));
                    }
                    break;

                case 'answer':
                    $targetPeer = (int) ($msg['target_peer_id'] ?? 0);
                    if ($targetPeer && isset($sfuClients[$targetPeer])) {
                        king_websocket_send($sfuClients[$targetPeer]['websocket'], json_encode([
                            'type' => 'answer',
                            'from_peer_id' => $clientId,
                            'sdp' => $msg['sdp'] ?? null,
                        ]));
                    }
                    break;

                case 'ice-candidate':
                    $targetPeer = (int) ($msg['target_peer_id'] ?? 0);
                    if ($targetPeer && isset($sfuClients[$targetPeer])) {
                        king_websocket_send($sfuClients[$targetPeer]['websocket'], json_encode([
                            'type' => 'ice-candidate',
                            'from_peer_id' => $clientId,
                            'candidate' => $msg['candidate'] ?? null,
                        ]));
                    }
                    break;

                case 'sfu/publish':
                    $trackId = $msg['track_id'] ?? uniqid('track_');
                    $kind = $msg['kind'] ?? 'video';
                    $label = $msg['label'] ?? '';

                    $sfuClients[$clientId]['tracks'][$trackId] = [
                        'id' => $trackId,
                        'kind' => $kind,
                        'label' => $label,
                    ];

                    king_websocket_send($websocket, json_encode([
                        'type' => 'sfu/published',
                        'track_id' => $trackId,
                        'server_time' => time(),
                    ]));

                    foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
                        king_websocket_send($subClient['websocket'], json_encode([
                            'type' => 'sfu/tracks',
                            'room_id' => $roomId,
                            'publisher_id' => $clientId,
                            'publisher_name' => $userName,
                            'tracks' => array_values($sfuClients[$clientId]['tracks']),
                        ]));
                    }
                    break;

                case 'sfu/subscribe':
                    $publisherId = $msg['publisher_id'] ?? null;
                    if (isset($sfuRooms[$roomId]['publishers'][$publisherId])) {
                        king_websocket_send($websocket, json_encode([
                            'type' => 'sfu/tracks',
                            'room_id' => $roomId,
                            'publisher_id' => $publisherId,
                            'publisher_name' => $sfuClients[$publisherId]['user_name'],
                            'tracks' => array_values($sfuClients[$publisherId]['tracks']),
                        ]));
                    }
                    break;

                case 'sfu/unpublish':
                    $trackId = $msg['track_id'] ?? null;
                    unset($sfuClients[$clientId]['tracks'][$trackId]);

                    foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
                        king_websocket_send($subClient['websocket'], json_encode([
                            'type' => 'sfu/unpublished',
                            'publisher_id' => $clientId,
                            'track_id' => $trackId,
                        ]));
                    }
                    break;

                case 'sfu/frame':
                    $trackId = $msg['track_id'] ?? '';
                    $timestamp = $msg['timestamp'] ?? 0;
                    $frameData = $msg['data'] ?? [];
                    $frameType = $msg['frameType'] ?? 'delta';

                    foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
                        if ($subClientId !== $clientId) {
                            king_websocket_send($subClient['websocket'], json_encode([
                                'type' => 'sfu/frame',
                                'publisher_id' => $clientId,
                                'track_id' => $trackId,
                                'timestamp' => $timestamp,
                                'data' => $frameData,
                                'frameType' => $frameType,
                            ]));
                        }
                    }
                    break;

                case 'sfu/leave':
                    break 2;
            }
        }
    }

    unset($sfuClients[$clientId]);
    if (isset($sfuRooms[$roomId]['publishers'][$clientId])) {
        unset($sfuRooms[$roomId]['publishers'][$clientId]);
        foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
            king_websocket_send($subClient['websocket'], json_encode([
                'type' => 'sfu/publisher_left',
                'publisher_id' => $clientId,
            ]));
        }
    }
    if (isset($sfuRooms[$roomId]['subscribers'][$clientId])) {
        unset($sfuRooms[$roomId]['subscribers'][$clientId]);
    }

    return [
        'status' => 101,
        'headers' => [],
        'body' => '',
    ];
}
