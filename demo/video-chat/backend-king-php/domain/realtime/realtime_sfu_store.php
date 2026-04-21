<?php

declare(strict_types=1);

function videochat_sfu_now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function videochat_sfu_bootstrap(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sfu_publishers (
    room_id TEXT NOT NULL,
    publisher_id TEXT NOT NULL,
    user_id TEXT NOT NULL,
    user_name TEXT NOT NULL,
    updated_at_ms INTEGER NOT NULL,
    PRIMARY KEY(room_id, publisher_id)
)
SQL
    );
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sfu_tracks (
    room_id TEXT NOT NULL,
    publisher_id TEXT NOT NULL,
    track_id TEXT NOT NULL,
    kind TEXT NOT NULL,
    label TEXT NOT NULL,
    updated_at_ms INTEGER NOT NULL,
    PRIMARY KEY(room_id, publisher_id, track_id)
)
SQL
    );
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sfu_frames (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id TEXT NOT NULL,
    publisher_id TEXT NOT NULL,
    publisher_user_id TEXT NOT NULL,
    track_id TEXT NOT NULL,
    timestamp INTEGER NOT NULL,
    frame_type TEXT NOT NULL,
    data_json TEXT NOT NULL,
    created_at_ms INTEGER NOT NULL
)
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sfu_frames_room_id ON sfu_frames(room_id, id)');
}

function videochat_sfu_upsert_publisher(PDO $pdo, string $roomId, string $publisherId, string $userId, string $userName): void
{
    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO sfu_publishers(room_id, publisher_id, user_id, user_name, updated_at_ms)
VALUES(:room_id, :publisher_id, :user_id, :user_name, :updated_at_ms)
ON CONFLICT(room_id, publisher_id) DO UPDATE SET
    user_id = excluded.user_id,
    user_name = excluded.user_name,
    updated_at_ms = excluded.updated_at_ms
SQL
    );
    $statement->execute([
        ':room_id' => $roomId,
        ':publisher_id' => $publisherId,
        ':user_id' => $userId,
        ':user_name' => $userName,
        ':updated_at_ms' => videochat_sfu_now_ms(),
    ]);
}

function videochat_sfu_remove_publisher(PDO $pdo, string $roomId, string $publisherId): void
{
    $deleteTracks = $pdo->prepare('DELETE FROM sfu_tracks WHERE room_id = :room_id AND publisher_id = :publisher_id');
    $deleteTracks->execute([':room_id' => $roomId, ':publisher_id' => $publisherId]);
    $deletePublisher = $pdo->prepare('DELETE FROM sfu_publishers WHERE room_id = :room_id AND publisher_id = :publisher_id');
    $deletePublisher->execute([':room_id' => $roomId, ':publisher_id' => $publisherId]);
}

function videochat_sfu_upsert_track(PDO $pdo, string $roomId, string $publisherId, string $trackId, string $kind, string $label): void
{
    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO sfu_tracks(room_id, publisher_id, track_id, kind, label, updated_at_ms)
VALUES(:room_id, :publisher_id, :track_id, :kind, :label, :updated_at_ms)
ON CONFLICT(room_id, publisher_id, track_id) DO UPDATE SET
    kind = excluded.kind,
    label = excluded.label,
    updated_at_ms = excluded.updated_at_ms
SQL
    );
    $statement->execute([
        ':room_id' => $roomId,
        ':publisher_id' => $publisherId,
        ':track_id' => $trackId,
        ':kind' => $kind,
        ':label' => $label,
        ':updated_at_ms' => videochat_sfu_now_ms(),
    ]);
}

function videochat_sfu_remove_track(PDO $pdo, string $roomId, string $publisherId, string $trackId): void
{
    $statement = $pdo->prepare(
        'DELETE FROM sfu_tracks WHERE room_id = :room_id AND publisher_id = :publisher_id AND track_id = :track_id'
    );
    $statement->execute([':room_id' => $roomId, ':publisher_id' => $publisherId, ':track_id' => $trackId]);
}

/**
 * @return array<int, array{publisher_id: string, user_id: string, user_name: string}>
 */
function videochat_sfu_fetch_publishers(PDO $pdo, string $roomId): array
{
    $statement = $pdo->prepare(
        'SELECT publisher_id, user_id, user_name FROM sfu_publishers WHERE room_id = :room_id ORDER BY publisher_id ASC'
    );
    $statement->execute([':room_id' => $roomId]);
    $rows = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $rows[] = [
            'publisher_id' => (string) ($row['publisher_id'] ?? ''),
            'user_id' => (string) ($row['user_id'] ?? ''),
            'user_name' => (string) ($row['user_name'] ?? ''),
        ];
    }
    return $rows;
}

/**
 * @return array<int, array{id: string, kind: string, label: string}>
 */
function videochat_sfu_fetch_tracks(PDO $pdo, string $roomId, string $publisherId): array
{
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT track_id, kind, label
FROM sfu_tracks
WHERE room_id = :room_id
  AND publisher_id = :publisher_id
ORDER BY track_id ASC
SQL
    );
    $statement->execute([':room_id' => $roomId, ':publisher_id' => $publisherId]);
    $tracks = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $kind = strtolower(trim((string) ($row['kind'] ?? 'video')));
        $tracks[] = [
            'id' => (string) ($row['track_id'] ?? ''),
            'kind' => in_array($kind, ['audio', 'video'], true) ? $kind : 'video',
            'label' => (string) ($row['label'] ?? ''),
        ];
    }
    return $tracks;
}

function videochat_sfu_latest_frame_id(PDO $pdo, string $roomId): int
{
    $statement = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM sfu_frames WHERE room_id = :room_id');
    $statement->execute([':room_id' => $roomId]);
    return (int) ($statement->fetchColumn() ?: 0);
}

function videochat_sfu_insert_frame(
    PDO $pdo,
    string $roomId,
    string $publisherId,
    string $publisherUserId,
    string $trackId,
    int $timestamp,
    string $frameType,
    array $frameData
): void {
    $encodedData = json_encode($frameData, JSON_UNESCAPED_SLASHES);
    if (!is_string($encodedData)) {
        return;
    }
    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO sfu_frames(room_id, publisher_id, publisher_user_id, track_id, timestamp, frame_type, data_json, created_at_ms)
VALUES(:room_id, :publisher_id, :publisher_user_id, :track_id, :timestamp, :frame_type, :data_json, :created_at_ms)
SQL
    );
    $statement->execute([
        ':room_id' => $roomId,
        ':publisher_id' => $publisherId,
        ':publisher_user_id' => $publisherUserId,
        ':track_id' => $trackId,
        ':timestamp' => $timestamp,
        ':frame_type' => $frameType,
        ':data_json' => $encodedData,
        ':created_at_ms' => videochat_sfu_now_ms(),
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_sfu_fetch_frames_since(PDO $pdo, string $roomId, int $afterId, string $excludePublisherId, int $limit = 50): array
{
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT id, publisher_id, publisher_user_id, track_id, timestamp, frame_type, data_json
FROM sfu_frames
WHERE room_id = :room_id
  AND id > :after_id
  AND publisher_id <> :exclude_publisher_id
ORDER BY id ASC
LIMIT :limit
SQL
    );
    $statement->bindValue(':room_id', $roomId, PDO::PARAM_STR);
    $statement->bindValue(':after_id', $afterId, PDO::PARAM_INT);
    $statement->bindValue(':exclude_publisher_id', $excludePublisherId, PDO::PARAM_STR);
    $statement->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
    $statement->execute();
    $frames = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (is_array($row)) {
            $frames[] = $row;
        }
    }
    return $frames;
}

function videochat_sfu_cleanup_frames(PDO $pdo): void
{
    $statement = $pdo->prepare('DELETE FROM sfu_frames WHERE created_at_ms < :cutoff_ms');
    $statement->execute([':cutoff_ms' => videochat_sfu_now_ms() - 15_000]);
}

/**
 * @return array{ok: bool, room_id: string, error: string}
 */
function videochat_sfu_resolve_bound_room(array $queryParams): array
{
    $rawRoomId = is_string($queryParams['room_id'] ?? null) ? trim((string) $queryParams['room_id']) : '';
    $legacyRoom = is_string($queryParams['room'] ?? null) ? trim((string) $queryParams['room']) : '';

    if ($rawRoomId === '' && $legacyRoom === '') {
        return [
            'ok' => false,
            'room_id' => '',
            'error' => 'missing_room_id',
        ];
    }

    $normalizedRoomId = $rawRoomId !== '' ? videochat_presence_normalize_room_id($rawRoomId, '') : '';
    $normalizedLegacyRoom = $legacyRoom !== '' ? videochat_presence_normalize_room_id($legacyRoom, '') : '';
    if ($rawRoomId !== '' && $normalizedRoomId === '') {
        return [
            'ok' => false,
            'room_id' => '',
            'error' => 'invalid_room_id',
        ];
    }
    if ($legacyRoom !== '' && $normalizedLegacyRoom === '') {
        return [
            'ok' => false,
            'room_id' => '',
            'error' => 'invalid_room_id',
        ];
    }
    if ($normalizedRoomId !== '' && $normalizedLegacyRoom !== '' && $normalizedRoomId !== $normalizedLegacyRoom) {
        return [
            'ok' => false,
            'room_id' => '',
            'error' => 'room_query_mismatch',
        ];
    }

    $boundRoomId = $normalizedRoomId !== '' ? $normalizedRoomId : $normalizedLegacyRoom;
    return [
        'ok' => $boundRoomId !== '',
        'room_id' => $boundRoomId,
        'error' => $boundRoomId !== '' ? '' : 'invalid_room_id',
    ];
}

/**
 * @return array{ok: bool, type: string, room_id: string, payload: array<string, mixed>, error: string}
 */
function videochat_sfu_decode_client_frame(string $frame, string $boundRoomId): array
{
    $decoded = json_decode($frame, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'type' => '',
            'room_id' => '',
            'payload' => [],
            'error' => 'invalid_json',
        ];
    }

    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if (!in_array($type, ['sfu/join', 'sfu/publish', 'sfu/subscribe', 'sfu/unpublish', 'sfu/frame', 'sfu/leave'], true)) {
        return [
            'ok' => false,
            'type' => $type,
            'room_id' => '',
            'payload' => [],
            'error' => $type === '' ? 'missing_type' : 'unsupported_type',
        ];
    }

    $normalizedBoundRoomId = videochat_presence_normalize_room_id($boundRoomId, '');
    $rawCommandRoom = $decoded['room_id'] ?? ($decoded['roomId'] ?? ($decoded['room'] ?? null));
    if ($rawCommandRoom !== null) {
        $commandRoomId = videochat_presence_normalize_room_id((string) $rawCommandRoom, '');
        if ($commandRoomId === '') {
            return [
                'ok' => false,
                'type' => $type,
                'room_id' => '',
                'payload' => [],
                'error' => 'invalid_room_id',
            ];
        }
        if ($normalizedBoundRoomId === '' || $commandRoomId !== $normalizedBoundRoomId) {
            return [
                'ok' => false,
                'type' => $type,
                'room_id' => $commandRoomId,
                'payload' => [],
                'error' => 'sfu_room_mismatch',
            ];
        }
    }

    $payload = $decoded;
    $payload['room_id'] = $normalizedBoundRoomId;
    return [
        'ok' => true,
        'type' => $type,
        'room_id' => $normalizedBoundRoomId,
        'payload' => $payload,
        'error' => '',
    ];
}

function videochat_sfu_poll_broker(
    PDO $pdo,
    mixed $websocket,
    string $roomId,
    string $clientId,
    array &$knownPublishers,
    array &$trackSignatures,
    int &$lastFrameId
): void {
    $activePublishers = [];
    foreach (videochat_sfu_fetch_publishers($pdo, $roomId) as $publisher) {
        $publisherId = (string) ($publisher['publisher_id'] ?? '');
        if ($publisherId === '' || $publisherId === $clientId) {
            continue;
        }
        $activePublishers[$publisherId] = true;
        if (!isset($knownPublishers[$publisherId])) {
            $knownPublishers[$publisherId] = true;
            videochat_presence_send_frame($websocket, [
                'type' => 'sfu/joined',
                'room_id' => $roomId,
                'publishers' => [$publisherId],
            ]);
        }

        $tracks = videochat_sfu_fetch_tracks($pdo, $roomId, $publisherId);
        $trackSignature = hash('sha256', json_encode($tracks, JSON_UNESCAPED_SLASHES) ?: '');
        if ($tracks !== [] && ($trackSignatures[$publisherId] ?? '') !== $trackSignature) {
            $trackSignatures[$publisherId] = $trackSignature;
            videochat_presence_send_frame($websocket, [
                'type' => 'sfu/tracks',
                'room_id' => $roomId,
                'publisher_id' => $publisherId,
                'publisher_user_id' => (string) ($publisher['user_id'] ?? ''),
                'publisher_name' => (string) ($publisher['user_name'] ?? ''),
                'tracks' => $tracks,
            ]);
        }
    }

    foreach (array_keys($knownPublishers) as $publisherId) {
        if (isset($activePublishers[$publisherId])) {
            continue;
        }
        unset($knownPublishers[$publisherId], $trackSignatures[$publisherId]);
        videochat_presence_send_frame($websocket, [
            'type' => 'sfu/publisher_left',
            'publisher_id' => $publisherId,
        ]);
    }

    foreach (videochat_sfu_fetch_frames_since($pdo, $roomId, $lastFrameId, $clientId) as $frame) {
        $lastFrameId = max($lastFrameId, (int) ($frame['id'] ?? 0));
        $decodedData = json_decode((string) ($frame['data_json'] ?? '[]'), true);
        if (!is_array($decodedData)) {
            continue;
        }
        videochat_presence_send_frame($websocket, [
            'type' => 'sfu/frame',
            'publisher_id' => (string) ($frame['publisher_id'] ?? ''),
            'publisher_user_id' => (string) ($frame['publisher_user_id'] ?? ''),
            'track_id' => (string) ($frame['track_id'] ?? ''),
            'timestamp' => (int) ($frame['timestamp'] ?? 0),
            'data' => $decodedData,
            'frame_type' => (string) ($frame['frame_type'] ?? 'delta'),
        ]);
    }
}
