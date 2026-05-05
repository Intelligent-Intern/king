<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_realtime_activity_layout_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-activity-layout-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_realtime_activity_layout_last_frame(array $frames, string $socket, string $type = ''): array
{
    $rows = $frames[$socket] ?? [];
    if (!is_array($rows) || $rows === []) {
        return [];
    }

    for ($index = count($rows) - 1; $index >= 0; $index--) {
        $frame = $rows[$index] ?? null;
        if (!is_array($frame)) {
            continue;
        }
        if ($type === '' || (string) ($frame['type'] ?? '') === $type) {
            return $frame;
        }
    }

    return [];
}

try {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "[realtime-activity-layout-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    putenv('VIDEOCHAT_DEMO_SEED_CALLS=0');
    $databasePath = tempnam(sys_get_temp_dir(), 'king_activity_layout_');
    if (!is_string($databasePath) || $databasePath === '') {
        throw new RuntimeException('Could not allocate temp sqlite path.');
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    videochat_activity_layout_bootstrap($pdo);

    $roles = [];
    foreach ($pdo->query('SELECT id, slug FROM roles') as $roleRow) {
        $roles[(string) $roleRow['slug']] = (int) $roleRow['id'];
    }
    $adminRoleId = (int) ($roles['admin'] ?? 0);
    $userRoleId = (int) ($roles['user'] ?? 0);
    videochat_realtime_activity_layout_assert($adminRoleId > 0 && $userRoleId > 0, 'bootstrap roles should exist');

    $now = '2026-04-19T12:00:00Z';
    $pdo->prepare(
        <<<'SQL'
INSERT INTO users(id, email, display_name, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:id, :email, :display_name, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    )->execute([
        ':id' => 1001,
        ':email' => 'layout-owner@example.test',
        ':display_name' => 'Layout Owner',
        ':role_id' => $adminRoleId,
        ':updated_at' => $now,
    ]);
    $insertUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(id, email, display_name, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:id, :email, :display_name, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insertUser->execute([
        ':id' => 1002,
        ':email' => 'speaker@example.test',
        ':display_name' => 'Active Speaker',
        ':role_id' => $userRoleId,
        ':updated_at' => $now,
    ]);
    $insertUser->execute([
        ':id' => 1003,
        ':email' => 'quiet@example.test',
        ':display_name' => 'Quiet User',
        ':role_id' => $userRoleId,
        ':updated_at' => $now,
    ]);

    $callId = 'call-activity-layout';
    $roomId = 'room-activity-layout';
    $pdo->prepare(
        <<<'SQL'
INSERT INTO rooms(id, name, visibility, status, created_by_user_id, created_at, updated_at)
VALUES(:id, 'Activity Layout Room', 'private', 'active', :created_by_user_id, :created_at, :updated_at)
SQL
    )->execute([
        ':id' => $roomId,
        ':created_by_user_id' => 1001,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $pdo->prepare(
        <<<'SQL'
INSERT INTO calls(id, room_id, title, owner_user_id, status, starts_at, ends_at, created_at, updated_at)
VALUES(:id, :room_id, 'Activity Layout Call', :owner_user_id, 'active', :starts_at, :ends_at, :created_at, :updated_at)
SQL
    )->execute([
        ':id' => $callId,
        ':room_id' => $roomId,
        ':owner_user_id' => 1001,
        ':starts_at' => '2026-04-19T12:00:00Z',
        ':ends_at' => '2026-04-19T13:00:00Z',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $insertParticipant = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, 'internal', :call_role, 'allowed', :joined_at, NULL)
SQL
    );
    foreach ([
        [1001, 'layout-owner@example.test', 'Layout Owner', 'owner'],
        [1002, 'speaker@example.test', 'Active Speaker', 'participant'],
        [1003, 'quiet@example.test', 'Quiet User', 'participant'],
    ] as [$userId, $email, $displayName, $callRole]) {
        $insertParticipant->execute([
            ':call_id' => $callId,
            ':user_id' => $userId,
            ':email' => $email,
            ':display_name' => $displayName,
            ':call_role' => $callRole,
            ':joined_at' => $now,
        ]);
    }

    $presenceState = videochat_presence_state_init();
    $frames = [];
    $sender = static function (mixed $socket, array $payload) use (&$frames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        if (!isset($frames[$key]) || !is_array($frames[$key])) {
            $frames[$key] = [];
        }
        $frames[$key][] = $payload;
        return true;
    };

    $ownerConnection = videochat_presence_connection_descriptor([
        'id' => 1001,
        'display_name' => 'Layout Owner',
        'role' => 'admin',
    ], 'sess-owner', 'conn-owner', 'socket-owner', $roomId, 1_776_000_000);
    $ownerConnection['active_call_id'] = $callId;
    $ownerConnection['requested_call_id'] = $callId;
    $ownerConnection['call_role'] = 'owner';
    $ownerConnection['can_moderate_call'] = true;
    $ownerJoin = videochat_presence_join_room($presenceState, $ownerConnection, $roomId, $sender);
    $ownerConnection = (array) ($ownerJoin['connection'] ?? $ownerConnection);

    $speakerConnection = videochat_presence_connection_descriptor([
        'id' => 1002,
        'display_name' => 'Active Speaker',
        'role' => 'user',
    ], 'sess-speaker', 'conn-speaker', 'socket-speaker', $roomId, 1_776_000_001);
    $speakerConnection['active_call_id'] = $callId;
    $speakerConnection['requested_call_id'] = $callId;
    $speakerConnection['call_role'] = 'participant';
    $speakerJoin = videochat_presence_join_room($presenceState, $speakerConnection, $roomId, $sender);
    $speakerConnection = (array) ($speakerJoin['connection'] ?? $speakerConnection);

    $quietConnection = videochat_presence_connection_descriptor([
        'id' => 1003,
        'display_name' => 'Quiet User',
        'role' => 'user',
    ], 'sess-quiet', 'conn-quiet', 'socket-quiet', $roomId, 1_776_000_002);
    $quietConnection['active_call_id'] = $callId;
    $quietConnection['requested_call_id'] = $callId;
    $quietConnection['call_role'] = 'participant';
    $quietJoin = videochat_presence_join_room($presenceState, $quietConnection, $roomId, $sender);
    $quietConnection = (array) ($quietJoin['connection'] ?? $quietConnection);

    $frames = [];
    $activityCommand = videochat_activity_decode_client_frame(json_encode([
        'type' => 'participant/activity',
        'user_id' => 1002,
        'audio_level' => 0.72,
        'speaking' => true,
        'motion_score' => 0.64,
        'gesture' => 'wave',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_activity_layout_assert((bool) ($activityCommand['ok'] ?? false), 'activity command should decode');
    $activityResult = videochat_activity_apply_command($pdo, $presenceState, $speakerConnection, $activityCommand, $sender, 1_776_000_010_000);
    videochat_realtime_activity_layout_assert((bool) ($activityResult['ok'] ?? false), 'activity publish should succeed');
    videochat_realtime_activity_layout_assert((int) ($activityResult['sent_count'] ?? 0) === 3, 'activity should fan out to all room connections');
    $ownerActivityFrame = videochat_realtime_activity_layout_last_frame($frames, 'socket-owner', 'participant/activity');
    videochat_realtime_activity_layout_assert((int) (($ownerActivityFrame['activity'] ?? [])['user_id'] ?? 0) === 1002, 'owner should receive speaker activity');
    videochat_realtime_activity_layout_assert((float) (($ownerActivityFrame['activity'] ?? [])['score_2s'] ?? 0) > 0, 'activity payload should include decayed 2s score');

    $forgedCommand = videochat_activity_decode_client_frame(json_encode([
        'type' => 'participant/activity',
        'user_id' => 1003,
        'audio_level' => 1,
    ], JSON_UNESCAPED_SLASHES));
    $forgedResult = videochat_activity_apply_command($pdo, $presenceState, $speakerConnection, $forgedCommand, $sender, 1_776_000_010_100);
    videochat_realtime_activity_layout_assert(!(bool) ($forgedResult['ok'] ?? true), 'forged activity should fail');
    videochat_realtime_activity_layout_assert((string) ($forgedResult['error'] ?? '') === 'forged_activity_user', 'forged activity error mismatch');

    $coalescedCommand = videochat_activity_decode_client_frame(json_encode([
        'type' => 'participant/activity',
        'user_id' => 1002,
        'audio_level' => 0.01,
        'motion_score' => 0.01,
    ], JSON_UNESCAPED_SLASHES));
    $coalescedResult = videochat_activity_apply_command($pdo, $presenceState, $speakerConnection, $coalescedCommand, $sender, 1_776_000_010_120);
    videochat_realtime_activity_layout_assert((bool) ($coalescedResult['ok'] ?? false), 'coalesced activity should still return ok');
    videochat_realtime_activity_layout_assert((bool) ($coalescedResult['coalesced'] ?? false), 'low activity inside rate window should coalesce');

    $waitingSpeakerConnection = $speakerConnection;
    $waitingSpeakerConnection['room_id'] = 'waiting-room';
    $waitingSpeakerConnection['pending_room_id'] = $roomId;
    $frames = [];
    $waitingRoomResult = videochat_activity_apply_command($pdo, $presenceState, $waitingSpeakerConnection, $activityCommand, $sender, 1_776_000_010_500);
    videochat_realtime_activity_layout_assert((bool) ($waitingRoomResult['ok'] ?? false), 'waiting-room activity should not hard-fail');
    videochat_realtime_activity_layout_assert((bool) ($waitingRoomResult['ignored'] ?? false), 'waiting-room activity should be ignored');
    videochat_realtime_activity_layout_assert((string) ($waitingRoomResult['skipped_reason'] ?? '') === 'waiting_room_context', 'waiting-room activity skip reason mismatch');
    videochat_realtime_activity_layout_assert((int) ($waitingRoomResult['sent_count'] ?? 0) === 0, 'waiting-room activity should not broadcast');
    videochat_realtime_activity_layout_assert($frames === [], 'waiting-room activity should not emit frames');

    $lockPdo = new PDO('sqlite:' . $databasePath);
    $lockPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $lockPdo->exec('PRAGMA busy_timeout = 1');
    $pdo->exec('PRAGMA busy_timeout = 1');
    $lockPdo->exec('BEGIN IMMEDIATE');
    $frames = [];
    $busyResult = videochat_activity_apply_command($pdo, $presenceState, $speakerConnection, $activityCommand, $sender, 1_776_000_020_000);
    $lockPdo->exec('ROLLBACK');
    videochat_realtime_activity_layout_assert((bool) ($busyResult['ok'] ?? false), 'busy activity publish should still succeed');
    videochat_realtime_activity_layout_assert((bool) ($busyResult['storage_busy'] ?? false), 'busy activity publish should be marked as storage busy');
    videochat_realtime_activity_layout_assert((bool) ($busyResult['storage_persisted'] ?? true) === false, 'busy activity publish should skip persistence');
    videochat_realtime_activity_layout_assert((string) ($busyResult['skipped_reason'] ?? '') === 'activity_storage_busy', 'busy activity skip reason mismatch');
    videochat_realtime_activity_layout_assert((int) ($busyResult['sent_count'] ?? 0) === 3, 'busy activity publish should still broadcast to the room');
    $busyOwnerFrame = videochat_realtime_activity_layout_last_frame($frames, 'socket-owner', 'participant/activity');
    videochat_realtime_activity_layout_assert((int) (($busyOwnerFrame['activity'] ?? [])['user_id'] ?? 0) === 1002, 'busy activity publish should still emit the participant payload');

    $userLayoutCommand = videochat_layout_decode_client_frame(json_encode([
        'type' => 'layout/mode',
        'mode' => 'grid',
    ], JSON_UNESCAPED_SLASHES));
    $userLayoutResult = videochat_layout_apply_command($pdo, $presenceState, $speakerConnection, $userLayoutCommand, $sender, 1_776_000_011_000);
    videochat_realtime_activity_layout_assert(!(bool) ($userLayoutResult['ok'] ?? true), 'non-moderator layout command should fail');
    videochat_realtime_activity_layout_assert((string) ($userLayoutResult['error'] ?? '') === 'layout_permission_denied', 'layout permission error mismatch');

    $frames = [];
    $ownerModeCommand = videochat_layout_decode_client_frame(json_encode([
        'type' => 'layout/mode',
        'mode' => 'grid',
    ], JSON_UNESCAPED_SLASHES));
    $ownerModeResult = videochat_layout_apply_command($pdo, $presenceState, $ownerConnection, $ownerModeCommand, $sender, 1_776_000_012_000);
    videochat_realtime_activity_layout_assert((bool) ($ownerModeResult['ok'] ?? false), 'owner layout mode command should succeed');
    $ownerModeFrame = videochat_realtime_activity_layout_last_frame($frames, 'socket-speaker', 'layout/mode');
    videochat_realtime_activity_layout_assert((string) (($ownerModeFrame['layout'] ?? [])['mode'] ?? '') === 'grid', 'layout mode should broadcast grid');

    $ownerStrategyCommand = videochat_layout_decode_client_frame(json_encode([
        'type' => 'layout/strategy',
        'strategy' => 'active_speaker_main',
        'automation_paused' => false,
    ], JSON_UNESCAPED_SLASHES));
    $ownerStrategyResult = videochat_layout_apply_command($pdo, $presenceState, $ownerConnection, $ownerStrategyCommand, $sender, 1_776_000_012_500);
    videochat_realtime_activity_layout_assert((bool) ($ownerStrategyResult['ok'] ?? false), 'owner layout strategy command should succeed');

    $quietSpikeCommand = videochat_activity_decode_client_frame(json_encode([
        'type' => 'participant/activity',
        'user_id' => 1003,
        'audio_level' => 1,
        'speaking' => true,
        'motion_score' => 1,
        'gesture' => 'wave',
    ], JSON_UNESCAPED_SLASHES));
    videochat_activity_apply_command($pdo, $presenceState, $quietConnection, $quietSpikeCommand, $sender, 1_776_000_012_700);
    $sustainedSpeakerCommand = videochat_activity_decode_client_frame(json_encode([
        'type' => 'participant/activity',
        'user_id' => 1002,
        'audio_level' => 0.72,
        'speaking' => true,
        'motion_score' => 0.02,
    ], JSON_UNESCAPED_SLASHES));
    videochat_activity_apply_command($pdo, $presenceState, $speakerConnection, $sustainedSpeakerCommand, $sender, 1_776_000_013_000);
    videochat_activity_apply_command($pdo, $presenceState, $speakerConnection, $sustainedSpeakerCommand, $sender, 1_776_000_013_300);
    videochat_activity_apply_command($pdo, $presenceState, $speakerConnection, $sustainedSpeakerCommand, $sender, 1_776_000_013_600);
    $rollingSnapshot = videochat_activity_layout_snapshot(
        $pdo,
        $callId,
        $roomId,
        videochat_presence_room_participants($presenceState, $roomId),
        1_776_000_013_700
    );
    videochat_realtime_activity_layout_assert((int) (($rollingSnapshot['layout']['selection'] ?? [])['main_user_id'] ?? 0) === 1002, 'sustained speaker should beat a single spike in rolling top-k layout');
    $rollingActivityByUserId = [];
    foreach ((array) ($rollingSnapshot['activity'] ?? []) as $activityRow) {
        $rollingActivityByUserId[(int) ($activityRow['user_id'] ?? 0)] = $activityRow;
    }
    videochat_realtime_activity_layout_assert((float) ($rollingActivityByUserId[1002]['topk_score_2s'] ?? 0) > (float) ($rollingActivityByUserId[1003]['topk_score_2s'] ?? 0), 'rolling top-k activity should rank sustained speech above a lone spike');

    $ownerSelectionCommand = videochat_layout_decode_client_frame(json_encode([
        'type' => 'layout/selection',
        'pinned_user_ids' => [1002],
        'selected_user_ids' => [1001, 1002, 1003],
        'main_user_id' => 1002,
    ], JSON_UNESCAPED_SLASHES));
    $ownerSelectionResult = videochat_layout_apply_command($pdo, $presenceState, $ownerConnection, $ownerSelectionCommand, $sender, 1_776_000_013_000);
    videochat_realtime_activity_layout_assert((bool) ($ownerSelectionResult['ok'] ?? false), 'owner layout selection command should succeed');

    $layoutState = videochat_layout_fetch_state($pdo, $callId, $roomId);
    videochat_realtime_activity_layout_assert((string) ($layoutState['mode'] ?? '') === 'grid', 'persisted layout mode mismatch');
    videochat_realtime_activity_layout_assert((string) ($layoutState['strategy'] ?? '') === 'active_speaker_main', 'persisted layout strategy mismatch');

    $layoutSnapshot = videochat_activity_layout_snapshot(
        $pdo,
        $callId,
        $roomId,
        videochat_presence_room_participants($presenceState, $roomId),
        1_776_000_013_100
    );
    videochat_realtime_activity_layout_assert((string) (($layoutSnapshot['layout'] ?? [])['mode'] ?? '') === 'grid', 'snapshot layout mode mismatch');
    videochat_realtime_activity_layout_assert((int) (($layoutSnapshot['layout']['selection'] ?? [])['main_user_id'] ?? 0) === 1002, 'pinned speaker should own main slot');
    videochat_realtime_activity_layout_assert(in_array(1002, (array) (($layoutSnapshot['layout']['selection'] ?? [])['visible_user_ids'] ?? []), true), 'visible ids should include pinned speaker');
    videochat_realtime_activity_layout_assert(count((array) ($layoutSnapshot['activity'] ?? [])) >= 1, 'snapshot should include activity rows');

    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };
    videochat_activity_apply_command($pdo, $presenceState, $speakerConnection, $activityCommand, null, videochat_activity_now_ms());
    $roomSnapshot = videochat_realtime_room_snapshot_payload($presenceState, $ownerConnection, $openDatabase, 'contract');
    videochat_realtime_activity_layout_assert((string) (($roomSnapshot['layout'] ?? [])['strategy'] ?? '') === 'active_speaker_main', 'room snapshot should expose strategy');
    videochat_realtime_activity_layout_assert(count((array) ($roomSnapshot['activity'] ?? [])) >= 1, 'room snapshot should expose activity');

    @unlink($databasePath);
    fwrite(STDOUT, "[realtime-activity-layout-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-activity-layout-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
