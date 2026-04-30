<?php

declare(strict_types=1);

require_once __DIR__ . '/realtime_sfu_binary_payload.php';
require_once __DIR__ . '/realtime_sfu_iibin.php';
require_once __DIR__ . '/realtime_sfu_subscriber_budget.php';

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

function videochat_sfu_frame_buffer_ttl_ms(): int
{
    return 2500;
}

function videochat_sfu_frame_buffer_max_rows_per_room(): int
{
    return 300;
}

function videochat_sfu_frame_buffer_poll_batch_limit(): int
{
    return 12;
}

function videochat_sfu_frame_buffer_cleanup_interval_ms(): int
{
    return 250;
}

function videochat_sfu_presence_cutoff_ms(): int
{
    return videochat_sfu_now_ms() - videochat_sfu_presence_ttl_ms();
}

function videochat_sfu_frame_buffer_cutoff_ms(): int
{
    return videochat_sfu_now_ms() - videochat_sfu_frame_buffer_ttl_ms();
}

function videochat_sfu_frame_chunk_max_chars(): int
{
    return 8 * 1024;
}

function videochat_sfu_binary_frame_magic(): string
{
    return 'KSFB';
}

function videochat_sfu_binary_frame_envelope_version(): int
{
    return 1;
}

function videochat_sfu_binary_frame_layout_envelope_version(): int
{
    return 2;
}

function videochat_sfu_binary_continuation_threshold_bytes(): int
{
    return 65_535;
}

function videochat_sfu_create_frame_id(): string
{
    return 'frame_' . bin2hex(random_bytes(12));
}

function videochat_sfu_binary_frame_has_magic(string $payload): bool
{
    return strlen($payload) >= 4
        && substr($payload, 0, 4) === videochat_sfu_binary_frame_magic();
}

function videochat_sfu_binary_write_u64_le(int $value): string
{
    $normalized = max(0, $value);
    $low = $normalized & 0xffffffff;
    $high = (int) floor($normalized / 4294967296);
    return pack('V2', $low, $high);
}

function videochat_sfu_binary_read_u64_le(string $payload, int $offset): int
{
    $parts = unpack('Vlow/Vhigh', substr($payload, $offset, 8));
    if (!is_array($parts)) {
        return 0;
    }

    $low = (int) ($parts['low'] ?? 0);
    $high = (int) ($parts['high'] ?? 0);
    return (int) ($low + ($high * 4294967296));
}

function videochat_sfu_binary_protection_mode_code(string $mode): int
{
    return match (strtolower(trim($mode))) {
        'required' => 2,
        'protected' => 1,
        default => 0,
    };
}

function videochat_sfu_binary_protection_mode_from_code(int $code): string
{
    return match ($code) {
        2 => 'required',
        1 => 'protected',
        default => 'transport_only',
    };
}

function videochat_sfu_normalize_codec_id(string $codecId): string
{
    $normalized = strtolower(trim($codecId));
    return match ($normalized) {
        'wlvc_wasm', 'wlvc_ts', 'webcodecs_vp8' => $normalized,
        default => 'wlvc_unknown',
    };
}

function videochat_sfu_normalize_runtime_id(string $runtimeId): string
{
    $normalized = strtolower(trim($runtimeId));
    return match ($normalized) {
        'wlvc_sfu', 'webrtc_native' => $normalized,
        default => 'unknown_runtime',
    };
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_sfu_expand_outbound_frame_payload(array $frame): array
{
    unset($frame['data_base64_chunk'], $frame['protected_frame_chunk']);
    return [$frame];
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
    frame_row_id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id TEXT NOT NULL,
    publisher_id TEXT NOT NULL,
    track_id TEXT NOT NULL DEFAULT '',
    frame_id TEXT NOT NULL DEFAULT '',
    frame_sequence INTEGER NOT NULL DEFAULT 0,
    created_at_ms INTEGER NOT NULL,
    payload_json TEXT NOT NULL
)
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sfu_frames_room_row ON sfu_frames(room_id, frame_row_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sfu_frames_room_created ON sfu_frames(room_id, created_at_ms)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sfu_frames_room_publisher ON sfu_frames(room_id, publisher_id, frame_row_id)');
}

function videochat_sfu_table_has_column(PDO $pdo, string $tableName, string $columnName): bool
{
    $statement = $pdo->query('PRAGMA table_info(' . $tableName . ')');
    if (!$statement) {
        return false;
    }
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if ((string) ($row['name'] ?? '') === $columnName) {
            return true;
        }
    }
    return false;
}

function videochat_sfu_ensure_column(PDO $pdo, string $tableName, string $columnName, string $columnSql): void
{
    if (videochat_sfu_table_has_column($pdo, $tableName, $columnName)) {
        return;
    }
    $pdo->exec('ALTER TABLE ' . $tableName . ' ADD COLUMN ' . $columnName . ' ' . $columnSql);
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
    $deleteFrames = $pdo->prepare('DELETE FROM sfu_frames WHERE room_id = :room_id AND publisher_id = :publisher_id');
    $deleteFrames->execute([':room_id' => $roomId, ':publisher_id' => $publisherId]);
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

function videochat_sfu_base64url_encode(string $value): string
{
    if ($value === '') {
        return '';
    }

    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function videochat_sfu_frame_data_base64(array $frameData): string
{
    $bytes = '';
    foreach ($frameData as $value) {
        $bytes .= chr(max(0, min(255, (int) $value)));
    }
    return videochat_sfu_base64url_encode($bytes);
}

function videochat_sfu_encode_binary_frame_envelope(array $frame): string|false
{
    $publisherId = (string) ($frame['publisher_id'] ?? '');
    $publisherUserId = (string) ($frame['publisher_user_id'] ?? '');
    $trackId = (string) ($frame['track_id'] ?? '');
    $frameId = trim((string) ($frame['frame_id'] ?? ''));
    $frameType = strtolower(trim((string) ($frame['frame_type'] ?? 'delta'))) === 'keyframe' ? 'keyframe' : 'delta';
    $protectionMode = strtolower(trim((string) ($frame['protection_mode'] ?? 'transport_only')));
    $protocolVersion = max(1, (int) ($frame['protocol_version'] ?? 1));
    $frameSequence = max(0, (int) ($frame['frame_sequence'] ?? 0));
    $senderSentAtMs = max(0, (int) ($frame['sender_sent_at_ms'] ?? 0));
    $timestamp = max(0, (int) ($frame['timestamp'] ?? 0));
    $layoutMetadataJson = videochat_sfu_encode_layout_metadata_json($frame);
    $layoutMetadataLength = strlen($layoutMetadataJson);
    $envelopeVersion = $layoutMetadataLength > 0
        ? videochat_sfu_binary_frame_layout_envelope_version()
        : videochat_sfu_binary_frame_envelope_version();

    $protectedFrame = is_string($frame['protected_frame'] ?? null) ? trim((string) $frame['protected_frame']) : '';
    $dataBase64 = is_string($frame['data_base64'] ?? null) ? trim((string) $frame['data_base64']) : '';
    $dataBinary = videochat_sfu_frame_data_binary($frame);
    if ($protectedFrame !== '' && ($dataBase64 !== '' || $dataBinary !== '')) {
        return false;
    }
    if ($dataBase64 !== '' && $dataBinary !== '') {
        return false;
    }

    if ($protectedFrame !== '') {
        $payloadBytes = videochat_sfu_base64url_decode_strict($protectedFrame);
    } elseif ($dataBinary !== '') {
        $payloadBytes = $dataBinary;
    } else {
        $payloadBytes = videochat_sfu_base64url_decode_strict($dataBase64);
    }
    if (!is_string($payloadBytes) || $payloadBytes === '') {
        return false;
    }

    return ''
        . videochat_sfu_binary_frame_magic()
        . chr($envelopeVersion)
        . chr(1)
        . chr($frameType === 'keyframe' ? 1 : 0)
        . chr(videochat_sfu_binary_protection_mode_code($protectionMode))
        . pack('v', $protocolVersion)
        . pack('v', strlen($publisherId))
        . pack('v', strlen($publisherUserId))
        . pack('v', strlen($trackId))
        . pack('v', strlen($frameId))
        . (
            $envelopeVersion === videochat_sfu_binary_frame_layout_envelope_version()
                ? (
                    pack('V', $layoutMetadataLength)
                    . videochat_sfu_binary_write_u64_le($timestamp)
                    . pack('V', $frameSequence)
                    . videochat_sfu_binary_write_u64_le($senderSentAtMs)
                    . pack('V', strlen($payloadBytes))
                )
                : (
                    videochat_sfu_binary_write_u64_le($timestamp)
                    . pack('V', $frameSequence)
                    . videochat_sfu_binary_write_u64_le($senderSentAtMs)
                    . pack('V', strlen($payloadBytes))
                )
        )
        . $publisherId
        . $publisherUserId
        . $trackId
        . $frameId
        . $layoutMetadataJson
        . $payloadBytes;
}

/**
 * @return array{ok: bool, payload: array<string, mixed>, error: string}
 */
function videochat_sfu_decode_binary_client_frame(string $frame, string $boundRoomId): array
{
    if (!videochat_sfu_binary_frame_has_magic($frame) || strlen($frame) < 40) {
        return ['ok' => false, 'payload' => [], 'error' => 'invalid_binary_envelope'];
    }

    $version = ord($frame[4] ?? "\0");
    $messageType = ord($frame[5] ?? "\0");
    if (
        !in_array($version, [videochat_sfu_binary_frame_envelope_version(), videochat_sfu_binary_frame_layout_envelope_version()], true)
        || $messageType !== 1
    ) {
        return ['ok' => false, 'payload' => [], 'error' => 'invalid_binary_envelope'];
    }

    $frameType = (ord($frame[6] ?? "\0") === 1) ? 'keyframe' : 'delta';
    $protectionMode = videochat_sfu_binary_protection_mode_from_code(ord($frame[7] ?? "\0"));
    $protocolVersion = (int) (unpack('vvalue', substr($frame, 8, 2))['value'] ?? 1);
    $publisherIdLength = (int) (unpack('vvalue', substr($frame, 10, 2))['value'] ?? 0);
    $publisherUserIdLength = (int) (unpack('vvalue', substr($frame, 12, 2))['value'] ?? 0);
    $trackIdLength = (int) (unpack('vvalue', substr($frame, 14, 2))['value'] ?? 0);
    $frameIdLength = (int) (unpack('vvalue', substr($frame, 16, 2))['value'] ?? 0);
    $headerLength = $version === videochat_sfu_binary_frame_layout_envelope_version() ? 46 : 42;
    $layoutMetadataLength = $version === videochat_sfu_binary_frame_layout_envelope_version()
        ? (int) (unpack('Vvalue', substr($frame, 18, 4))['value'] ?? 0)
        : 0;
    $timestamp = $version === videochat_sfu_binary_frame_layout_envelope_version()
        ? videochat_sfu_binary_read_u64_le($frame, 22)
        : videochat_sfu_binary_read_u64_le($frame, 18);
    $frameSequence = $version === videochat_sfu_binary_frame_layout_envelope_version()
        ? (int) (unpack('Vvalue', substr($frame, 30, 4))['value'] ?? 0)
        : (int) (unpack('Vvalue', substr($frame, 26, 4))['value'] ?? 0);
    $senderSentAtMs = $version === videochat_sfu_binary_frame_layout_envelope_version()
        ? videochat_sfu_binary_read_u64_le($frame, 34)
        : videochat_sfu_binary_read_u64_le($frame, 30);
    $payloadByteLength = $version === videochat_sfu_binary_frame_layout_envelope_version()
        ? (int) (unpack('Vvalue', substr($frame, 42, 4))['value'] ?? 0)
        : (int) (unpack('Vvalue', substr($frame, 38, 4))['value'] ?? 0);

    $metadataByteLength = $publisherIdLength + $publisherUserIdLength + $trackIdLength + $frameIdLength + $layoutMetadataLength;
    $expectedByteLength = $headerLength + $metadataByteLength + $payloadByteLength;
    if (
        $payloadByteLength <= 0
        || strlen($frame) !== $expectedByteLength
    ) {
        return ['ok' => false, 'payload' => [], 'error' => 'invalid_binary_envelope'];
    }

    $offset = $headerLength;
    $publisherId = substr($frame, $offset, $publisherIdLength);
    $offset += $publisherIdLength;
    $publisherUserId = substr($frame, $offset, $publisherUserIdLength);
    $offset += $publisherUserIdLength;
    $trackId = substr($frame, $offset, $trackIdLength);
    $offset += $trackIdLength;
    $frameId = substr($frame, $offset, $frameIdLength);
    $offset += $frameIdLength;
    $layoutMetadataJson = $layoutMetadataLength > 0 ? substr($frame, $offset, $layoutMetadataLength) : '';
    $offset += $layoutMetadataLength;
    $payloadBytes = substr($frame, $offset, $payloadByteLength);
    if ($payloadBytes === '') {
        return ['ok' => false, 'payload' => [], 'error' => 'invalid_binary_envelope'];
    }

    $payload = [
        'type' => 'sfu/frame',
        'room_id' => videochat_presence_normalize_room_id($boundRoomId, ''),
        'protocol_version' => max(1, $protocolVersion),
        'publisher_id' => $publisherId,
        'publisher_user_id' => $publisherUserId,
        'track_id' => $trackId,
        'timestamp' => $timestamp,
        'frame_type' => $frameType,
        'protection_mode' => $protectionMode,
        'frame_sequence' => max(0, $frameSequence),
        'sender_sent_at_ms' => max(0, $senderSentAtMs),
        'payload_chars' => videochat_sfu_base64url_encoded_length($payloadByteLength),
        'chunk_count' => 1,
        'payload_bytes' => $payloadByteLength,
    ];
    foreach (videochat_sfu_decode_layout_metadata_json($layoutMetadataJson) as $key => $value) {
        $payload[$key] = $value;
    }
    if ($frameId !== '') {
        $payload['frame_id'] = $frameId;
    }
    if ($protectionMode === 'transport_only') {
        $payload['data_binary'] = $payloadBytes;
    } else {
        $payload['protected_frame'] = videochat_sfu_base64url_encode($payloadBytes);
    }

    return ['ok' => true, 'payload' => $payload, 'error' => ''];
}

function videochat_sfu_send_outbound_message(mixed $socket, array $payload, array $sendContext = []): bool
{
    $type = strtolower(trim((string) ($payload['type'] ?? '')));
    if ($type === 'sfu/frame') {
        $binaryPayload = videochat_sfu_encode_binary_frame_envelope($payload);
        $wirePayloadBytes = is_string($binaryPayload) ? strlen($binaryPayload) : 0;
        $transportMetrics = [
            'transport_path' => 'binary_required',
            'binary_media_required' => true,
            ...$sendContext,
            ...videochat_sfu_transport_metric_fields($payload, $wirePayloadBytes),
        ];
        if (is_string($binaryPayload) && $binaryPayload !== '') {
            try {
                if (king_websocket_send($socket, $binaryPayload, true) === true) {
                    videochat_sfu_log_runtime_event('sfu_frame_binary_send_sample', $transportMetrics, 3000);
                    return true;
                }
            } catch (Throwable) {}
        }
        videochat_sfu_log_runtime_event('sfu_frame_binary_required_send_failed', [
            'publisher_id' => (string) ($payload['publisher_id'] ?? ''),
            'track_id' => (string) ($payload['track_id'] ?? ''),
            'frame_type' => (string) ($payload['frame_type'] ?? 'delta'),
            'protection_mode' => (string) ($payload['protection_mode'] ?? 'transport_only'),
            'payload_chars' => (int) ($payload['payload_chars'] ?? 0),
            ...$transportMetrics,
        ], 3000);
        return false;
    }

    return videochat_presence_send_frame($socket, $payload);
}

/**
 * @return array<string, mixed>
 */
function videochat_sfu_extract_stage_transport_metadata(array $frame): array
{
    $metadata = [];
    $profile = strtolower(trim((string) ($frame['outgoing_video_quality_profile'] ?? ($frame['outgoingVideoQualityProfile'] ?? ''))));
    if ($profile !== '' && preg_match('/^[a-z0-9_-]{1,32}$/', $profile) === 1) {
        $metadata['outgoing_video_quality_profile'] = $profile;
    }
    $recovery = trim((string) ($frame['budget_expected_recovery'] ?? ($frame['budgetExpectedRecovery'] ?? '')));
    if ($recovery !== '' && preg_match('/^[A-Za-z0-9_.:-]{1,96}$/', $recovery) === 1) {
        $metadata['budget_expected_recovery'] = $recovery;
    }
    $stringFields = [
        'publisher_readback_method' => ['publisher_readback_method', 'publisherReadbackMethod'],
        'publisher_browser_encoder_codec' => ['publisher_browser_encoder_codec', 'publisherBrowserEncoderCodec'],
        'publisher_browser_encoder_config_codec' => ['publisher_browser_encoder_config_codec', 'publisherBrowserEncoderConfigCodec'],
        'publisher_browser_encoder_hardware_acceleration' => ['publisher_browser_encoder_hardware_acceleration', 'publisherBrowserEncoderHardwareAcceleration'],
        'publisher_browser_encoder_latency_mode' => ['publisher_browser_encoder_latency_mode', 'publisherBrowserEncoderLatencyMode'],
    ];
    foreach ($stringFields as $target => $keys) {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $frame)) {
                continue;
            }
            $value = trim((string) $frame[$key]);
            if ($value !== '' && preg_match('/^[A-Za-z0-9_.:-]{1,96}$/', $value) === 1) {
                $metadata[$target] = $value;
            }
            break;
        }
    }

    $intFields = [
        'capture_width' => ['capture_width', 'captureWidth'],
        'capture_height' => ['capture_height', 'captureHeight'],
        'frame_width' => ['frame_width', 'frameWidth'],
        'frame_height' => ['frame_height', 'frameHeight'],
        'encoded_payload_bytes' => ['encoded_payload_bytes', 'encodedPayloadBytes'],
        'max_payload_bytes' => ['max_payload_bytes', 'maxPayloadBytes'],
        'budget_max_encoded_bytes_per_frame' => ['budget_max_encoded_bytes_per_frame', 'budgetMaxEncodedBytesPerFrame'],
        'budget_max_keyframe_bytes_per_frame' => ['budget_max_keyframe_bytes_per_frame', 'budgetMaxKeyframeBytesPerFrame'],
        'budget_max_wire_bytes_per_second' => ['budget_max_wire_bytes_per_second', 'budgetMaxWireBytesPerSecond'],
        'budget_max_queue_age_ms' => ['budget_max_queue_age_ms', 'budgetMaxQueueAgeMs'],
        'queued_age_ms' => ['queued_age_ms', 'queuedAgeMs'],
        'queue_age_ms' => ['queue_age_ms', 'queueAgeMs'],
        'budget_max_buffered_bytes' => ['budget_max_buffered_bytes', 'budgetMaxBufferedBytes'],
        'budget_payload_soft_limit_bytes' => ['budget_payload_soft_limit_bytes', 'budgetPayloadSoftLimitBytes'],
        'budget_min_keyframe_retry_ms' => ['budget_min_keyframe_retry_ms', 'budgetMinKeyframeRetryMs'],
        'outbound_media_generation' => ['outbound_media_generation', 'outboundMediaGeneration'],
        'king_receive_at_ms' => ['king_receive_at_ms', 'kingReceiveAtMs'],
        'publisher_browser_encoder_bitrate' => ['publisher_browser_encoder_bitrate', 'publisherBrowserEncoderBitrate'],
    ];
    foreach ($intFields as $target => $keys) {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $frame)) {
                continue;
            }
            $value = max(0, (int) $frame[$key]);
            if ($value > 0) {
                $metadata[$target] = $value;
            }
            break;
        }
    }

    $floatFields = [
        'capture_frame_rate' => ['capture_frame_rate', 'captureFrameRate'],
        'draw_image_ms' => ['draw_image_ms', 'drawImageMs'],
        'readback_ms' => ['readback_ms', 'readbackMs'],
        'encode_ms' => ['encode_ms', 'encodeMs'],
        'local_stage_elapsed_ms' => ['local_stage_elapsed_ms', 'localStageElapsedMs'],
        'budget_max_encode_ms' => ['budget_max_encode_ms', 'budgetMaxEncodeMs'],
        'budget_max_draw_image_ms' => ['budget_max_draw_image_ms', 'budgetMaxDrawImageMs'],
        'budget_max_readback_ms' => ['budget_max_readback_ms', 'budgetMaxReadbackMs'],
        'budget_payload_soft_limit_ratio' => ['budget_payload_soft_limit_ratio', 'budgetPayloadSoftLimitRatio'],
        'send_drain_ms' => ['send_drain_ms', 'sendDrainMs'],
        'king_receive_latency_ms' => ['king_receive_latency_ms', 'kingReceiveLatencyMs'],
        'king_fanout_latency_ms' => ['king_fanout_latency_ms', 'kingFanoutLatencyMs'],
        'subscriber_send_latency_ms' => ['subscriber_send_latency_ms', 'subscriberSendLatencyMs'],
        'live_relay_age_ms' => ['live_relay_age_ms', 'liveRelayAgeMs'],
        'receiver_render_latency_ms' => ['receiver_render_latency_ms', 'receiverRenderLatencyMs'],
    ];
    foreach ($floatFields as $target => $keys) {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $frame)) {
                continue;
            }
            $value = max(0.0, (float) $frame[$key]);
            if ($value > 0.0) {
                $metadata[$target] = round($value, 3);
            }
            break;
        }
    }

    return $metadata;
}

function videochat_sfu_extract_layout_metadata(array $frame): array
{
    $layoutMode = strtolower(trim((string) ($frame['layout_mode'] ?? '')));
    $metadata = array_merge([
        'codec_id' => videochat_sfu_normalize_codec_id((string) ($frame['codec_id'] ?? ($frame['codecId'] ?? ''))),
        'runtime_id' => videochat_sfu_normalize_runtime_id((string) ($frame['runtime_id'] ?? ($frame['runtimeId'] ?? ''))),
    ], videochat_sfu_extract_stage_transport_metadata($frame));
    if ($layoutMode === '') {
        return $metadata;
    }

    return array_merge($metadata, [
        'layout_mode' => in_array($layoutMode, ['tile_foreground', 'background_snapshot'], true)
            ? $layoutMode
            : 'full_frame',
        'layer_id' => in_array((string) ($frame['layer_id'] ?? ''), ['foreground', 'background'], true)
            ? (string) $frame['layer_id']
            : 'full',
        'cache_epoch' => max(0, (int) ($frame['cache_epoch'] ?? 0)),
        'tile_columns' => max(0, (int) ($frame['tile_columns'] ?? 0)),
        'tile_rows' => max(0, (int) ($frame['tile_rows'] ?? 0)),
        'tile_width' => max(0, (int) ($frame['tile_width'] ?? 0)),
        'tile_height' => max(0, (int) ($frame['tile_height'] ?? 0)),
        'tile_indices' => is_array($frame['tile_indices'] ?? null)
            ? array_values(array_map(static fn ($value): int => max(0, (int) $value), $frame['tile_indices']))
            : [],
        'roi_norm_x' => max(0.0, min(1.0, (float) ($frame['roi_norm_x'] ?? 0))),
        'roi_norm_y' => max(0.0, min(1.0, (float) ($frame['roi_norm_y'] ?? 0))),
        'roi_norm_width' => max(0.0, min(1.0, (float) ($frame['roi_norm_width'] ?? 1))),
        'roi_norm_height' => max(0.0, min(1.0, (float) ($frame['roi_norm_height'] ?? 1))),
    ]);
}

function videochat_sfu_encode_layout_metadata_json(array $frame): string
{
    $metadata = videochat_sfu_extract_layout_metadata($frame);
    if ($metadata === []) {
        return '';
    }
    $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES);
    return is_string($encoded) ? $encoded : '';
}

/**
 * @return array<string, mixed>
 */
function videochat_sfu_decode_layout_metadata_json(string $json): array
{
    $normalized = trim($json);
    if ($normalized === '') {
        return [];
    }
    $decoded = json_decode($normalized, true);
    return is_array($decoded) ? videochat_sfu_extract_layout_metadata($decoded) : [];
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, int|float|string|bool>
 */
function videochat_sfu_transport_metric_fields(array $payload, int $wirePayloadBytes = 0): array
{
    $layoutMode = strtolower(trim((string) ($payload['layout_mode'] ?? 'full_frame')));
    if ($layoutMode !== 'tile_foreground' && $layoutMode !== 'background_snapshot') {
        $layoutMode = 'full_frame';
    }
    $layerId = strtolower(trim((string) ($payload['layer_id'] ?? 'full')));
    if ($layerId !== 'foreground' && $layerId !== 'background') {
        $layerId = 'full';
    }
    $tileIndices = is_array($payload['tile_indices'] ?? null) ? $payload['tile_indices'] : [];
    $roiNormWidth = max(0.0, min(1.0, (float) ($payload['roi_norm_width'] ?? 1.0)));
    $roiNormHeight = max(0.0, min(1.0, (float) ($payload['roi_norm_height'] ?? 1.0)));
    $payloadBytes = max(0, (int) ($payload['payload_bytes'] ?? 0));
    $payloadChars = max(0, (int) ($payload['payload_chars'] ?? 0));
    $binaryContinuationRequired = $wirePayloadBytes > videochat_sfu_binary_continuation_threshold_bytes();
    return [
        'layout_mode' => $layoutMode,
        'layer_id' => $layerId,
        'transport_frame_kind' => $layoutMode . ':' . $layerId,
        'codec_id' => videochat_sfu_normalize_codec_id((string) ($payload['codec_id'] ?? ($payload['codecId'] ?? ''))),
        'runtime_id' => videochat_sfu_normalize_runtime_id((string) ($payload['runtime_id'] ?? ($payload['runtimeId'] ?? ''))),
        ...videochat_sfu_extract_stage_transport_metadata($payload),
        'cache_epoch' => max(0, (int) ($payload['cache_epoch'] ?? 0)),
        'tile_count' => count($tileIndices),
        'selection_tile_count' => max(0, (int) ($payload['selection_tile_count'] ?? 0)),
        'selection_total_tile_count' => max(0, (int) ($payload['selection_total_tile_count'] ?? 0)),
        'selection_tile_ratio' => max(0.0, min(1.0, (float) ($payload['selection_tile_ratio'] ?? 0.0))),
        'selection_mask_guided' => (bool) ($payload['selection_mask_guided'] ?? false),
        'roi_area_ratio' => round($roiNormWidth * $roiNormHeight, 6),
        'payload_bytes' => $payloadBytes,
        'payload_chars' => $payloadChars,
        'legacy_base64_overhead_bytes' => max(0, $payloadChars - $payloadBytes),
        'wire_payload_bytes' => max(0, $wirePayloadBytes),
        'wire_overhead_bytes' => max(0, $wirePayloadBytes - $payloadBytes),
        'binary_envelope_version' => videochat_sfu_binary_frame_layout_envelope_version(),
        'binary_continuation_state' => $binaryContinuationRequired
            ? 'receiver_reassembles_rfc_continuation_frames'
            : 'single_binary_message_no_continuation_expected',
        'binary_continuation_required' => $binaryContinuationRequired,
        'binary_continuation_threshold_bytes' => videochat_sfu_binary_continuation_threshold_bytes(),
        'application_media_chunking' => false,
    ];
}

function videochat_sfu_frame_buffer_max_record_bytes(array $frame): int
{
    $payloadBudgetBytes = max(
        (int) ($frame['budget_max_keyframe_bytes_per_frame'] ?? 0),
        (int) ($frame['budget_max_encoded_bytes_per_frame'] ?? 0),
        (int) ($frame['max_payload_bytes'] ?? 0),
        (int) ($frame['payload_bytes'] ?? 0)
    );
    if ($payloadBudgetBytes <= 0) {
        return 10 * 1024 * 1024;
    }

    return min(10 * 1024 * 1024, max(512 * 1024, (int) ceil($payloadBudgetBytes * 1.5) + 64 * 1024));
}

function videochat_sfu_frame_buffer_should_cleanup(string $roomId, int $nowMs): bool
{
    static $nextCleanupAtByRoom = [];

    $nextCleanupAtMs = (int) ($nextCleanupAtByRoom[$roomId] ?? 0);
    if ($nowMs < $nextCleanupAtMs) {
        return false;
    }
    $nextCleanupAtByRoom[$roomId] = $nowMs + videochat_sfu_frame_buffer_cleanup_interval_ms();
    return true;
}

/**
 * @return array<string, mixed>
 */
function videochat_sfu_decode_stored_frame_payload(string $payloadJson): array
{
    $decoded = json_decode($payloadJson, true);
    if (!is_array($decoded) || strtolower(trim((string) ($decoded['type'] ?? ''))) !== 'sfu/frame') {
        return [];
    }

    $publisherId = trim((string) ($decoded['publisher_id'] ?? ''));
    if ($publisherId === '') {
        return [];
    }

    $frame = $decoded;
    $frame['type'] = 'sfu/frame';
    $frame['publisher_id'] = $publisherId;
    $frame['publisher_user_id'] = (string) ($frame['publisher_user_id'] ?? '');
    $frame['track_id'] = (string) ($frame['track_id'] ?? '');
    $frame['frame_type'] = strtolower(trim((string) ($frame['frame_type'] ?? 'delta'))) === 'keyframe' ? 'keyframe' : 'delta';
    $frame['protocol_version'] = max(1, (int) ($frame['protocol_version'] ?? 1));
    $frame['frame_sequence'] = max(0, (int) ($frame['frame_sequence'] ?? 0));
    $frame['sender_sent_at_ms'] = max(0, (int) ($frame['sender_sent_at_ms'] ?? 0));
    $frame['timestamp'] = max(0, (int) ($frame['timestamp'] ?? 0));
    $frame['chunk_count'] = max(1, (int) ($frame['chunk_count'] ?? 1));

    $protectedFrame = is_string($frame['protected_frame'] ?? null) ? trim((string) $frame['protected_frame']) : '';
    $dataBase64 = is_string($frame['data_base64'] ?? null) ? trim((string) $frame['data_base64']) : '';
    if ($protectedFrame !== '') {
        $parsed = videochat_sfu_parse_protected_frame_envelope($protectedFrame);
        if (!(bool) ($parsed['ok'] ?? false)) {
            return [];
        }
        $frame['protected_frame'] = (string) ($parsed['protected_frame'] ?? '');
        $frame['payload_bytes'] = max(0, (int) ($parsed['byte_length'] ?? 0));
        $frame['payload_chars'] = strlen((string) $frame['protected_frame']);
        $storedProtectionMode = strtolower(trim((string) ($frame['protection_mode'] ?? 'protected')));
        $frame['protection_mode'] = $storedProtectionMode === 'required' ? 'required' : 'protected';
        unset($frame['data'], $frame['data_base64'], $frame['data_binary']);
    } else {
        if ($dataBase64 === '') {
            return [];
        }
        $binary = videochat_sfu_base64url_decode_strict($dataBase64);
        if (!is_string($binary) || $binary === '') {
            return [];
        }
        $frame['data_base64'] = $dataBase64;
        $frame['payload_bytes'] = strlen($binary);
        $frame['payload_chars'] = videochat_sfu_base64url_encoded_length(strlen($binary));
        $frame['protection_mode'] = 'transport_only';
        unset($frame['data'], $frame['data_binary'], $frame['protected_frame']);
    }

    return array_merge($frame, videochat_sfu_normalize_frame_transport_metadata($frame));
}

function videochat_sfu_cleanup_stale_frames(PDO $pdo, ?string $roomId = null): void
{
    $cutoffMs = videochat_sfu_frame_buffer_cutoff_ms();
    $normalizedRoomId = $roomId !== null ? videochat_presence_normalize_room_id($roomId, '') : '';
    if ($normalizedRoomId !== '') {
        $statement = $pdo->prepare('DELETE FROM sfu_frames WHERE room_id = :room_id AND created_at_ms < :cutoff_ms');
        $statement->execute([
            ':room_id' => $normalizedRoomId,
            ':cutoff_ms' => $cutoffMs,
        ]);
        return;
    }

    $statement = $pdo->prepare('DELETE FROM sfu_frames WHERE created_at_ms < :cutoff_ms');
    $statement->execute([':cutoff_ms' => $cutoffMs]);
}

function videochat_sfu_trim_frame_buffer_room(PDO $pdo, string $roomId): void
{
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedRoomId === '') {
        return;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
DELETE FROM sfu_frames
WHERE room_id = :room_id
  AND frame_row_id NOT IN (
      SELECT frame_row_id
      FROM sfu_frames
      WHERE room_id = :room_id_keep
      ORDER BY frame_row_id DESC
      LIMIT :row_limit
  )
SQL
    );
    $statement->bindValue(':room_id', $normalizedRoomId, PDO::PARAM_STR);
    $statement->bindValue(':room_id_keep', $normalizedRoomId, PDO::PARAM_STR);
    $statement->bindValue(':row_limit', videochat_sfu_frame_buffer_max_rows_per_room(), PDO::PARAM_INT);
    $statement->execute();
}

function videochat_sfu_insert_frame(PDO $pdo, string $roomId, string $publisherId, array $frame): bool
{
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    $normalizedPublisherId = trim($publisherId);
    if ($normalizedRoomId === '' || $normalizedPublisherId === '') {
        return false;
    }

    $storedFrame = videochat_sfu_frame_json_safe_for_live_relay($frame);
    $storedFrame['type'] = 'sfu/frame';
    $storedFrame['room_id'] = $normalizedRoomId;
    $storedFrame['publisher_id'] = (string) ($storedFrame['publisher_id'] ?? $normalizedPublisherId);
    $encoded = json_encode($storedFrame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || $encoded === '' || strlen($encoded) > videochat_sfu_frame_buffer_max_record_bytes($storedFrame)) {
        return false;
    }

    if (videochat_sfu_decode_stored_frame_payload($encoded) === []) {
        return false;
    }

    $nowMs = videochat_sfu_now_ms();
    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO sfu_frames(room_id, publisher_id, track_id, frame_id, frame_sequence, created_at_ms, payload_json)
VALUES(:room_id, :publisher_id, :track_id, :frame_id, :frame_sequence, :created_at_ms, :payload_json)
SQL
    );
    $statement->execute([
        ':room_id' => $normalizedRoomId,
        ':publisher_id' => $normalizedPublisherId,
        ':track_id' => (string) ($storedFrame['track_id'] ?? ''),
        ':frame_id' => (string) ($storedFrame['frame_id'] ?? ''),
        ':frame_sequence' => max(0, (int) ($storedFrame['frame_sequence'] ?? 0)),
        ':created_at_ms' => $nowMs,
        ':payload_json' => $encoded,
    ]);

    if (videochat_sfu_frame_buffer_should_cleanup($normalizedRoomId, $nowMs)) {
        videochat_sfu_cleanup_stale_frames($pdo, $normalizedRoomId);
        videochat_sfu_trim_frame_buffer_room($pdo, $normalizedRoomId);
    }

    return true;
}

/**
 * @param array<int|string, string|bool> $localPublisherIds
 * @return array<int, array<string, mixed>>
 */
function videochat_sfu_fetch_buffered_frames(
    PDO $pdo,
    string $roomId,
    string $clientId,
    array $localPublisherIds,
    int &$cursor,
    int $limit = 80
): array {
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedRoomId === '') {
        return [];
    }

    $localPublisherLookup = [];
    foreach ($localPublisherIds as $key => $value) {
        $publisherId = is_string($key) && is_bool($value) ? $key : (string) $value;
        $publisherId = trim($publisherId);
        if ($publisherId !== '') {
            $localPublisherLookup[$publisherId] = true;
        }
    }
    $localPublisherLookup[trim($clientId)] = true;

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT frame_row_id, publisher_id, created_at_ms, payload_json
FROM sfu_frames
WHERE room_id = :room_id
  AND frame_row_id > :cursor
ORDER BY frame_row_id ASC
LIMIT :limit
SQL
    );
    $statement->bindValue(':room_id', $normalizedRoomId, PDO::PARAM_STR);
    $statement->bindValue(':cursor', max(0, $cursor), PDO::PARAM_INT);
    $statement->bindValue(':limit', max(1, min(120, $limit)), PDO::PARAM_INT);
    $statement->execute();

    $frames = [];
    $nowMs = videochat_sfu_now_ms();
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $rowId = max(0, (int) ($row['frame_row_id'] ?? 0));
        $cursor = max($cursor, $rowId);
        $publisherId = trim((string) ($row['publisher_id'] ?? ''));
        if ($publisherId === '' || isset($localPublisherLookup[$publisherId])) {
            continue;
        }

        $frame = videochat_sfu_decode_stored_frame_payload((string) ($row['payload_json'] ?? ''));
        if ($frame === []) {
            continue;
        }
        $createdAtMs = max(0, (int) ($row['created_at_ms'] ?? 0));
        if ($createdAtMs > 0) {
            $frame['sqlite_buffer_age_ms'] = max(0, $nowMs - $createdAtMs);
        }
        $frames[] = $frame;
    }

    return $frames;
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
 * @param array<string|int, mixed> $source
 * @return array<string, mixed>
 */
function videochat_sfu_normalize_frame_transport_metadata(array $source): array
{
    $metadata = [];
    $protocolVersion = (int) ($source['protocol_version'] ?? ($source['protocolVersion'] ?? 0));
    if ($protocolVersion > 0) {
        $metadata['protocol_version'] = min(100, $protocolVersion);
    }
    $frameSequence = (int) ($source['frame_sequence'] ?? ($source['frameSequence'] ?? 0));
    if ($frameSequence > 0) {
        $metadata['frame_sequence'] = $frameSequence;
    }
    $senderSentAtMs = (int) ($source['sender_sent_at_ms'] ?? ($source['senderSentAtMs'] ?? 0));
    if ($senderSentAtMs > 0) {
        $metadata['sender_sent_at_ms'] = $senderSentAtMs;
    }
    $payloadChars = (int) ($source['payload_chars'] ?? ($source['payloadChars'] ?? 0));
    if ($payloadChars > 0) {
        $metadata['payload_chars'] = $payloadChars;
    }
    $chunkCount = (int) ($source['chunk_count'] ?? ($source['chunkCount'] ?? 0));
    if ($chunkCount > 0) {
        $metadata['chunk_count'] = min(4096, $chunkCount);
    }
    $frameId = trim((string) ($source['frame_id'] ?? ($source['frameId'] ?? '')));
    if ($frameId !== '' && preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $frameId) === 1) {
        $metadata['frame_id'] = $frameId;
    }
    $metadata['codec_id'] = videochat_sfu_normalize_codec_id((string) ($source['codec_id'] ?? ($source['codecId'] ?? '')));
    $metadata['runtime_id'] = videochat_sfu_normalize_runtime_id((string) ($source['runtime_id'] ?? ($source['runtimeId'] ?? '')));
    $selectionTileCount = (int) ($source['selection_tile_count'] ?? ($source['selectionTileCount'] ?? 0));
    if ($selectionTileCount > 0) {
        $metadata['selection_tile_count'] = $selectionTileCount;
    }
    $selectionTotalTileCount = (int) ($source['selection_total_tile_count'] ?? ($source['selectionTotalTileCount'] ?? 0));
    if ($selectionTotalTileCount > 0) {
        $metadata['selection_total_tile_count'] = $selectionTotalTileCount;
    }
    $selectionTileRatio = (float) ($source['selection_tile_ratio'] ?? ($source['selectionTileRatio'] ?? -1));
    if ($selectionTileRatio >= 0) {
        $metadata['selection_tile_ratio'] = max(0.0, min(1.0, $selectionTileRatio));
    }
    if (array_key_exists('selection_mask_guided', $source) || array_key_exists('selectionMaskGuided', $source)) {
        $metadata['selection_mask_guided'] = (bool) ($source['selection_mask_guided'] ?? $source['selectionMaskGuided']);
    }
    $metadata = array_merge($metadata, videochat_sfu_extract_stage_transport_metadata($source));
    $protectionMode = strtolower(trim((string) ($source['protection_mode'] ?? ($source['protectionMode'] ?? ''))));
    if (in_array($protectionMode, ['transport_only', 'protected', 'required'], true)) {
        $metadata['protection_mode'] = $protectionMode;
    }
    return array_merge($metadata, videochat_sfu_extract_layout_metadata($source));
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

    videochat_sfu_cleanup_stale_frames($pdo);
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
    if (!in_array($type, ['sfu/join', 'sfu/publish', 'sfu/layer-preference', 'sfu/subscribe', 'sfu/unpublish', 'sfu/frame', 'sfu/frame-chunk', 'sfu/leave'], true)) {
        return [
            'ok' => false,
            'type' => $type,
            'room_id' => '',
            'payload' => [],
            'error' => $type === '' ? 'missing_type' : 'unsupported_type',
        ];
    }
    if ($type === 'sfu/frame' || $type === 'sfu/frame-chunk') {
        return [
            'ok' => false,
            'type' => $type,
            'room_id' => videochat_presence_normalize_room_id($boundRoomId, ''),
            'payload' => [],
            'error' => 'binary_media_required',
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
    $payload['protocol_version'] = max(1, (int) ($decoded['protocol_version'] ?? ($decoded['protocolVersion'] ?? 1)));
    $payload['frame_sequence'] = max(0, (int) ($decoded['frame_sequence'] ?? ($decoded['frameSequence'] ?? 0)));
    $payload['sender_sent_at_ms'] = max(0, (int) ($decoded['sender_sent_at_ms'] ?? ($decoded['senderSentAtMs'] ?? 0)));
    $payload['payload_chars'] = max(0, (int) ($decoded['payload_chars'] ?? ($decoded['payloadChars'] ?? 0)));
    $payload['chunk_count'] = max(1, (int) ($decoded['chunk_count'] ?? ($decoded['chunkCount'] ?? 1)));
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
        $chunkPayloadChars = (int) ($decoded['chunk_payload_chars'] ?? ($decoded['chunkPayloadChars'] ?? strlen($chunkValue)));
        if ($chunkPayloadChars !== strlen($chunkValue)) {
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
        $payload['protocol_version'] = max(1, (int) ($decoded['protocol_version'] ?? ($decoded['protocolVersion'] ?? 1)));
        $payload['frame_sequence'] = max(0, (int) ($decoded['frame_sequence'] ?? ($decoded['frameSequence'] ?? 0)));
        $payload['sender_sent_at_ms'] = max(0, (int) ($decoded['sender_sent_at_ms'] ?? ($decoded['senderSentAtMs'] ?? 0)));
        $payload['payload_chars'] = max(0, (int) ($decoded['payload_chars'] ?? ($decoded['payloadChars'] ?? 0)));
        $payload['chunk_payload_chars'] = $chunkPayloadChars;
    }
    return [
        'ok' => true,
        'type' => $type,
        'room_id' => $normalizedBoundRoomId,
        'payload' => $payload,
        'error' => '',
    ];
}

require_once __DIR__ . '/realtime_sfu_broker_replay.php';
