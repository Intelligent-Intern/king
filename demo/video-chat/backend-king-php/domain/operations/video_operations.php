<?php

declare(strict_types=1);

require_once __DIR__ . '/../realtime/realtime_call_presence_db.php';
require_once __DIR__ . '/../realtime/realtime_sfu_store.php';

/**
 * Build the admin video-operations snapshot from current realtime presence.
 *
 * The dashboard must not count invited/assigned participants as concurrent
 * users. A participant is live only while a fresh websocket call-presence row
 * exists; SFU publisher state is reported separately as media-plane telemetry.
 *
 * @return array<string, mixed>
 */
function videochat_video_operations_snapshot(PDO $pdo, ?int $nowEpoch = null): array
{
    $now = $nowEpoch ?? time();
    $nowMs = $now * 1000;
    videochat_realtime_presence_db_bootstrap($pdo);
    videochat_sfu_bootstrap($pdo);
    videochat_realtime_presence_db_prune($pdo, $nowMs);

    $rows = videochat_video_operations_fetch_live_call_rows($pdo, $nowMs);
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
            'sfu' => [
                'publishers' => videochat_video_operations_int($row['sfu_publishers'] ?? 0),
                'publisher_users' => videochat_video_operations_int($row['sfu_publisher_users'] ?? 0),
            ],
            'assigned_participants' => [
                'total' => videochat_video_operations_int($row['assigned_total'] ?? 0),
                'internal' => videochat_video_operations_int($row['assigned_internal'] ?? 0),
                'external' => videochat_video_operations_int($row['assigned_external'] ?? 0),
            ],
            'presence' => [
                'ttl_ms' => videochat_realtime_presence_db_ttl_ms(),
                'source' => 'realtime_presence_connections',
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
        'transport' => videochat_video_operations_sfu_transport_snapshot($pdo, $nowMs),
        'running_calls' => $runningCalls,
        'time' => gmdate('c', $now),
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_video_operations_sfu_transport_snapshot(PDO $pdo, int $nowMs): array
{
    $cutoffMs = max(0, $nowMs - videochat_realtime_presence_db_ttl_ms());
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT data_json
FROM sfu_frames
WHERE created_at_ms >= :cutoff_ms
ORDER BY id DESC
LIMIT 240
SQL
    );
    $statement->execute([
        ':cutoff_ms' => $cutoffMs,
    ]);

    $rows = $statement instanceof PDOStatement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
    $kindStats = [];
    $recentFrameCount = 0;
    $matteGuidedFrameCount = 0;
    $selectionRatioSum = 0.0;
    $selectionRatioCount = 0;
    $roiAreaRatioSum = 0.0;
    $roiAreaRatioCount = 0;

    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $decoded = json_decode((string) ($row['data_json'] ?? '[]'), true);
        if (!is_array($decoded)) {
            continue;
        }
        $storedPayload = videochat_sfu_decode_stored_frame_payload($decoded);
        $metadata = is_array($storedPayload['metadata'] ?? null) ? $storedPayload['metadata'] : [];
        $metricFields = videochat_sfu_transport_metric_fields($metadata, 0);
        $kind = (string) ($metricFields['transport_frame_kind'] ?? 'full_frame:full');
        if (!isset($kindStats[$kind]) || !is_array($kindStats[$kind])) {
            $kindStats[$kind] = [
                'kind' => $kind,
                'frames' => 0,
                'matte_guided_frames' => 0,
                'selection_tile_ratio_sum' => 0.0,
                'selection_tile_ratio_count' => 0,
                'roi_area_ratio_sum' => 0.0,
                'roi_area_ratio_count' => 0,
            ];
        }

        $recentFrameCount += 1;
        $selectionMaskGuided = (bool) ($metricFields['selection_mask_guided'] ?? false);
        if ($selectionMaskGuided) {
            $matteGuidedFrameCount += 1;
            $kindStats[$kind]['matte_guided_frames'] += 1;
        }

        $selectionTileRatio = (float) ($metricFields['selection_tile_ratio'] ?? -1.0);
        if ($selectionTileRatio >= 0.0) {
            $selectionRatioSum += $selectionTileRatio;
            $selectionRatioCount += 1;
            $kindStats[$kind]['selection_tile_ratio_sum'] += $selectionTileRatio;
            $kindStats[$kind]['selection_tile_ratio_count'] += 1;
        }

        $roiAreaRatio = (float) ($metricFields['roi_area_ratio'] ?? -1.0);
        if ($roiAreaRatio >= 0.0) {
            $roiAreaRatioSum += $roiAreaRatio;
            $roiAreaRatioCount += 1;
            $kindStats[$kind]['roi_area_ratio_sum'] += $roiAreaRatio;
            $kindStats[$kind]['roi_area_ratio_count'] += 1;
        }

        $kindStats[$kind]['frames'] += 1;
    }

    $kindRows = array_values(array_map(static function (array $row): array {
        $selectionRatioCount = max(0, (int) ($row['selection_tile_ratio_count'] ?? 0));
        $roiAreaRatioCount = max(0, (int) ($row['roi_area_ratio_count'] ?? 0));
        return [
            'kind' => (string) ($row['kind'] ?? 'unknown'),
            'frames' => max(0, (int) ($row['frames'] ?? 0)),
            'matte_guided_frames' => max(0, (int) ($row['matte_guided_frames'] ?? 0)),
            'avg_selection_tile_ratio' => $selectionRatioCount > 0
                ? round(((float) ($row['selection_tile_ratio_sum'] ?? 0.0)) / $selectionRatioCount, 6)
                : 0.0,
            'avg_roi_area_ratio' => $roiAreaRatioCount > 0
                ? round(((float) ($row['roi_area_ratio_sum'] ?? 0.0)) / $roiAreaRatioCount, 6)
                : 0.0,
        ];
    }, $kindStats));

    usort($kindRows, static function (array $left, array $right): int {
        return ((int) ($right['frames'] ?? 0)) <=> ((int) ($left['frames'] ?? 0));
    });

    return [
        'recent_frame_count' => $recentFrameCount,
        'matte_guided_frame_count' => $matteGuidedFrameCount,
        'avg_selection_tile_ratio' => $selectionRatioCount > 0 ? round($selectionRatioSum / $selectionRatioCount, 6) : 0.0,
        'avg_roi_area_ratio' => $roiAreaRatioCount > 0 ? round($roiAreaRatioSum / $roiAreaRatioCount, 6) : 0.0,
        'frame_kinds' => $kindRows,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_video_operations_fetch_live_call_rows(PDO $pdo, int $nowMs): array
{
    $freshnessMs = videochat_realtime_presence_db_ttl_ms();
    $presenceCutoffMs = max(0, $nowMs - $freshnessMs);
    $sfuCutoffMs = max(0, $nowMs - $freshnessMs);

    $statement = $pdo->prepare(
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
    COALESCE(assigned.assigned_external, 0) AS assigned_external,
    COALESCE(sfu.sfu_publishers, 0) AS sfu_publishers,
    COALESCE(sfu.sfu_publisher_users, 0) AS sfu_publisher_users
FROM calls
INNER JOIN users owners ON owners.id = calls.owner_user_id
INNER JOIN (
    SELECT
        presence.call_id,
        presence.room_id,
        COUNT(DISTINCT presence.user_id) AS live_total,
        COUNT(DISTINCT CASE WHEN COALESCE(participants.is_external, 0) = 1 THEN presence.user_id END) AS live_external,
        COUNT(DISTINCT CASE WHEN COALESCE(participants.is_external, 0) = 0 THEN presence.user_id END) AS live_internal,
        MIN(presence.connected_at) AS running_since
    FROM realtime_presence_connections presence
    LEFT JOIN (
        SELECT
            call_id,
            user_id,
            MAX(CASE WHEN source = 'external' THEN 1 ELSE 0 END) AS is_external
        FROM call_participants
        WHERE user_id IS NOT NULL
        GROUP BY call_id, user_id
    ) participants ON participants.call_id = presence.call_id
       AND participants.user_id = presence.user_id
    WHERE presence.last_seen_at_ms >= :presence_cutoff_ms
    GROUP BY presence.call_id, presence.room_id
) live ON live.call_id = calls.id AND live.room_id = calls.room_id
LEFT JOIN (
    SELECT
        call_id,
        COUNT(*) AS assigned_total,
        SUM(CASE WHEN source = 'internal' THEN 1 ELSE 0 END) AS assigned_internal,
        SUM(CASE WHEN source = 'external' THEN 1 ELSE 0 END) AS assigned_external
    FROM call_participants
    GROUP BY call_id
) assigned ON assigned.call_id = calls.id
LEFT JOIN (
    SELECT
        room_id,
        COUNT(*) AS sfu_publishers,
        COUNT(DISTINCT user_id) AS sfu_publisher_users
    FROM sfu_publishers
    WHERE updated_at_ms >= :sfu_cutoff_ms
    GROUP BY room_id
) sfu ON sfu.room_id = calls.room_id
WHERE lower(trim(calls.status)) NOT IN ('ended', 'cancelled')
ORDER BY
    live.running_since ASC,
    calls.starts_at ASC,
    calls.created_at ASC,
    calls.id ASC
SQL
    );
    $statement->execute([
        ':presence_cutoff_ms' => $presenceCutoffMs,
        ':sfu_cutoff_ms' => $sfuCutoffMs,
    ]);

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
