<?php

declare(strict_types=1);

function videochat_signaling_message_id(?int $nowUnixMs = null): string
{
    $effectiveNowMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);

    try {
        $suffix = bin2hex(random_bytes(8));
    } catch (Throwable) {
        $suffix = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 16);
    }

    return 'signal_' . $effectiveNowMs . '_' . $suffix;
}

/**
 * @return array{
 *   ok: bool,
 *   type: string,
 *   target_user_id: int,
 *   payload: mixed,
 *   error: string
 * }
 */
function videochat_signaling_decode_client_frame(string $frame): array
{
    $decoded = json_decode($frame, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'type' => '',
            'target_user_id' => 0,
            'payload' => null,
            'error' => 'invalid_json',
        ];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type === '') {
        return [
            'ok' => false,
            'type' => '',
            'target_user_id' => 0,
            'payload' => null,
            'error' => 'missing_type',
        ];
    }

    if (!in_array($type, ['call/offer', 'call/answer', 'call/ice', 'call/hangup'], true)) {
        return [
            'ok' => false,
            'type' => $type,
            'target_user_id' => 0,
            'payload' => null,
            'error' => 'unsupported_type',
        ];
    }

    $rawTargetUserId = $decoded['target_user_id'] ?? ($decoded['targetUserId'] ?? null);
    if ($rawTargetUserId === null) {
        return [
            'ok' => false,
            'type' => $type,
            'target_user_id' => 0,
            'payload' => null,
            'error' => 'missing_target_user_id',
        ];
    }

    $targetUserId = 0;
    if (is_int($rawTargetUserId)) {
        $targetUserId = $rawTargetUserId;
    } elseif (is_string($rawTargetUserId)) {
        $candidate = trim($rawTargetUserId);
        if ($candidate !== '' && preg_match('/^[0-9]+$/', $candidate) === 1) {
            $targetUserId = (int) $candidate;
        }
    }

    if ($targetUserId <= 0) {
        return [
            'ok' => false,
            'type' => $type,
            'target_user_id' => 0,
            'payload' => null,
            'error' => 'invalid_target_user_id',
        ];
    }

    $payload = $decoded['payload'] ?? null;
    if ($payload !== null && !is_scalar($payload) && !is_array($payload)) {
        return [
            'ok' => false,
            'type' => $type,
            'target_user_id' => 0,
            'payload' => null,
            'error' => 'invalid_payload',
        ];
    }

    return [
        'ok' => true,
        'type' => $type,
        'target_user_id' => $targetUserId,
        'payload' => $payload,
        'error' => '',
    ];
}

function videochat_signaling_sender_payload(array $connection): array
{
    return [
        'user_id' => (int) ($connection['user_id'] ?? 0),
        'display_name' => (string) ($connection['display_name'] ?? ''),
        'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? 'user')),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_signaling_target_connections(array $presenceState, string $roomId, int $targetUserId): array
{
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId);
    $roomConnections = $presenceState['rooms'][$normalizedRoomId] ?? null;
    if (!is_array($roomConnections) || $roomConnections === []) {
        return [];
    }

    $targets = [];
    foreach ($roomConnections as $connectionId => $_socket) {
        if (!is_string($connectionId) || $connectionId === '') {
            continue;
        }

        $connection = $presenceState['connections'][$connectionId] ?? null;
        if (!is_array($connection)) {
            continue;
        }

        if ((int) ($connection['user_id'] ?? 0) !== $targetUserId) {
            continue;
        }

        $targets[] = $connection;
    }

    return $targets;
}

/**
 * @return array{
 *   ok: bool,
 *   error: string,
 *   event: array<string, mixed>|null,
 *   sent_count: int,
 *   target_user_id: int
 * }
 */
function videochat_signaling_publish(
    array $presenceState,
    array $connection,
    array $command,
    ?callable $sender = null,
    ?int $nowUnixMs = null,
    ?callable $broker = null
): array {
    if (!(bool) ($command['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'invalid_command',
            'event' => null,
            'sent_count' => 0,
            'target_user_id' => 0,
        ];
    }

    $senderUserId = (int) ($connection['user_id'] ?? 0);
    if ($senderUserId <= 0) {
        return [
            'ok' => false,
            'error' => 'invalid_sender',
            'event' => null,
            'sent_count' => 0,
            'target_user_id' => 0,
        ];
    }

    $targetUserId = (int) ($command['target_user_id'] ?? 0);
    if ($targetUserId <= 0 || $targetUserId === $senderUserId) {
        return [
            'ok' => false,
            'error' => 'invalid_target_user_id',
            'event' => null,
            'sent_count' => 0,
            'target_user_id' => $targetUserId,
        ];
    }

    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? 'lobby'));
    $connectionId = trim((string) ($connection['connection_id'] ?? ''));
    $roomConnections = $presenceState['rooms'][$roomId] ?? null;
    if (
        $connectionId === ''
        || !is_array($roomConnections)
        || !array_key_exists($connectionId, $roomConnections)
    ) {
        return [
            'ok' => false,
            'error' => 'sender_not_in_room',
            'event' => null,
            'sent_count' => 0,
            'target_user_id' => $targetUserId,
        ];
    }

    $effectiveNowMs = is_int($nowUnixMs) && $nowUnixMs > 0
        ? $nowUnixMs
        : (int) floor(microtime(true) * 1000);
    $effectiveNowIso = gmdate('c', (int) floor($effectiveNowMs / 1000));

    $event = [
        'type' => (string) ($command['type'] ?? ''),
        'room_id' => $roomId,
        'target_user_id' => $targetUserId,
        'sender' => videochat_signaling_sender_payload($connection),
        'payload' => $command['payload'] ?? null,
        'signal' => [
            'id' => videochat_signaling_message_id($effectiveNowMs),
            'server_unix_ms' => $effectiveNowMs,
            'server_time' => $effectiveNowIso,
        ],
        'time' => $effectiveNowIso,
    ];

    $targetConnections = videochat_signaling_target_connections($presenceState, $roomId, $targetUserId);
    if ($targetConnections === []) {
        if ($broker !== null) {
            try {
                if ($broker($roomId, $targetUserId, $event) === true) {
                    return [
                        'ok' => true,
                        'error' => '',
                        'event' => $event,
                        'sent_count' => 0,
                        'target_user_id' => $targetUserId,
                    ];
                }
            } catch (Throwable) {
                // Fall through to the fail-closed local signaling error below.
            }
        }

        return [
            'ok' => false,
            'error' => 'target_not_in_room',
            'event' => $event,
            'sent_count' => 0,
            'target_user_id' => $targetUserId,
        ];
    }

    $sentCount = 0;
    foreach ($targetConnections as $targetConnection) {
        if (videochat_presence_send_frame($targetConnection['socket'] ?? null, $event, $sender)) {
            $sentCount++;
        }
    }

    if ($sentCount <= 0) {
        return [
            'ok' => false,
            'error' => 'target_delivery_failed',
            'event' => $event,
            'sent_count' => 0,
            'target_user_id' => $targetUserId,
        ];
    }

    return [
        'ok' => true,
        'error' => '',
        'event' => $event,
        'sent_count' => $sentCount,
        'target_user_id' => $targetUserId,
    ];
}

function videochat_signaling_broker_now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function videochat_signaling_broker_bootstrap(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS realtime_signaling_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id TEXT NOT NULL,
    event_key TEXT NOT NULL,
    target_user_id INTEGER NOT NULL,
    sender_user_id INTEGER NOT NULL,
    event_json TEXT NOT NULL,
    created_at_ms INTEGER NOT NULL,
    UNIQUE(room_id, event_key, target_user_id)
)
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_realtime_signaling_events_target ON realtime_signaling_events(room_id, target_user_id, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_realtime_signaling_events_created_at ON realtime_signaling_events(created_at_ms)');
}

function videochat_signaling_broker_event_key(array $event): string
{
    $signalId = trim((string) (($event['signal'] ?? [])['id'] ?? ''));
    if ($signalId !== '') {
        return 'signal:' . $signalId;
    }

    return 'payload:' . hash('sha256', json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: serialize($event));
}

function videochat_signaling_broker_insert_event(PDO $pdo, string $roomId, int $targetUserId, array $event): bool
{
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedRoomId === '' || $targetUserId <= 0) {
        return false;
    }

    $eventJson = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($eventJson) || $eventJson === '') {
        return false;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO realtime_signaling_events(room_id, event_key, target_user_id, sender_user_id, event_json, created_at_ms)
VALUES(:room_id, :event_key, :target_user_id, :sender_user_id, :event_json, :created_at_ms)
SQL
    );
    $statement->execute([
        ':room_id' => $normalizedRoomId,
        ':event_key' => videochat_signaling_broker_event_key($event),
        ':target_user_id' => $targetUserId,
        ':sender_user_id' => (int) (($event['sender'] ?? [])['user_id'] ?? 0),
        ':event_json' => $eventJson,
        ':created_at_ms' => videochat_signaling_broker_now_ms(),
    ]);

    return true;
}

function videochat_signaling_broker_latest_event_id(PDO $pdo, string $roomId, int $targetUserId): int
{
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT COALESCE(MAX(id), 0)
FROM realtime_signaling_events
WHERE room_id = :room_id
  AND target_user_id = :target_user_id
SQL
    );
    $statement->execute([
        ':room_id' => videochat_presence_normalize_room_id($roomId),
        ':target_user_id' => max(0, $targetUserId),
    ]);
    return (int) ($statement->fetchColumn() ?: 0);
}

function videochat_signaling_broker_latest_event_id_before(
    PDO $pdo,
    string $roomId,
    int $targetUserId,
    int $beforeCreatedAtMs
): int {
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT COALESCE(MAX(id), 0)
FROM realtime_signaling_events
WHERE room_id = :room_id
  AND target_user_id = :target_user_id
  AND created_at_ms < :before_created_at_ms
SQL
    );
    $statement->execute([
        ':room_id' => videochat_presence_normalize_room_id($roomId),
        ':target_user_id' => max(0, $targetUserId),
        ':before_created_at_ms' => max(0, $beforeCreatedAtMs),
    ]);
    return (int) ($statement->fetchColumn() ?: 0);
}

/**
 * @return array<int, array{id: int, event_json: string}>
 */
function videochat_signaling_broker_fetch_events_since(
    PDO $pdo,
    string $roomId,
    int $targetUserId,
    int $afterId,
    int $limit = 100
): array {
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT id, event_json
FROM realtime_signaling_events
WHERE room_id = :room_id
  AND target_user_id = :target_user_id
  AND id > :after_id
ORDER BY id ASC
LIMIT :limit
SQL
    );
    $statement->bindValue(':room_id', videochat_presence_normalize_room_id($roomId), PDO::PARAM_STR);
    $statement->bindValue(':target_user_id', max(0, $targetUserId), PDO::PARAM_INT);
    $statement->bindValue(':after_id', max(0, $afterId), PDO::PARAM_INT);
    $statement->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $statement->execute();

    $events = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $events[] = [
            'id' => (int) ($row['id'] ?? 0),
            'event_json' => (string) ($row['event_json'] ?? ''),
        ];
    }

    return $events;
}

function videochat_signaling_broker_cleanup(PDO $pdo): void
{
    $statement = $pdo->prepare('DELETE FROM realtime_signaling_events WHERE created_at_ms < :cutoff_ms');
    $statement->execute([':cutoff_ms' => videochat_signaling_broker_now_ms() - 60_000]);
}

function videochat_signaling_broker_poll(
    PDO $pdo,
    mixed $websocket,
    string $roomId,
    int $targetUserId,
    int &$lastEventId,
    ?callable $sender = null
): void {
    foreach (videochat_signaling_broker_fetch_events_since($pdo, $roomId, $targetUserId, $lastEventId) as $row) {
        $lastEventId = max($lastEventId, (int) ($row['id'] ?? 0));
        $payload = json_decode((string) ($row['event_json'] ?? ''), true);
        if (!is_array($payload)) {
            continue;
        }
        videochat_presence_send_frame($websocket, $payload, $sender);
    }
}
