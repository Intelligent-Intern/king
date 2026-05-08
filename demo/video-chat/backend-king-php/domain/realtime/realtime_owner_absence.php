<?php

declare(strict_types=1);

require_once __DIR__ . '/realtime_call_presence_db.php';
require_once __DIR__ . '/../calls/call_lifecycle.php';

const VIDEOCHAT_OWNER_ABSENCE_TIMER_MS = 15 * 60 * 1000;
const VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS = 5 * 60 * 1000;

function videochat_realtime_owner_absence_now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}

function videochat_realtime_owner_absence_effective_now_ms(?int $nowMs = null): int
{
    return is_int($nowMs) && $nowMs > 0 ? $nowMs : videochat_realtime_owner_absence_now_ms();
}

function videochat_realtime_owner_absence_iso_from_ms(int $ms): string
{
    return gmdate('c', (int) floor(max(0, $ms) / 1000));
}

function videochat_realtime_owner_absence_ms_from_iso(mixed $value): int
{
    $trimmed = is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    if ($trimmed === '') {
        return 0;
    }

    $unixSeconds = strtotime($trimmed);
    return is_int($unixSeconds) && $unixSeconds > 0 ? $unixSeconds * 1000 : 0;
}

function videochat_realtime_owner_absence_disabled_payload(string $status = 'unavailable'): array
{
    return [
        'enabled' => false,
        'status' => $status,
        'owner_present' => false,
        'active_participant_count' => 0,
        'active_non_owner_count' => 0,
        'timer_ms' => VIDEOCHAT_OWNER_ABSENCE_TIMER_MS,
        'countdown_ms' => VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS,
    ];
}

function videochat_realtime_owner_absence_fetch_call(PDO $pdo, string $callId, string $roomId): ?array
{
    $normalizedCallId = videochat_realtime_normalize_call_id($callId, '');
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedCallId === '' || $normalizedRoomId === '') {
        return null;
    }

    $tenantSelect = videochat_tenant_table_has_column($pdo, 'calls', 'tenant_id')
        ? ', tenant_id'
        : '';
    $statement = $pdo->prepare(
        <<<SQL
SELECT id, room_id, owner_user_id, status{$tenantSelect}
FROM calls
WHERE id = :call_id
  AND room_id = :room_id
LIMIT 1
SQL
    );
    $statement->execute([
        ':call_id' => $normalizedCallId,
        ':room_id' => $normalizedRoomId,
    ]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (string) ($row['id'] ?? ''),
        'room_id' => (string) ($row['room_id'] ?? ''),
        'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
        'status' => strtolower(trim((string) ($row['status'] ?? ''))),
        'tenant_id' => is_numeric($row['tenant_id'] ?? null) ? (int) $row['tenant_id'] : null,
    ];
}

function videochat_realtime_owner_absence_fetch_owner_participant(PDO $pdo, string $callId, int $ownerUserId): array
{
    if ($callId === '' || $ownerUserId <= 0) {
        return ['joined_at' => '', 'left_at' => ''];
    }

    $statement = $pdo->prepare(
        <<<'SQL'
SELECT joined_at, left_at
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :owner_user_id
  AND source = 'internal'
LIMIT 1
SQL
    );
    $statement->execute([
        ':call_id' => $callId,
        ':owner_user_id' => $ownerUserId,
    ]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['joined_at' => '', 'left_at' => ''];
    }

    return [
        'joined_at' => trim((string) ($row['joined_at'] ?? '')),
        'left_at' => trim((string) ($row['left_at'] ?? '')),
    ];
}

function videochat_realtime_owner_absence_active_presence(PDO $pdo, string $callId, string $roomId, int $nowMs): array
{
    $normalizedCallId = videochat_realtime_normalize_call_id($callId, '');
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedCallId === '' || $normalizedRoomId === '') {
        return [];
    }

    videochat_realtime_presence_db_bootstrap($pdo);
    videochat_realtime_presence_db_prune($pdo, $nowMs);
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT connection_id, user_id, call_role, connected_at, last_seen_at_ms
FROM realtime_presence_connections
WHERE call_id = :call_id
  AND room_id = :room_id
  AND last_seen_at_ms >= :cutoff_ms
ORDER BY last_seen_at_ms DESC, connection_id ASC
SQL
    );
    $statement->execute([
        ':call_id' => $normalizedCallId,
        ':room_id' => $normalizedRoomId,
        ':cutoff_ms' => $nowMs - videochat_realtime_presence_db_ttl_ms(),
    ]);

    $rows = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function videochat_realtime_owner_absence_stale_owner_left_at_ms(
    PDO $pdo,
    string $callId,
    string $roomId,
    int $ownerUserId,
    int $nowMs
): int {
    $normalizedCallId = videochat_realtime_normalize_call_id($callId, '');
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedCallId === '' || $normalizedRoomId === '' || $ownerUserId <= 0) {
        return 0;
    }

    videochat_realtime_presence_db_bootstrap($pdo);
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT MAX(last_seen_at_ms)
FROM realtime_presence_connections
WHERE call_id = :call_id
  AND room_id = :room_id
  AND user_id = :owner_user_id
SQL
    );
    $statement->execute([
        ':call_id' => $normalizedCallId,
        ':room_id' => $normalizedRoomId,
        ':owner_user_id' => $ownerUserId,
    ]);
    $lastSeenMs = (int) ($statement->fetchColumn() ?: 0);
    if ($lastSeenMs <= 0) {
        return 0;
    }

    $leftAtMs = $lastSeenMs + videochat_realtime_presence_db_ttl_ms();
    return $leftAtMs < $nowMs ? $leftAtMs : 0;
}

function videochat_realtime_owner_absence_mark_stale_owner_left(
    PDO $pdo,
    string $callId,
    int $ownerUserId,
    int $leftAtMs
): void {
    if ($callId === '' || $ownerUserId <= 0 || $leftAtMs <= 0) {
        return;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET left_at = :left_at
WHERE call_id = :call_id
  AND user_id = :owner_user_id
  AND source = 'internal'
  AND (left_at IS NULL OR left_at = '')
SQL
    );
    $statement->execute([
        ':left_at' => videochat_realtime_owner_absence_iso_from_ms($leftAtMs),
        ':call_id' => $callId,
        ':owner_user_id' => $ownerUserId,
    ]);
}

function videochat_realtime_owner_absence_earliest_non_owner_presence_ms(array $presenceRows, int $ownerUserId): int
{
    $earliestMs = 0;
    foreach ($presenceRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId <= 0 || $userId === $ownerUserId) {
            continue;
        }
        $connectedAtMs = videochat_realtime_owner_absence_ms_from_iso($row['connected_at'] ?? '');
        if ($connectedAtMs <= 0) {
            $connectedAtMs = (int) ($row['last_seen_at_ms'] ?? 0);
        }
        if ($connectedAtMs <= 0) {
            continue;
        }
        $earliestMs = $earliestMs <= 0 ? $connectedAtMs : min($earliestMs, $connectedAtMs);
    }

    return $earliestMs;
}

function videochat_realtime_owner_absence_snapshot(PDO $pdo, string $callId, string $roomId, ?int $nowMs = null): array
{
    $effectiveNowMs = videochat_realtime_owner_absence_effective_now_ms($nowMs);
    $call = videochat_realtime_owner_absence_fetch_call($pdo, $callId, $roomId);
    if (!is_array($call)) {
        return videochat_realtime_owner_absence_disabled_payload('call_not_found');
    }

    $callStatus = (string) ($call['status'] ?? '');
    $ownerUserId = (int) ($call['owner_user_id'] ?? 0);
    if (!in_array($callStatus, ['scheduled', 'active'], true) || $ownerUserId <= 0) {
        return [
            ...videochat_realtime_owner_absence_disabled_payload('call_inactive'),
            'call_status' => $callStatus,
            'owner_user_id' => $ownerUserId,
        ];
    }

    $staleOwnerLeftAtMs = videochat_realtime_owner_absence_stale_owner_left_at_ms(
        $pdo,
        (string) $call['id'],
        (string) $call['room_id'],
        $ownerUserId,
        $effectiveNowMs
    );
    $presenceRows = videochat_realtime_owner_absence_active_presence($pdo, (string) $call['id'], (string) $call['room_id'], $effectiveNowMs);
    $activeUserIds = [];
    $activeNonOwnerUserIds = [];
    $ownerPresent = false;
    foreach ($presenceRows as $row) {
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }
        $activeUserIds[$userId] = $userId;
        if ($userId === $ownerUserId) {
            $ownerPresent = true;
            continue;
        }
        $activeNonOwnerUserIds[$userId] = $userId;
    }

    $basePayload = [
        'enabled' => true,
        'call_id' => (string) $call['id'],
        'room_id' => (string) $call['room_id'],
        'call_status' => $callStatus,
        'tenant_id' => is_numeric($call['tenant_id'] ?? null) ? (int) $call['tenant_id'] : null,
        'owner_user_id' => $ownerUserId,
        'owner_present' => $ownerPresent,
        'active_participant_count' => count($activeUserIds),
        'active_non_owner_count' => count($activeNonOwnerUserIds),
        'timer_ms' => VIDEOCHAT_OWNER_ABSENCE_TIMER_MS,
        'countdown_ms' => VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS,
    ];

    if ($ownerPresent) {
        return [
            ...$basePayload,
            'status' => 'owner_present',
            'countdown_started' => false,
        ];
    }

    if ($activeNonOwnerUserIds === []) {
        return [
            ...$basePayload,
            'status' => 'no_participants',
            'countdown_started' => false,
        ];
    }

    if (!$ownerPresent && $staleOwnerLeftAtMs > 0) {
        videochat_realtime_owner_absence_mark_stale_owner_left($pdo, (string) $call['id'], $ownerUserId, $staleOwnerLeftAtMs);
    }
    $ownerParticipant = videochat_realtime_owner_absence_fetch_owner_participant($pdo, (string) $call['id'], $ownerUserId);
    $absentSinceMs = videochat_realtime_owner_absence_ms_from_iso($ownerParticipant['left_at'] ?? '');
    if ($absentSinceMs <= 0) {
        $absentSinceMs = videochat_realtime_owner_absence_earliest_non_owner_presence_ms($presenceRows, $ownerUserId);
    }
    if ($absentSinceMs <= 0 || $absentSinceMs > $effectiveNowMs) {
        $absentSinceMs = $effectiveNowMs;
    }

    $endsAtMs = $absentSinceMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS;
    $countdownStartsAtMs = max($absentSinceMs, $endsAtMs - VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS);
    $countdownStarted = $effectiveNowMs >= $countdownStartsAtMs;
    $status = $countdownStarted ? 'countdown' : 'monitoring';
    if ($effectiveNowMs >= $endsAtMs) {
        $status = 'ended';
    }

    $payload = [
        ...$basePayload,
        'status' => $status,
        'countdown_started' => $countdownStarted,
        'absent_since' => videochat_realtime_owner_absence_iso_from_ms($absentSinceMs),
        'absent_since_ms' => $absentSinceMs,
        'countdown_starts_at' => videochat_realtime_owner_absence_iso_from_ms($countdownStartsAtMs),
        'countdown_starts_at_ms' => $countdownStartsAtMs,
        'ends_at' => videochat_realtime_owner_absence_iso_from_ms($endsAtMs),
        'ends_at_ms' => $endsAtMs,
    ];

    if ($countdownStarted) {
        $payload['countdown_remaining_ms'] = max(0, $endsAtMs - $effectiveNowMs);
    }
    if ($status === 'ended') {
        $payload['ended_at'] = videochat_realtime_owner_absence_iso_from_ms($effectiveNowMs);
        $payload['ended_at_ms'] = $effectiveNowMs;
        $payload['ended_reason'] = 'owner_absent_timeout';
    }

    return $payload;
}

function videochat_realtime_apply_owner_absence_timeout(PDO $pdo, string $callId, string $roomId, ?int $nowMs = null): array
{
    $effectiveNowMs = videochat_realtime_owner_absence_effective_now_ms($nowMs);
    $snapshot = videochat_realtime_owner_absence_snapshot($pdo, $callId, $roomId, $effectiveNowMs);
    if ((string) ($snapshot['status'] ?? '') !== 'ended' || !(bool) ($snapshot['enabled'] ?? false)) {
        return $snapshot;
    }

    try {
        $endedAt = videochat_realtime_owner_absence_iso_from_ms($effectiveNowMs);
        $pdo->beginTransaction();
        $updateCall = $pdo->prepare(
            <<<'SQL'
UPDATE calls
SET status = 'ended',
    updated_at = :updated_at
WHERE id = :call_id
  AND room_id = :room_id
  AND status IN ('scheduled', 'active')
SQL
        );
        $updateCall->execute([
            ':updated_at' => $endedAt,
            ':call_id' => (string) ($snapshot['call_id'] ?? $callId),
            ':room_id' => (string) ($snapshot['room_id'] ?? $roomId),
        ]);

        $updateParticipants = $pdo->prepare(
            <<<'SQL'
UPDATE call_participants
SET left_at = CASE
    WHEN joined_at IS NOT NULL AND left_at IS NULL THEN :left_at
    ELSE left_at
END
WHERE call_id = :call_id
SQL
        );
        $updateParticipants->execute([
            ':left_at' => $endedAt,
            ':call_id' => (string) ($snapshot['call_id'] ?? $callId),
        ]);
        $transitioned = $updateCall->rowCount() > 0;
        $pdo->commit();
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            ...$snapshot,
            'status' => 'error',
            'error' => 'owner_absence_end_failed',
        ];
    }

    $lifecycle = [
        'ok' => true,
        'reason' => 'not_applied',
        'transition' => 'ended',
        'invalidated_link_count' => 0,
        'revoked_access_session_count' => 0,
        'lobby_cleared_count' => 0,
        'presence_cleared_count' => 0,
    ];
    if ($transitioned) {
        $tenantId = is_numeric($snapshot['tenant_id'] ?? null) ? (int) $snapshot['tenant_id'] : null;
        $lifecycle = videochat_apply_call_terminal_lifecycle(
            $pdo,
            [
                'id' => (string) ($snapshot['call_id'] ?? $callId),
                'room_id' => (string) ($snapshot['room_id'] ?? $roomId),
                'owner_user_id' => is_numeric($snapshot['owner_user_id'] ?? null) ? (int) $snapshot['owner_user_id'] : 0,
                'tenant_id' => $tenantId,
                'status' => 'ended',
            ],
            'ended',
            $tenantId,
            null
        );
        if (!(bool) ($lifecycle['ok'] ?? false)) {
            return [
                ...$snapshot,
                'call_status' => 'ended',
                'transitioned' => true,
                'status' => 'error',
                'error' => 'owner_absence_lifecycle_failed',
                'lifecycle' => $lifecycle,
            ];
        }
    }

    return [
        ...$snapshot,
        'call_status' => 'ended',
        'transitioned' => $transitioned,
        'lifecycle' => $lifecycle,
    ];
}
