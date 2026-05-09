<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_realtime_lobby_timeout_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-lobby-timeout-consistency-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_realtime_lobby_timeout_open_database(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        <<<'SQL'
CREATE TABLE roles (
    id INTEGER PRIMARY KEY,
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
    (40, 'moderator@example.test', 'Moderator User', 1),
    (20, 'waiting@example.test', 'Waiting User', 1)
SQL
    );
    $pdo->exec(
        <<<'SQL'
INSERT INTO calls(id, room_id, owner_user_id, status, starts_at, created_at)
VALUES('call-lobby-timeout', 'room-lobby-timeout', 10, 'active', '2026-05-09T10:00:00Z', '2026-05-09T09:00:00Z')
SQL
    );
    $pdo->exec(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at) VALUES
    ('call-lobby-timeout', 10, 'owner@example.test', 'Owner User', 'internal', 'owner', 'allowed', '2026-05-09T10:01:00Z', NULL),
    ('call-lobby-timeout', 40, 'moderator@example.test', 'Moderator User', 'internal', 'moderator', 'allowed', '2026-05-09T10:02:00Z', NULL),
    ('call-lobby-timeout', 20, 'waiting@example.test', 'Waiting User', 'internal', 'participant', 'pending', NULL, NULL)
SQL
    );

    return [
        $pdo,
        static fn (): PDO => $pdo,
        static function (): PDO {
            throw new RuntimeException('simulated lobby admission timeout');
        },
    ];
}

function videochat_realtime_lobby_timeout_connection(int $userId, string $displayName, string $suffix, string $roomId): array
{
    $connection = videochat_presence_connection_descriptor(
        [
            'id' => $userId,
            'display_name' => $displayName,
            'role' => 'user',
        ],
        'sess-' . $suffix,
        'conn-' . $suffix,
        'socket-' . $suffix,
        $roomId
    );
    $connection['active_call_id'] = 'call-lobby-timeout';
    $connection['requested_call_id'] = 'call-lobby-timeout';
    $connection['call_role'] = $userId === 40 ? 'moderator' : 'participant';
    $connection['can_moderate_call'] = $userId === 40;

    return $connection;
}

function videochat_realtime_lobby_timeout_presence(): array
{
    $presenceState = videochat_presence_state_init();
    $moderator = videochat_realtime_lobby_timeout_connection(40, 'Moderator User', 'moderator', 'room-lobby-timeout');
    $waiting = videochat_realtime_lobby_timeout_connection(20, 'Waiting User', 'waiting', videochat_realtime_waiting_room_id());
    $waiting['pending_room_id'] = 'room-lobby-timeout';

    $moderatorJoin = videochat_presence_join_room($presenceState, $moderator, 'room-lobby-timeout');
    $waitingJoin = videochat_presence_join_room($presenceState, $waiting, videochat_realtime_waiting_room_id());
    $moderator = (array) ($moderatorJoin['connection'] ?? $moderator);
    $waiting = (array) ($waitingJoin['connection'] ?? $waiting);
    $waiting['pending_room_id'] = 'room-lobby-timeout';
    $presenceState['connections']['conn-waiting']['pending_room_id'] = 'room-lobby-timeout';

    return [$presenceState, $moderator, $waiting];
}

function videochat_realtime_lobby_timeout_sync(callable $openDatabase, int $nowMs): array
{
    $lobbyState = videochat_lobby_state_init();
    $sync = videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        'room-lobby-timeout',
        'call-lobby-timeout',
        $nowMs
    );
    videochat_realtime_lobby_timeout_assert((bool) ($sync['ok'] ?? false), 'lobby DB sync should succeed');

    return $lobbyState;
}

function videochat_realtime_lobby_timeout_waiting_state(PDO $pdo): string
{
    return (string) $pdo->query(
        "SELECT invite_state FROM call_participants WHERE call_id = 'call-lobby-timeout' AND user_id = 20"
    )->fetchColumn();
}

function videochat_realtime_lobby_timeout_snapshot(array $lobbyState): array
{
    return videochat_lobby_snapshot_payload($lobbyState, 'room-lobby-timeout', 'assert_timeout_consistency');
}

try {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "[realtime-lobby-timeout-consistency-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    [$pdo, $openDatabase, $timeoutOpenDatabase] = videochat_realtime_lobby_timeout_open_database();
    [$presenceState, $moderator, $waiting] = videochat_realtime_lobby_timeout_presence();
    $lobbyState = videochat_realtime_lobby_timeout_sync($openDatabase, 1_780_700_000_000);
    $initialSnapshot = videochat_realtime_lobby_timeout_snapshot($lobbyState);
    videochat_realtime_lobby_timeout_assert((int) ($initialSnapshot['queue_count'] ?? -1) === 1, 'initial lobby should contain one queued participant');
    videochat_realtime_lobby_timeout_assert((int) ($initialSnapshot['admitted_count'] ?? -1) === 0, 'initial lobby should contain no admitted handoff');

    $allowCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'target_user_id' => 20,
        'room_id' => 'room-lobby-timeout',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_lobby_timeout_assert((bool) ($allowCommand['ok'] ?? false), 'allow command should decode');
    videochat_realtime_lobby_timeout_assert(videochat_realtime_lobby_command_sender($allowCommand) !== null, 'runtime admission sender must defer pre-persistence broadcast');

    $allowResult = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $moderator,
        $allowCommand,
        videochat_realtime_lobby_command_sender($allowCommand),
        1_780_700_001_000
    );
    videochat_realtime_lobby_timeout_assert((bool) ($allowResult['ok'] ?? false), 'lobby admission should apply locally before persistence');
    $prePersistenceSnapshot = videochat_realtime_lobby_timeout_snapshot($lobbyState);
    videochat_realtime_lobby_timeout_assert((int) ($prePersistenceSnapshot['queue_count'] ?? -1) === 0, 'pre-persistence local queue should be empty');
    videochat_realtime_lobby_timeout_assert((int) ($prePersistenceSnapshot['admitted_count'] ?? -1) === 1, 'pre-persistence local admitted handoff should exist');

    videochat_realtime_apply_successful_lobby_command($allowResult, $lobbyState, $presenceState, $moderator, $timeoutOpenDatabase);
    $timeoutSnapshot = videochat_realtime_lobby_timeout_snapshot($lobbyState);
    videochat_realtime_lobby_timeout_assert(videochat_realtime_lobby_timeout_waiting_state($pdo) === 'pending', 'Timeout during lobby admission leads to consistent state: database remains pending');
    videochat_realtime_lobby_timeout_assert((int) ($timeoutSnapshot['queue_count'] ?? -1) === 1, 'timeout should restore local queued participant');
    videochat_realtime_lobby_timeout_assert((int) ($timeoutSnapshot['admitted_count'] ?? -1) === 0, 'timeout must leave no unpersisted admitted handoff');

    $freshAfterTimeout = videochat_realtime_lobby_timeout_sync($openDatabase, 1_780_700_002_000);
    $freshTimeoutSnapshot = videochat_realtime_lobby_timeout_snapshot($freshAfterTimeout);
    videochat_realtime_lobby_timeout_assert((int) ($freshTimeoutSnapshot['queue_count'] ?? -1) === 1, 'fresh DB sync after timeout should keep the participant queued');
    videochat_realtime_lobby_timeout_assert((int) ($freshTimeoutSnapshot['admitted_count'] ?? -1) === 0, 'fresh DB sync after timeout should keep admitted empty');

    $clear = videochat_lobby_clear_for_connection(
        $lobbyState,
        $presenceState,
        $waiting,
        'abort_join_attempt',
        null,
        1_780_700_003_000
    );
    videochat_realtime_lobby_timeout_assert((bool) ($clear['cleared'] ?? false), 'aborting waiting connection should clear local lobby entry');
    $reset = videochat_realtime_reset_waiting_connection_invite(
        $openDatabase,
        $lobbyState,
        $presenceState,
        $waiting,
        'abort_join_attempt',
        false
    );
    videochat_realtime_lobby_timeout_assert((bool) $reset, 'Participant is removed from lobby after aborting join attempt: database state should reset');
    videochat_realtime_lobby_timeout_assert(videochat_realtime_lobby_timeout_waiting_state($pdo) === 'invited', 'aborted join should reset pending participant to invited');
    $abortSnapshot = videochat_realtime_lobby_timeout_snapshot($lobbyState);
    videochat_realtime_lobby_timeout_assert((int) ($abortSnapshot['queue_count'] ?? -1) === 0, 'aborted join should leave no queued lobby participant');
    videochat_realtime_lobby_timeout_assert((int) ($abortSnapshot['admitted_count'] ?? -1) === 0, 'aborted join should leave no admitted handoff');

    $freshAfterAbort = videochat_realtime_lobby_timeout_sync($openDatabase, 1_780_700_004_000);
    $freshAbortSnapshot = videochat_realtime_lobby_timeout_snapshot($freshAfterAbort);
    videochat_realtime_lobby_timeout_assert((int) ($freshAbortSnapshot['queue_count'] ?? -1) === 0, 'fresh DB sync after abort should keep lobby empty');
    videochat_realtime_lobby_timeout_assert((int) ($freshAbortSnapshot['admitted_count'] ?? -1) === 0, 'fresh DB sync after abort should keep admitted empty');

    fwrite(STDOUT, "[realtime-lobby-timeout-consistency-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-lobby-timeout-consistency-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
