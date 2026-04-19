<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_realtime_admission_bypass_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-admission-bypass-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "[realtime-admission-bypass-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
    user_id INTEGER NOT NULL,
    source TEXT NOT NULL,
    call_role TEXT NOT NULL,
    invite_state TEXT NOT NULL DEFAULT 'invited'
)
SQL
    );

    $insertCall = $pdo->prepare(
        <<<'SQL'
INSERT INTO calls(id, room_id, owner_user_id, status, starts_at, created_at)
VALUES(:id, :room_id, :owner_user_id, :status, :starts_at, :created_at)
SQL
    );
    $insertCall->execute([
        ':id' => 'call-owner-room',
        ':room_id' => 'demo-call-room',
        ':owner_user_id' => 77,
        ':status' => 'active',
        ':starts_at' => '2026-04-17T00:00:00Z',
        ':created_at' => '2026-04-17T00:00:00Z',
    ]);

    $insertCall->execute([
        ':id' => 'call-user79-moderator-room',
        ':room_id' => 'demo-call-room',
        ':owner_user_id' => 11,
        ':status' => 'active',
        ':starts_at' => '2026-04-16T00:00:00Z',
        ':created_at' => '2026-04-16T00:00:00Z',
    ]);

    $insertParticipant = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, source, call_role)
VALUES(:call_id, :user_id, :source, :call_role)
SQL
    );

    $insertParticipant->execute([
        ':call_id' => 'call-owner-room',
        ':user_id' => 78,
        ':source' => 'internal',
        ':call_role' => 'moderator',
    ]);

    $insertParticipant->execute([
        ':call_id' => 'call-owner-room',
        ':user_id' => 79,
        ':source' => 'internal',
        ':call_role' => 'participant',
    ]);

    $insertParticipant->execute([
        ':call_id' => 'call-user79-moderator-room',
        ':user_id' => 79,
        ':source' => 'internal',
        ':call_role' => 'moderator',
    ]);

    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };

    $ownerConnection = [
        'user_id' => 77,
        'role' => 'user',
        'call_role' => 'participant',
        'requested_room_id' => 'demo-call-room',
        'pending_room_id' => 'demo-call-room',
    ];
    videochat_realtime_admission_bypass_assert(
        videochat_realtime_connection_can_bypass_admission_for_room($ownerConnection, 'demo-call-room', $openDatabase),
        'owner must bypass admission for own room'
    );

    $moderatorConnection = [
        'user_id' => 78,
        'role' => 'user',
        'call_role' => 'participant',
        'requested_room_id' => 'demo-call-room',
        'pending_room_id' => 'demo-call-room',
    ];
    videochat_realtime_admission_bypass_assert(
        videochat_realtime_connection_can_bypass_admission_for_room($moderatorConnection, 'demo-call-room', $openDatabase),
        'moderator must bypass admission for moderated room'
    );

    $participantConnection = [
        'user_id' => 79,
        'role' => 'user',
        'call_role' => 'participant',
        'requested_call_id' => 'call-owner-room',
        'requested_room_id' => 'demo-call-room',
        'pending_room_id' => 'demo-call-room',
    ];
    videochat_realtime_admission_bypass_assert(
        !videochat_realtime_connection_can_bypass_admission_for_room($participantConnection, 'demo-call-room', $openDatabase),
        'participant must not bypass admission when requested_call_id is non-moderator call'
    );

    $pdo->exec("UPDATE call_participants SET invite_state = 'allowed' WHERE call_id = 'call-owner-room' AND user_id = 79");
    $allowedParticipantConnection = [
        'user_id' => 79,
        'role' => 'user',
        'call_role' => 'participant',
        'requested_call_id' => 'call-owner-room',
        'requested_room_id' => 'demo-call-room',
        'pending_room_id' => 'demo-call-room',
    ];
    videochat_realtime_admission_bypass_assert(
        videochat_realtime_connection_can_bypass_admission_for_room($allowedParticipantConnection, 'demo-call-room', $openDatabase),
        'allowed participant must bypass admission after owner approval'
    );
    $pdo->exec("UPDATE call_participants SET invite_state = 'invited' WHERE call_id = 'call-owner-room' AND user_id = 79");

    $participantModeratorElsewhereConnection = [
        'user_id' => 79,
        'role' => 'user',
        'call_role' => 'participant',
        'requested_call_id' => 'call-user79-moderator-room',
        'requested_room_id' => 'demo-call-room',
        'pending_room_id' => 'demo-call-room',
    ];
    videochat_realtime_admission_bypass_assert(
        videochat_realtime_connection_can_bypass_admission_for_room(
            $participantModeratorElsewhereConnection,
            'demo-call-room',
            $openDatabase
        ),
        'participant must bypass admission when requested_call_id is moderator call'
    );

    $adminConnection = [
        'user_id' => 1,
        'role' => 'admin',
        'call_role' => 'participant',
        'requested_room_id' => 'demo-call-room',
        'pending_room_id' => 'demo-call-room',
    ];
    videochat_realtime_admission_bypass_assert(
        videochat_realtime_connection_can_bypass_admission_for_room($adminConnection, 'demo-call-room', $openDatabase),
        'admin must bypass admission'
    );

    $ownerFastPathConnection = [
        'user_id' => 77,
        'role' => 'user',
        'call_role' => 'owner',
        'requested_room_id' => 'demo-call-room',
        'pending_room_id' => 'demo-call-room',
    ];
    $failingOpenDatabase = static function (): PDO {
        throw new RuntimeException('database_unavailable');
    };
    videochat_realtime_admission_bypass_assert(
        videochat_realtime_connection_can_bypass_admission_for_room($ownerFastPathConnection, 'demo-call-room', $failingOpenDatabase),
        'owner call-role fast-path must bypass admission even when db lookup is unavailable'
    );

    fwrite(STDOUT, "[realtime-admission-bypass-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-admission-bypass-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
