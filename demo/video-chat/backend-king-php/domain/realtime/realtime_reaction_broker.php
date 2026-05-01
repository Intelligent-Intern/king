<?php

declare(strict_types=1);

function videochat_reaction_broker_now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function videochat_reaction_broker_bootstrap(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS realtime_reaction_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id TEXT NOT NULL,
    event_key TEXT NOT NULL,
    sender_user_id INTEGER NOT NULL,
    event_json TEXT NOT NULL,
    created_at_ms INTEGER NOT NULL,
    UNIQUE(room_id, event_key)
)
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_realtime_reaction_events_room_id ON realtime_reaction_events(room_id, id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_realtime_reaction_events_created_at ON realtime_reaction_events(created_at_ms)');
}

function videochat_reaction_broker_event_key(array $event): string
{
    $type = strtolower(trim((string) ($event['type'] ?? '')));
    if ($type === 'reaction/event') {
        $reactionId = trim((string) (($event['reaction'] ?? [])['id'] ?? ''));
        if ($reactionId !== '') {
            return 'event:' . $reactionId;
        }
    }

    if ($type === 'reaction/batch') {
        $batchId = trim((string) (($event['batch'] ?? [])['id'] ?? ''));
        if ($batchId !== '') {
            return 'batch:' . $batchId;
        }
    }

    return 'payload:' . hash('sha256', json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: serialize($event));
}

function videochat_reaction_broker_insert_event(PDO $pdo, string $roomId, array $event): bool
{
    $eventJson = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($eventJson) || $eventJson === '') {
        return false;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO realtime_reaction_events(room_id, event_key, sender_user_id, event_json, created_at_ms)
VALUES(:room_id, :event_key, :sender_user_id, :event_json, :created_at_ms)
SQL
    );
    $statement->execute([
        ':room_id' => videochat_presence_normalize_room_id($roomId),
        ':event_key' => videochat_reaction_broker_event_key($event),
        ':sender_user_id' => (int) (($event['sender'] ?? [])['user_id'] ?? 0),
        ':event_json' => $eventJson,
        ':created_at_ms' => videochat_reaction_broker_now_ms(),
    ]);

    return true;
}

function videochat_reaction_broker_latest_event_id(PDO $pdo, string $roomId): int
{
    $statement = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM realtime_reaction_events WHERE room_id = :room_id');
    $statement->execute([':room_id' => videochat_presence_normalize_room_id($roomId)]);
    return (int) ($statement->fetchColumn() ?: 0);
}

/**
 * @return array<int, array{id: int, event_json: string}>
 */
function videochat_reaction_broker_fetch_events_since(
    PDO $pdo,
    string $roomId,
    int $afterId,
    int $excludeSenderUserId,
    int $limit = 50
): array {
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT id, event_json
FROM realtime_reaction_events
WHERE room_id = :room_id
  AND id > :after_id
  AND sender_user_id <> :sender_user_id
ORDER BY id ASC
LIMIT :limit
SQL
    );
    $statement->bindValue(':room_id', videochat_presence_normalize_room_id($roomId), PDO::PARAM_STR);
    $statement->bindValue(':after_id', max(0, $afterId), PDO::PARAM_INT);
    $statement->bindValue(':sender_user_id', max(0, $excludeSenderUserId), PDO::PARAM_INT);
    $statement->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
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

function videochat_reaction_broker_cleanup(PDO $pdo): void
{
    $statement = $pdo->prepare('DELETE FROM realtime_reaction_events WHERE created_at_ms < :cutoff_ms');
    $statement->execute([':cutoff_ms' => videochat_reaction_broker_now_ms() - 60_000]);
}

function videochat_reaction_broker_poll(
    PDO $pdo,
    mixed $websocket,
    string $roomId,
    int $viewerUserId,
    int &$lastEventId
): void {
    foreach (videochat_reaction_broker_fetch_events_since($pdo, $roomId, $lastEventId, $viewerUserId) as $row) {
        $lastEventId = max($lastEventId, (int) ($row['id'] ?? 0));
        $payload = json_decode((string) ($row['event_json'] ?? ''), true);
        if (!is_array($payload)) {
            continue;
        }
        videochat_presence_send_frame($websocket, $payload);
    }
}
