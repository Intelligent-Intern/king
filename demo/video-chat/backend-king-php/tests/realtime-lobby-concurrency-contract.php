<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_realtime_lobby_concurrency_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-lobby-concurrency-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_realtime_lobby_concurrency_open_database(): array
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
    (40, 'moderator-a@example.test', 'Moderator A', 1),
    (41, 'moderator-b@example.test', 'Moderator B', 1),
    (20, 'waiting@example.test', 'Waiting User', 1)
SQL
    );
    $pdo->exec(
        <<<'SQL'
INSERT INTO calls(id, room_id, owner_user_id, status, starts_at, created_at)
VALUES('call-lobby-concurrency', 'room-lobby-concurrency', 10, 'active', '2026-05-08T10:00:00Z', '2026-05-08T09:00:00Z')
SQL
    );
    $pdo->exec(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at) VALUES
    ('call-lobby-concurrency', 10, 'owner@example.test', 'Owner User', 'internal', 'owner', 'allowed', '2026-05-08T10:01:00Z', NULL),
    ('call-lobby-concurrency', 40, 'moderator-a@example.test', 'Moderator A', 'internal', 'moderator', 'allowed', '2026-05-08T10:02:00Z', NULL),
    ('call-lobby-concurrency', 41, 'moderator-b@example.test', 'Moderator B', 'internal', 'moderator', 'allowed', '2026-05-08T10:03:00Z', NULL),
    ('call-lobby-concurrency', 20, 'waiting@example.test', 'Waiting User', 'internal', 'participant', 'pending', NULL, NULL)
SQL
    );

    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };

    return [$pdo, $openDatabase];
}

function videochat_realtime_lobby_concurrency_presence(): array
{
    $presenceState = videochat_presence_state_init();
    $moderatorA = videochat_realtime_lobby_concurrency_connection(40, 'Moderator A', 'moderator-a');
    $moderatorB = videochat_realtime_lobby_concurrency_connection(41, 'Moderator B', 'moderator-b');
    $waitingUser = videochat_realtime_lobby_concurrency_connection(20, 'Waiting User', 'waiting');

    $moderatorAJoin = videochat_presence_join_room($presenceState, $moderatorA, 'room-lobby-concurrency');
    $moderatorBJoin = videochat_presence_join_room($presenceState, $moderatorB, 'room-lobby-concurrency');
    $waitingUserJoin = videochat_presence_join_room($presenceState, $waitingUser, 'waiting-room');

    $moderatorA = (array) ($moderatorAJoin['connection'] ?? $moderatorA);
    $moderatorB = (array) ($moderatorBJoin['connection'] ?? $moderatorB);
    $waitingUser = (array) ($waitingUserJoin['connection'] ?? $waitingUser);
    $waitingUser['pending_room_id'] = 'room-lobby-concurrency';
    $presenceState['connections']['conn-waiting']['pending_room_id'] = 'room-lobby-concurrency';

    return [$presenceState, $moderatorA, $moderatorB, $waitingUser];
}

function videochat_realtime_lobby_concurrency_connection(int $userId, string $displayName, string $suffix): array
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
        'room-lobby-concurrency'
    );
    $connection['active_call_id'] = 'call-lobby-concurrency';
    $connection['requested_call_id'] = 'call-lobby-concurrency';
    $connection['call_role'] = in_array($userId, [40, 41], true) ? 'moderator' : 'participant';
    $connection['can_moderate_call'] = in_array($userId, [40, 41], true);

    return $connection;
}

function videochat_realtime_lobby_concurrency_sync(callable $openDatabase, int $nowMs): array
{
    $lobbyState = videochat_lobby_state_init();
    $sync = videochat_realtime_sync_lobby_room_from_database(
        $lobbyState,
        $openDatabase,
        'room-lobby-concurrency',
        'call-lobby-concurrency',
        $nowMs
    );
    videochat_realtime_lobby_concurrency_assert((bool) ($sync['ok'] ?? false), 'lobby DB sync should succeed');

    return $lobbyState;
}

function videochat_realtime_lobby_concurrency_set_waiting_state(PDO $pdo, string $state): void
{
    $statement = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = :state,
    joined_at = NULL,
    left_at = NULL
WHERE call_id = 'call-lobby-concurrency'
  AND user_id = 20
SQL
    );
    $statement->execute([':state' => $state]);
}

function videochat_realtime_lobby_concurrency_waiting_state(PDO $pdo): string
{
    return (string) $pdo->query(
        "SELECT invite_state FROM call_participants WHERE call_id = 'call-lobby-concurrency' AND user_id = 20"
    )->fetchColumn();
}

function videochat_realtime_lobby_concurrency_snapshot(array $lobbyState): array
{
    return videochat_lobby_snapshot_payload($lobbyState, 'room-lobby-concurrency', 'assert_lobby_concurrency');
}

try {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "[realtime-lobby-concurrency-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    [$pdo, $openDatabase] = videochat_realtime_lobby_concurrency_open_database();
    [$presenceState, $moderatorA, $moderatorB] = videochat_realtime_lobby_concurrency_presence();

    $allowCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'target_user_id' => 20,
        'room_id' => 'room-lobby-concurrency',
    ], JSON_UNESCAPED_SLASHES));
    $rejectCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/reject',
        'target_user_id' => 20,
        'room_id' => 'room-lobby-concurrency',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_lobby_concurrency_assert((bool) ($allowCommand['ok'] ?? false), 'allow command should decode');
    videochat_realtime_lobby_concurrency_assert((bool) ($rejectCommand['ok'] ?? false), 'reject command should decode');

    $workerAState = videochat_realtime_lobby_concurrency_sync($openDatabase, 1_780_500_000_000);
    $workerBState = videochat_realtime_lobby_concurrency_sync($openDatabase, 1_780_500_000_000);
    $workerAAllow = videochat_lobby_apply_command(
        $workerAState,
        $presenceState,
        $moderatorA,
        $allowCommand,
        null,
        1_780_500_001_000
    );
    $workerBAllow = videochat_lobby_apply_command(
        $workerBState,
        $presenceState,
        $moderatorB,
        $allowCommand,
        null,
        1_780_500_001_010
    );
    videochat_realtime_lobby_concurrency_assert((bool) ($workerAAllow['ok'] ?? false), 'first concurrent allow should succeed');
    videochat_realtime_lobby_concurrency_assert((bool) ($workerBAllow['ok'] ?? false), 'second stale concurrent allow should also succeed');
    videochat_realtime_apply_successful_lobby_command($workerAAllow, $workerAState, $presenceState, $moderatorA, $openDatabase);
    videochat_realtime_apply_successful_lobby_command($workerBAllow, $workerBState, $presenceState, $moderatorB, $openDatabase);
    videochat_realtime_lobby_concurrency_assert(videochat_realtime_lobby_concurrency_waiting_state($pdo) === 'allowed', 'concurrent allow should persist one allowed state');

    $canonicalAllowedState = videochat_realtime_lobby_concurrency_sync($openDatabase, 1_780_500_002_000);
    $allowedSnapshot = videochat_realtime_lobby_concurrency_snapshot($canonicalAllowedState);
    videochat_realtime_lobby_concurrency_assert((int) ($allowedSnapshot['queue_count'] ?? -1) === 0, 'allowed canonical queue should be empty');
    videochat_realtime_lobby_concurrency_assert((int) ($allowedSnapshot['admitted_count'] ?? -1) === 1, 'concurrent allow should create one admitted handoff');
    videochat_realtime_lobby_concurrency_assert((int) (($allowedSnapshot['admitted'][0]['user_id'] ?? 0)) === 20, 'admitted user mismatch after concurrent allow');

    $lateAllow = videochat_lobby_apply_command(
        $canonicalAllowedState,
        $presenceState,
        $moderatorB,
        $allowCommand,
        null,
        1_780_500_003_000
    );
    videochat_realtime_lobby_concurrency_assert((bool) ($lateAllow['ok'] ?? false), 'late duplicate allow should be idempotent');
    videochat_realtime_lobby_concurrency_assert(!(bool) ($lateAllow['changed'] ?? true), 'late duplicate allow should not mutate lobby state');
    videochat_realtime_lobby_concurrency_assert((string) ($lateAllow['state'] ?? '') === 'already_allowed', 'late duplicate allow state mismatch');

    videochat_realtime_lobby_concurrency_set_waiting_state($pdo, 'pending');
    $allowFirstState = videochat_realtime_lobby_concurrency_sync($openDatabase, 1_780_500_010_000);
    $rejectSecondState = videochat_realtime_lobby_concurrency_sync($openDatabase, 1_780_500_010_000);
    $allowFirst = videochat_lobby_apply_command(
        $allowFirstState,
        $presenceState,
        $moderatorA,
        $allowCommand,
        null,
        1_780_500_011_000
    );
    $rejectSecond = videochat_lobby_apply_command(
        $rejectSecondState,
        $presenceState,
        $moderatorB,
        $rejectCommand,
        null,
        1_780_500_011_010
    );
    videochat_realtime_lobby_concurrency_assert((bool) ($allowFirst['ok'] ?? false), 'admit side of admit-then-reject race should succeed');
    videochat_realtime_lobby_concurrency_assert((bool) ($rejectSecond['ok'] ?? false), 'reject side of admit-then-reject race should succeed');
    videochat_realtime_apply_successful_lobby_command($allowFirst, $allowFirstState, $presenceState, $moderatorA, $openDatabase);
    videochat_realtime_apply_successful_lobby_command($rejectSecond, $rejectSecondState, $presenceState, $moderatorB, $openDatabase);
    videochat_realtime_lobby_concurrency_assert(videochat_realtime_lobby_concurrency_waiting_state($pdo) === 'invited', 'reject should win after admit-then-reject race');
    $admitThenRejectCanonical = videochat_realtime_lobby_concurrency_sync($openDatabase, 1_780_500_012_000);
    $admitThenRejectSnapshot = videochat_realtime_lobby_concurrency_snapshot($admitThenRejectCanonical);
    videochat_realtime_lobby_concurrency_assert((int) ($admitThenRejectSnapshot['queue_count'] ?? -1) === 0, 'admit-then-reject should leave no queued entry');
    videochat_realtime_lobby_concurrency_assert((int) ($admitThenRejectSnapshot['admitted_count'] ?? -1) === 0, 'admit-then-reject should leave no admitted handoff');

    videochat_realtime_lobby_concurrency_set_waiting_state($pdo, 'pending');
    $rejectFirstState = videochat_realtime_lobby_concurrency_sync($openDatabase, 1_780_500_020_000);
    $staleAllowSecondState = videochat_realtime_lobby_concurrency_sync($openDatabase, 1_780_500_020_000);
    $rejectFirst = videochat_lobby_apply_command(
        $rejectFirstState,
        $presenceState,
        $moderatorA,
        $rejectCommand,
        null,
        1_780_500_021_000
    );
    $staleAllowSecond = videochat_lobby_apply_command(
        $staleAllowSecondState,
        $presenceState,
        $moderatorB,
        $allowCommand,
        null,
        1_780_500_021_010
    );
    videochat_realtime_lobby_concurrency_assert((bool) ($rejectFirst['ok'] ?? false), 'reject side of reject-then-admit race should succeed');
    videochat_realtime_lobby_concurrency_assert((bool) ($staleAllowSecond['ok'] ?? false), 'stale admit side should not error before DB compare-and-set');
    videochat_realtime_apply_successful_lobby_command($rejectFirst, $rejectFirstState, $presenceState, $moderatorA, $openDatabase);
    videochat_realtime_apply_successful_lobby_command($staleAllowSecond, $staleAllowSecondState, $presenceState, $moderatorB, $openDatabase);
    videochat_realtime_lobby_concurrency_assert(videochat_realtime_lobby_concurrency_waiting_state($pdo) === 'invited', 'reject should win after reject-then-stale-admit race');
    $rejectThenAdmitCanonical = videochat_realtime_lobby_concurrency_sync($openDatabase, 1_780_500_022_000);
    $rejectThenAdmitSnapshot = videochat_realtime_lobby_concurrency_snapshot($rejectThenAdmitCanonical);
    videochat_realtime_lobby_concurrency_assert((int) ($rejectThenAdmitSnapshot['queue_count'] ?? -1) === 0, 'reject-then-stale-admit should leave no queued entry');
    videochat_realtime_lobby_concurrency_assert((int) ($rejectThenAdmitSnapshot['admitted_count'] ?? -1) === 0, 'reject-then-stale-admit should leave no admitted handoff');

    fwrite(STDOUT, "[realtime-lobby-concurrency-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-lobby-concurrency-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
