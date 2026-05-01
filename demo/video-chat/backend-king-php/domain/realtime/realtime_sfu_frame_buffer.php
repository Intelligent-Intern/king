<?php

declare(strict_types=1);

function videochat_sfu_frame_buffer_ttl_ms(): int
{
    return 2500;
}

function videochat_sfu_frame_buffer_max_rows_per_room(): int
{
    return 300;
}

function videochat_sfu_frame_buffer_max_room_bytes(): int
{
    return 96 * 1024 * 1024;
}

function videochat_sfu_frame_buffer_eviction_grace_ms(): int
{
    return 250;
}

function videochat_sfu_frame_buffer_poll_batch_limit(): int
{
    return 12;
}

function videochat_sfu_frame_buffer_cleanup_interval_ms(): int
{
    return 250;
}

function videochat_sfu_frame_buffer_cutoff_ms(): int
{
    return videochat_sfu_now_ms() - videochat_sfu_frame_buffer_ttl_ms();
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
 * @return array{row_count: int, total_bytes: int, oldest_age_ms: int, newest_age_ms: int}
 */
function videochat_sfu_frame_buffer_room_pressure(PDO $pdo, string $roomId, ?int $nowMs = null): array
{
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedRoomId === '') {
        return ['row_count' => 0, 'total_bytes' => 0, 'oldest_age_ms' => 0, 'newest_age_ms' => 0];
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*) AS row_count,
       COALESCE(SUM(LENGTH(payload_json)), 0) AS total_bytes,
       COALESCE(MIN(created_at_ms), 0) AS oldest_created_at_ms,
       COALESCE(MAX(created_at_ms), 0) AS newest_created_at_ms
FROM sfu_frames
WHERE room_id = :room_id
SQL
    );
    $statement->execute([':room_id' => $normalizedRoomId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['row_count' => 0, 'total_bytes' => 0, 'oldest_age_ms' => 0, 'newest_age_ms' => 0];
    }

    $effectiveNowMs = $nowMs ?? videochat_sfu_now_ms();
    $oldestCreatedAtMs = max(0, (int) ($row['oldest_created_at_ms'] ?? 0));
    $newestCreatedAtMs = max(0, (int) ($row['newest_created_at_ms'] ?? 0));
    return [
        'row_count' => max(0, (int) ($row['row_count'] ?? 0)),
        'total_bytes' => max(0, (int) ($row['total_bytes'] ?? 0)),
        'oldest_age_ms' => $oldestCreatedAtMs > 0 ? max(0, $effectiveNowMs - $oldestCreatedAtMs) : 0,
        'newest_age_ms' => $newestCreatedAtMs > 0 ? max(0, $effectiveNowMs - $newestCreatedAtMs) : 0,
    ];
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

/**
 * @param array<int, array{frame_row_id: int, created_at_ms: int, record_bytes: int}> $rows
 * @return array<int, int>
 */
function videochat_sfu_frame_buffer_select_age_biased_eviction_rows(
    array $rows,
    int $maxRows,
    int $maxBytes,
    int $nowMs
): array {
    if ($rows === []) {
        return [];
    }

    usort($rows, static function (array $left, array $right): int {
        $createdCmp = ((int) $left['created_at_ms']) <=> ((int) $right['created_at_ms']);
        if ($createdCmp !== 0) {
            return $createdCmp;
        }
        $bytesCmp = ((int) $right['record_bytes']) <=> ((int) $left['record_bytes']);
        if ($bytesCmp !== 0) {
            return $bytesCmp;
        }
        return ((int) $left['frame_row_id']) <=> ((int) $right['frame_row_id']);
    });

    $remainingRows = count($rows);
    $remainingBytes = array_reduce(
        $rows,
        static fn (int $sum, array $row): int => $sum + max(0, (int) $row['record_bytes']),
        0
    );
    if ($remainingRows <= $maxRows && $remainingBytes <= $maxBytes) {
        return [];
    }

    $evictRowIds = [];
    $graceCutoffMs = max(0, $nowMs - videochat_sfu_frame_buffer_eviction_grace_ms());
    $passes = [
        static fn (array $row): bool => (int) $row['created_at_ms'] <= $graceCutoffMs,
        static fn (array $row): bool => true,
    ];
    foreach ($passes as $isEligible) {
        foreach ($rows as $row) {
            if ($remainingRows <= $maxRows && $remainingBytes <= $maxBytes) {
                break 2;
            }
            $rowId = max(0, (int) $row['frame_row_id']);
            if ($rowId <= 0 || isset($evictRowIds[$rowId]) || !$isEligible($row)) {
                continue;
            }
            $evictRowIds[$rowId] = $rowId;
            $remainingRows -= 1;
            $remainingBytes -= max(0, (int) $row['record_bytes']);
        }
    }

    return array_values($evictRowIds);
}

/**
 * @return array{evicted_rows: int, evicted_bytes: int, before: array<string, int>, after: array<string, int>}
 */
function videochat_sfu_trim_frame_buffer_room(PDO $pdo, string $roomId): array
{
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedRoomId === '') {
        return [
            'evicted_rows' => 0,
            'evicted_bytes' => 0,
            'before' => ['row_count' => 0, 'total_bytes' => 0],
            'after' => ['row_count' => 0, 'total_bytes' => 0],
        ];
    }

    $nowMs = videochat_sfu_now_ms();
    $before = videochat_sfu_frame_buffer_room_pressure($pdo, $normalizedRoomId, $nowMs);
    if (
        (int) $before['row_count'] <= videochat_sfu_frame_buffer_max_rows_per_room()
        && (int) $before['total_bytes'] <= videochat_sfu_frame_buffer_max_room_bytes()
    ) {
        return [
            'evicted_rows' => 0,
            'evicted_bytes' => 0,
            'before' => $before,
            'after' => $before,
        ];
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT frame_row_id,
       created_at_ms,
       LENGTH(payload_json) AS record_bytes
FROM sfu_frames
WHERE room_id = :room_id
ORDER BY created_at_ms ASC, frame_row_id ASC
SQL
    );
    $statement->execute([':room_id' => $normalizedRoomId]);
    $rows = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $rows[] = [
            'frame_row_id' => max(0, (int) ($row['frame_row_id'] ?? 0)),
            'created_at_ms' => max(0, (int) ($row['created_at_ms'] ?? 0)),
            'record_bytes' => max(0, (int) ($row['record_bytes'] ?? 0)),
        ];
    }

    $evictRowIds = videochat_sfu_frame_buffer_select_age_biased_eviction_rows(
        $rows,
        videochat_sfu_frame_buffer_max_rows_per_room(),
        videochat_sfu_frame_buffer_max_room_bytes(),
        $nowMs
    );
    if ($evictRowIds === []) {
        return [
            'evicted_rows' => 0,
            'evicted_bytes' => 0,
            'before' => $before,
            'after' => $before,
        ];
    }

    $recordBytesByRowId = [];
    foreach ($rows as $row) {
        $recordBytesByRowId[(int) $row['frame_row_id']] = max(0, (int) $row['record_bytes']);
    }
    $evictedBytes = 0;
    foreach ($evictRowIds as $rowId) {
        $evictedBytes += (int) ($recordBytesByRowId[$rowId] ?? 0);
    }

    foreach (array_chunk($evictRowIds, 80) as $rowIdChunk) {
        $placeholders = implode(',', array_fill(0, count($rowIdChunk), '?'));
        $delete = $pdo->prepare('DELETE FROM sfu_frames WHERE room_id = ? AND frame_row_id IN (' . $placeholders . ')');
        $delete->execute([$normalizedRoomId, ...$rowIdChunk]);
    }

    $after = videochat_sfu_frame_buffer_room_pressure($pdo, $normalizedRoomId, $nowMs);
    if (function_exists('videochat_sfu_log_runtime_event')) {
        videochat_sfu_log_runtime_event('sfu_frame_buffer_age_biased_eviction', [
            'room_id_hash' => substr(hash('sha256', $normalizedRoomId), 0, 16),
            'evicted_rows' => count($evictRowIds),
            'evicted_bytes' => $evictedBytes,
            'buffer_rows_before' => (int) $before['row_count'],
            'buffer_rows_after' => (int) $after['row_count'],
            'buffer_bytes_before' => (int) $before['total_bytes'],
            'buffer_bytes_after' => (int) $after['total_bytes'],
            'buffer_oldest_age_ms_before' => (int) $before['oldest_age_ms'],
            'buffer_oldest_age_ms_after' => (int) $after['oldest_age_ms'],
            'buffer_max_rows' => videochat_sfu_frame_buffer_max_rows_per_room(),
            'buffer_max_bytes' => videochat_sfu_frame_buffer_max_room_bytes(),
            'eviction_policy' => 'age_biased_bounded_room_pressure',
        ], 1000);
    }

    return [
        'evicted_rows' => count($evictRowIds),
        'evicted_bytes' => $evictedBytes,
        'before' => $before,
        'after' => $after,
    ];
}

function videochat_sfu_insert_frame(PDO $pdo, string $roomId, string $publisherId, array $frame, ?string &$error = null): bool
{
    $error = '';
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    $normalizedPublisherId = trim($publisherId);
    if ($normalizedRoomId === '' || $normalizedPublisherId === '') {
        $error = 'invalid_room_or_publisher';
        return false;
    }

    $storedFrame = videochat_sfu_frame_json_safe_for_live_relay($frame);
    $storedFrame['type'] = 'sfu/frame';
    $storedFrame['room_id'] = $normalizedRoomId;
    $storedFrame['publisher_id'] = (string) ($storedFrame['publisher_id'] ?? $normalizedPublisherId);
    $encoded = json_encode($storedFrame, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || $encoded === '' || strlen($encoded) > videochat_sfu_frame_buffer_max_record_bytes($storedFrame)) {
        $error = 'record_budget_exceeded';
        return false;
    }

    if (videochat_sfu_decode_stored_frame_payload($encoded) === []) {
        $error = 'stored_payload_decode_failed';
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
    }
    videochat_sfu_trim_frame_buffer_room($pdo, $normalizedRoomId);

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
