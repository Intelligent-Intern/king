<?php

declare(strict_types=1);

function videochat_realtime_call_presence_target(array $connection): array
{
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $callId = strtolower(trim((string) ($connection['active_call_id'] ?? '')));
    $userId = (int) ($connection['user_id'] ?? 0);
    $callRole = videochat_normalize_call_participant_role((string) ($connection['call_role'] ?? 'participant'));

    if ($roomId === '' || $roomId === videochat_realtime_waiting_room_id() || $callId === '' || $userId <= 0) {
        return [
            'call_id' => '',
            'room_id' => '',
            'user_id' => 0,
            'call_role' => 'participant',
        ];
    }

    return [
        'call_id' => $callId,
        'room_id' => $roomId,
        'user_id' => $userId,
        'call_role' => $callRole,
    ];
}

function videochat_realtime_connection_call_id(array $connection): string
{
    $activeCallId = videochat_realtime_normalize_call_id((string) ($connection['active_call_id'] ?? ''), '');
    if ($activeCallId !== '') {
        return $activeCallId;
    }

    return videochat_realtime_normalize_call_id((string) ($connection['requested_call_id'] ?? ''), '');
}

function videochat_realtime_presence_db_now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function videochat_realtime_presence_db_ttl_ms(): int
{
    return 45_000;
}

function videochat_realtime_presence_db_bootstrap(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS realtime_presence_connections (
    connection_id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL,
    room_id TEXT NOT NULL,
    call_id TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    display_name TEXT NOT NULL,
    role TEXT NOT NULL,
    call_role TEXT NOT NULL,
    connected_at TEXT NOT NULL,
    last_seen_at_ms INTEGER NOT NULL
)
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_realtime_presence_connections_room ON realtime_presence_connections(call_id, room_id, last_seen_at_ms)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_realtime_presence_connections_user ON realtime_presence_connections(call_id, room_id, user_id, last_seen_at_ms)');
}

function videochat_realtime_presence_db_prune(PDO $pdo, ?int $nowMs = null): void
{
    $effectiveNowMs = is_int($nowMs) && $nowMs > 0 ? $nowMs : videochat_realtime_presence_db_now_ms();
    $statement = $pdo->prepare('DELETE FROM realtime_presence_connections WHERE last_seen_at_ms < :cutoff_ms');
    $statement->execute([
        ':cutoff_ms' => $effectiveNowMs - (videochat_realtime_presence_db_ttl_ms() * 2),
    ]);
}

function videochat_realtime_presence_db_upsert(PDO $pdo, array $connection, ?int $nowMs = null): bool
{
    $target = videochat_realtime_call_presence_target($connection);
    $callId = (string) ($target['call_id'] ?? '');
    $roomId = (string) ($target['room_id'] ?? '');
    $userId = (int) ($target['user_id'] ?? 0);
    $connectionId = trim((string) ($connection['connection_id'] ?? ''));
    if ($callId === '' || $roomId === '' || $userId <= 0 || $connectionId === '') {
        return false;
    }

    $effectiveNowMs = is_int($nowMs) && $nowMs > 0 ? $nowMs : videochat_realtime_presence_db_now_ms();
    videochat_realtime_presence_db_bootstrap($pdo);
    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO realtime_presence_connections(
    connection_id,
    session_id,
    room_id,
    call_id,
    user_id,
    display_name,
    role,
    call_role,
    connected_at,
    last_seen_at_ms
) VALUES (
    :connection_id,
    :session_id,
    :room_id,
    :call_id,
    :user_id,
    :display_name,
    :role,
    :call_role,
    :connected_at,
    :last_seen_at_ms
)
ON CONFLICT(connection_id) DO UPDATE SET
    session_id = excluded.session_id,
    room_id = excluded.room_id,
    call_id = excluded.call_id,
    user_id = excluded.user_id,
    display_name = excluded.display_name,
    role = excluded.role,
    call_role = excluded.call_role,
    last_seen_at_ms = excluded.last_seen_at_ms
SQL
    );
    $statement->execute([
        ':connection_id' => $connectionId,
        ':session_id' => trim((string) ($connection['session_id'] ?? '')),
        ':room_id' => $roomId,
        ':call_id' => $callId,
        ':user_id' => $userId,
        ':display_name' => trim((string) ($connection['display_name'] ?? '')) ?: ('User ' . $userId),
        ':role' => videochat_normalize_role_slug((string) ($connection['role'] ?? 'user')),
        ':call_role' => videochat_normalize_call_participant_role((string) ($connection['call_role'] ?? 'participant')),
        ':connected_at' => trim((string) ($connection['connected_at'] ?? '')) ?: gmdate('c'),
        ':last_seen_at_ms' => $effectiveNowMs,
    ]);
    videochat_realtime_presence_db_prune($pdo, $effectiveNowMs);

    return true;
}

function videochat_realtime_touch_call_presence(callable $openDatabase, array $connection): void
{
    try {
        videochat_realtime_presence_db_upsert($openDatabase(), $connection);
    } catch (Throwable) {
        return;
    }
}

function videochat_realtime_remove_call_presence(callable $openDatabase, array $connection): void
{
    $connectionId = trim((string) ($connection['connection_id'] ?? ''));
    if ($connectionId === '') {
        return;
    }

    try {
        $pdo = $openDatabase();
        videochat_realtime_presence_db_bootstrap($pdo);
        $statement = $pdo->prepare('DELETE FROM realtime_presence_connections WHERE connection_id = :connection_id');
        $statement->execute([':connection_id' => $connectionId]);
    } catch (Throwable) {
        return;
    }
}

function videochat_realtime_presence_db_has_room_membership(
    PDO $pdo,
    string $roomId,
    string $callId,
    int $userId,
    string $excludeConnectionId = ''
): bool {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    $normalizedCallId = videochat_realtime_normalize_call_id($callId, '');
    if ($normalizedRoomId === '' || $normalizedCallId === '' || $userId <= 0) {
        return false;
    }

    videochat_realtime_presence_db_bootstrap($pdo);
    $excludeSql = '';
    $params = [
        ':room_id' => $normalizedRoomId,
        ':call_id' => $normalizedCallId,
        ':user_id' => $userId,
        ':cutoff_ms' => videochat_realtime_presence_db_now_ms() - videochat_realtime_presence_db_ttl_ms(),
    ];
    $trimmedExcludeConnectionId = trim($excludeConnectionId);
    if ($trimmedExcludeConnectionId !== '') {
        $excludeSql = ' AND connection_id <> :exclude_connection_id';
        $params[':exclude_connection_id'] = $trimmedExcludeConnectionId;
    }

    $statement = $pdo->prepare(
        <<<SQL
SELECT COUNT(*)
FROM realtime_presence_connections
WHERE call_id = :call_id
  AND room_id = :room_id
  AND user_id = :user_id
  AND last_seen_at_ms >= :cutoff_ms
{$excludeSql}
SQL
    );
    $statement->execute($params);

    return (int) ($statement->fetchColumn() ?: 0) > 0;
}
