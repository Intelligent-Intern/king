<?php

declare(strict_types=1);

require_once __DIR__ . '/../realtime/realtime_call_presence_db.php';
require_once __DIR__ . '/../realtime/realtime_sfu_gateway.php';
require_once __DIR__ . '/../realtime/realtime_gossipmesh_room_state.php';
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
    $sfuPublisherCounts = videochat_video_operations_fetch_sfu_publisher_counts($pdo, $nowMs);
    $participantsByCall = videochat_video_operations_fetch_live_call_participants_by_key($pdo, $nowMs);
    $runningCalls = [];
    $concurrentParticipants = 0;

    foreach ($rows as $row) {
        $liveTotal = videochat_video_operations_int($row['live_total'] ?? 0);
        if ($liveTotal <= 0) {
            continue;
        }

        $concurrentParticipants += $liveTotal;
        $callId = videochat_video_operations_string($row['id'] ?? '');
        $roomId = videochat_video_operations_string($row['room_id'] ?? '');
        $runningSince = videochat_video_operations_string($row['running_since'] ?? '');
        $ownerEmail = videochat_video_operations_string($row['owner_email'] ?? '');
        $ownerDisplayName = videochat_video_operations_string($row['owner_display_name'] ?? '');
        $host = $ownerDisplayName !== '' ? $ownerDisplayName : $ownerEmail;
        $sfuCounts = $sfuPublisherCounts[$roomId] ?? [
            'publishers' => videochat_video_operations_int($row['sfu_publishers'] ?? 0),
            'publisher_users' => videochat_video_operations_int($row['sfu_publisher_users'] ?? 0),
        ];
        $gossipParticipants = $participantsByCall[videochat_video_operations_call_key($callId, $roomId)] ?? [];
        $gossipTopologyByPeerId = [];
        if ($gossipParticipants !== []) {
            try {
                $gossipTopologyByPeerId = videochat_gossipmesh_room_state_payloads_by_peer(
                    $callId,
                    $roomId,
                    $gossipParticipants,
                    'operations_snapshot'
                );
            } catch (Throwable) {
                $gossipTopologyByPeerId = [];
            }
        }

        $runningCalls[] = [
            'id' => $callId,
            'room_id' => $roomId,
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
                'publishers' => videochat_video_operations_int($sfuCounts['publishers'] ?? 0),
                'publisher_users' => videochat_video_operations_int($sfuCounts['publisher_users'] ?? 0),
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
            'gossip' => [
                'scope' => 'call',
                'lifecycle' => 'running',
                'topology_state' => $gossipTopologyByPeerId === [] ? 'waiting' : 'spawned',
                'topology_source' => 'realtime_presence_connections',
                'topology_peer_count' => count($gossipTopologyByPeerId),
            ],
            'gossip_topology_by_peer_id' => $gossipTopologyByPeerId,
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
    return [
        'recent_frame_count' => 0,
        'matte_guided_frame_count' => 0,
        'avg_selection_tile_ratio' => 0.0,
        'avg_roi_area_ratio' => 0.0,
        'frame_kinds' => [],
        'storage' => 'disabled',
    ];
}

/**
 * @return array<string, array{publishers: int, publisher_users: int}>
 */
function videochat_video_operations_fetch_sfu_publisher_counts(PDO $pdo, int $nowMs): array
{
    $freshnessMs = videochat_realtime_presence_db_ttl_ms();
    $cutoffMs = max(0, $nowMs - $freshnessMs);
    $publishersByRoom = [];
    $usersByRoom = [];

    foreach (videochat_video_operations_sfu_databases($pdo) as $sfuPdo) {
        foreach (videochat_video_operations_fetch_sfu_publisher_rows($sfuPdo, $cutoffMs) as $row) {
            $roomId = videochat_video_operations_normalize_sfu_room_id(
                videochat_video_operations_string($row['room_id'] ?? '')
            );
            $publisherId = videochat_video_operations_string($row['publisher_id'] ?? '');
            $userId = videochat_video_operations_string($row['user_id'] ?? '');
            if ($roomId === '' || $publisherId === '') {
                continue;
            }
            $publishersByRoom[$roomId][$publisherId] = true;
            if ($userId !== '') {
                $usersByRoom[$roomId][$userId] = true;
            }
        }
    }

    $roomIds = array_unique(array_merge(array_keys($publishersByRoom), array_keys($usersByRoom)));
    $counts = [];
    foreach ($roomIds as $roomId) {
        $counts[$roomId] = [
            'publishers' => count($publishersByRoom[$roomId] ?? []),
            'publisher_users' => count($usersByRoom[$roomId] ?? []),
        ];
    }
    return $counts;
}

/**
 * @return array<int, PDO>
 */
function videochat_video_operations_sfu_databases(PDO $mainPdo): array
{
    $databases = [$mainPdo];
    $brokerPath = function_exists('videochat_sfu_broker_database_path') ? videochat_sfu_broker_database_path() : '';
    $mainPath = trim((string) (getenv('VIDEOCHAT_KING_DB_PATH') ?: ''));
    if ($brokerPath === '' || ($mainPath !== '' && $brokerPath === $mainPath)) {
        return $databases;
    }
    if (!is_file($brokerPath) || !is_readable($brokerPath)) {
        return $databases;
    }

    try {
        $brokerPdo = new PDO('sqlite:' . $brokerPath);
        $brokerPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $databases[] = $brokerPdo;
    } catch (Throwable) {
        // Operations should keep reporting main realtime presence even when
        // the SFU broker snapshot is transiently unavailable.
    }

    return $databases;
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_video_operations_fetch_sfu_publisher_rows(PDO $pdo, int $cutoffMs): array
{
    try {
        videochat_sfu_bootstrap($pdo);
        $statement = $pdo->prepare(
            <<<'SQL'
SELECT room_id, publisher_id, user_id
FROM sfu_publishers
WHERE updated_at_ms >= :cutoff_ms
SQL
        );
        $statement->execute([':cutoff_ms' => $cutoffMs]);
        $rows = $statement instanceof PDOStatement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    } catch (Throwable) {
        return [];
    }
}

function videochat_video_operations_normalize_sfu_room_id(string $roomId): string
{
    $normalized = trim($roomId);
    if ($normalized === '') {
        return '';
    }
    $marker = ':room:';
    $position = strpos($normalized, $marker);
    if (str_starts_with($normalized, 'tenant:') && $position !== false) {
        $normalized = substr($normalized, $position + strlen($marker));
    }
    return trim($normalized);
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

function videochat_video_operations_call_key(string $callId, string $roomId): string
{
    return strtolower(trim($callId)) . '::' . strtolower(trim($roomId));
}

/**
 * @return array<string, array<int, array<string, mixed>>>
 */
function videochat_video_operations_fetch_live_call_participants_by_key(PDO $pdo, int $nowMs): array
{
    $presenceCutoffMs = max(0, $nowMs - videochat_realtime_presence_db_ttl_ms());
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT
    rpc.connection_id,
    rpc.call_id,
    rpc.room_id,
    rpc.user_id,
    rpc.display_name AS presence_display_name,
    rpc.role AS presence_role,
    rpc.call_role AS presence_call_role,
    rpc.connected_at,
    cp.participant_display_name,
    cp.participant_call_role,
    users.display_name AS user_display_name,
    roles.slug AS role_slug
FROM realtime_presence_connections rpc
LEFT JOIN (
    SELECT
        call_id,
        user_id,
        MAX(display_name) AS participant_display_name,
        MAX(call_role) AS participant_call_role
    FROM call_participants
    WHERE user_id IS NOT NULL
    GROUP BY call_id, user_id
) cp ON cp.call_id = rpc.call_id AND cp.user_id = rpc.user_id
LEFT JOIN users ON users.id = rpc.user_id
LEFT JOIN roles ON roles.id = users.role_id
WHERE rpc.last_seen_at_ms >= :presence_cutoff_ms
ORDER BY
    rpc.call_id ASC,
    rpc.room_id ASC,
    rpc.display_name ASC,
    rpc.user_id ASC,
    rpc.connection_id ASC
SQL
    );
    $statement->execute([
        ':presence_cutoff_ms' => $presenceCutoffMs,
    ]);

    $participantsByKey = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $callId = videochat_video_operations_string($row['call_id'] ?? '');
        $roomId = videochat_video_operations_string($row['room_id'] ?? '');
        $userId = videochat_video_operations_int($row['user_id'] ?? 0);
        if ($callId === '' || $roomId === '' || $userId <= 0) {
            continue;
        }
        $key = videochat_video_operations_call_key($callId, $roomId);
        if (isset($participantsByKey[$key][(string) $userId])) {
            continue;
        }

        $displayName = videochat_video_operations_first_string(
            $row['presence_display_name'] ?? '',
            $row['participant_display_name'] ?? '',
            $row['user_display_name'] ?? '',
            'User ' . $userId
        );
        $participantsByKey[$key][(string) $userId] = [
            'connection_id' => videochat_video_operations_string($row['connection_id'] ?? ('operations:' . $callId . ':' . $userId)),
            'room_id' => $roomId,
            'user' => [
                'id' => $userId,
                'display_name' => $displayName,
                'role' => videochat_video_operations_role_slug(
                    videochat_video_operations_first_string($row['presence_role'] ?? '', $row['role_slug'] ?? '', 'user')
                ),
                'call_role' => videochat_video_operations_call_role(
                    videochat_video_operations_first_string($row['presence_call_role'] ?? '', $row['participant_call_role'] ?? '', 'participant')
                ),
            ],
            'connected_at' => videochat_video_operations_string($row['connected_at'] ?? ''),
        ];
    }

    $normalized = [];
    foreach ($participantsByKey as $key => $participantsByUserId) {
        if (is_array($participantsByUserId)) {
            $normalized[(string) $key] = array_values($participantsByUserId);
        }
    }

    return $normalized;
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

function videochat_video_operations_first_string(mixed ...$values): string
{
    foreach ($values as $value) {
        $normalized = videochat_video_operations_string($value);
        if ($normalized !== '') {
            return $normalized;
        }
    }

    return '';
}

function videochat_video_operations_role_slug(mixed $value): string
{
    $normalized = strtolower(videochat_video_operations_string($value));
    return in_array($normalized, ['admin', 'user'], true) ? $normalized : 'user';
}

function videochat_video_operations_call_role(mixed $value): string
{
    $normalized = strtolower(videochat_video_operations_string($value));
    return in_array($normalized, ['owner', 'moderator', 'participant'], true) ? $normalized : 'participant';
}

function videochat_video_operations_string(mixed $value): string
{
    return is_scalar($value) ? trim((string) $value) : '';
}
