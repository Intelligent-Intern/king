<?php

declare(strict_types=1);

function videochat_client_capabilities_schema_version(): string
{
    return 'king.video.client_capabilities.v1';
}

/**
 * @return list<string>
 */
function videochat_client_capabilities_forbidden_keys(): array
{
    return [
        'authorization',
        'candidate',
        'cookie',
        'credential',
        'device_label',
        'device_labels',
        'encoded_frame',
        'frame',
        'ice',
        'ice_candidates',
        'label',
        'labels',
        'media_frame',
        'password',
        'protected_frame',
        'raw_frame',
        'sdp',
        'secret',
        'token',
        'turn_credential',
    ];
}

function videochat_client_capabilities_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value !== 0;
    }
    $normalized = strtolower(trim((string) $value));

    return in_array($normalized, ['1', 'true', 'yes', 'on', 'available', 'supported'], true);
}

function videochat_client_capabilities_int(mixed $value): int
{
    return is_numeric($value) ? max(0, min(8192, (int) $value)) : 0;
}

function videochat_client_capabilities_timestamp(mixed $value, string $fallback = ''): string
{
    $candidate = trim((string) $value);
    if (
        $candidate !== ''
        && strlen($candidate) <= 40
        && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\+00:00|Z)$/', $candidate) === 1
    ) {
        return $candidate;
    }

    return $fallback !== '' ? $fallback : gmdate('c');
}

function videochat_client_capabilities_string(mixed $value, string $fallback = 'unknown'): string
{
    $normalized = strtolower(trim((string) $value));
    if ($normalized === '') {
        return $fallback;
    }
    $normalized = preg_replace('/[^a-z0-9._:-]+/', '_', $normalized) ?? '';
    $normalized = trim($normalized, '_');

    return $normalized !== '' ? substr($normalized, 0, 96) : $fallback;
}

function videochat_client_capabilities_session_id(array $payload, array $connection): string
{
    $candidate = trim((string) ($payload['participant_session_id'] ?? $payload['participantSessionId'] ?? ''));
    if ($candidate !== '') {
        return substr(preg_replace('/[^A-Za-z0-9._:-]+/', '_', $candidate) ?? '', 0, 128);
    }

    return substr(trim((string) ($connection['connection_id'] ?? $connection['session_id'] ?? '')), 0, 128);
}

/**
 * @return array<string, mixed>
 */
function videochat_client_capabilities_normalize(array $payload, array $connection = []): array
{
    $schemaVersion = trim((string) ($payload['schema_version'] ?? $payload['schemaVersion'] ?? ''));
    $media = is_array($payload['media'] ?? null) ? (array) $payload['media'] : [];
    $runtime = is_array($payload['runtime'] ?? null) ? (array) $payload['runtime'] : [];
    $constraints = is_array($payload['constraints'] ?? null) ? (array) $payload['constraints'] : [];

    return [
        'schema_version' => videochat_client_capabilities_schema_version(),
        'schema_valid' => $schemaVersion === '' || $schemaVersion === videochat_client_capabilities_schema_version(),
        'participant_session_id' => videochat_client_capabilities_session_id($payload, $connection),
        'media' => [
            'camera' => videochat_client_capabilities_bool($media['camera'] ?? false),
            'camera_720p30' => videochat_client_capabilities_bool($media['camera_720p30'] ?? $media['camera720p30'] ?? false),
            'microphone' => videochat_client_capabilities_bool($media['microphone'] ?? false),
            'screen_share' => videochat_client_capabilities_bool($media['screen_share'] ?? $media['screenShare'] ?? false),
        ],
        'runtime' => [
            'websocket' => videochat_client_capabilities_bool($runtime['websocket'] ?? true),
            'webrtc' => videochat_client_capabilities_bool($runtime['webrtc'] ?? false),
            'webassembly' => videochat_client_capabilities_bool($runtime['webassembly'] ?? $runtime['webAssembly'] ?? false),
            'webcodecs' => videochat_client_capabilities_bool($runtime['webcodecs'] ?? $runtime['webCodecs'] ?? false),
            'gpu' => videochat_client_capabilities_string($runtime['gpu'] ?? 'unknown'),
            'wlvc_encoder' => videochat_client_capabilities_bool($runtime['wlvc_encoder'] ?? $runtime['wlvcEncoder'] ?? false),
            'wlvc_decoder' => videochat_client_capabilities_bool($runtime['wlvc_decoder'] ?? $runtime['wlvcDecoder'] ?? false),
        ],
        'constraints' => [
            'video_width' => videochat_client_capabilities_int($constraints['video_width'] ?? $constraints['videoWidth'] ?? 0),
            'video_height' => videochat_client_capabilities_int($constraints['video_height'] ?? $constraints['videoHeight'] ?? 0),
            'video_fps' => videochat_client_capabilities_int($constraints['video_fps'] ?? $constraints['videoFps'] ?? 0),
        ],
        'received_at' => gmdate('c'),
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_client_capabilities_public_projection(array $capabilities): array
{
    $normalized = videochat_client_capabilities_normalize($capabilities, [
        'connection_id' => (string) ($capabilities['participant_session_id'] ?? ''),
    ]);

    return [
        'schema_version' => videochat_client_capabilities_schema_version(),
        'schema_valid' => (bool) ($normalized['schema_valid'] ?? false),
        'participant_session_id' => (string) ($normalized['participant_session_id'] ?? ''),
        'media' => (array) ($normalized['media'] ?? []),
        'runtime' => (array) ($normalized['runtime'] ?? []),
        'constraints' => (array) ($normalized['constraints'] ?? []),
        'received_at' => videochat_client_capabilities_timestamp(
            $capabilities['received_at'] ?? '',
            (string) ($normalized['received_at'] ?? '')
        ),
    ];
}

function videochat_client_capabilities_now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function videochat_client_capabilities_ttl_ms(): int
{
    if (function_exists('videochat_realtime_presence_db_ttl_ms')) {
        return videochat_realtime_presence_db_ttl_ms();
    }

    return 45_000;
}

function videochat_client_capabilities_retention_ms(): int
{
    if (function_exists('videochat_realtime_presence_db_retention_ms')) {
        return videochat_realtime_presence_db_retention_ms();
    }

    return 20 * 60 * 1000;
}

function videochat_client_capabilities_db_bootstrap(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS realtime_client_capabilities (
    connection_id TEXT PRIMARY KEY,
    session_id TEXT NOT NULL,
    room_id TEXT NOT NULL,
    call_id TEXT NOT NULL,
    user_id INTEGER NOT NULL,
    schema_version TEXT NOT NULL,
    capabilities_json TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    updated_at_ms INTEGER NOT NULL
)
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_realtime_client_capabilities_room ON realtime_client_capabilities(call_id, room_id, updated_at_ms)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_realtime_client_capabilities_user ON realtime_client_capabilities(call_id, room_id, user_id, updated_at_ms)');
}

function videochat_client_capabilities_prune(PDO $pdo, ?int $nowMs = null): void
{
    videochat_client_capabilities_db_bootstrap($pdo);
    $effectiveNowMs = is_int($nowMs) && $nowMs > 0 ? $nowMs : videochat_client_capabilities_now_ms();
    $retentionMs = max(
        videochat_client_capabilities_ttl_ms() * 2,
        videochat_client_capabilities_retention_ms()
    );
    $orphanGraceMs = max(videochat_client_capabilities_ttl_ms() * 2, 1);

    $statement = $pdo->prepare('DELETE FROM realtime_client_capabilities WHERE updated_at_ms < :cutoff_ms');
    $statement->execute([':cutoff_ms' => $effectiveNowMs - $retentionMs]);

    try {
        if (function_exists('videochat_realtime_presence_db_bootstrap')) {
            videochat_realtime_presence_db_bootstrap($pdo);
        }
        $statement = $pdo->prepare(
            <<<'SQL'
DELETE FROM realtime_client_capabilities
WHERE updated_at_ms < :orphan_cutoff_ms
  AND NOT EXISTS (
    SELECT 1
    FROM realtime_presence_connections rpc
    WHERE rpc.connection_id = realtime_client_capabilities.connection_id
      AND rpc.session_id = realtime_client_capabilities.session_id
      AND rpc.call_id = realtime_client_capabilities.call_id
      AND rpc.room_id = realtime_client_capabilities.room_id
      AND rpc.user_id = realtime_client_capabilities.user_id
      AND rpc.last_seen_at_ms >= :orphan_cutoff_ms
)
SQL
        );
        $statement->execute([':orphan_cutoff_ms' => $effectiveNowMs - $orphanGraceMs]);
    } catch (Throwable) {
        return;
    }
}

function videochat_client_capabilities_remove_connection(PDO $pdo, string $connectionId): void
{
    $trimmedConnectionId = trim($connectionId);
    if ($trimmedConnectionId === '') {
        return;
    }

    videochat_client_capabilities_db_bootstrap($pdo);
    $statement = $pdo->prepare('DELETE FROM realtime_client_capabilities WHERE connection_id = :connection_id');
    $statement->execute([':connection_id' => $trimmedConnectionId]);
}

function videochat_client_capabilities_connection_call_id(array $connection): string
{
    if (function_exists('videochat_realtime_connection_call_id')) {
        return strtolower(videochat_realtime_connection_call_id($connection));
    }

    return strtolower(trim((string) (($connection['active_call_id'] ?? '') ?: ($connection['requested_call_id'] ?? ''))));
}

function videochat_client_capabilities_upsert(PDO $pdo, array $connection, array $capabilities): bool
{
    $connectionId = trim((string) ($connection['connection_id'] ?? ''));
    $roomId = function_exists('videochat_presence_normalize_room_id')
        ? videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '')
        : strtolower(trim((string) ($connection['room_id'] ?? '')));
    $callId = videochat_client_capabilities_connection_call_id($connection);
    $userId = (int) ($connection['user_id'] ?? 0);
    if ($connectionId === '' || $roomId === '' || $callId === '' || $userId <= 0) {
        return false;
    }

    videochat_client_capabilities_db_bootstrap($pdo);
    $nowMs = (int) floor(microtime(true) * 1000);
    $publicCapabilities = videochat_client_capabilities_public_projection($capabilities);
    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO realtime_client_capabilities(
    connection_id,
    session_id,
    room_id,
    call_id,
    user_id,
    schema_version,
    capabilities_json,
    updated_at,
    updated_at_ms
) VALUES (
    :connection_id,
    :session_id,
    :room_id,
    :call_id,
    :user_id,
    :schema_version,
    :capabilities_json,
    :updated_at,
    :updated_at_ms
)
ON CONFLICT(connection_id) DO UPDATE SET
    session_id = excluded.session_id,
    room_id = excluded.room_id,
    call_id = excluded.call_id,
    user_id = excluded.user_id,
    schema_version = excluded.schema_version,
    capabilities_json = excluded.capabilities_json,
    updated_at = excluded.updated_at,
    updated_at_ms = excluded.updated_at_ms
SQL
    );
    $statement->execute([
        ':connection_id' => $connectionId,
        ':session_id' => trim((string) ($connection['session_id'] ?? '')),
        ':room_id' => $roomId,
        ':call_id' => $callId,
        ':user_id' => $userId,
        ':schema_version' => videochat_client_capabilities_schema_version(),
        ':capabilities_json' => json_encode($publicCapabilities, JSON_UNESCAPED_SLASHES),
        ':updated_at' => gmdate('c'),
        ':updated_at_ms' => $nowMs,
    ]);
    videochat_client_capabilities_prune($pdo, $nowMs);

    return true;
}

/**
 * @return array<string, array<string, mixed>>
 */
function videochat_client_capabilities_fetch_room(PDO $pdo, string $callId, string $roomId, ?int $nowMs = null): array
{
    $normalizedCallId = strtolower(trim($callId));
    $normalizedRoomId = function_exists('videochat_presence_normalize_room_id')
        ? videochat_presence_normalize_room_id($roomId, '')
        : strtolower(trim($roomId));
    if ($normalizedCallId === '' || $normalizedRoomId === '') {
        return [];
    }

    videochat_client_capabilities_db_bootstrap($pdo);
    $effectiveNowMs = is_int($nowMs) && $nowMs > 0 ? $nowMs : videochat_client_capabilities_now_ms();
    videochat_client_capabilities_prune($pdo, $effectiveNowMs);
    try {
        if (function_exists('videochat_realtime_presence_db_bootstrap')) {
            videochat_realtime_presence_db_bootstrap($pdo);
        }
        $statement = $pdo->prepare(
            <<<'SQL'
SELECT
    rcc.connection_id,
    rcc.capabilities_json
FROM realtime_client_capabilities rcc
INNER JOIN realtime_presence_connections rpc
   ON rpc.connection_id = rcc.connection_id
  AND rpc.session_id = rcc.session_id
  AND rpc.call_id = rcc.call_id
  AND rpc.room_id = rcc.room_id
  AND rpc.user_id = rcc.user_id
WHERE rcc.call_id = :call_id
  AND rcc.room_id = :room_id
  AND rpc.last_seen_at_ms >= :presence_cutoff_ms
  AND rcc.updated_at_ms >= :capability_cutoff_ms
ORDER BY rpc.last_seen_at_ms DESC, rcc.updated_at_ms DESC
SQL
        );
        $statement->execute([
            ':call_id' => $normalizedCallId,
            ':room_id' => $normalizedRoomId,
            ':presence_cutoff_ms' => $effectiveNowMs - videochat_client_capabilities_ttl_ms(),
            ':capability_cutoff_ms' => $effectiveNowMs - max(
                videochat_client_capabilities_ttl_ms() * 2,
                videochat_client_capabilities_retention_ms()
            ),
        ]);
    } catch (Throwable) {
        return [];
    }

    $rows = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $connectionId = trim((string) ($row['connection_id'] ?? ''));
        $decoded = json_decode((string) ($row['capabilities_json'] ?? ''), true);
        if ($connectionId !== '' && is_array($decoded) && !isset($rows[$connectionId])) {
            $rows[$connectionId] = videochat_client_capabilities_public_projection($decoded);
        }
    }

    return $rows;
}
