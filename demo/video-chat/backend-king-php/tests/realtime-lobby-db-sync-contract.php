<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_realtime_lobby_db_sync_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-lobby-db-sync-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "[realtime-lobby-db-sync-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        <<<'SQL'
CREATE TABLE roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE
)
SQL
    );
    $pdo->exec(
        <<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    email TEXT NOT NULL,
    display_name TEXT NOT NULL,
    role_id INTEGER NOT NULL
)
SQL
    );
    $pdo->exec(
        <<<'SQL'
CREATE TABLE calls (
    id TEXT PRIMARY KEY,
    room_id TEXT NOT NULL,
    owner_user_id INTEGER NOT NULL,
    status TEXT NOT NULL,
    starts_at TEXT NOT NULL,
    created_at TEXT NOT NULL
)
SQL
    );
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
    $pdo->exec(
        <<<'SQL'
INSERT INTO users(id, email, display_name, role_id) VALUES
    (10, 'owner@example.test', 'Owner User', 1),
    (20, 'waiting@example.test', 'Waiting User', 1)
SQL
    );
    $pdo->exec(
        <<<'SQL'
INSERT INTO calls(id, room_id, owner_user_id, status, starts_at, created_at)
VALUES('call-db-sync', 'room-db-sync', 10, 'active', '2026-04-19T10:00:00Z', '2026-04-19T09:00:00Z')
SQL
    );
    $pdo->exec(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at) VALUES
    ('call-db-sync', 10, 'owner@example.test', 'Owner User', 'internal', 'owner', 'allowed', '2026-04-19T10:01:00Z', NULL),
    ('call-db-sync', 20, 'waiting@example.test', 'Waiting User', 'internal', 'participant', 'pending', NULL, NULL)
SQL
    );

    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };

    $frames = [];
    $sender = static function (mixed $socket, array $payload) use (&$frames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        if (!isset($frames[$key]) || !is_array($frames[$key])) {
            $frames[$key] = [];
        }
        $frames[$key][] = $payload;
        return true;
    };

    $ownerLobbyState = videochat_lobby_state_init();
    $ownerConnection = videochat_presence_connection_descriptor(
        [
            'id' => 10,
            'display_name' => 'Owner User',
            'role' => 'user',
        ],
        'sess-owner',
        'conn-owner',
        'socket-owner',
        'room-db-sync'
    );
    $ownerConnection['active_call_id'] = 'call-db-sync';
    $ownerConnection['requested_call_id'] = 'call-db-sync';
    $ownerConnection['call_role'] = 'owner';
    $ownerConnection['can_moderate_call'] = true;

    $ownerSnapshot = videochat_realtime_send_synced_lobby_snapshot_to_connection(
        $ownerLobbyState,
        $ownerConnection,
        $openDatabase,
        'assert_owner_backfill',
        $sender,
        1_777_000_000_000
    );
    videochat_realtime_lobby_db_sync_assert((bool) ($ownerSnapshot['sent'] ?? false), 'owner snapshot should be sent');
    $ownerFrame = end($frames['socket-owner']);
    videochat_realtime_lobby_db_sync_assert((string) ($ownerFrame['type'] ?? '') === 'lobby/snapshot', 'owner should receive lobby snapshot');
    videochat_realtime_lobby_db_sync_assert((int) ($ownerFrame['queue_count'] ?? 0) === 1, 'owner snapshot should include one pending DB participant');
    videochat_realtime_lobby_db_sync_assert((int) (($ownerFrame['queue'][0]['user_id'] ?? 0)) === 20, 'pending DB participant id mismatch');

    $presenceState = videochat_presence_state_init();
    videochat_presence_join_room($presenceState, $ownerConnection, 'room-db-sync', $sender);
    $allowCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'target_user_id' => 20,
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_sync_lobby_room_from_database(
        $ownerLobbyState,
        $openDatabase,
        'room-db-sync',
        'call-db-sync',
        1_777_000_001_000
    );
    $allowResult = videochat_lobby_apply_command(
        $ownerLobbyState,
        $presenceState,
        $ownerConnection,
        $allowCommand,
        $sender,
        1_777_000_002_000
    );
    videochat_realtime_lobby_db_sync_assert((bool) ($allowResult['ok'] ?? false), 'owner allow should succeed after DB backfill');
    videochat_realtime_mark_call_participant_invite_state_by_user_id($openDatabase, 'call-db-sync', 20, 'allowed', ['pending']);
    videochat_realtime_sync_lobby_room_from_database(
        $ownerLobbyState,
        $openDatabase,
        'room-db-sync',
        'call-db-sync',
        1_777_000_003_000
    );
    $admittedSnapshot = videochat_lobby_snapshot_payload($ownerLobbyState, 'room-db-sync', 'assert_admitted');
    videochat_realtime_lobby_db_sync_assert((int) ($admittedSnapshot['queue_count'] ?? -1) === 0, 'allowed DB participant should leave queue');
    videochat_realtime_lobby_db_sync_assert((int) ($admittedSnapshot['admitted_count'] ?? 0) === 1, 'allowed DB participant should become admitted handoff');

    $waitingLobbyState = videochat_lobby_state_init();
    $waitingConnection = videochat_presence_connection_descriptor(
        [
            'id' => 20,
            'display_name' => 'Waiting User',
            'role' => 'user',
        ],
        'sess-waiting',
        'conn-waiting',
        'socket-waiting',
        'waiting-room'
    );
    $waitingConnection['requested_call_id'] = 'call-db-sync';
    $waitingConnection['active_call_id'] = 'call-db-sync';
    $waitingConnection['pending_room_id'] = 'room-db-sync';
    videochat_realtime_send_synced_lobby_snapshot_to_connection(
        $waitingLobbyState,
        $waitingConnection,
        $openDatabase,
        'assert_waiting_backfill',
        $sender,
        1_777_000_004_000
    );
    $waitingFrame = end($frames['socket-waiting']);
    videochat_realtime_lobby_db_sync_assert((string) ($waitingFrame['room_id'] ?? '') === 'room-db-sync', 'waiting snapshot should target pending room');
    videochat_realtime_lobby_db_sync_assert((int) ($waitingFrame['admitted_count'] ?? 0) === 1, 'waiting user should receive admitted handoff from DB');
    videochat_realtime_lobby_db_sync_assert((int) (($waitingFrame['admitted'][0]['user_id'] ?? 0)) === 20, 'waiting admitted user id mismatch');

    $pdo->exec("UPDATE call_participants SET invite_state = 'pending', joined_at = NULL WHERE call_id = 'call-db-sync' AND user_id = 20");
    videochat_realtime_sync_lobby_room_from_database($ownerLobbyState, $openDatabase, 'room-db-sync', 'call-db-sync');
    $removeCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/remove',
        'target_user_id' => 20,
    ], JSON_UNESCAPED_SLASHES));
    $removeResult = videochat_lobby_apply_command(
        $ownerLobbyState,
        $presenceState,
        $ownerConnection,
        $removeCommand,
        $sender,
        1_777_000_005_000
    );
    videochat_realtime_lobby_db_sync_assert((bool) ($removeResult['ok'] ?? false), 'owner remove should succeed after DB backfill');
    videochat_realtime_mark_call_participant_invite_state_by_user_id($openDatabase, 'call-db-sync', 20, 'invited', ['pending', 'allowed', 'accepted']);
    videochat_realtime_sync_lobby_room_from_database($ownerLobbyState, $openDatabase, 'room-db-sync', 'call-db-sync');
    $removeSnapshot = videochat_lobby_snapshot_payload($ownerLobbyState, 'room-db-sync', 'assert_removed');
    videochat_realtime_lobby_db_sync_assert((int) ($removeSnapshot['queue_count'] ?? -1) === 0, 'removed DB participant should not be re-queued');

    $inviteState = (string) $pdo->query(
        "SELECT invite_state FROM call_participants WHERE call_id = 'call-db-sync' AND user_id = 20"
    )->fetchColumn();
    videochat_realtime_lobby_db_sync_assert($inviteState === 'invited', 'remove should reset DB participant to invited');

    $pdo->exec(
        "UPDATE call_participants SET invite_state = 'allowed', joined_at = '2026-04-19T10:10:00Z', left_at = '2026-04-19T10:15:00Z' WHERE call_id = 'call-db-sync' AND user_id = 20"
    );
    $staleAllowedConnection = $waitingConnection;
    $staleAllowedConnection['room_id'] = 'waiting-room';
    $staleAllowedConnection['pending_room_id'] = 'room-db-sync';
    $staleAllowedConnection['requested_room_id'] = 'room-db-sync';
    $staleAllowedConnection['requested_call_id'] = 'call-db-sync';
    $staleAllowedConnection['active_call_id'] = 'call-db-sync';
    $staleAllowedConnection['user_id'] = 20;
    videochat_realtime_lobby_db_sync_assert(
        !videochat_realtime_connection_can_bypass_admission_for_room($staleAllowedConnection, 'room-db-sync', $openDatabase),
        'left allowed participant should be gated again'
    );
    videochat_realtime_lobby_db_sync_assert(
        videochat_realtime_mark_call_participant_pending_for_queue($openDatabase, $staleAllowedConnection),
        'queue join should turn stale allowed participant back into pending'
    );
    $staleQueued = $pdo->query(
        "SELECT invite_state, joined_at, left_at FROM call_participants WHERE call_id = 'call-db-sync' AND user_id = 20"
    )->fetch(PDO::FETCH_ASSOC);
    videochat_realtime_lobby_db_sync_assert(is_array($staleQueued), 'stale queued participant row missing');
    videochat_realtime_lobby_db_sync_assert((string) ($staleQueued['invite_state'] ?? '') === 'pending', 'stale allowed queue join should write pending');
    videochat_realtime_lobby_db_sync_assert((string) ($staleQueued['joined_at'] ?? '') === '', 'stale allowed queue join should clear joined_at');
    videochat_realtime_lobby_db_sync_assert((string) ($staleQueued['left_at'] ?? '') === '', 'stale allowed queue join should clear left_at');

    $pdo->exec(
        "UPDATE call_participants SET invite_state = 'allowed', joined_at = '2026-04-19T10:20:00Z', left_at = NULL WHERE call_id = 'call-db-sync' AND user_id = 20"
    );
    $leftConnection = $waitingConnection;
    $leftConnection['room_id'] = 'room-db-sync';
    $leftConnection['pending_room_id'] = '';
    $leftConnection['requested_room_id'] = 'room-db-sync';
    $leftConnection['requested_call_id'] = 'call-db-sync';
    $leftConnection['active_call_id'] = 'call-db-sync';
    $leftConnection['user_id'] = 20;
    $leftConnection['call_role'] = 'participant';

    $activeConnection = $leftConnection;
    $activeConnection['connection_id'] = 'conn-waiting-active-other-worker';
    videochat_realtime_touch_call_presence($openDatabase, $activeConnection);
    videochat_realtime_mark_call_participant_left($openDatabase, $leftConnection, videochat_presence_state_init());
    $stillActiveRow = $pdo->query(
        "SELECT invite_state, left_at FROM call_participants WHERE call_id = 'call-db-sync' AND user_id = 20"
    )->fetch(PDO::FETCH_ASSOC);
    videochat_realtime_lobby_db_sync_assert(is_array($stillActiveRow), 'active participant row missing');
    videochat_realtime_lobby_db_sync_assert((string) ($stillActiveRow['invite_state'] ?? '') === 'allowed', 'active cross-worker participant must not be reset to invited');
    videochat_realtime_lobby_db_sync_assert(trim((string) ($stillActiveRow['left_at'] ?? '')) === '', 'active cross-worker participant must not get left_at');
    videochat_realtime_remove_call_presence($openDatabase, $activeConnection);

    videochat_realtime_mark_call_participant_left($openDatabase, $leftConnection, videochat_presence_state_init());
    $leftRow = $pdo->query(
        "SELECT invite_state, left_at FROM call_participants WHERE call_id = 'call-db-sync' AND user_id = 20"
    )->fetch(PDO::FETCH_ASSOC);
    videochat_realtime_lobby_db_sync_assert(is_array($leftRow), 'left participant row missing');
    videochat_realtime_lobby_db_sync_assert((string) ($leftRow['invite_state'] ?? '') === 'invited', 'leaving an admitted participant should reset to invited');
    videochat_realtime_lobby_db_sync_assert(trim((string) ($leftRow['left_at'] ?? '')) !== '', 'leaving an admitted participant should set left_at');

    fwrite(STDOUT, "[realtime-lobby-db-sync-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-lobby-db-sync-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
