<?php

declare(strict_types=1);

function videochat_activity_layout_modes(): array
{
    return ['grid', 'main_mini', 'main_only'];
}

function videochat_activity_layout_strategies(): array
{
    return ['manual_pinned', 'most_active_window', 'active_speaker_main', 'round_robin_active'];
}

function videochat_activity_now_ms(?int $nowUnixMs = null): int
{
    return is_int($nowUnixMs) && $nowUnixMs > 0 ? $nowUnixMs : (int) floor(microtime(true) * 1000);
}

function videochat_activity_clamp_float(mixed $value, float $min, float $max, float $fallback = 0.0): float
{
    $number = is_numeric($value) ? (float) $value : $fallback;
    if (!is_finite($number)) {
        $number = $fallback;
    }

    return max($min, min($max, $number));
}

function videochat_activity_normalize_mode(mixed $value, string $fallback = 'main_mini'): string
{
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, videochat_activity_layout_modes(), true) ? $normalized : $fallback;
}

function videochat_activity_normalize_strategy(mixed $value, string $fallback = 'manual_pinned'): string
{
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, videochat_activity_layout_strategies(), true) ? $normalized : $fallback;
}

function videochat_activity_layout_bootstrap(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS call_layout_state (
    call_id TEXT PRIMARY KEY REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    mode TEXT NOT NULL DEFAULT 'main_mini' CHECK (mode IN ('grid', 'main_mini', 'main_only')),
    strategy TEXT NOT NULL DEFAULT 'manual_pinned' CHECK (strategy IN ('manual_pinned', 'most_active_window', 'active_speaker_main', 'round_robin_active')),
    automation_paused INTEGER NOT NULL DEFAULT 0 CHECK (automation_paused IN (0, 1)),
    pinned_user_ids_json TEXT NOT NULL DEFAULT '[]',
    main_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    selected_user_ids_json TEXT NOT NULL DEFAULT '[]',
    updated_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_layout_state_room_id ON call_layout_state(room_id)');

    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS call_participant_activity (
    call_id TEXT NOT NULL REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    room_id TEXT NOT NULL REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    raw_score REAL NOT NULL DEFAULT 0,
    audio_level REAL NOT NULL DEFAULT 0,
    motion_score REAL NOT NULL DEFAULT 0,
    gesture_score REAL NOT NULL DEFAULT 0,
    is_speaking INTEGER NOT NULL DEFAULT 0 CHECK (is_speaking IN (0, 1)),
    source TEXT NOT NULL DEFAULT 'client_observed',
    updated_at_ms INTEGER NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (call_id, user_id)
)
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_participant_activity_room_score ON call_participant_activity(room_id, updated_at_ms)');
}

function videochat_activity_public_participant(array $connection): array
{
    return [
        'user_id' => (int) ($connection['user_id'] ?? 0),
        'display_name' => (string) ($connection['display_name'] ?? ''),
        'role' => videochat_normalize_role_slug((string) ($connection['role'] ?? 'user')),
        'call_role' => videochat_normalize_call_participant_role((string) ($connection['call_role'] ?? 'participant')),
    ];
}

function videochat_activity_authorizes_layout(array $connection): bool
{
    if (videochat_normalize_role_slug((string) ($connection['role'] ?? '')) === 'admin') {
        return true;
    }

    if ((bool) ($connection['can_moderate_call'] ?? false)) {
        return true;
    }

    $callRole = videochat_normalize_call_participant_role((string) ($connection['call_role'] ?? 'participant'));
    return in_array($callRole, ['owner', 'moderator'], true);
}

function videochat_activity_decode_json(string $frame): array
{
    $decoded = json_decode($frame, true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_activity_decode_client_frame(string $frame): array
{
    $decoded = videochat_activity_decode_json($frame);
    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type === '') {
        return ['ok' => false, 'type' => '', 'error' => 'missing_type'];
    }

    if ($type !== 'participant/activity') {
        return ['ok' => false, 'type' => $type, 'error' => 'unsupported_type'];
    }

    $reportedUserId = $decoded['user_id'] ?? ($decoded['userId'] ?? null);
    $userId = 0;
    if (is_int($reportedUserId)) {
        $userId = $reportedUserId;
    } elseif (is_string($reportedUserId) && preg_match('/^[0-9]+$/', trim($reportedUserId)) === 1) {
        $userId = (int) trim($reportedUserId);
    }

    return [
        'ok' => true,
        'type' => 'participant/activity',
        'user_id' => $userId,
        'audio_level' => $decoded['audio_level'] ?? ($decoded['audioLevel'] ?? 0),
        'speaking' => (bool) ($decoded['speaking'] ?? false),
        'motion_score' => $decoded['motion_score'] ?? ($decoded['motionScore'] ?? 0),
        'gesture' => strtolower(trim((string) ($decoded['gesture'] ?? ''))),
        'source' => strtolower(trim((string) ($decoded['source'] ?? 'client_observed'))),
        'error' => '',
    ];
}

function videochat_layout_decode_client_frame(string $frame): array
{
    $decoded = videochat_activity_decode_json($frame);
    $type = strtolower(trim((string) ($decoded['type'] ?? '')));
    if ($type === '') {
        return ['ok' => false, 'type' => '', 'error' => 'missing_type'];
    }
    if (!in_array($type, ['layout/mode', 'layout/strategy', 'layout/selection'], true)) {
        return ['ok' => false, 'type' => $type, 'error' => 'unsupported_type'];
    }

    $ids = [];
    $rawIds = $decoded['pinned_user_ids'] ?? ($decoded['pinnedUserIds'] ?? []);
    if (is_array($rawIds)) {
        foreach ($rawIds as $rawId) {
            $id = (int) $rawId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }

    $selected = [];
    $rawSelected = $decoded['selected_user_ids'] ?? ($decoded['selectedUserIds'] ?? []);
    if (is_array($rawSelected)) {
        foreach ($rawSelected as $rawId) {
            $id = (int) $rawId;
            if ($id > 0) {
                $selected[$id] = $id;
            }
        }
    }

    return [
        'ok' => true,
        'type' => $type,
        'mode' => $decoded['mode'] ?? null,
        'strategy' => $decoded['strategy'] ?? null,
        'automation_paused' => array_key_exists('automation_paused', $decoded) ? (bool) $decoded['automation_paused'] : null,
        'pinned_user_ids' => array_values($ids),
        'selected_user_ids' => array_values($selected),
        'main_user_id' => (int) ($decoded['main_user_id'] ?? ($decoded['mainUserId'] ?? 0)),
        'error' => '',
    ];
}

function videochat_activity_score_from_command(array $command): array
{
    $audioLevel = videochat_activity_clamp_float($command['audio_level'] ?? 0, 0.0, 1.0);
    $motionScore = videochat_activity_clamp_float($command['motion_score'] ?? 0, 0.0, 1.0);
    $gesture = strtolower(trim((string) ($command['gesture'] ?? '')));
    $isSpeaking = (bool) ($command['speaking'] ?? false);
    $gestureScore = in_array($gesture, ['wave', 'hand', 'raise_hand', 'large_motion'], true) ? 1.0 : 0.0;
    $score = ($audioLevel * 45.0) + ($motionScore * 30.0) + ($gestureScore * 18.0) + ($isSpeaking ? 12.0 : 0.0);

    return [
        'raw_score' => min(100.0, max(0.0, $score)),
        'audio_level' => $audioLevel,
        'motion_score' => $motionScore,
        'gesture_score' => $gestureScore,
        'is_speaking' => $isSpeaking,
        'source' => in_array((string) ($command['source'] ?? ''), ['sfu_observed', 'webrtc_stats', 'client_observed'], true)
            ? (string) ($command['source'] ?? 'client_observed')
            : 'client_observed',
    ];
}

function videochat_activity_decay_score(float $rawScore, int $updatedAtMs, int $windowMs, int $nowMs): float
{
    $ageMs = max(0, $nowMs - $updatedAtMs);
    if ($ageMs >= $windowMs) {
        return 0.0;
    }

    return round($rawScore * (1.0 - ($ageMs / $windowMs)), 3);
}

function videochat_activity_row_payload(array $row, ?int $nowUnixMs = null): array
{
    $nowMs = videochat_activity_now_ms($nowUnixMs);
    $updatedAtMs = (int) ($row['updated_at_ms'] ?? 0);
    $rawScore = videochat_activity_clamp_float($row['raw_score'] ?? 0, 0.0, 100.0);

    return [
        'user_id' => (int) ($row['user_id'] ?? 0),
        'score' => round($rawScore, 3),
        'score_2s' => videochat_activity_decay_score($rawScore, $updatedAtMs, 2000, $nowMs),
        'score_5s' => videochat_activity_decay_score($rawScore, $updatedAtMs, 5000, $nowMs),
        'score_15s' => videochat_activity_decay_score($rawScore, $updatedAtMs, 15000, $nowMs),
        'audio_level' => videochat_activity_clamp_float($row['audio_level'] ?? 0, 0.0, 1.0),
        'motion_score' => videochat_activity_clamp_float($row['motion_score'] ?? 0, 0.0, 1.0),
        'gesture_score' => videochat_activity_clamp_float($row['gesture_score'] ?? 0, 0.0, 1.0),
        'is_speaking' => (int) ($row['is_speaking'] ?? 0) === 1,
        'source' => (string) ($row['source'] ?? 'client_observed'),
        'updated_at_ms' => $updatedAtMs,
        'updated_at' => (string) ($row['updated_at'] ?? gmdate('c', (int) floor($updatedAtMs / 1000))),
    ];
}

function videochat_activity_apply_command(PDO $pdo, array $presenceState, array $connection, array $command, ?callable $sender = null, ?int $nowUnixMs = null): array
{
    if (!(bool) ($command['ok'] ?? false)) {
        return ['ok' => false, 'error' => 'invalid_command', 'event' => null, 'emitted' => false];
    }

    $callId = videochat_realtime_connection_call_id($connection);
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $userId = (int) ($connection['user_id'] ?? 0);
    $reportedUserId = (int) ($command['user_id'] ?? 0);
    if ($callId === '' || $roomId === '' || $userId <= 0) {
        return ['ok' => false, 'error' => 'missing_call_context', 'event' => null, 'emitted' => false];
    }
    if ($reportedUserId > 0 && $reportedUserId !== $userId) {
        return ['ok' => false, 'error' => 'forged_activity_user', 'event' => null, 'emitted' => false];
    }
    if ($roomId === videochat_realtime_waiting_room_id()) {
        return [
            'ok' => true,
            'error' => '',
            'event' => null,
            'emitted' => false,
            'ignored' => true,
            'skipped_reason' => 'waiting_room_context',
        ];
    }

    $nowMs = videochat_activity_now_ms($nowUnixMs);
    $score = videochat_activity_score_from_command($command);
    $updatedAt = gmdate('c', (int) floor($nowMs / 1000));
    $upsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participant_activity(call_id, room_id, user_id, raw_score, audio_level, motion_score, gesture_score, is_speaking, source, updated_at_ms, updated_at)
VALUES(:call_id, :room_id, :user_id, :raw_score, :audio_level, :motion_score, :gesture_score, :is_speaking, :source, :updated_at_ms, :updated_at)
ON CONFLICT(call_id, user_id) DO UPDATE SET
    room_id = excluded.room_id,
    raw_score = excluded.raw_score,
    audio_level = excluded.audio_level,
    motion_score = excluded.motion_score,
    gesture_score = excluded.gesture_score,
    is_speaking = excluded.is_speaking,
    source = excluded.source,
    updated_at_ms = excluded.updated_at_ms,
    updated_at = excluded.updated_at
SQL
    );
    $params = [
        ':call_id' => $callId,
        ':room_id' => $roomId,
        ':user_id' => $userId,
        ':raw_score' => $score['raw_score'],
        ':audio_level' => $score['audio_level'],
        ':motion_score' => $score['motion_score'],
        ':gesture_score' => $score['gesture_score'],
        ':is_speaking' => $score['is_speaking'] ? 1 : 0,
        ':source' => $score['source'],
        ':updated_at_ms' => $nowMs,
        ':updated_at' => $updatedAt,
    ];

    $payload = videochat_activity_row_payload([
        'user_id' => $userId,
        ...$score,
        'is_speaking' => $score['is_speaking'] ? 1 : 0,
        'updated_at_ms' => $nowMs,
        'updated_at' => $updatedAt,
    ], $nowMs);
    $event = [
        'type' => 'participant/activity',
        'room_id' => $roomId,
        'call_id' => $callId,
        'participant' => videochat_activity_public_participant($connection),
        'activity' => $payload,
        'time' => $updatedAt,
    ];
    try {
        videochat_activity_layout_bootstrap($pdo);
        $existing = $pdo->prepare('SELECT raw_score, updated_at_ms FROM call_participant_activity WHERE call_id = :call_id AND user_id = :user_id LIMIT 1');
        $existing->execute([':call_id' => $callId, ':user_id' => $userId]);
        $previous = $existing->fetch(PDO::FETCH_ASSOC);
        if (is_array($previous)) {
            $ageMs = $nowMs - (int) ($previous['updated_at_ms'] ?? 0);
            $previousScore = (float) ($previous['raw_score'] ?? 0);
            if ($ageMs >= 0 && $ageMs < 250 && (float) $score['raw_score'] <= ($previousScore + 12.0)) {
                return ['ok' => true, 'error' => '', 'event' => null, 'emitted' => false, 'coalesced' => true];
            }
        }

        $upsert->execute($params);
    } catch (Throwable $error) {
        if (!videochat_activity_is_transient_database_lock($error)) {
            throw $error;
        }

        $sent = videochat_presence_broadcast_room_event($presenceState, $roomId, $event, null, $sender);
        return [
            'ok' => true,
            'error' => '',
            'event' => $event,
            'emitted' => $sent > 0,
            'sent_count' => $sent,
            'storage_busy' => true,
            'storage_persisted' => false,
            'skipped_reason' => 'activity_storage_busy',
        ];
    }

    $sent = videochat_presence_broadcast_room_event($presenceState, $roomId, $event, null, $sender);
    return ['ok' => true, 'error' => '', 'event' => $event, 'emitted' => $sent > 0, 'sent_count' => $sent];
}

function videochat_activity_is_transient_database_lock(Throwable $error): bool
{
    $message = strtolower(trim($error->getMessage()));
    if ($message === '') {
        return false;
    }

    return str_contains($message, 'database is locked')
        || str_contains($message, 'database table is locked')
        || str_contains($message, 'database schema is locked')
        || str_contains($message, 'database busy');
}

function videochat_layout_default_state(string $callId, string $roomId): array
{
    return [
        'call_id' => $callId,
        'room_id' => $roomId,
        'mode' => 'main_mini',
        'strategy' => 'manual_pinned',
        'automation_paused' => false,
        'pinned_user_ids' => [],
        'main_user_id' => 0,
        'selected_user_ids' => [],
        'updated_by_user_id' => 0,
        'updated_at' => '',
    ];
}

function videochat_layout_decode_ids(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $ids = [];
    foreach ($decoded as $rawId) {
        $id = (int) $rawId;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function videochat_layout_fetch_state(PDO $pdo, string $callId, string $roomId): array
{
    videochat_activity_layout_bootstrap($pdo);
    $normalizedCallId = videochat_realtime_normalize_call_id($callId, '');
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedCallId === '' || $normalizedRoomId === '') {
        return videochat_layout_default_state($normalizedCallId, $normalizedRoomId);
    }

    $query = $pdo->prepare('SELECT * FROM call_layout_state WHERE call_id = :call_id LIMIT 1');
    $query->execute([':call_id' => $normalizedCallId]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return videochat_layout_default_state($normalizedCallId, $normalizedRoomId);
    }

    return [
        'call_id' => $normalizedCallId,
        'room_id' => videochat_presence_normalize_room_id((string) ($row['room_id'] ?? $normalizedRoomId), $normalizedRoomId),
        'mode' => videochat_activity_normalize_mode($row['mode'] ?? 'main_mini'),
        'strategy' => videochat_activity_normalize_strategy($row['strategy'] ?? 'manual_pinned'),
        'automation_paused' => (int) ($row['automation_paused'] ?? 0) === 1,
        'pinned_user_ids' => videochat_layout_decode_ids((string) ($row['pinned_user_ids_json'] ?? '[]')),
        'main_user_id' => (int) ($row['main_user_id'] ?? 0),
        'selected_user_ids' => videochat_layout_decode_ids((string) ($row['selected_user_ids_json'] ?? '[]')),
        'updated_by_user_id' => (int) ($row['updated_by_user_id'] ?? 0),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function videochat_layout_apply_command(PDO $pdo, array $presenceState, array $connection, array $command, ?callable $sender = null, ?int $nowUnixMs = null): array
{
    if (!(bool) ($command['ok'] ?? false)) {
        return ['ok' => false, 'error' => 'invalid_command', 'event' => null];
    }
    if (!videochat_activity_authorizes_layout($connection)) {
        return ['ok' => false, 'error' => 'layout_permission_denied', 'event' => null];
    }

    $callId = videochat_realtime_connection_call_id($connection);
    $roomId = videochat_presence_normalize_room_id((string) ($connection['room_id'] ?? ''), '');
    $userId = (int) ($connection['user_id'] ?? 0);
    if ($callId === '' || $roomId === '' || $userId <= 0) {
        return ['ok' => false, 'error' => 'missing_call_context', 'event' => null];
    }

    $previous = videochat_layout_fetch_state($pdo, $callId, $roomId);
    $type = (string) ($command['type'] ?? '');
    $mode = (string) ($previous['mode'] ?? 'main_mini');
    $strategy = (string) ($previous['strategy'] ?? 'manual_pinned');
    $automationPaused = (bool) ($previous['automation_paused'] ?? false);
    $pinnedUserIds = (array) ($previous['pinned_user_ids'] ?? []);
    $selectedUserIds = (array) ($previous['selected_user_ids'] ?? []);
    $mainUserId = (int) ($previous['main_user_id'] ?? 0);

    if ($type === 'layout/mode') {
        $mode = videochat_activity_normalize_mode($command['mode'] ?? '', $mode);
    }
    if ($type === 'layout/strategy') {
        $strategy = videochat_activity_normalize_strategy($command['strategy'] ?? '', $strategy);
    }
    if ($type === 'layout/selection') {
        $pinnedUserIds = (array) ($command['pinned_user_ids'] ?? []);
        $selectedUserIds = (array) ($command['selected_user_ids'] ?? []);
        $mainUserId = (int) ($command['main_user_id'] ?? 0);
    }
    if (array_key_exists('automation_paused', $command) && $command['automation_paused'] !== null) {
        $automationPaused = (bool) $command['automation_paused'];
    }

    $nowMs = videochat_activity_now_ms($nowUnixMs);
    $updatedAt = gmdate('c', (int) floor($nowMs / 1000));
    $upsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_layout_state(call_id, room_id, mode, strategy, automation_paused, pinned_user_ids_json, main_user_id, selected_user_ids_json, updated_by_user_id, updated_at)
VALUES(:call_id, :room_id, :mode, :strategy, :automation_paused, :pinned_user_ids_json, :main_user_id, :selected_user_ids_json, :updated_by_user_id, :updated_at)
ON CONFLICT(call_id) DO UPDATE SET
    room_id = excluded.room_id,
    mode = excluded.mode,
    strategy = excluded.strategy,
    automation_paused = excluded.automation_paused,
    pinned_user_ids_json = excluded.pinned_user_ids_json,
    main_user_id = excluded.main_user_id,
    selected_user_ids_json = excluded.selected_user_ids_json,
    updated_by_user_id = excluded.updated_by_user_id,
    updated_at = excluded.updated_at
SQL
    );
    $upsert->execute([
        ':call_id' => $callId,
        ':room_id' => $roomId,
        ':mode' => $mode,
        ':strategy' => $strategy,
        ':automation_paused' => $automationPaused ? 1 : 0,
        ':pinned_user_ids_json' => json_encode(array_values(array_unique(array_map('intval', $pinnedUserIds))), JSON_UNESCAPED_SLASHES),
        ':main_user_id' => $mainUserId > 0 ? $mainUserId : null,
        ':selected_user_ids_json' => json_encode(array_values(array_unique(array_map('intval', $selectedUserIds))), JSON_UNESCAPED_SLASHES),
        ':updated_by_user_id' => $userId,
        ':updated_at' => $updatedAt,
    ]);

    $layout = videochat_layout_fetch_state($pdo, $callId, $roomId);
    $event = [
        'type' => $type,
        'room_id' => $roomId,
        'call_id' => $callId,
        'layout' => $layout,
        'actor' => videochat_activity_public_participant($connection),
        'time' => $updatedAt,
    ];
    $sent = videochat_presence_broadcast_room_event($presenceState, $roomId, $event, null, $sender);

    return ['ok' => true, 'error' => '', 'event' => $event, 'sent_count' => $sent, 'layout' => $layout];
}

function videochat_activity_fetch_room(PDO $pdo, string $callId, string $roomId, ?int $nowUnixMs = null): array
{
    videochat_activity_layout_bootstrap($pdo);
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT *
FROM call_participant_activity
WHERE call_id = :call_id
  AND room_id = :room_id
  AND updated_at_ms >= :cutoff_ms
ORDER BY raw_score DESC, updated_at_ms DESC, user_id ASC
SQL
    );
    $nowMs = videochat_activity_now_ms($nowUnixMs);
    $statement->execute([
        ':call_id' => $callId,
        ':room_id' => $roomId,
        ':cutoff_ms' => $nowMs - 15000,
    ]);

    $rows = [];
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (is_array($row)) {
            $rows[] = videochat_activity_row_payload($row, $nowMs);
        }
    }
    return $rows;
}

function videochat_activity_select_layout(array $participants, array $activityRows, array $layout): array
{
    $byUserId = [];
    foreach ($participants as $participant) {
        $userId = (int) (($participant['user'] ?? [])['id'] ?? 0);
        if ($userId > 0) {
            $byUserId[$userId] = $participant;
        }
    }

    $activityByUserId = [];
    foreach ($activityRows as $row) {
        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId > 0) {
            $activityByUserId[$userId] = (float) ($row['score_2s'] ?? $row['score'] ?? 0);
        }
    }

    $pinned = array_values(array_filter(array_map('intval', (array) ($layout['pinned_user_ids'] ?? [])), static fn (int $id): bool => $id > 0 && isset($byUserId[$id])));
    $selected = array_values(array_filter(array_map('intval', (array) ($layout['selected_user_ids'] ?? [])), static fn (int $id): bool => $id > 0 && isset($byUserId[$id])));
    $mode = videochat_activity_normalize_mode($layout['mode'] ?? 'main_mini');
    $strategy = videochat_activity_normalize_strategy($layout['strategy'] ?? 'manual_pinned');
    $limit = $mode === 'grid' ? 8 : ($mode === 'main_only' ? 1 : 5);

    $ranked = array_keys($byUserId);
    usort($ranked, static function (int $left, int $right) use ($activityByUserId, $byUserId): int {
        $scoreCmp = ($activityByUserId[$right] ?? 0.0) <=> ($activityByUserId[$left] ?? 0.0);
        if ($scoreCmp !== 0) {
            return $scoreCmp;
        }
        $leftName = strtolower((string) (($byUserId[$left]['user'] ?? [])['display_name'] ?? ''));
        $rightName = strtolower((string) (($byUserId[$right]['user'] ?? [])['display_name'] ?? ''));
        return $leftName <=> $rightName ?: ($left <=> $right);
    });

    $visible = [];
    foreach ([$pinned, $selected] as $prioritySet) {
        foreach ($prioritySet as $id) {
            if (!in_array($id, $visible, true)) {
                $visible[] = $id;
            }
        }
    }
    if (!(bool) ($layout['automation_paused'] ?? false) && $strategy !== 'manual_pinned') {
        foreach ($ranked as $id) {
            if (!in_array($id, $visible, true)) {
                $visible[] = $id;
            }
        }
    } else {
        foreach (array_keys($byUserId) as $id) {
            if (!in_array($id, $visible, true)) {
                $visible[] = $id;
            }
        }
    }
    $visible = array_slice($visible, 0, $limit);

    $mainUserId = (int) ($layout['main_user_id'] ?? 0);
    if ($pinned !== []) {
        $mainUserId = $pinned[0];
    } elseif (!(bool) ($layout['automation_paused'] ?? false) && in_array($strategy, ['active_speaker_main', 'most_active_window', 'round_robin_active'], true)) {
        $mainUserId = (int) ($ranked[0] ?? $mainUserId);
    }
    if ($mainUserId <= 0 || !isset($byUserId[$mainUserId])) {
        $mainUserId = (int) ($visible[0] ?? 0);
    }

    return [
        'mode' => $mode,
        'strategy' => $strategy,
        'automation_paused' => (bool) ($layout['automation_paused'] ?? false),
        'main_user_id' => $mainUserId,
        'visible_user_ids' => $visible,
        'mini_user_ids' => array_values(array_filter($visible, static fn (int $id): bool => $id !== $mainUserId)),
        'pinned_user_ids' => $pinned,
        'updated_at' => (string) ($layout['updated_at'] ?? ''),
    ];
}

function videochat_activity_layout_snapshot(PDO $pdo, string $callId, string $roomId, array $participants, ?int $nowUnixMs = null): array
{
    $layout = videochat_layout_fetch_state($pdo, $callId, $roomId);
    $activity = videochat_activity_fetch_room($pdo, $callId, $roomId, $nowUnixMs);
    $layout['selection'] = videochat_activity_select_layout($participants, $activity, $layout);

    return [
        'layout' => $layout,
        'activity' => $activity,
    ];
}
