<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_realtime_room_leave_snapshot_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-room-leave-snapshot-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_realtime_room_leave_snapshot_last_frame(array $frames, string $socket, string $type = ''): array
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
        fwrite(STDOUT, "[realtime-room-leave-snapshot-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE)');
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, display_name TEXT NOT NULL, role_id INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE rooms (id TEXT PRIMARY KEY, name TEXT NOT NULL, visibility TEXT NOT NULL, status TEXT NOT NULL, created_by_user_id INTEGER NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE calls (id TEXT PRIMARY KEY, room_id TEXT NOT NULL, title TEXT NOT NULL, owner_user_id INTEGER NOT NULL, status TEXT NOT NULL, starts_at TEXT NOT NULL, ends_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec(
        <<<'SQL'
CREATE TABLE call_participants (
    call_id TEXT NOT NULL,
    user_id INTEGER,
    email TEXT NOT NULL,
    display_name TEXT NOT NULL,
    source TEXT NOT NULL,
    call_role TEXT NOT NULL,
    invite_state TEXT NOT NULL DEFAULT 'invited',
    joined_at TEXT,
    left_at TEXT
)
SQL
    );
    $pdo->exec("INSERT INTO roles(id, slug) VALUES(1, 'user'), (2, 'admin')");
    $pdo->exec("INSERT INTO users(id, email, display_name, role_id) VALUES(101, 'owner@example.test', 'Owner User', 2), (102, 'leaver@example.test', 'Leaving User', 1)");
    $pdo->exec("INSERT INTO rooms(id, name, visibility, status, created_by_user_id, created_at, updated_at) VALUES('room-leave-cleanup', 'Leave Cleanup', 'private', 'active', 101, '2026-04-29T10:00:00Z', '2026-04-29T10:00:00Z')");
    $pdo->exec("INSERT INTO calls(id, room_id, title, owner_user_id, status, starts_at, ends_at, created_at, updated_at) VALUES('call-leave-cleanup', 'room-leave-cleanup', 'Leave Cleanup Call', 101, 'active', '2026-04-29T10:00:00Z', '2026-04-29T11:00:00Z', '2026-04-29T10:00:00Z', '2026-04-29T10:00:00Z')");
    $pdo->exec(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at) VALUES
    ('call-leave-cleanup', 101, 'owner@example.test', 'Owner User', 'internal', 'owner', 'allowed', '2026-04-29T10:00:00Z', NULL),
    ('call-leave-cleanup', 102, 'leaver@example.test', 'Leaving User', 'internal', 'participant', 'allowed', '2026-04-29T10:00:00Z', NULL)
SQL
    );

    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };
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
        'id' => 101,
        'display_name' => 'Owner User',
        'role' => 'admin',
    ], 'sess-owner', 'conn-owner', 'socket-owner', 'room-leave-cleanup');
    $ownerConnection['active_call_id'] = 'call-leave-cleanup';
    $ownerConnection['requested_call_id'] = 'call-leave-cleanup';
    $ownerConnection['call_role'] = 'owner';
    $ownerConnection['can_moderate_call'] = true;
    $ownerJoin = videochat_presence_join_room($presenceState, $ownerConnection, 'room-leave-cleanup', $sender);
    $ownerConnection = (array) ($ownerJoin['connection'] ?? $ownerConnection);
    videochat_realtime_presence_db_upsert($pdo, $ownerConnection, 1_777_000_000_000);

    $leavingConnection = videochat_presence_connection_descriptor([
        'id' => 102,
        'display_name' => 'Leaving User',
        'role' => 'user',
    ], 'sess-leaver', 'conn-leaver', 'socket-leaver', 'room-leave-cleanup');
    $leavingConnection['active_call_id'] = 'call-leave-cleanup';
    $leavingConnection['requested_call_id'] = 'call-leave-cleanup';
    $leavingConnection['call_role'] = 'participant';
    $leaverJoin = videochat_presence_join_room($presenceState, $leavingConnection, 'room-leave-cleanup', $sender);
    $leavingConnection = (array) ($leaverJoin['connection'] ?? $leavingConnection);
    videochat_realtime_presence_db_upsert($pdo, $leavingConnection, 1_777_000_000_100);

    $initialSnapshot = videochat_realtime_room_snapshot_payload($presenceState, $ownerConnection, $openDatabase, 'assert_initial');
    videochat_realtime_room_leave_snapshot_assert((int) ($initialSnapshot['participant_count'] ?? 0) === 2, 'initial snapshot should include both active participants');

    $frames = [];
    videochat_presence_remove_connection($presenceState, 'conn-leaver', $sender);
    videochat_realtime_remove_call_presence($openDatabase, $leavingConnection);
    videochat_realtime_mark_call_participant_left($openDatabase, $leavingConnection, $presenceState);
    $sentCount = videochat_realtime_broadcast_room_snapshot($presenceState, 'room-leave-cleanup', $openDatabase, 'participant_left', 'conn-leaver', $sender);
    videochat_realtime_room_leave_snapshot_assert($sentCount === 1, 'post-leave snapshot should be sent to the remaining participant');

    $leftEvent = videochat_realtime_room_leave_snapshot_last_frame($frames, 'socket-owner', 'room/left');
    videochat_realtime_room_leave_snapshot_assert((int) (($leftEvent['participant'] ?? [])['user']['id'] ?? 0) === 102, 'remaining participant should receive room/left for leaver');

    $postLeaveSnapshot = videochat_realtime_room_leave_snapshot_last_frame($frames, 'socket-owner', 'room/snapshot');
    videochat_realtime_room_leave_snapshot_assert((string) ($postLeaveSnapshot['reason'] ?? '') === 'participant_left', 'post-leave snapshot reason mismatch');
    videochat_realtime_room_leave_snapshot_assert((int) ($postLeaveSnapshot['participant_count'] ?? 0) === 1, 'post-leave snapshot should contain one participant');
    videochat_realtime_room_leave_snapshot_assert((int) (($postLeaveSnapshot['participants'][0]['user'] ?? [])['id'] ?? 0) === 101, 'post-leave snapshot should keep the remaining owner');

    $leftAt = $pdo->query("SELECT left_at FROM call_participants WHERE call_id = 'call-leave-cleanup' AND user_id = 102")->fetchColumn();
    videochat_realtime_room_leave_snapshot_assert(is_string($leftAt) && trim($leftAt) !== '', 'leaving participant should be marked left in DB');

    fwrite(STDOUT, "[realtime-room-leave-snapshot-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-room-leave-snapshot-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
