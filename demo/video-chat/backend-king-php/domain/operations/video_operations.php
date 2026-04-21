<?php

declare(strict_types=1);

/**
 * Build the admin video-operations snapshot from real participant presence.
 *
 * The dashboard must not count invited/assigned participants as concurrent
 * users. A participant is live only while joined_at is set and left_at is open.
 *
 * @return array<string, mixed>
 */
function videochat_video_operations_snapshot(PDO $pdo, ?int $nowEpoch = null): array
{
    $now = $nowEpoch ?? time();
    $rows = videochat_video_operations_fetch_live_call_rows($pdo);
    $runningCalls = [];
    $concurrentParticipants = 0;

    foreach ($rows as $row) {
        $liveTotal = videochat_video_operations_int($row['live_total'] ?? 0);
        if ($liveTotal <= 0) {
            continue;
        }

        $concurrentParticipants += $liveTotal;
        $runningSince = videochat_video_operations_string($row['running_since'] ?? '');
        $ownerEmail = videochat_video_operations_string($row['owner_email'] ?? '');
        $ownerDisplayName = videochat_video_operations_string($row['owner_display_name'] ?? '');
        $host = $ownerDisplayName !== '' ? $ownerDisplayName : $ownerEmail;

        $runningCalls[] = [
            'id' => videochat_video_operations_string($row['id'] ?? ''),
            'room_id' => videochat_video_operations_string($row['room_id'] ?? ''),
            'title' => videochat_video_operations_string($row['title'] ?? 'Untitled call'),
            'status' => 'live',
            'call_status' => videochat_video_operations_string($row['status'] ?? 'scheduled'),
            'host' => $host !== '' ? $host : 'unknown',
            'owner' => [
                'user_id' => videochat_video_operations_int($row['owner_user_id'] ?? 0),
                'email' => $ownerEmail,
                'display_name' => $ownerDisplayName,
            ],
            'live_participants' => [
                'total' => $liveTotal,
                'internal' => videochat_video_operations_int($row['live_internal'] ?? 0),
                'external' => videochat_video_operations_int($row['live_external'] ?? 0),
            ],
            'assigned_participants' => [
                'total' => videochat_video_operations_int($row['assigned_total'] ?? 0),
                'internal' => videochat_video_operations_int($row['assigned_internal'] ?? 0),
                'external' => videochat_video_operations_int($row['assigned_external'] ?? 0),
            ],
            'running_since' => $runningSince,
            'uptime_seconds' => videochat_video_operations_uptime_seconds($runningSince, $now),
            'starts_at' => videochat_video_operations_string($row['starts_at'] ?? ''),
            'ends_at' => videochat_video_operations_string($row['ends_at'] ?? ''),
        ];
    }

    return [
        'status' => 'ok',
        'metrics' => [
            'live_calls' => count($runningCalls),
            'concurrent_participants' => $concurrentParticipants,
        ],
        'running_calls' => $runningCalls,
        'time' => gmdate('c', $now),
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_video_operations_fetch_live_call_rows(PDO $pdo): array
{
    $statement = $pdo->query(
        <<<'SQL'
SELECT
    calls.id,
    calls.room_id,
    calls.title,
    calls.status,
    calls.starts_at,
    calls.ends_at,
    calls.created_at,
    calls.updated_at,
    owners.id AS owner_user_id,
    owners.email AS owner_email,
    owners.display_name AS owner_display_name,
    live.live_total,
    live.live_internal,
    live.live_external,
    live.running_since,
    COALESCE(assigned.assigned_total, 0) AS assigned_total,
    COALESCE(assigned.assigned_internal, 0) AS assigned_internal,
    COALESCE(assigned.assigned_external, 0) AS assigned_external
FROM calls
INNER JOIN users owners ON owners.id = calls.owner_user_id
INNER JOIN (
    SELECT
        call_id,
        COUNT(*) AS live_total,
        SUM(CASE WHEN source = 'internal' THEN 1 ELSE 0 END) AS live_internal,
        SUM(CASE WHEN source = 'external' THEN 1 ELSE 0 END) AS live_external,
        MIN(joined_at) AS running_since
    FROM call_participants
    WHERE joined_at IS NOT NULL
      AND trim(joined_at) <> ''
      AND (left_at IS NULL OR trim(left_at) = '')
    GROUP BY call_id
) live ON live.call_id = calls.id
LEFT JOIN (
    SELECT
        call_id,
        COUNT(*) AS assigned_total,
        SUM(CASE WHEN source = 'internal' THEN 1 ELSE 0 END) AS assigned_internal,
        SUM(CASE WHEN source = 'external' THEN 1 ELSE 0 END) AS assigned_external
    FROM call_participants
    GROUP BY call_id
) assigned ON assigned.call_id = calls.id
WHERE lower(trim(calls.status)) NOT IN ('ended', 'cancelled')
ORDER BY
    live.running_since ASC,
    calls.starts_at ASC,
    calls.created_at ASC,
    calls.id ASC
SQL
    );

    $rows = $statement instanceof PDOStatement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
    return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
}

function videochat_video_operations_uptime_seconds(string $runningSince, int $nowEpoch): int
{
    $trimmed = trim($runningSince);
    if ($trimmed === '') {
        return 0;
    }

    $startedAt = strtotime($trimmed);
    if ($startedAt === false || $startedAt > $nowEpoch) {
        return 0;
    }

    return max(0, $nowEpoch - $startedAt);
}

function videochat_video_operations_int(mixed $value): int
{
    if (is_int($value)) {
        return $value;
    }

    if (is_float($value)) {
        return (int) $value;
    }

    if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
        return (int) trim($value);
    }

    return 0;
}

function videochat_video_operations_string(mixed $value): string
{
    return is_scalar($value) ? trim((string) $value) : '';
}
