<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/realtime_signaling.php';

const VIDEOCHAT_GOSSIP_MEDIA_RELAY_CLIENT_TYPE = 'gossip/server-frame';
const VIDEOCHAT_GOSSIP_MEDIA_RELAY_DELIVERY_TYPE = 'call/gossip-server-frame';

function videochat_gossip_media_relay_socket_requested(array $queryParams): bool
{
    $relay = strtolower(trim((string) ($queryParams['relay'] ?? '')));
    $channel = strtolower(trim((string) ($queryParams['channel'] ?? '')));
    return $relay === 'media' || $channel === 'gossip_media_relay';
}

function videochat_gossip_media_relay_max_frame_chars(): int
{
    $configured = (int) (getenv('VIDEOCHAT_GOSSIP_MEDIA_RELAY_MAX_FRAME_CHARS') ?: 0);
    return $configured > 0 ? $configured : 8_000_000;
}

function videochat_gossip_media_relay_frame_looks_like_client_type(string $frame): bool
{
    return preg_match('/"type"\s*:\s*"gossip\/server-frame"/i', substr($frame, 0, 512)) === 1;
}

/**
 * @return array{ok: bool, type: string, room_id: string, call_id: string, payload: array<string, mixed>, error: string}
 */
function videochat_gossip_media_relay_decode_client_frame(string $frame): array
{
    $unsupported = [
        'ok' => false,
        'type' => '',
        'room_id' => '',
        'call_id' => '',
        'payload' => [],
        'error' => 'unsupported_type',
    ];

    if (strlen($frame) > videochat_gossip_media_relay_max_frame_chars()) {
        if (!videochat_gossip_media_relay_frame_looks_like_client_type($frame)) {
            return $unsupported;
        }
        return [
            ...$unsupported,
            'type' => VIDEOCHAT_GOSSIP_MEDIA_RELAY_CLIENT_TYPE,
            'error' => 'frame_too_large',
        ];
    }

    try {
        $decoded = json_decode($frame, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return [
            ...$unsupported,
            'error' => 'invalid_json',
        ];
    }

    if (!is_array($decoded)) {
        return [
            ...$unsupported,
            'error' => 'invalid_command',
        ];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type !== VIDEOCHAT_GOSSIP_MEDIA_RELAY_CLIENT_TYPE) {
        return $unsupported;
    }

    $payload = is_array($decoded['payload'] ?? null) ? (array) $decoded['payload'] : [];
    if ($payload === []) {
        return [
            ...$unsupported,
            'type' => $type,
            'error' => 'invalid_payload',
        ];
    }

    $trackId = trim((string) ($payload['track_id'] ?? ($payload['trackId'] ?? '')));
    $dataBase64 = trim((string) ($payload['data_base64'] ?? ($payload['dataBase64'] ?? '')));
    if ($trackId === '' || $dataBase64 === '') {
        return [
            ...$unsupported,
            'type' => $type,
            'error' => 'missing_frame_payload',
        ];
    }

    return [
        'ok' => true,
        'type' => $type,
        'room_id' => videochat_presence_normalize_room_id((string) ($decoded['room_id'] ?? ($payload['room_id'] ?? '')), ''),
        'call_id' => videochat_realtime_normalize_call_id((string) ($decoded['call_id'] ?? ($payload['call_id'] ?? '')), ''),
        'payload' => $payload,
        'error' => '',
    ];
}

function videochat_gossip_media_relay_register_socket(array &$presenceState, array $presenceConnection): void
{
    $connectionId = trim((string) ($presenceConnection['connection_id'] ?? ''));
    if ($connectionId === '') {
        return;
    }

    $presenceState['media_relay_connections'] ??= [];
    $presenceState['media_relay_connections'][$connectionId] = [
        ...$presenceConnection,
        'media_relay_socket' => true,
    ];
}

function videochat_gossip_media_relay_unregister_socket(array &$presenceState, string $connectionId): void
{
    $normalizedConnectionId = trim($connectionId);
    if ($normalizedConnectionId === '') {
        return;
    }

    if (is_array($presenceState['media_relay_connections'] ?? null)) {
        unset($presenceState['media_relay_connections'][$normalizedConnectionId]);
    }
}

function videochat_gossip_media_relay_send_relay_socket_error(
    mixed $websocket,
    string $errorCode,
    ?callable $sender = null
): void {
    videochat_presence_send_frame(
        $websocket,
        [
            'type' => 'system/error',
            'code' => 'gossip_media_relay_socket_failed',
            'message' => 'The media relay websocket accepted only Gossip media relay frames.',
            'details' => [
                'error' => $errorCode,
                'accepted_type' => VIDEOCHAT_GOSSIP_MEDIA_RELAY_CLIENT_TYPE,
            ],
            'time' => gmdate('c'),
        ],
        $sender
    );
}

function videochat_realtime_serve_gossip_media_relay_websocket(
    mixed $websocket,
    array &$presenceState,
    array $presenceConnection,
    callable $authenticateRequest,
    string $authSessionId,
    string $wsPath,
    callable $disconnectStaleAssetClient,
    callable $openDatabase,
    ?callable $onDetach = null,
    ?callable $sender = null
): void {
    $presenceConnection['media_relay_socket'] = true;
    videochat_gossip_media_relay_register_socket($presenceState, $presenceConnection);

    $transientSessionLivenessFailures = 0;
    $transientSessionLivenessStartedAtMs = 0;
    $transientSessionLivenessGraceMs = 5000;

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
                $livenessAction = videochat_realtime_handle_session_liveness_failure(
                    $websocket,
                    $sessionLiveness,
                    $transientSessionLivenessFailures,
                    $transientSessionLivenessStartedAtMs,
                    $transientSessionLivenessGraceMs
                );
                if ($livenessAction === 'continue') {
                    continue;
                }
                break;
            }
            $transientSessionLivenessFailures = 0;
            $transientSessionLivenessStartedAtMs = 0;

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
            $presenceConnection['media_relay_socket'] = true;
            videochat_gossip_media_relay_register_socket($presenceState, $presenceConnection);

            if (strlen($frame) < 128) {
                try {
                    $smallCommand = json_decode($frame, true, 8, JSON_THROW_ON_ERROR);
                    if (is_array($smallCommand) && strtolower(trim((string) ($smallCommand['type'] ?? ''))) === 'ping') {
                        videochat_presence_send_frame(
                            $websocket,
                            [
                                'type' => 'system/pong',
                                'runtime' => videochat_realtime_runtime_descriptor(),
                                'relay' => 'media',
                                'time' => gmdate('c'),
                            ],
                            $sender
                        );
                        continue;
                    }
                } catch (JsonException) {
                    // Let the strict relay decoder below produce the public error.
                }
            }

            $relayCommand = videochat_gossip_media_relay_decode_client_frame($frame);
            $relayResult = videochat_realtime_handle_gossip_media_relay_command(
                $relayCommand,
                $websocket,
                $presenceState,
                $presenceConnection,
                $sender
            );
            if ($relayResult !== null && (bool) ($relayResult['handled'] ?? false)) {
                continue;
            }

            videochat_gossip_media_relay_send_relay_socket_error(
                $websocket,
                (string) ($relayCommand['error'] ?? 'unsupported_type'),
                $sender
            );
        }
    } finally {
        videochat_gossip_media_relay_unregister_socket(
            $presenceState,
            (string) ($presenceConnection['connection_id'] ?? '')
        );
        if ($onDetach !== null) {
            $onDetach();
        }
    }
}

function videochat_realtime_send_gossip_media_relay_error(
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
            'code' => 'gossip_media_relay_failed',
            'message' => 'Could not relay the Gossip media frame.',
            'details' => [
                'error' => $errorCode,
                'type' => VIDEOCHAT_GOSSIP_MEDIA_RELAY_CLIENT_TYPE,
                'room_id' => (string) ($command['room_id'] ?? ($presenceConnection['room_id'] ?? '')),
                'call_id' => (string) ($command['call_id'] ?? videochat_realtime_connection_call_id($presenceConnection)),
                'max_frame_chars' => videochat_gossip_media_relay_max_frame_chars(),
            ],
            'time' => gmdate('c'),
        ],
        $sender
    );
}

function videochat_gossip_media_relay_broadcast_call_event(
    array $presenceState,
    string $roomId,
    string $callId,
    array $payload,
    string $excludeConnectionId,
    ?callable $sender = null,
    ?int $tenantId = null,
    ?int $excludeUserId = null
): int {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $normalizedCallId = videochat_realtime_normalize_call_id($callId, '');
    if ($normalizedRoomId === '' || $normalizedCallId === '') {
        return 0;
    }

    $roomConnections = $presenceState['rooms'][videochat_presence_room_key($normalizedRoomId, $tenantId)] ?? null;
    if (!is_array($roomConnections) || $roomConnections === []) {
        return 0;
    }

    $sentCount = 0;
    $excludedId = trim($excludeConnectionId);
    $excludedUserId = is_int($excludeUserId) && $excludeUserId > 0 ? $excludeUserId : 0;
    $relayDeliveredUserIds = [];
    $relayConnections = $presenceState['media_relay_connections'] ?? null;
    if (is_array($relayConnections) && $relayConnections !== []) {
        foreach ($relayConnections as $connectionId => $connection) {
            if (!is_string($connectionId) || $connectionId === '' || !is_array($connection)) {
                continue;
            }
            if ($excludedId !== '' && $connectionId === $excludedId) {
                continue;
            }
            $targetUserId = (int) ($connection['user_id'] ?? 0);
            if ($excludedUserId > 0 && $targetUserId === $excludedUserId) {
                continue;
            }
            if (videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '') !== $normalizedRoomId) {
                continue;
            }
            if (videochat_realtime_connection_call_id($connection) !== $normalizedCallId) {
                continue;
            }
            $connectionTenantId = is_numeric($connection['tenant_id'] ?? null) ? (int) $connection['tenant_id'] : null;
            if ($tenantId !== null && $connectionTenantId !== $tenantId) {
                continue;
            }
            if (videochat_presence_send_frame($connection['socket'] ?? null, $payload, $sender)) {
                $sentCount++;
                if ($targetUserId > 0) {
                    $relayDeliveredUserIds[$targetUserId] = true;
                }
            }
        }
    }

    foreach ($roomConnections as $connectionId => $_socket) {
        if (!is_string($connectionId) || $connectionId === '') {
            continue;
        }
        if ($excludedId !== '' && $connectionId === $excludedId) {
            continue;
        }

        $connection = $presenceState['connections'][$connectionId] ?? null;
        if (!is_array($connection)) {
            continue;
        }
        $targetUserId = (int) ($connection['user_id'] ?? 0);
        if ($excludedUserId > 0 && $targetUserId === $excludedUserId) {
            continue;
        }
        if ($targetUserId > 0 && ($relayDeliveredUserIds[$targetUserId] ?? false)) {
            continue;
        }
        if (videochat_realtime_connection_call_id($connection) !== $normalizedCallId) {
            continue;
        }

        if (videochat_presence_send_frame($connection['socket'] ?? null, $payload, $sender)) {
            $sentCount++;
        }
    }

    return $sentCount;
}

function videochat_realtime_handle_gossip_media_relay_command(
    array $relayCommand,
    mixed $websocket,
    array &$presenceState,
    array $presenceConnection,
    ?callable $sender = null
): ?array {
    if (!(bool) ($relayCommand['ok'] ?? false)) {
        if ((string) ($relayCommand['error'] ?? '') === 'unsupported_type') {
            return null;
        }
        videochat_realtime_send_gossip_media_relay_error(
            $websocket,
            $presenceConnection,
            $relayCommand,
            (string) ($relayCommand['error'] ?? 'invalid_command'),
            $sender
        );
        return videochat_realtime_secondary_handled_result();
    }

    $roomId = videochat_presence_normalize_room_id((string) ($relayCommand['room_id'] ?? ''), '');
    $connectionRoomId = videochat_presence_normalize_room_id((string) ($presenceConnection['room_id'] ?? ''), '');
    $callId = videochat_realtime_normalize_call_id((string) ($relayCommand['call_id'] ?? ''), '');
    $connectionCallId = videochat_realtime_connection_call_id($presenceConnection);
    if ($roomId === '' || $connectionRoomId === '' || $roomId !== $connectionRoomId || $callId === '' || $connectionCallId === '' || $callId !== $connectionCallId) {
        videochat_realtime_send_gossip_media_relay_error($websocket, $presenceConnection, $relayCommand, 'context_mismatch', $sender);
        return videochat_realtime_secondary_handled_result();
    }

    $userId = (int) ($presenceConnection['user_id'] ?? 0);
    if ($userId <= 0) {
        videochat_realtime_send_gossip_media_relay_error($websocket, $presenceConnection, $relayCommand, 'sender_not_in_room', $sender);
        return videochat_realtime_secondary_handled_result();
    }

    $frame = (array) ($relayCommand['payload'] ?? []);
    $frame['type'] = 'sfu/frame';
    $frame['publisher_id'] = (string) $userId;
    $frame['publisher_user_id'] = (string) $userId;
    $frame['protection_mode'] = 'transport_only';
    unset($frame['protected_frame'], $frame['protectedFrame']);

    $tenantId = is_numeric($presenceConnection['tenant_id'] ?? null) ? (int) $presenceConnection['tenant_id'] : null;
    videochat_gossip_media_relay_broadcast_call_event(
        $presenceState,
        $roomId,
        $callId,
        [
            'type' => VIDEOCHAT_GOSSIP_MEDIA_RELAY_DELIVERY_TYPE,
            'lane' => 'media',
            'room_id' => $roomId,
            'call_id' => $callId,
            'sender' => videochat_signaling_sender_payload($presenceConnection),
            'payload' => $frame,
            'time' => gmdate('c'),
        ],
        (string) ($presenceConnection['connection_id'] ?? ''),
        $sender,
        $tenantId,
        $userId
    );

    return videochat_realtime_secondary_handled_result();
}
