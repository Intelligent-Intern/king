<?php

declare(strict_types=1);

function videochat_handle_sfu_routes(
    string $path,
    array $request,
    array $presenceState,
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

    $queryParams = videochat_request_query_params($request);
    $roomResolution = videochat_sfu_resolve_bound_room($queryParams);
    if (!(bool) ($roomResolution['ok'] ?? false)) {
        return $errorResponse(
            400,
            'sfu_room_binding_invalid',
            'SFU connections require a valid room_id query parameter.',
            [
                'reason' => (string) ($roomResolution['error'] ?? 'invalid_room_id'),
            ]
        );
    }
    $roomId = (string) ($roomResolution['room_id'] ?? '');
    $requestedCallId = videochat_realtime_normalize_call_id(
        is_string($queryParams['call_id'] ?? null)
            ? (string) $queryParams['call_id']
            : (is_string($queryParams['callId'] ?? null) ? (string) $queryParams['callId'] : ''),
        ''
    );
    $userId = (int) ($websocketAuth['user']['id'] ?? 0);
    $userRole = (string) ($websocketAuth['user']['role'] ?? 'user');
    $sessionId = trim((string) ($websocketAuth['token'] ?? ($websocketAuth['session']['id'] ?? '')));
    $isAdmittedInRoom = videochat_realtime_presence_has_room_membership(
        $presenceState,
        $roomId,
        $userId,
        $sessionId
    );
    $hasPersistentAdmission = videochat_realtime_user_has_sfu_room_admission(
        $openDatabase,
        $userId,
        $userRole,
        $roomId,
        $requestedCallId
    );
    if (!$isAdmittedInRoom && !$hasPersistentAdmission) {
        return $errorResponse(
            403,
            'sfu_room_admission_required',
            'Join the call room over /ws before connecting to SFU.',
            [
                'room_id' => $roomId,
                'reason' => 'room_admission_required',
            ]
        );
    }

    $session = $request['session'] ?? null;
    $streamId = (int) ($request['stream_id'] ?? 0);
    $websocket = king_server_upgrade_to_websocket($session, $streamId);
    if ($websocket === false) {
        return $errorResponse(400, 'websocket_upgrade_failed', 'Could not upgrade request to websocket.');
    }

    $userIdString = (string) ($websocketAuth['user']['id'] ?? '');
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

    $clientObjectId = is_object($websocket)
        ? (string) spl_object_id($websocket)
        : (is_resource($websocket) ? (string) get_resource_id($websocket) : bin2hex(random_bytes(4)));
    $clientId = 'sfu_' . getmypid() . '_' . $clientObjectId . '_' . bin2hex(random_bytes(4));
    $sfuClients[$clientId] = [
        'websocket' => $websocket,
        'user_id' => $userIdString,
        'user_name' => $userName,
        'room_id' => $roomId,
        'role' => $role,
        'tracks' => [],
    ];

    if ($role === 'publisher') {
        $sfuRooms[$roomId]['publishers'][$clientId] = &$sfuClients[$clientId];
    }
    // A video-call peer both publishes local media and subscribes to remote media.
    $sfuRooms[$roomId]['subscribers'][$clientId] = &$sfuClients[$clientId];

    $sfuDatabase = null;
    try {
        $sfuDatabase = $openDatabase();
        videochat_sfu_bootstrap($sfuDatabase);
        if ($role === 'publisher') {
            videochat_sfu_upsert_publisher($sfuDatabase, $roomId, $clientId, $userIdString, $userName);
        }
    } catch (Throwable) {
        $sfuDatabase = null;
    }

    king_websocket_send($websocket, json_encode([
        'type' => 'sfu/welcome',
        'user_id' => $userIdString,
        'name' => $userName,
        'room_id' => $roomId,
        'server_time' => time(),
    ]));

    $publishersInRoom = array_values(array_filter(
        array_map('strval', array_keys($sfuRooms[$roomId]['publishers'] ?? [])),
        static fn (string $publisherId): bool => $publisherId !== (string) $clientId
    ));
    if (!empty($publishersInRoom)) {
        king_websocket_send($websocket, json_encode([
            'type' => 'sfu/joined',
            'room_id' => $roomId,
            'publishers' => $publishersInRoom,
        ]));
    }

    $joinedPublishers = $role === 'publisher' ? [(string) $clientId] : [];
    if ($joinedPublishers !== []) {
        foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
            if ((string) $subClientId === (string) $clientId) {
                continue;
            }
            king_websocket_send($subClient['websocket'], json_encode([
                'type' => 'sfu/joined',
                'room_id' => $roomId,
                'publishers' => $joinedPublishers,
            ]));
        }
    }

    $knownBrokerPublishers = [];
    $brokerTrackSignatures = [];
    $lastBrokerFrameId = $sfuDatabase instanceof PDO ? videochat_sfu_latest_frame_id($sfuDatabase, $roomId) : 0;
    $nextBrokerPollMs = 0;
    $nextBrokerCleanupMs = videochat_sfu_now_ms() + 5000;

    while (true) {
        if ($sfuDatabase instanceof PDO && videochat_sfu_now_ms() >= $nextBrokerPollMs) {
            try {
                videochat_sfu_poll_broker(
                    $sfuDatabase,
                    $websocket,
                    $roomId,
                    $clientId,
                    $knownBrokerPublishers,
                    $brokerTrackSignatures,
                    $lastBrokerFrameId
                );
                if (videochat_sfu_now_ms() >= $nextBrokerCleanupMs) {
                    videochat_sfu_cleanup_frames($sfuDatabase);
                    $nextBrokerCleanupMs = videochat_sfu_now_ms() + 5000;
                }
            } catch (Throwable) {
                // Keep the live socket up even if a transient SQLite lock hits the broker poll.
            }
            $nextBrokerPollMs = videochat_sfu_now_ms() + 100;
        }

        $frame = @king_client_websocket_receive($websocket, 100);
        if ($frame === null || $frame === false) {
            $status = function_exists('king_client_websocket_get_status')
                ? (int) king_client_websocket_get_status($websocket)
                : 1;
            if ($status === 3) {
                break;
            }
            continue;
        }

        if (!is_string($frame) || trim($frame) === '') {
            continue;
        }

        $messages = preg_split('/\r?\n/', trim($frame)) ?: [];
        foreach ($messages as $msgJson) {
            if (trim($msgJson) === '') {
                continue;
            }

            $command = videochat_sfu_decode_client_frame($msgJson, $roomId);
            if (!(bool) ($command['ok'] ?? false)) {
                videochat_presence_send_frame($websocket, [
                    'type' => 'sfu/error',
                    'room_id' => $roomId,
                    'error' => (string) ($command['error'] ?? 'invalid_command'),
                    'command_type' => (string) ($command['type'] ?? ''),
                ]);
                if ((string) ($command['error'] ?? '') === 'sfu_room_mismatch') {
                    break 2;
                }
                continue;
            }

            $msg = is_array($command['payload'] ?? null) ? $command['payload'] : [];
            $msgType = (string) ($command['type'] ?? '');

            switch ($msgType) {
                case 'sfu/join':
                    break;

                case 'sfu/publish':
                    $trackId = $msg['track_id'] ?? $msg['trackId'] ?? uniqid('track_');
                    $kind = $msg['kind'] ?? 'video';
                    $label = $msg['label'] ?? '';

                    $sfuClients[$clientId]['tracks'][$trackId] = [
                        'id' => $trackId,
                        'kind' => $kind,
                        'label' => $label,
                    ];
                    if ($sfuDatabase instanceof PDO) {
                        try {
                            videochat_sfu_upsert_track(
                                $sfuDatabase,
                                $roomId,
                                (string) $clientId,
                                (string) $trackId,
                                (string) $kind,
                                (string) $label
                            );
                        } catch (Throwable) {
                            // best-effort cross-worker track advertisement
                        }
                    }

                    king_websocket_send($websocket, json_encode([
                        'type' => 'sfu/published',
                        'track_id' => $trackId,
                        'server_time' => time(),
                    ]));

                    foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
                        if ((string) $subClientId === (string) $clientId) {
                            continue;
                        }
                        king_websocket_send($subClient['websocket'], json_encode([
                            'type' => 'sfu/tracks',
                            'room_id' => $roomId,
                            'publisher_id' => $clientId,
                            'publisher_user_id' => $userIdString,
                            'publisher_name' => $userName,
                            'tracks' => array_values($sfuClients[$clientId]['tracks']),
                        ]));
                    }
                    break;

                case 'sfu/subscribe':
                    $publisherId = $msg['publisher_id'] ?? $msg['publisherId'] ?? null;
                    if (isset($sfuRooms[$roomId]['publishers'][$publisherId])) {
                        king_websocket_send($websocket, json_encode([
                            'type' => 'sfu/tracks',
                            'room_id' => $roomId,
                            'publisher_id' => $publisherId,
                            'publisher_user_id' => $sfuClients[$publisherId]['user_id'],
                            'publisher_name' => $sfuClients[$publisherId]['user_name'],
                            'tracks' => array_values($sfuClients[$publisherId]['tracks']),
                        ]));
                    } elseif ($sfuDatabase instanceof PDO && is_string($publisherId) && $publisherId !== '') {
                        try {
                            foreach (videochat_sfu_fetch_publishers($sfuDatabase, $roomId) as $publisher) {
                                if ((string) ($publisher['publisher_id'] ?? '') !== $publisherId) {
                                    continue;
                                }
                                king_websocket_send($websocket, json_encode([
                                    'type' => 'sfu/tracks',
                                    'room_id' => $roomId,
                                    'publisher_id' => $publisherId,
                                    'publisher_user_id' => (string) ($publisher['user_id'] ?? ''),
                                    'publisher_name' => (string) ($publisher['user_name'] ?? ''),
                                    'tracks' => videochat_sfu_fetch_tracks($sfuDatabase, $roomId, $publisherId),
                                ]));
                                break;
                            }
                        } catch (Throwable) {
                            // best-effort cross-worker subscribe
                        }
                    }
                    break;

                case 'sfu/unpublish':
                    $trackId = $msg['track_id'] ?? $msg['trackId'] ?? null;
                    unset($sfuClients[$clientId]['tracks'][$trackId]);
                    if ($sfuDatabase instanceof PDO && is_string($trackId) && $trackId !== '') {
                        try {
                            videochat_sfu_remove_track($sfuDatabase, $roomId, (string) $clientId, $trackId);
                        } catch (Throwable) {
                            // best-effort cross-worker unpublish
                        }
                    }

                    foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
                        if ((string) $subClientId === (string) $clientId) {
                            continue;
                        }
                        king_websocket_send($subClient['websocket'], json_encode([
                            'type' => 'sfu/unpublished',
                            'publisher_id' => $clientId,
                            'publisher_user_id' => $userIdString,
                            'track_id' => $trackId,
                        ]));
                    }
                    break;

                case 'sfu/frame':
                    $trackId = $msg['track_id'] ?? $msg['trackId'] ?? '';
                    $timestamp = $msg['timestamp'] ?? 0;
                    $frameData = $msg['data'] ?? [];
                    $frameType = $msg['frame_type'] ?? $msg['frameType'] ?? 'delta';
                    $protectedMetadata = is_array($msg['protected'] ?? null) ? $msg['protected'] : [];
                    if ($sfuDatabase instanceof PDO && is_array($frameData)) {
                        try {
                            videochat_sfu_insert_frame(
                                $sfuDatabase,
                                $roomId,
                                (string) $clientId,
                                $userIdString,
                                (string) $trackId,
                                (int) $timestamp,
                                (string) $frameType,
                                $frameData,
                                $protectedMetadata
                            );
                        } catch (Throwable) {
                            // best-effort cross-worker frame relay
                        }
                    }

                    foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
                        if ((string) $subClientId !== (string) $clientId) {
                            $outboundFrame = [
                                'type' => 'sfu/frame',
                                'publisher_id' => $clientId,
                                'publisher_user_id' => $userIdString,
                                'track_id' => $trackId,
                                'timestamp' => $timestamp,
                                'data' => $frameData,
                                'frame_type' => $frameType,
                            ];
                            if ($protectedMetadata !== []) {
                                $outboundFrame['protected'] = $protectedMetadata;
                            }
                            king_websocket_send($subClient['websocket'], json_encode($outboundFrame));
                        }
                    }
                    break;

                case 'sfu/leave':
                    break 2;
            }
        }
    }

    unset($sfuClients[$clientId]);
    if ($sfuDatabase instanceof PDO) {
        try {
            videochat_sfu_remove_publisher($sfuDatabase, $roomId, (string) $clientId);
        } catch (Throwable) {
            // best-effort cleanup; stale rows are harmless for the next reconnect.
        }
    }
    if (isset($sfuRooms[$roomId]['publishers'][$clientId])) {
        unset($sfuRooms[$roomId]['publishers'][$clientId]);
        foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
            if ((string) $subClientId === (string) $clientId) {
                continue;
            }
            king_websocket_send($subClient['websocket'], json_encode([
                'type' => 'sfu/publisher_left',
                'publisher_id' => $clientId,
                'publisher_user_id' => $userIdString,
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
