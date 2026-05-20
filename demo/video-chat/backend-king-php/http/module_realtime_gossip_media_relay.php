<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/realtime_signaling.php';
require_once __DIR__ . '/../domain/realtime/realtime_room_snapshot.php';
require_once __DIR__ . '/../domain/realtime/realtime_sfu_store.php';

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
function videochat_gossip_media_relay_decode_binary_client_frame(string $frame, array $presenceConnection = []): array
{
    $unsupported = [
        'ok' => false,
        'type' => '',
        'room_id' => '',
        'call_id' => '',
        'payload' => [],
        'error' => 'unsupported_type',
    ];

    if (!function_exists('videochat_sfu_binary_frame_has_magic') || !videochat_sfu_binary_frame_has_magic($frame)) {
        return $unsupported;
    }

    $boundRoomId = videochat_presence_normalize_room_id((string) ($presenceConnection['room_id'] ?? ''), '');
    $decoded = videochat_sfu_decode_binary_client_frame($frame, $boundRoomId);
    if (!(bool) ($decoded['ok'] ?? false)) {
        return [
            ...$unsupported,
            'type' => VIDEOCHAT_GOSSIP_MEDIA_RELAY_CLIENT_TYPE,
            'error' => (string) ($decoded['error'] ?? 'invalid_binary_envelope'),
        ];
    }

    $payload = is_array($decoded['payload'] ?? null) ? (array) $decoded['payload'] : [];
    $trackId = trim((string) ($payload['track_id'] ?? ($payload['trackId'] ?? '')));
    $dataBinary = $payload['data_binary'] ?? null;
    if ($trackId === '' || !is_string($dataBinary) || $dataBinary === '') {
        return [
            ...$unsupported,
            'type' => VIDEOCHAT_GOSSIP_MEDIA_RELAY_CLIENT_TYPE,
            'error' => 'missing_binary_frame_payload',
        ];
    }

    $connectionCallId = function_exists('videochat_realtime_connection_call_id')
        ? videochat_realtime_connection_call_id($presenceConnection)
        : '';

    return [
        'ok' => true,
        'type' => VIDEOCHAT_GOSSIP_MEDIA_RELAY_CLIENT_TYPE,
        'room_id' => $boundRoomId,
        'call_id' => $connectionCallId,
        'payload' => $payload,
        'error' => '',
    ];
}

function videochat_gossip_media_relay_decode_client_frame(string $frame, array $presenceConnection = []): array
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

    if (function_exists('videochat_sfu_binary_frame_has_magic') && videochat_sfu_binary_frame_has_magic($frame)) {
        return videochat_gossip_media_relay_decode_binary_client_frame($frame, $presenceConnection);
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
    if ($trackId === '') {
        return [
            ...$unsupported,
            'type' => $type,
            'error' => 'missing_frame_payload',
        ];
    }

    return [
        ...$unsupported,
        'type' => $type,
        'error' => 'binary_frame_required',
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
    $signalingBrokerDatabase = null;
    try {
        $signalingBrokerDatabase = $openDatabase();
        videochat_signaling_broker_bootstrap($signalingBrokerDatabase);
        videochat_gossip_media_relay_broker_bootstrap($signalingBrokerDatabase);
    } catch (Throwable) {
        $signalingBrokerDatabase = null;
    }

    try {
        while (true) {
            $frame = @king_client_websocket_receive($websocket, 250);
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

            $presenceConnection['media_relay_socket'] = true;

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

            $relayCommand = videochat_gossip_media_relay_decode_client_frame($frame, $presenceConnection);
            $relayResult = videochat_realtime_handle_gossip_media_relay_command(
                $relayCommand,
                $websocket,
                $presenceState,
                $presenceConnection,
                $signalingBrokerDatabase,
                $openDatabase,
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

function videochat_gossip_media_relay_frame_from_delivery_payload(array $payload): array
{
    $frame = is_array($payload['payload'] ?? null) ? (array) $payload['payload'] : $payload;
    return strtolower(trim((string) ($frame['type'] ?? ''))) === 'sfu/frame' ? $frame : [];
}

function videochat_gossip_media_relay_payload_contains_binary_frame(array $payload): bool
{
    $frame = videochat_gossip_media_relay_frame_from_delivery_payload($payload);
    return is_string($frame['data_binary'] ?? null) && (string) $frame['data_binary'] !== '';
}

function videochat_gossip_media_relay_send_delivery_payload(
    mixed $socket,
    array $payload,
    ?callable $sender = null,
    array $sendContext = []
): bool {
    $frame = videochat_gossip_media_relay_frame_from_delivery_payload($payload);
    if ($frame !== [] && is_string($frame['data_binary'] ?? null) && (string) $frame['data_binary'] !== '') {
        if ($sender !== null) {
            $binaryPayload = videochat_sfu_encode_binary_frame_envelope($frame);
            if (!is_string($binaryPayload) || $binaryPayload === '') {
                return false;
            }
            return videochat_presence_send_frame(
                $socket,
                [
                    'type' => VIDEOCHAT_GOSSIP_MEDIA_RELAY_DELIVERY_TYPE,
                    'lane' => 'media',
                    'payload' => videochat_gossip_media_relay_broker_metadata($frame),
                    'binary_media_required' => true,
                    'binary_payload_bytes' => strlen($binaryPayload),
                ],
                $sender
            );
        }
        return videochat_sfu_send_outbound_message($socket, $frame, [
            'sfu_send_path' => 'gossip_media_binary_relay',
            ...$sendContext,
        ]);
    }

    return videochat_presence_send_frame($socket, $payload, $sender);
}

function videochat_gossip_media_relay_broker_now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function videochat_gossip_media_relay_broker_bootstrap(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS realtime_gossip_media_binary_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id TEXT NOT NULL,
    call_id TEXT NOT NULL,
    event_key TEXT NOT NULL,
    target_user_id INTEGER NOT NULL,
    sender_user_id INTEGER NOT NULL,
    publisher_user_id INTEGER NOT NULL,
    track_id TEXT NOT NULL,
    frame_id TEXT NOT NULL,
    frame_sequence INTEGER NOT NULL,
    metadata_json TEXT NOT NULL,
    payload_binary BLOB NOT NULL,
    created_at_ms INTEGER NOT NULL,
    UNIQUE(room_id, call_id, event_key, target_user_id)
)
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_realtime_gossip_media_binary_events_target ON realtime_gossip_media_binary_events(room_id, call_id, target_user_id, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_realtime_gossip_media_binary_events_created_at ON realtime_gossip_media_binary_events(created_at_ms)');
}

function videochat_gossip_media_relay_broker_event_key(array $frame, string $callId): string
{
    $frameId = trim((string) ($frame['frame_id'] ?? ($frame['frameId'] ?? '')));
    if ($frameId !== '') {
        return 'frame:' . $callId . ':' . $frameId;
    }

    return 'payload:' . hash('sha256', implode('|', [
        $callId,
        (string) ($frame['publisher_user_id'] ?? ''),
        (string) ($frame['track_id'] ?? ''),
        (string) ($frame['frame_sequence'] ?? ''),
        (string) ($frame['timestamp'] ?? ''),
        (string) ($frame['payload_bytes'] ?? strlen((string) ($frame['data_binary'] ?? ''))),
    ]));
}

function videochat_gossip_media_relay_broker_metadata(array $frame): array
{
    unset(
        $frame['data_binary'],
        $frame['dataBinary'],
        $frame['payload'],
        $frame['protected_frame'],
        $frame['protectedFrame']
    );
    $frame['type'] = 'sfu/frame';
    $frame['protection_mode'] = 'transport_only';
    return $frame;
}

function videochat_gossip_media_relay_broker_insert_event(
    PDO $pdo,
    string $roomId,
    string $callId,
    int $targetUserId,
    array $event
): bool {
    $frame = videochat_gossip_media_relay_frame_from_delivery_payload($event);
    $payloadBinary = $frame['data_binary'] ?? null;
    if ($targetUserId <= 0 || !is_string($payloadBinary) || $payloadBinary === '') {
        return false;
    }

    $normalizedRoomId = videochat_presence_normalize_room_storage_key($roomId, '');
    $normalizedCallId = videochat_realtime_normalize_call_id($callId, '');
    if ($normalizedRoomId === '' || $normalizedCallId === '') {
        return false;
    }

    $metadata = videochat_gossip_media_relay_broker_metadata($frame);
    $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($metadataJson) || $metadataJson === '') {
        return false;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO realtime_gossip_media_binary_events(
    room_id,
    call_id,
    event_key,
    target_user_id,
    sender_user_id,
    publisher_user_id,
    track_id,
    frame_id,
    frame_sequence,
    metadata_json,
    payload_binary,
    created_at_ms
) VALUES(
    :room_id,
    :call_id,
    :event_key,
    :target_user_id,
    :sender_user_id,
    :publisher_user_id,
    :track_id,
    :frame_id,
    :frame_sequence,
    :metadata_json,
    :payload_binary,
    :created_at_ms
)
SQL
    );
    $statement->bindValue(':room_id', $normalizedRoomId, PDO::PARAM_STR);
    $statement->bindValue(':call_id', $normalizedCallId, PDO::PARAM_STR);
    $statement->bindValue(':event_key', videochat_gossip_media_relay_broker_event_key($frame, $normalizedCallId), PDO::PARAM_STR);
    $statement->bindValue(':target_user_id', $targetUserId, PDO::PARAM_INT);
    $statement->bindValue(':sender_user_id', (int) (($event['sender'] ?? [])['user_id'] ?? 0), PDO::PARAM_INT);
    $statement->bindValue(':publisher_user_id', (int) ($frame['publisher_user_id'] ?? 0), PDO::PARAM_INT);
    $statement->bindValue(':track_id', (string) ($frame['track_id'] ?? ''), PDO::PARAM_STR);
    $statement->bindValue(':frame_id', (string) ($frame['frame_id'] ?? ''), PDO::PARAM_STR);
    $statement->bindValue(':frame_sequence', (int) ($frame['frame_sequence'] ?? 0), PDO::PARAM_INT);
    $statement->bindValue(':metadata_json', $metadataJson, PDO::PARAM_STR);
    $statement->bindValue(':payload_binary', $payloadBinary, PDO::PARAM_LOB);
    $statement->bindValue(':created_at_ms', videochat_gossip_media_relay_broker_now_ms(), PDO::PARAM_INT);
    return $statement->execute() === true;
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_gossip_media_relay_broker_fetch_events_since(
    PDO $pdo,
    string $roomId,
    string $callId,
    int $targetUserId,
    int $lastEventId
): array {
    $normalizedRoomId = videochat_presence_normalize_room_storage_key($roomId, '');
    $normalizedCallId = videochat_realtime_normalize_call_id($callId, '');
    if ($normalizedRoomId === '' || $normalizedCallId === '' || $targetUserId <= 0) {
        return [];
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT id, metadata_json, payload_binary
FROM realtime_gossip_media_binary_events
WHERE room_id = :room_id
  AND call_id = :call_id
  AND target_user_id = :target_user_id
  AND id > :last_event_id
ORDER BY id ASC
LIMIT 24
SQL
    );
    $statement->execute([
        ':room_id' => $normalizedRoomId,
        ':call_id' => $normalizedCallId,
        ':target_user_id' => $targetUserId,
        ':last_event_id' => max(0, $lastEventId),
    ]);

    $events = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (is_array($row)) {
            $events[] = $row;
        }
    }
    return $events;
}

function videochat_gossip_media_relay_broker_poll(
    PDO $pdo,
    mixed $websocket,
    string $roomId,
    string $callId,
    int $targetUserId,
    int &$lastEventId
): void {
    foreach (videochat_gossip_media_relay_broker_fetch_events_since($pdo, $roomId, $callId, $targetUserId, $lastEventId) as $row) {
        $lastEventId = max($lastEventId, (int) ($row['id'] ?? 0));
        $frame = json_decode((string) ($row['metadata_json'] ?? ''), true);
        if (!is_array($frame)) {
            continue;
        }
        $payloadBinary = $row['payload_binary'] ?? null;
        if (!is_string($payloadBinary) || $payloadBinary === '') {
            continue;
        }
        $frame['data_binary'] = $payloadBinary;
        $frame['protection_mode'] = 'transport_only';
        $frame['payload_bytes'] = strlen($payloadBinary);
        videochat_sfu_send_outbound_message($websocket, $frame, [
            'sfu_send_path' => 'gossip_media_binary_broker',
        ]);
    }
}

function videochat_gossip_media_relay_broker_cleanup(PDO $pdo): void
{
    $statement = $pdo->prepare('DELETE FROM realtime_gossip_media_binary_events WHERE created_at_ms < :cutoff_ms');
    $statement->execute([':cutoff_ms' => videochat_gossip_media_relay_broker_now_ms() - 15_000]);
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
    return (int) videochat_gossip_media_relay_broadcast_call_event_with_targets(
        $presenceState,
        $roomId,
        $callId,
        $payload,
        $excludeConnectionId,
        $sender,
        $tenantId,
        $excludeUserId
    )['sent_count'];
}

/**
 * @return array{sent_count: int, delivered_user_ids: array<int, true>}
 */
function videochat_gossip_media_relay_broadcast_call_event_with_targets(
    array $presenceState,
    string $roomId,
    string $callId,
    array $payload,
    string $excludeConnectionId,
    ?callable $sender = null,
    ?int $tenantId = null,
    ?int $excludeUserId = null
): array {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $normalizedCallId = videochat_realtime_normalize_call_id($callId, '');
    if ($normalizedRoomId === '' || $normalizedCallId === '') {
        return ['sent_count' => 0, 'delivered_user_ids' => []];
    }

    $roomConnections = $presenceState['rooms'][videochat_presence_room_key($normalizedRoomId, $tenantId)] ?? null;
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
            $connectionTenantId = is_numeric($connection['tenant_id'] ?? null) ? (int) $connection['tenant_id'] : null;
            if ($tenantId !== null && $connectionTenantId !== $tenantId) {
                continue;
            }
            $targetCallId = videochat_realtime_connection_call_id($connection);
            if ($targetCallId !== '' && $targetCallId !== $normalizedCallId) {
                continue;
            }
            if (videochat_gossip_media_relay_send_delivery_payload(
                $connection['socket'] ?? null,
                $payload,
                $sender,
                ['gossip_relay_target' => 'dedicated_media_relay_socket']
            )) {
                $sentCount++;
                if ($targetUserId > 0) {
                    $relayDeliveredUserIds[$targetUserId] = true;
                }
            }
        }
    }

    if (!is_array($roomConnections) || $roomConnections === []) {
        return ['sent_count' => $sentCount, 'delivered_user_ids' => $relayDeliveredUserIds];
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
        $targetCallId = videochat_realtime_connection_call_id($connection);
        if ($targetCallId !== '' && $targetCallId !== $normalizedCallId) {
            continue;
        }

        if (videochat_gossip_media_relay_send_delivery_payload(
            $connection['socket'] ?? null,
            $payload,
            $sender,
            ['gossip_relay_target' => 'call_control_socket']
        )) {
            $sentCount++;
        }
    }

    return ['sent_count' => $sentCount, 'delivered_user_ids' => $relayDeliveredUserIds];
}

function videochat_gossip_media_relay_broker_cross_worker_event(
    ?PDO $signalingBrokerDatabase,
    callable $openDatabase,
    array $presenceConnection,
    string $roomId,
    string $callId,
    array $event,
    array $localDeliveredUserIds,
    int $publisherUserId
): int {
    if (!$signalingBrokerDatabase instanceof PDO) {
        return 0;
    }

    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    $normalizedCallId = videochat_realtime_normalize_call_id($callId, '');
    if ($normalizedRoomId === '' || $normalizedCallId === '') {
        return 0;
    }

    try {
        $participants = videochat_realtime_db_room_participants($openDatabase, [
            ...$presenceConnection,
            'room_id' => $normalizedRoomId,
            'active_call_id' => $normalizedCallId,
            'requested_call_id' => $normalizedCallId,
        ]);
    } catch (Throwable) {
        return 0;
    }

    $tenantId = is_numeric($presenceConnection['tenant_id'] ?? null) ? (int) $presenceConnection['tenant_id'] : null;
    $roomKey = videochat_presence_room_key($normalizedRoomId, $tenantId);
    $brokeredCount = 0;
    $seenUserIds = [];
    foreach ($participants as $participant) {
        $participantUser = is_array($participant['user'] ?? null) ? (array) $participant['user'] : [];
        $targetUserId = (int) (
            $participant['user_id']
            ?? $participant['userId']
            ?? $participantUser['id']
            ?? 0
        );
        if ($targetUserId <= 0 || $targetUserId === $publisherUserId) {
            continue;
        }
        if (($localDeliveredUserIds[$targetUserId] ?? false) === true) {
            continue;
        }
        if (($seenUserIds[$targetUserId] ?? false) === true) {
            continue;
        }
        $seenUserIds[$targetUserId] = true;
        $brokered = videochat_gossip_media_relay_payload_contains_binary_frame($event)
            ? videochat_gossip_media_relay_broker_insert_event($signalingBrokerDatabase, $roomKey, $normalizedCallId, $targetUserId, $event)
            : videochat_signaling_broker_insert_event($signalingBrokerDatabase, $roomKey, $targetUserId, $event);
        if ($brokered) {
            $brokeredCount++;
        }
    }

    return $brokeredCount;
}

function videochat_realtime_handle_gossip_media_relay_command(
    array $relayCommand,
    mixed $websocket,
    array &$presenceState,
    array $presenceConnection,
    mixed $signalingBrokerDatabase = null,
    ?callable $openDatabase = null,
    ?callable $sender = null
): ?array {
    if ($signalingBrokerDatabase !== null && !$signalingBrokerDatabase instanceof PDO) {
        if ($sender === null && $openDatabase === null && is_callable($signalingBrokerDatabase)) {
            $sender = $signalingBrokerDatabase;
        }
        $signalingBrokerDatabase = null;
    }

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

    $connectionRoomId = videochat_presence_normalize_room_id((string) ($presenceConnection['room_id'] ?? ''), '');
    $connectionCallId = videochat_realtime_connection_call_id($presenceConnection);
    $roomId = videochat_presence_normalize_room_id((string) ($relayCommand['room_id'] ?? ''), '') ?: $connectionRoomId;
    $callId = videochat_realtime_normalize_call_id((string) ($relayCommand['call_id'] ?? ''), '') ?: $connectionCallId;
    if ($roomId === '' || $callId === '') {
        videochat_realtime_send_gossip_media_relay_error($websocket, $presenceConnection, $relayCommand, 'missing_relay_context', $sender);
        return videochat_realtime_secondary_handled_result();
    }

    $userId = (int) ($presenceConnection['user_id'] ?? 0);
    $publisherId = $userId > 0 ? (string) $userId : (string) ($presenceConnection['connection_id'] ?? 'relay');

    $frame = (array) ($relayCommand['payload'] ?? []);
    $frame['type'] = 'sfu/frame';
    $frame['publisher_id'] = $publisherId;
    $frame['publisher_user_id'] = $userId > 0 ? (string) $userId : $publisherId;
    $frame['protection_mode'] = 'transport_only';
    unset($frame['protected_frame'], $frame['protectedFrame']);

    $tenantId = is_numeric($presenceConnection['tenant_id'] ?? null) ? (int) $presenceConnection['tenant_id'] : null;
    $event = [
        'type' => VIDEOCHAT_GOSSIP_MEDIA_RELAY_DELIVERY_TYPE,
        'lane' => 'media',
        'room_id' => $roomId,
        'call_id' => $callId,
        'sender' => videochat_signaling_sender_payload($presenceConnection),
        'payload' => $frame,
        'time' => gmdate('c'),
    ];
    $localDelivery = videochat_gossip_media_relay_broadcast_call_event_with_targets(
        $presenceState,
        $roomId,
        $callId,
        $event,
        (string) ($presenceConnection['connection_id'] ?? ''),
        $sender,
        $tenantId,
        $userId
    );
    if ($openDatabase !== null) {
        videochat_gossip_media_relay_broker_cross_worker_event(
            $signalingBrokerDatabase,
            $openDatabase,
            $presenceConnection,
            $roomId,
            $callId,
            $event,
            (array) ($localDelivery['delivered_user_ids'] ?? []),
            $userId
        );
    }

    return videochat_realtime_secondary_handled_result();
}
