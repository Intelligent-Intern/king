<?php

declare(strict_types=1);

const SFU_MSG_JOIN = 0x01;
const SFU_MSG_JOINED = 0x02;
const SFU_MSG_PUBLISH = 0x03;
const SFU_MSG_PUBLISHED = 0x04;
const SFU_MSG_UNPUBLISH = 0x05;
const SFU_MSG_UNPUBLISHED = 0x06;
const SFU_MSG_SUBSCRIBE = 0x07;
const SFU_MSG_TRACKS = 0x09;
const SFU_MSG_FRAME = 0x0A;
const SFU_MSG_PUBLISHER_LEFT = 0x0B;
const SFU_MSG_LEAVE = 0x0C;
const SFU_MSG_WELCOME = 0x0D;
const SFU_MSG_ERROR = 0xFF;

function videochat_sfu_decode_varint(string $data, int &$pos): int {
    $value = 0;
    $shift = 0;
    while ($pos < strlen($data)) {
        $b = ord($data[$pos++]);
        $value |= ($b & 0x7F) << $shift;
        if (($b & 0x80) === 0) return $value;
        $shift += 7;
    }
    return $value;
}

function videochat_sfu_encode_varint(int $value): string {
    $result = '';
    while ($value > 0x7F) {
        $result .= chr(($value & 0x7F) | 0x80);
        $value >>= 7;
    }
    $result .= chr($value & 0x7F);
    return $result;
}

function videochat_sfu_decode_string(string $data, int &$pos): string {
    $len = videochat_sfu_decode_varint($data, $pos);
    if ($pos + $len > strlen($data)) return '';
    $result = substr($data, $pos, $len);
    $pos += $len;
    return $result;
}

function videochat_sfu_encode_string(string $str): string {
    $bytes = mb_convert_encoding($str, 'ASCII', 'UTF-8');
    return videochat_sfu_encode_varint(strlen($bytes)) . $bytes;
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

    king_websocket_send($websocket, SFU_MSG_WELCOME . videochat_sfu_encode_string($userIdString) . videochat_sfu_encode_string($userName) . videochat_sfu_encode_string($roomId), true);

    $publishersInRoom = array_values(array_filter(
        array_map('strval', array_keys($sfuRooms[$roomId]['publishers'] ?? [])),
        static fn (string $publisherId): bool => $publisherId !== (string) $clientId
    ));
    if (!empty($publishersInRoom)) {
        $joinedPayload = SFU_MSG_JOINED . videochat_sfu_encode_string($roomId);
        foreach ($publishersInRoom as $pubId) {
            $joinedPayload .= videochat_sfu_encode_string($pubId);
        }
        king_websocket_send($websocket, $joinedPayload, true);
    }

    $joinedPublishers = $role === 'publisher' ? [(string) $clientId] : [];
    if ($joinedPublishers !== []) {
        $joinedPayload = SFU_MSG_JOINED . videochat_sfu_encode_string($roomId);
        foreach ($joinedPublishers as $pubId) {
            $joinedPayload .= videochat_sfu_encode_string($pubId);
        }
        foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
            if ((string) $subClientId === (string) $clientId) continue;
            king_websocket_send($subClient['websocket'], $joinedPayload, true);
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

        if ($frame === '') {
            continue;
        }

        if (is_string($frame) && strlen($frame) >= 1) {
            $msgType = ord($frame[0]);
            $pos = 1;

            if ($msgType === SFU_MSG_JOIN) {
                $roomArg = videochat_sfu_decode_string($frame, $pos);
                $roleArg = videochat_sfu_decode_string($frame, $pos);
            } elseif ($msgType === SFU_MSG_PUBLISH) {
                $trackId = videochat_sfu_decode_string($frame, $pos);
                $kind = videochat_sfu_decode_string($frame, $pos) ?: 'video';
                $label = videochat_sfu_decode_string($frame, $pos);

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
                    } catch (Throwable) {}
                }

                $thisTracks = videochat_sfu_encode_string($clientId)
                    . videochat_sfu_encode_string($userIdString)
                    . videochat_sfu_encode_string($userName);
                foreach ($sfuClients[$clientId]['tracks'] as $t) {
                    $thisTracks .= videochat_sfu_encode_string($t['id'])
                        . videochat_sfu_encode_string($t['kind'])
                        . videochat_sfu_encode_string($t['label']);
                }
                king_websocket_send($websocket, SFU_MSG_PUBLISHED . videochat_sfu_encode_string($trackId) . videochat_sfu_encode_string((string) time()), true);

                $tracksPayload = SFU_MSG_TRACKS . videochat_sfu_encode_string($roomId) . $thisTracks;
                foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
                    if ((string) $subClientId === (string) $clientId) continue;
                    king_websocket_send($subClient['websocket'], $tracksPayload, true);
                }
            } elseif ($msgType === SFU_MSG_SUBSCRIBE) {
                $publisherId = videochat_sfu_decode_string($frame, $pos);
                if (isset($sfuRooms[$roomId]['publishers'][$publisherId])) {
                    $pubTracks = videochat_sfu_encode_string($clientId)
                        . videochat_sfu_encode_string($userIdString)
                        . videochat_sfu_encode_string($userName);
                    foreach ($sfuClients[$clientId]['tracks'] as $t) {
                        $pubTracks .= videochat_sfu_encode_string($t['id'])
                            . videochat_sfu_encode_string($t['kind'])
                            . videochat_sfu_encode_string($t['label']);
                    }
                    king_websocket_send($websocket, SFU_MSG_TRACKS . $pubTracks, true);
                } elseif ($sfuDatabase instanceof PDO && $publisherId !== '') {
                    try {
                        foreach (videochat_sfu_fetch_publishers($sfuDatabase, $roomId) as $publisher) {
                            if (($publisher['publisher_id'] ?? '') !== $publisherId) continue;
                            $pubTracks = videochat_sfu_encode_string($publisherId)
                                . videochat_sfu_encode_string($publisher['user_id'] ?? '')
                                . videochat_sfu_encode_string($publisher['user_name'] ?? '');
                            foreach (videochat_sfu_fetch_tracks($sfuDatabase, $roomId, $publisherId) as $t) {
                                $pubTracks .= videochat_sfu_encode_string($t['track_id'] ?? '')
                                    . videochat_sfu_encode_string($t['kind'] ?? '')
                                    . videochat_sfu_encode_string($t['label'] ?? '');
                            }
                            king_websocket_send($websocket, SFU_MSG_TRACKS . $pubTracks, true);
                            break;
                        }
                    } catch (Throwable) {}
                }
            } elseif ($msgType === SFU_MSG_UNPUBLISH) {
                $trackId = videochat_sfu_decode_string($frame, $pos);
                unset($sfuClients[$clientId]['tracks'][$trackId]);
                if ($sfuDatabase instanceof PDO && $trackId !== '') {
                    try {
                        videochat_sfu_remove_track($sfuDatabase, $roomId, (string) $clientId, $trackId);
                    } catch (Throwable) {}
                }

                foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
                    if ((string) $subClientId === (string) $clientId) continue;
                    king_websocket_send($subClient['websocket'], SFU_MSG_UNPUBLISHED . videochat_sfu_encode_string($clientId) . videochat_sfu_encode_string($trackId), true);
                }
            } elseif ($msgType === SFU_MSG_FRAME) {
                $bf = videochat_sfu_parse_binary_frame($frame);
                if ($bf !== null) {
                    foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $subClientId => &$subClient) {
                        if ((string) $subClientId === (string) $clientId) continue;
                        $payload = pack('N', 0x574C5643)
                            . ($bf['frame_type'] === 'keyframe' ? "\x01" : "\x00")
                            . pack('N', $bf['timestamp'])
                            . pack('N', strlen($bf['data']))
                            . substr(($bf['track_id'] ?? '') . str_repeat("\x00", 8), 0, 8)
                            . $bf['data'];
                        king_websocket_send($subClient['websocket'], $payload, true);
                    }
                }
            } elseif ($msgType === SFU_MSG_LEAVE) {
                break;
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
            if ((string) $subClientId === (string) $clientId) continue;
            king_websocket_send($subClient['websocket'], SFU_MSG_PUBLISHER_LEFT . videochat_sfu_encode_string($clientId), true);
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

function videochat_sfu_parse_binary_frame(string $data): ?array {
    if (strlen($data) < 24) return null;
    $magic = unpack('N', substr($data, 0, 4))[1] ?? 0;
    if ($magic !== 0x574C5643) return null;
    $frameType = ord($data[4]) === 1 ? 'keyframe' : 'delta';
    $timestamp = unpack('N', substr($data, 8, 4))[1] ?? 0;
    $dataLen = unpack('N', substr($data, 12, 4))[1] ?? 0;
    if (strlen($data) !== 24 + $dataLen) return null;
    $trackId = rtrim(substr($data, 16, 8), "\x00");
    return [
        'timestamp' => $timestamp,
        'frame_type' => $frameType,
        'track_id' => $trackId,
        'data' => substr($data, 24),
    ];
}

function videochat_sfu_relay_binary_frame(array &$sfuRooms, string $clientId, string $roomId, array $frame): void {
    $ts = (int) ($frame['timestamp'] ?? 0);
    $ft = (string) ($frame['frame_type'] ?? 'delta');
    $tid = (string) ($frame['track_id'] ?? '');
    $fdata = (string) ($frame['data'] ?? '');
    $tid8 = substr($tid . str_repeat("\x00", 8), 0, 8);
    foreach ($sfuRooms[$roomId]['subscribers'] ?? [] as $scId => &$sc) {
        if ((string) $scId === (string) $clientId) continue;
        $payload = pack('N', 0x574C5643)
            . ($ft === 'keyframe' ? "\x01" : "\x00")
            . "\x00\x00\x00"
            . pack('N', $ts)
            . pack('N', strlen($fdata))
            . $tid8
            . $fdata;
        king_websocket_send($sc['websocket'], $payload, true);
    }
}
