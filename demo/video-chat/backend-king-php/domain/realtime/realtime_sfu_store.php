<?php

declare(strict_types=1);

function videochat_sfu_now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function videochat_sfu_protected_frame_max_total_bytes(): int
{
    return 16_781_320;
}

function videochat_sfu_presence_ttl_ms(): int
{
    return 20_000;
}

function videochat_sfu_presence_cutoff_ms(): int
{
    return videochat_sfu_now_ms() - videochat_sfu_presence_ttl_ms();
}

function videochat_sfu_frame_chunk_max_chars(): int
{
    return 8 * 1024;
}

function videochat_sfu_create_frame_id(): string
{
    return 'frame_' . bin2hex(random_bytes(12));
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_sfu_expand_outbound_frame_payload(array $frame): array
{
    $chunkMaxChars = videochat_sfu_frame_chunk_max_chars();
    $protectedFrame = is_string($frame['protected_frame'] ?? null) ? trim((string) $frame['protected_frame']) : '';
    if ($protectedFrame !== '' && strlen($protectedFrame) > $chunkMaxChars) {
        return videochat_sfu_chunk_outbound_frame_payload($frame, 'protected_frame_chunk', $protectedFrame, $chunkMaxChars);
    }

    $dataBase64 = is_string($frame['data_base64'] ?? null) ? trim((string) $frame['data_base64']) : '';
    if ($dataBase64 !== '' && strlen($dataBase64) > $chunkMaxChars) {
        return videochat_sfu_chunk_outbound_frame_payload($frame, 'data_base64_chunk', $dataBase64, $chunkMaxChars);
    }

    return [$frame];
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_sfu_chunk_outbound_frame_payload(
    array $frame,
    string $chunkField,
    string $chunkValue,
    int $chunkMaxChars
): array {
    if ($chunkValue === '' || $chunkMaxChars < 1) {
        return [$frame];
    }

    $chunkCount = max(1, (int) ceil(strlen($chunkValue) / $chunkMaxChars));
    if ($chunkCount === 1) {
        return [$frame];
    }

    $frameId = videochat_sfu_create_frame_id();
    $messages = [];

    for ($chunkIndex = 0; $chunkIndex < $chunkCount; $chunkIndex += 1) {
        $start = $chunkIndex * $chunkMaxChars;
        $messages[] = [
            'type' => 'sfu/frame-chunk',
            'frame_id' => $frameId,
            'publisher_id' => (string) ($frame['publisher_id'] ?? ''),
            'publisher_user_id' => (string) ($frame['publisher_user_id'] ?? ''),
            'track_id' => (string) ($frame['track_id'] ?? ''),
            'timestamp' => (int) ($frame['timestamp'] ?? 0),
            'frame_type' => (string) ($frame['frame_type'] ?? 'delta'),
            'protection_mode' => (string) ($frame['protection_mode'] ?? 'transport_only'),
            'chunk_index' => $chunkIndex,
            'chunk_count' => $chunkCount,
            $chunkField => substr($chunkValue, $start, $chunkMaxChars),
        ];
    }

    return $messages;
}

function videochat_sfu_bootstrap(PDO $pdo): void
{
    $pdo->query('PRAGMA journal_mode = WAL');
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

function videochat_sfu_touch_publisher(PDO $pdo, string $roomId, string $publisherId): void
{
    $statement = $pdo->prepare(
        'UPDATE sfu_publishers SET updated_at_ms = :updated_at_ms WHERE room_id = :room_id AND publisher_id = :publisher_id'
    );
    $statement->execute([
        ':room_id' => $roomId,
        ':publisher_id' => $publisherId,
        ':updated_at_ms' => videochat_sfu_now_ms(),
    ]);
}

function videochat_sfu_touch_track(PDO $pdo, string $roomId, string $publisherId, string $trackId): void
{
    $statement = $pdo->prepare(
        'UPDATE sfu_tracks SET updated_at_ms = :updated_at_ms WHERE room_id = :room_id AND publisher_id = :publisher_id AND track_id = :track_id'
    );
    $statement->execute([
        ':room_id' => $roomId,
        ':publisher_id' => $publisherId,
        ':track_id' => $trackId,
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
        <<<'SQL'
SELECT publisher_id, user_id, user_name
FROM sfu_publishers
WHERE room_id = :room_id
  AND updated_at_ms >= :cutoff_ms
ORDER BY publisher_id ASC
SQL
    );
    $statement->execute([
        ':room_id' => $roomId,
        ':cutoff_ms' => videochat_sfu_presence_cutoff_ms(),
    ]);
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
  AND updated_at_ms >= :cutoff_ms
ORDER BY track_id ASC
SQL
    );
    $statement->execute([
        ':room_id' => $roomId,
        ':publisher_id' => $publisherId,
        ':cutoff_ms' => videochat_sfu_presence_cutoff_ms(),
    ]);
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

function videochat_sfu_base64url_decode_strict(string $value): string|false
{
    $normalized = trim($value);
    if ($normalized === '' || preg_match('/^[A-Za-z0-9_-]+$/', $normalized) !== 1) {
        return false;
    }

    $padded = strtr($normalized, '-_', '+/');
    $padding = strlen($padded) % 4;
    if ($padding > 0) {
        $padded .= str_repeat('=', 4 - $padding);
    }

    return base64_decode($padded, true);
}

/**
 * @return array{ok: bool, metadata: array<string, mixed>, error: string}
 */
function videochat_sfu_normalize_protected_metadata(mixed $metadata): array
{
    if ($metadata === null) {
        return ['ok' => true, 'metadata' => [], 'error' => ''];
    }
    if (!is_array($metadata)) {
        return ['ok' => false, 'metadata' => [], 'error' => 'invalid_protected_metadata'];
    }

    $forbidden = [
        'raw_media_key',
        'private_key',
        'shared_secret',
        'plaintext_frame',
        'decoded_audio',
        'decoded_video',
    ];
    foreach ($forbidden as $field) {
        if (array_key_exists($field, $metadata)) {
            return ['ok' => false, 'metadata' => [], 'error' => 'forbidden_protected_metadata'];
        }
    }

    $allowed = [
        'contract_name' => true,
        'contract_version' => true,
        'magic' => true,
        'version' => true,
        'runtime_path' => true,
        'track_kind' => true,
        'frame_kind' => true,
        'kex_suite' => true,
        'media_suite' => true,
        'epoch' => true,
        'sender_key_id' => true,
        'sequence' => true,
        'nonce' => true,
        'aad_length' => true,
        'ciphertext_length' => true,
        'tag_length' => true,
    ];
    $normalized = [];
    foreach ($metadata as $key => $value) {
        $field = is_string($key) ? trim($key) : '';
        if ($field === '' || !isset($allowed[$field])) {
            return ['ok' => false, 'metadata' => [], 'error' => 'unknown_protected_metadata'];
        }
        if (!is_scalar($value) && $value !== null) {
            return ['ok' => false, 'metadata' => [], 'error' => 'invalid_protected_metadata'];
        }
        $normalized[$field] = $value;
    }

    if ($normalized !== []) {
        $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || strlen($encoded) > 4096) {
            return ['ok' => false, 'metadata' => [], 'error' => 'protected_metadata_too_large'];
        }
    }

    return ['ok' => true, 'metadata' => $normalized, 'error' => ''];
}

/**
 * @return array{ok: bool, protected_frame: string, metadata: array<string, mixed>, byte_length: int, error: string}
 */
function videochat_sfu_parse_protected_frame_envelope(mixed $protectedFrame): array
{
    if (!is_string($protectedFrame) || trim($protectedFrame) === '') {
        return ['ok' => false, 'protected_frame' => '', 'metadata' => [], 'byte_length' => 0, 'error' => 'missing_protected_frame'];
    }

    $normalizedFrame = trim($protectedFrame);
    $maxEncodedLength = (int) ceil(videochat_sfu_protected_frame_max_total_bytes() / 3) * 4;
    if (strlen($normalizedFrame) > $maxEncodedLength) {
        return ['ok' => false, 'protected_frame' => '', 'metadata' => [], 'byte_length' => 0, 'error' => 'protected_frame_too_large'];
    }

    $binary = videochat_sfu_base64url_decode_strict($normalizedFrame);
    if (!is_string($binary)) {
        return ['ok' => false, 'protected_frame' => '', 'metadata' => [], 'byte_length' => 0, 'error' => 'malformed_protected_frame'];
    }

    $byteLength = strlen($binary);
    if ($byteLength < 80 || $byteLength > videochat_sfu_protected_frame_max_total_bytes()) {
        return ['ok' => false, 'protected_frame' => '', 'metadata' => [], 'byte_length' => $byteLength, 'error' => 'protected_frame_too_large'];
    }
    if (substr($binary, 0, 4) !== 'KPMF') {
        return ['ok' => false, 'protected_frame' => '', 'metadata' => [], 'byte_length' => $byteLength, 'error' => 'malformed_protected_frame'];
    }

    $headerLengthRow = unpack('Nlength', substr($binary, 4, 4));
    $headerLength = is_array($headerLengthRow) ? (int) ($headerLengthRow['length'] ?? 0) : 0;
    if ($headerLength <= 0 || $headerLength > 4096 || $byteLength <= 8 + $headerLength) {
        return ['ok' => false, 'protected_frame' => '', 'metadata' => [], 'byte_length' => $byteLength, 'error' => 'malformed_protected_frame'];
    }

    $headerJson = substr($binary, 8, $headerLength);
    $header = json_decode($headerJson, true);
    if (!is_array($header)) {
        return ['ok' => false, 'protected_frame' => '', 'metadata' => [], 'byte_length' => $byteLength, 'error' => 'malformed_protected_frame'];
    }

    $metadataResult = videochat_sfu_normalize_protected_metadata($header);
    if (!(bool) ($metadataResult['ok'] ?? false)) {
        return [
            'ok' => false,
            'protected_frame' => '',
            'metadata' => [],
            'byte_length' => $byteLength,
            'error' => (string) ($metadataResult['error'] ?? 'malformed_protected_frame'),
        ];
    }

    $metadata = $metadataResult['metadata'];
    $required = [
        'contract_name' => 'king-video-chat-protected-media-frame',
        'contract_version' => 'v1.0.0',
        'magic' => 'KPMF',
        'version' => 1,
        'runtime_path' => 'wlvc_sfu',
        'tag_length' => 16,
    ];
    foreach ($required as $field => $expected) {
        if (!array_key_exists($field, $metadata) || $metadata[$field] !== $expected) {
            return ['ok' => false, 'protected_frame' => '', 'metadata' => [], 'byte_length' => $byteLength, 'error' => 'malformed_protected_frame'];
        }
    }

    $ciphertextLength = $byteLength - 8 - $headerLength;
    if ($ciphertextLength <= 0 || $ciphertextLength > 16_777_216) {
        return ['ok' => false, 'protected_frame' => '', 'metadata' => [], 'byte_length' => $byteLength, 'error' => 'protected_frame_too_large'];
    }
    if ((int) ($metadata['ciphertext_length'] ?? -1) !== $ciphertextLength) {
        return ['ok' => false, 'protected_frame' => '', 'metadata' => [], 'byte_length' => $byteLength, 'error' => 'malformed_protected_frame'];
    }

    return [
        'ok' => true,
        'protected_frame' => $normalizedFrame,
        'metadata' => $metadata,
        'byte_length' => $byteLength,
        'error' => '',
    ];
}

/**
 * @return array<int|string, mixed>
 */
function videochat_sfu_encode_stored_frame_payload(array $frameData, string $protectedFrame = '', string $dataBase64 = ''): array
{
    if ($protectedFrame === '') {
        if ($dataBase64 !== '') {
            return [
                'data_base64' => $dataBase64,
                'protection_mode' => 'transport_only',
            ];
        }
        return $frameData;
    }
    return [
        'protected_frame' => $protectedFrame,
        'protection_mode' => 'protected',
    ];
}

/**
 * @return array{data: array<int, mixed>, data_base64: string, protected_frame: string, protection_mode: string}
 */
function videochat_sfu_decode_stored_frame_payload(array $decodedData): array
{
    $protectedFrame = is_string($decodedData['protected_frame'] ?? null) ? trim((string) $decodedData['protected_frame']) : '';
    if ($protectedFrame !== '') {
        return [
            'data' => [],
            'data_base64' => '',
            'protected_frame' => $protectedFrame,
            'protection_mode' => 'protected',
        ];
    }

    $dataBase64 = is_string($decodedData['data_base64'] ?? null) ? trim((string) $decodedData['data_base64']) : '';
    if ($dataBase64 !== '') {
        return [
            'data' => [],
            'data_base64' => $dataBase64,
            'protected_frame' => '',
            'protection_mode' => 'transport_only',
        ];
    }

    if (array_key_exists('data', $decodedData) && is_array($decodedData['data'])) {
        return [
            'data' => $decodedData['data'],
            'data_base64' => '',
            'protected_frame' => '',
            'protection_mode' => 'transport_only',
        ];
    }

    return [
        'data' => $decodedData,
        'data_base64' => '',
        'protected_frame' => '',
        'protection_mode' => 'transport_only',
    ];
}

function videochat_sfu_insert_frame(
    PDO $pdo,
    string $roomId,
    string $publisherId,
    string $publisherUserId,
    string $trackId,
    int $timestamp,
    string $frameType,
    array $frameData,
    string $protectedFrame = '',
    string $dataBase64 = ''
): void {
    $storedPayload = videochat_sfu_encode_stored_frame_payload($frameData, $protectedFrame, $dataBase64);
    $encodedData = json_encode($storedPayload, JSON_UNESCAPED_SLASHES);
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
    videochat_sfu_touch_publisher($pdo, $roomId, $publisherId);
    if ($trackId !== '') {
        videochat_sfu_touch_track($pdo, $roomId, $publisherId, $trackId);
    }
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

function videochat_sfu_cleanup_stale_presence(PDO $pdo): void
{
    $cutoffMs = videochat_sfu_presence_cutoff_ms();

    $deleteTracks = $pdo->prepare('DELETE FROM sfu_tracks WHERE updated_at_ms < :cutoff_ms');
    $deleteTracks->execute([':cutoff_ms' => $cutoffMs]);

    $deleteOrphanTracks = $pdo->prepare(
        <<<'SQL'
DELETE FROM sfu_tracks
WHERE NOT EXISTS (
    SELECT 1
    FROM sfu_publishers
    WHERE sfu_publishers.room_id = sfu_tracks.room_id
      AND sfu_publishers.publisher_id = sfu_tracks.publisher_id
)
SQL
    );
    $deleteOrphanTracks->execute();

    $deletePublishers = $pdo->prepare('DELETE FROM sfu_publishers WHERE updated_at_ms < :cutoff_ms');
    $deletePublishers->execute([':cutoff_ms' => $cutoffMs]);
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
    if (!in_array($type, ['sfu/join', 'sfu/publish', 'sfu/subscribe', 'sfu/unpublish', 'sfu/frame', 'sfu/frame-chunk', 'sfu/leave'], true)) {
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
    if ($type === 'sfu/frame') {
        $protectionMode = strtolower(trim((string) ($decoded['protection_mode'] ?? ($decoded['protectionMode'] ?? 'transport_only'))));
        if (!in_array($protectionMode, ['transport_only', 'protected', 'required'], true)) {
            return [
                'ok' => false,
                'type' => $type,
                'room_id' => $normalizedBoundRoomId,
                'payload' => [],
                'error' => 'invalid_protection_mode',
            ];
        }

        $protectedFrameRaw = $decoded['protected_frame'] ?? ($decoded['protectedFrame'] ?? null);
        if (is_string($protectedFrameRaw) && trim($protectedFrameRaw) !== '') {
            if (
                array_key_exists('data', $decoded)
                || array_key_exists('data_base64', $decoded)
                || array_key_exists('protected', $decoded)
            ) {
                return [
                    'ok' => false,
                    'type' => $type,
                    'room_id' => $normalizedBoundRoomId,
                    'payload' => [],
                    'error' => 'protected_frame_data_conflict',
                ];
            }
            $protectedFrame = videochat_sfu_parse_protected_frame_envelope($protectedFrameRaw);
            if (!(bool) ($protectedFrame['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'type' => $type,
                    'room_id' => $normalizedBoundRoomId,
                    'payload' => [],
                    'error' => (string) ($protectedFrame['error'] ?? 'malformed_protected_frame'),
                ];
            }
            $payload['protected_frame'] = (string) ($protectedFrame['protected_frame'] ?? '');
            $payload['protection_mode'] = $protectionMode === 'required' ? 'required' : 'protected';
            unset($payload['data'], $payload['data_base64'], $payload['protected']);
        } else {
            if ($protectionMode === 'required' || $protectionMode === 'protected') {
                return [
                    'ok' => false,
                    'type' => $type,
                    'room_id' => $normalizedBoundRoomId,
                    'payload' => [],
                    'error' => 'protected_frame_required',
                ];
            }
            if (array_key_exists('protected', $decoded)) {
                return [
                    'ok' => false,
                    'type' => $type,
                    'room_id' => $normalizedBoundRoomId,
                    'payload' => [],
                    'error' => 'legacy_protected_metadata_rejected',
                ];
            }
            $dataBase64 = is_string($decoded['data_base64'] ?? null) ? trim((string) $decoded['data_base64']) : '';
            if ($dataBase64 !== '') {
                $binary = videochat_sfu_base64url_decode_strict($dataBase64);
                if (!is_string($binary) || $binary === '') {
                    return [
                        'ok' => false,
                        'type' => $type,
                        'room_id' => $normalizedBoundRoomId,
                        'payload' => [],
                        'error' => 'invalid_transport_frame',
                    ];
                }
                $payload['data_base64'] = $dataBase64;
                unset($payload['data']);
            } elseif (!is_array($decoded['data'] ?? null)) {
                return [
                    'ok' => false,
                    'type' => $type,
                    'room_id' => $normalizedBoundRoomId,
                    'payload' => [],
                    'error' => 'missing_transport_frame',
                ];
            }
            $payload['protection_mode'] = 'transport_only';
        }
    } elseif ($type === 'sfu/frame-chunk') {
        $protectionMode = strtolower(trim((string) ($decoded['protection_mode'] ?? ($decoded['protectionMode'] ?? 'transport_only'))));
        if (!in_array($protectionMode, ['transport_only', 'protected', 'required'], true)) {
            return [
                'ok' => false,
                'type' => $type,
                'room_id' => $normalizedBoundRoomId,
                'payload' => [],
                'error' => 'invalid_protection_mode',
            ];
        }

        $frameId = trim((string) ($decoded['frame_id'] ?? ($decoded['frameId'] ?? '')));
        if ($frameId === '' || !preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $frameId)) {
            return [
                'ok' => false,
                'type' => $type,
                'room_id' => $normalizedBoundRoomId,
                'payload' => [],
                'error' => 'invalid_frame_id',
            ];
        }

        $chunkIndex = (int) ($decoded['chunk_index'] ?? ($decoded['chunkIndex'] ?? -1));
        $chunkCount = (int) ($decoded['chunk_count'] ?? ($decoded['chunkCount'] ?? 0));
        if ($chunkCount < 1 || $chunkCount > 4096 || $chunkIndex < 0 || $chunkIndex >= $chunkCount) {
            return [
                'ok' => false,
                'type' => $type,
                'room_id' => $normalizedBoundRoomId,
                'payload' => [],
                'error' => 'invalid_frame_chunk',
            ];
        }

        $dataChunk = is_string($decoded['data_base64_chunk'] ?? null) ? trim((string) $decoded['data_base64_chunk']) : '';
        $protectedChunk = is_string($decoded['protected_frame_chunk'] ?? null) ? trim((string) $decoded['protected_frame_chunk']) : '';
        if (($dataChunk === '' && $protectedChunk === '') || ($dataChunk !== '' && $protectedChunk !== '')) {
            return [
                'ok' => false,
                'type' => $type,
                'room_id' => $normalizedBoundRoomId,
                'payload' => [],
                'error' => 'invalid_frame_chunk',
            ];
        }

        $chunkValue = $protectedChunk !== '' ? $protectedChunk : $dataChunk;
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $chunkValue) || strlen($chunkValue) > 16384) {
            return [
                'ok' => false,
                'type' => $type,
                'room_id' => $normalizedBoundRoomId,
                'payload' => [],
                'error' => 'invalid_frame_chunk',
            ];
        }

        if ($protectedChunk !== '') {
            if ($protectionMode === 'transport_only') {
                return [
                    'ok' => false,
                    'type' => $type,
                    'room_id' => $normalizedBoundRoomId,
                    'payload' => [],
                    'error' => 'invalid_protection_mode',
                ];
            }
            $payload['protected_frame_chunk'] = $protectedChunk;
            $payload['protection_mode'] = $protectionMode === 'required' ? 'required' : 'protected';
            unset($payload['data_base64_chunk']);
        } else {
            if ($protectionMode === 'required' || $protectionMode === 'protected') {
                return [
                    'ok' => false,
                    'type' => $type,
                    'room_id' => $normalizedBoundRoomId,
                    'payload' => [],
                    'error' => 'protected_frame_required',
                ];
            }
            $payload['data_base64_chunk'] = $dataChunk;
            $payload['protection_mode'] = 'transport_only';
            unset($payload['protected_frame_chunk']);
        }

        $payload['frame_id'] = $frameId;
        $payload['chunk_index'] = $chunkIndex;
        $payload['chunk_count'] = $chunkCount;
    }
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
        $storedPayload = videochat_sfu_decode_stored_frame_payload($decodedData);
        $outboundFrame = [
            'type' => 'sfu/frame',
            'publisher_id' => (string) ($frame['publisher_id'] ?? ''),
            'publisher_user_id' => (string) ($frame['publisher_user_id'] ?? ''),
            'track_id' => (string) ($frame['track_id'] ?? ''),
            'timestamp' => (int) ($frame['timestamp'] ?? 0),
            'frame_type' => (string) ($frame['frame_type'] ?? 'delta'),
            'protection_mode' => (string) ($storedPayload['protection_mode'] ?? 'transport_only'),
        ];
        if (($storedPayload['protected_frame'] ?? '') !== '') {
            $outboundFrame['protected_frame'] = $storedPayload['protected_frame'];
        } elseif (($storedPayload['data_base64'] ?? '') !== '') {
            $outboundFrame['data_base64'] = $storedPayload['data_base64'];
        } else {
            $outboundFrame['data'] = $storedPayload['data'];
        }
        foreach (videochat_sfu_expand_outbound_frame_payload($outboundFrame) as $outboundMessage) {
            videochat_presence_send_frame($websocket, $outboundMessage);
        }
    }
}
