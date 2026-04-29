<?php

declare(strict_types=1);

function videochat_sfu_log_runtime_warning(string $code, Throwable $error, array $context = []): void
{
    static $lastLoggedAtByCode = [];

    $nowMs = function_exists('videochat_sfu_now_ms')
        ? videochat_sfu_now_ms()
        : (int) floor(microtime(true) * 1000);
    $lastLoggedAt = (int) ($lastLoggedAtByCode[$code] ?? 0);
    if (($nowMs - $lastLoggedAt) < 5000) {
        return;
    }
    $lastLoggedAtByCode[$code] = $nowMs;

    $payload = [
        'code' => $code,
        'exception_class' => get_class($error),
        'exception_message' => $error->getMessage(),
        'context' => $context,
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    error_log('[video-chat][sfu] ' . (is_string($encoded) && $encoded !== '' ? $encoded : ($code . ': ' . $error->getMessage())));
}

function videochat_sfu_log_runtime_event(string $code, array $context = [], int $cooldownMs = 5000): void
{
    static $lastLoggedAtByCode = [];

    $nowMs = function_exists('videochat_sfu_now_ms')
        ? videochat_sfu_now_ms()
        : (int) floor(microtime(true) * 1000);
    $lastLoggedAt = (int) ($lastLoggedAtByCode[$code] ?? 0);
    if (($nowMs - $lastLoggedAt) < $cooldownMs) {
        return;
    }
    $lastLoggedAtByCode[$code] = $nowMs;

    $payload = ['code' => $code, 'context' => $context];
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    error_log('[video-chat][sfu] ' . (is_string($encoded) && $encoded !== '' ? $encoded : $code));
}

/**
 * @param array<string, mixed> $roomState
 * @return array<int, array<string, mixed>>
 */
function videochat_sfu_room_subscriber_targets(array $roomState, string $excludeClientId = ''): array
{
    $targets = [];
    foreach (($roomState['subscribers'] ?? []) as $subscriberClientId => $subscriber) {
        if ((string) $subscriberClientId === $excludeClientId) {
            continue;
        }
        if (!is_array($subscriber) || !array_key_exists('websocket', $subscriber)) {
            continue;
        }
        $subscriber['client_id'] = (string) $subscriberClientId;
        $targets[] = $subscriber;
    }

    return $targets;
}

function videochat_sfu_broker_database_path(): string
{
    $configuredPath = trim((string) (getenv('VIDEOCHAT_KING_SFU_BROKER_DB_PATH') ?: ''));
    if ($configuredPath !== '') {
        return $configuredPath;
    }

    $mainDatabasePath = trim((string) (getenv('VIDEOCHAT_KING_DB_PATH') ?: ''));
    if ($mainDatabasePath === '') {
        return __DIR__ . '/../../.local/video-chat-sfu-broker.sqlite';
    }

    return dirname($mainDatabasePath) . '/video-chat-sfu-broker.sqlite';
}

function videochat_sfu_open_broker_database(callable $openDatabase): PDO
{
    $brokerDatabasePath = videochat_sfu_broker_database_path();
    $mainDatabasePath = trim((string) (getenv('VIDEOCHAT_KING_DB_PATH') ?: ''));

    if ($brokerDatabasePath !== '' && $brokerDatabasePath !== $mainDatabasePath) {
        return videochat_open_sqlite_pdo($brokerDatabasePath);
    }

    return $openDatabase();
}

function videochat_sfu_is_transient_database_lock(Throwable $error): bool
{
    $message = strtolower(trim($error->getMessage()));
    if ($message === '') {
        return false;
    }

    return str_contains($message, 'database is locked')
        || str_contains($message, 'database table is locked')
        || str_contains($message, 'database schema is locked')
        || str_contains($message, 'database busy');
}

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

    $clientAssetVersion = videochat_realtime_client_asset_version_from_query($queryParams);
    $disconnectStaleAssetClient = static function () use ($websocket, $clientAssetVersion): bool {
        return videochat_realtime_disconnect_stale_asset_client(
            $websocket,
            $clientAssetVersion,
            static function (array $frame) use ($websocket): void {
                king_websocket_send(
                    $websocket,
                    json_encode($frame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
            },
            'sfu'
        );
    };
    if ($disconnectStaleAssetClient()) {
        return [
            'status' => 101,
            'headers' => [],
            'body' => '',
        ];
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
    $nextBrokerOpenAttemptMs = 0;
    $ensureBrokerDatabase = static function () use (
        &$sfuDatabase,
        &$nextBrokerOpenAttemptMs,
        $openDatabase,
        $roomId,
        $clientId,
        $role,
        $userIdString,
        $userName
    ): ?PDO {
        if ($sfuDatabase instanceof PDO) {
            return $sfuDatabase;
        }

        $nowMs = videochat_sfu_now_ms();
        if ($nowMs < $nextBrokerOpenAttemptMs) {
            return null;
        }

        try {
            $sfuDatabase = videochat_sfu_open_broker_database($openDatabase);
            videochat_sfu_bootstrap($sfuDatabase);
            if ($role === 'publisher') {
                videochat_sfu_upsert_publisher($sfuDatabase, $roomId, $clientId, $userIdString, $userName);
            }
            $nextBrokerOpenAttemptMs = 0;
            return $sfuDatabase;
        } catch (Throwable $error) {
            $nextBrokerOpenAttemptMs = $nowMs + (videochat_sfu_is_transient_database_lock($error) ? 500 : 2000);
            videochat_sfu_log_runtime_warning('sfu_broker_open_failed', $error, [
                'room_id' => $roomId,
                'client_id' => $clientId,
                'role' => $role,
                'broker_db_path' => videochat_sfu_broker_database_path(),
            ]);
            $sfuDatabase = null;
            return null;
        }
    };
    $ensureBrokerDatabase();

    king_websocket_send($websocket, json_encode([
        'type' => 'sfu/welcome',
        'user_id' => $userIdString,
        'name' => $userName,
        'room_id' => $roomId,
        'runtime' => videochat_realtime_runtime_descriptor(),
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
        foreach (videochat_sfu_room_subscriber_targets($sfuRooms[$roomId] ?? [], (string) $clientId) as $subClient) {
            king_websocket_send($subClient['websocket'], json_encode([
                'type' => 'sfu/joined',
                'room_id' => $roomId,
                'publishers' => $joinedPublishers,
            ]));
        }
    }

    $knownBrokerPublishers = [];
    $brokerTrackSignatures = [];
    $liveFrameRelayCursor = '';
    $liveFrameRelaySeenFiles = [];
    $nextBrokerPollMs = 0;
    $nextLiveFrameRelayPollMs = 0;
    $nextBrokerCleanupMs = videochat_sfu_now_ms() + 5000;
    $nextLiveFrameRelayCleanupMs = videochat_sfu_now_ms() + 5000;
    $nextBrokerFramePresenceTouchMs = 0;
    $stampKingReceiveMetrics = static function (array $msg): array {
        $kingReceiveAtMs = videochat_sfu_now_ms();
        $msg['king_receive_at_ms'] = $kingReceiveAtMs;
        $senderSentAtMs = max(0, (int) ($msg['sender_sent_at_ms'] ?? ($msg['senderSentAtMs'] ?? 0)));
        if ($senderSentAtMs > 0) {
            $msg['king_receive_latency_ms'] = max(0, $kingReceiveAtMs - $senderSentAtMs);
        }
        return $msg;
    };
    $processFramePayload = static function (array $msg) use (
        &$sfuRooms,
        &$sfuClients,
        $roomId,
        $clientId,
        $userIdString,
        $userName,
        &$sfuDatabase,
        &$nextBrokerOpenAttemptMs,
        &$nextBrokerFramePresenceTouchMs,
        $ensureBrokerDatabase
    ): void {
        $trackId = $msg['track_id'] ?? $msg['trackId'] ?? '';
        $timestamp = $msg['timestamp'] ?? 0;
        $frameData = $msg['data'] ?? [];
        $dataBase64 = is_string($msg['data_base64'] ?? null) ? trim((string) $msg['data_base64']) : '';
        $dataBinary = videochat_sfu_frame_data_binary($msg);
        $frameType = $msg['frame_type'] ?? $msg['frameType'] ?? 'delta';
        $protectedFrame = is_string($msg['protected_frame'] ?? null) ? trim((string) $msg['protected_frame']) : '';
        $protectionMode = (string) ($msg['protection_mode'] ?? ($protectedFrame !== '' ? 'protected' : 'transport_only'));
        $frameProtocolVersion = max(1, (int) ($msg['protocol_version'] ?? ($msg['protocolVersion'] ?? 1)));
        $frameSequence = max(0, (int) ($msg['frame_sequence'] ?? ($msg['frameSequence'] ?? 0)));
        $senderSentAtMs = max(0, (int) ($msg['sender_sent_at_ms'] ?? ($msg['senderSentAtMs'] ?? 0)));
        $payloadChars = max(0, (int) ($msg['payload_chars'] ?? ($msg['payloadChars'] ?? 0)));
        $actualPayloadCharsForFrame = $protectedFrame !== ''
            ? strlen($protectedFrame)
            : videochat_sfu_transport_payload_chars($dataBase64, $dataBinary);
        if ($payloadChars > 0 && $actualPayloadCharsForFrame > 0 && $payloadChars !== $actualPayloadCharsForFrame) {
            return;
        }
        if ($payloadChars <= 0 && $actualPayloadCharsForFrame > 0) {
            $payloadChars = $actualPayloadCharsForFrame;
        }
        $chunkCount = max(1, (int) ($msg['chunk_count'] ?? ($msg['chunkCount'] ?? 1)));
        $frameId = trim((string) ($msg['frame_id'] ?? ($msg['frameId'] ?? '')));
        $activeSfuDatabase = $ensureBrokerDatabase();
        if ($activeSfuDatabase instanceof PDO) {
            try {
                $nowMs = videochat_sfu_now_ms();
                if ($nowMs >= $nextBrokerFramePresenceTouchMs) {
                    videochat_sfu_upsert_publisher(
                        $activeSfuDatabase,
                        $roomId,
                        (string) $clientId,
                        $userIdString,
                        $userName
                    );
                    if ((string) $trackId !== '') {
                        videochat_sfu_touch_track($activeSfuDatabase, $roomId, (string) $clientId, (string) $trackId);
                    }
                    $nextBrokerFramePresenceTouchMs = $nowMs + 2000;
                }
            } catch (Throwable $error) {
                videochat_sfu_log_runtime_warning('sfu_presence_touch_failed', $error, [
                    'room_id' => $roomId,
                    'client_id' => $clientId,
                    'track_id' => (string) $trackId,
                    'frame_type' => (string) $frameType,
                    'protection_mode' => (string) $protectionMode,
                ]);
                if (videochat_sfu_is_transient_database_lock($error)) {
                    $sfuDatabase = null;
                    $nextBrokerOpenAttemptMs = videochat_sfu_now_ms() + 500;
                }
            }
        }

        $outboundFrame = array_merge([
            'type' => 'sfu/frame',
            'publisher_id' => $clientId,
            'publisher_user_id' => $userIdString,
            'track_id' => $trackId,
            'timestamp' => $timestamp,
            'frame_type' => $frameType,
            'protection_mode' => $protectionMode,
            'protocol_version' => $frameProtocolVersion,
            'frame_sequence' => $frameSequence,
            'sender_sent_at_ms' => $senderSentAtMs,
            'payload_chars' => $payloadChars,
            'chunk_count' => $chunkCount,
        ], videochat_sfu_normalize_frame_transport_metadata($msg));
        $fanoutStartedAtMs = videochat_sfu_now_ms();
        $kingReceiveAtMs = max(0, (int) ($outboundFrame['king_receive_at_ms'] ?? 0));
        if ($kingReceiveAtMs > 0) {
            $outboundFrame['king_fanout_latency_ms'] = max(0, $fanoutStartedAtMs - $kingReceiveAtMs);
        }
        if ($frameId !== '') {
            $outboundFrame['frame_id'] = $frameId;
        }
        if ($protectedFrame !== '') {
            $outboundFrame['protected_frame'] = $protectedFrame;
        } elseif ($dataBinary !== '') {
            $outboundFrame['data_binary'] = $dataBinary;
            $outboundFrame['payload_bytes'] = strlen($dataBinary);
        } elseif ($dataBase64 !== '') {
            $outboundFrame['data_base64'] = $dataBase64;
        } else {
            $outboundFrame['data'] = $frameData;
        }
        $relayFrame = videochat_sfu_frame_json_safe_for_live_relay($outboundFrame);
        if (!videochat_sfu_live_frame_relay_publish($roomId, (string) $clientId, $relayFrame)) {
            videochat_sfu_log_runtime_event('sfu_frame_live_relay_publish_failed', [
                'room_id' => $roomId,
                'publisher_id' => (string) $clientId,
                'track_id' => (string) $trackId,
                'frame_type' => (string) $frameType,
                'protection_mode' => (string) $protectionMode,
                'sfu_send_path' => 'live_relay_publish',
                'worker_pid' => getmypid(),
                ...videochat_sfu_transport_metric_fields($relayFrame, 0),
            ], 3000);
        }

        foreach (videochat_sfu_room_subscriber_targets($sfuRooms[$roomId] ?? [], (string) $clientId) as $subClient) {
            $subscriberSendStartedAtMs = videochat_sfu_now_ms();
            $outboundFrame['subscriber_send_latency_ms'] = max(0, $subscriberSendStartedAtMs - $fanoutStartedAtMs);
            if (!videochat_sfu_send_outbound_message($subClient['websocket'], $outboundFrame, [
                'sfu_send_path' => 'direct_fanout',
                'room_id' => $roomId,
                'subscriber_id' => (string) ($subClient['client_id'] ?? ''),
                'worker_pid' => getmypid(),
                'subscriber_send_latency_ms' => $outboundFrame['subscriber_send_latency_ms'],
            ])) {
                videochat_sfu_log_runtime_event('sfu_frame_direct_fanout_binary_required_failed', [
                    'room_id' => $roomId,
                    'publisher_id' => (string) $clientId,
                    'subscriber_id' => (string) ($subClient['client_id'] ?? ''),
                    'track_id' => (string) $trackId,
                    'frame_type' => (string) $frameType,
                    'protection_mode' => (string) $protectionMode,
                    'sfu_send_path' => 'direct_fanout',
                    'worker_pid' => getmypid(),
                    ...videochat_sfu_transport_metric_fields($outboundFrame, 0),
                ]);
            }
        }
    };
    while (true) {
        if ($disconnectStaleAssetClient()) {
            break;
        }

        $activeSfuDatabase = $ensureBrokerDatabase();
        if ($activeSfuDatabase instanceof PDO && videochat_sfu_now_ms() >= $nextBrokerPollMs) {
            try {
                videochat_sfu_poll_broker(
                    $activeSfuDatabase,
                    $websocket,
                    $roomId,
                    $clientId,
                    $knownBrokerPublishers,
                    $brokerTrackSignatures
                );
                if (videochat_sfu_now_ms() >= $nextBrokerCleanupMs) {
                    videochat_sfu_cleanup_stale_presence($activeSfuDatabase);
                    $nextBrokerCleanupMs = videochat_sfu_now_ms() + 5000;
                }
            } catch (Throwable $error) {
                videochat_sfu_log_runtime_warning('sfu_broker_poll_failed', $error, [
                    'room_id' => $roomId,
                    'client_id' => $clientId,
                    'broker_db_path' => videochat_sfu_broker_database_path(),
                ]);
                if (videochat_sfu_is_transient_database_lock($error)) {
                    $sfuDatabase = null;
                    $nextBrokerOpenAttemptMs = videochat_sfu_now_ms() + 500;
                }
            }
            $nextBrokerPollMs = videochat_sfu_now_ms() + 100;
        }
        if (videochat_sfu_now_ms() >= $nextLiveFrameRelayPollMs) {
            try {
                videochat_sfu_live_frame_relay_poll(
                    $websocket,
                    $roomId,
                    (string) $clientId,
                    array_keys($sfuRooms[$roomId]['publishers'] ?? []),
                    $liveFrameRelayCursor,
                    $liveFrameRelaySeenFiles
                );
                if (videochat_sfu_now_ms() >= $nextLiveFrameRelayCleanupMs) {
                    videochat_sfu_live_frame_relay_cleanup_room($roomId);
                    $nextLiveFrameRelayCleanupMs = videochat_sfu_now_ms() + 5000;
                }
            } catch (Throwable $error) {
                videochat_sfu_log_runtime_warning('sfu_frame_live_relay_poll_failed', $error, [
                    'room_id' => $roomId,
                    'client_id' => $clientId,
                    'worker_pid' => getmypid(),
                ]);
            }
            $nextLiveFrameRelayPollMs = videochat_sfu_now_ms() + videochat_sfu_live_frame_relay_poll_interval_ms();
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

        if (!is_string($frame) || $frame === '') {
            continue;
        }

        if (videochat_sfu_binary_frame_has_magic($frame)) {
            $command = videochat_sfu_decode_binary_client_frame($frame, $roomId);
            if (!(bool) ($command['ok'] ?? false)) {
                videochat_presence_send_frame($websocket, [
                    'type' => 'sfu/error',
                    'room_id' => $roomId,
                    'error' => (string) ($command['error'] ?? 'invalid_binary_envelope'),
                    'command_type' => 'sfu/frame',
                ]);
                continue;
            }
            $msg = is_array($command['payload'] ?? null) ? $stampKingReceiveMetrics($command['payload']) : [];
            $processFramePayload($msg);
            continue;
        }

        if (videochat_sfu_iibin_control_frame_has_magic($frame)) {
            $command = videochat_sfu_iibin_decode_control_frame($frame, $roomId);
            if (!(bool) ($command['ok'] ?? false)) {
                videochat_presence_send_frame($websocket, [
                    'type' => 'sfu/error',
                    'room_id' => $roomId,
                    'error' => (string) ($command['error'] ?? 'invalid_iibin_control_payload'),
                    'command_type' => 'sfu/iibin-control',
                ]);
                if ((string) ($command['error'] ?? '') === 'sfu_room_mismatch') {
                    break;
                }
                continue;
            }
            $encodedCommand = json_encode(
                is_array($command['payload'] ?? null) ? $command['payload'] : [],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $messages = is_string($encodedCommand) && $encodedCommand !== '' ? [$encodedCommand] : [];
        } else {
            if (trim($frame) === '') {
                continue;
            }
            $messages = preg_split('/\r?\n/', trim($frame)) ?: [];
        }
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
                    $activeSfuDatabase = $ensureBrokerDatabase();
                    if ($activeSfuDatabase instanceof PDO) {
                        try {
                            videochat_sfu_upsert_publisher(
                                $activeSfuDatabase,
                                $roomId,
                                (string) $clientId,
                                $userIdString,
                                $userName
                            );
                            videochat_sfu_upsert_track(
                                $activeSfuDatabase,
                                $roomId,
                                (string) $clientId,
                                (string) $trackId,
                                (string) $kind,
                                (string) $label
                            );
                        } catch (Throwable $error) {
                            if (videochat_sfu_is_transient_database_lock($error)) {
                                $sfuDatabase = null;
                                $nextBrokerOpenAttemptMs = videochat_sfu_now_ms() + 500;
                            }
                            // Cross-worker track advertisement is broker-backed; transient broker lock only delays it.
                        }
                    }

                    king_websocket_send($websocket, json_encode([
                        'type' => 'sfu/published',
                        'track_id' => $trackId,
                        'server_time' => time(),
                    ]));

                    foreach (videochat_sfu_room_subscriber_targets($sfuRooms[$roomId] ?? [], (string) $clientId) as $subClient) {
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
                    } else {
                        $activeSfuDatabase = $ensureBrokerDatabase();
                    }
                    if ($activeSfuDatabase instanceof PDO && is_string($publisherId) && $publisherId !== '') {
                        try {
                            foreach (videochat_sfu_fetch_publishers($activeSfuDatabase, $roomId) as $publisher) {
                                if ((string) ($publisher['publisher_id'] ?? '') !== $publisherId) {
                                    continue;
                                }
                                king_websocket_send($websocket, json_encode([
                                    'type' => 'sfu/tracks',
                                    'room_id' => $roomId,
                                    'publisher_id' => $publisherId,
                                    'publisher_user_id' => (string) ($publisher['user_id'] ?? ''),
                                    'publisher_name' => (string) ($publisher['user_name'] ?? ''),
                                    'tracks' => videochat_sfu_fetch_tracks($activeSfuDatabase, $roomId, $publisherId),
                                ]));
                                break;
                            }
                        } catch (Throwable $error) {
                            if (videochat_sfu_is_transient_database_lock($error)) {
                                $sfuDatabase = null;
                                $nextBrokerOpenAttemptMs = videochat_sfu_now_ms() + 500;
                            }
                            // Cross-worker subscribe is broker-backed; transient broker lock only delays it.
                        }
                    }
                    break;

                case 'sfu/unpublish':
                    $trackId = $msg['track_id'] ?? $msg['trackId'] ?? null;
                    unset($sfuClients[$clientId]['tracks'][$trackId]);
                    $activeSfuDatabase = $ensureBrokerDatabase();
                    if ($activeSfuDatabase instanceof PDO && is_string($trackId) && $trackId !== '') {
                        try {
                            videochat_sfu_remove_track($activeSfuDatabase, $roomId, (string) $clientId, $trackId);
                        } catch (Throwable $error) {
                            if (videochat_sfu_is_transient_database_lock($error)) {
                                $sfuDatabase = null;
                                $nextBrokerOpenAttemptMs = videochat_sfu_now_ms() + 500;
                            }
                            // Cross-worker unpublish is broker-backed; transient broker lock only delays it.
                        }
                    }

                    foreach (videochat_sfu_room_subscriber_targets($sfuRooms[$roomId] ?? [], (string) $clientId) as $subClient) {
                        king_websocket_send($subClient['websocket'], json_encode([
                            'type' => 'sfu/unpublished',
                            'publisher_id' => $clientId,
                            'publisher_user_id' => $userIdString,
                            'track_id' => $trackId,
                        ]));
                    }
                    break;

                case 'sfu/frame':
                    $processFramePayload($stampKingReceiveMetrics($msg));
                    break;

                case 'sfu/frame-chunk':
                    videochat_presence_send_frame($websocket, [
                        'type' => 'sfu/error',
                        'room_id' => $roomId,
                        'error' => 'binary_media_required',
                        'command_type' => 'sfu/frame-chunk',
                    ]);
                    break;

                case 'sfu/iibin-control':
                    videochat_sfu_log_runtime_event('sfu_iibin_control_metadata', [
                        'room_id' => $roomId,
                        'client_id' => $clientId,
                        'diagnostic_code' => (string) ($msg['diagnostic_code'] ?? ''),
                        'diagnostic_level' => (string) ($msg['diagnostic_level'] ?? 'info'),
                        'transport_path' => (string) ($msg['transport_path'] ?? ''),
                        'payload_bytes' => (int) ($msg['payload_bytes'] ?? 0),
                        'wire_payload_bytes' => (int) ($msg['wire_payload_bytes'] ?? 0),
                        'queue_pressure_bytes' => (int) ($msg['queue_pressure_bytes'] ?? 0),
                        'binary_continuation_state' => (string) ($msg['binary_continuation_state'] ?? ''),
                    ], 3000);
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
        foreach (videochat_sfu_room_subscriber_targets($sfuRooms[$roomId] ?? [], (string) $clientId) as $subClient) {
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
