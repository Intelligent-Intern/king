<?php

declare(strict_types=1);

require_once __DIR__ . '/../http/module_realtime.php';

function videochat_realtime_lobby_security_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[realtime-lobby-security-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fwrite(STDOUT, "[realtime-lobby-security-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

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
    (20, 'waiting@example.test', 'Waiting User', 1),
    (30, 'admin@example.test', 'Admin User', 2),
    (40, 'moderator@example.test', 'Moderator User', 1),
    (50, 'plain@example.test', 'Plain User', 1)
SQL
    );
    $pdo->exec(
        <<<'SQL'
INSERT INTO calls(id, room_id, owner_user_id, status, starts_at, created_at) VALUES
    ('call-secure', 'room-secure', 10, 'active', '2026-05-08T10:00:00Z', '2026-05-08T09:00:00Z'),
    ('call-other', 'room-other', 50, 'active', '2026-05-08T10:00:00Z', '2026-05-08T09:00:00Z')
SQL
    );
    $pdo->exec(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at) VALUES
    ('call-secure', 10, 'owner@example.test', 'Owner User', 'internal', 'owner', 'allowed', '2026-05-08T10:01:00Z', NULL),
    ('call-secure', 20, 'waiting@example.test', 'Waiting User', 'internal', 'participant', 'pending', NULL, NULL),
    ('call-secure', 40, 'moderator@example.test', 'Moderator User', 'internal', 'moderator', 'allowed', '2026-05-08T10:02:00Z', NULL),
    ('call-secure', 50, 'plain@example.test', 'Plain User', 'internal', 'participant', 'allowed', '2026-05-08T10:03:00Z', NULL),
    ('call-other', 50, 'plain@example.test', 'Plain User', 'internal', 'owner', 'allowed', '2026-05-08T10:04:00Z', NULL)
SQL
    );

    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };
    $allowCommand = videochat_lobby_decode_client_frame(json_encode([
        'type' => 'lobby/allow',
        'target_user_id' => 20,
        'user_id' => 10,
        'role' => 'admin',
        'call_id' => 'call-other',
    ], JSON_UNESCAPED_SLASHES));
    videochat_realtime_lobby_security_assert((bool) ($allowCommand['ok'] ?? false), 'allow command should decode while ignoring forged ids');
    videochat_realtime_lobby_security_assert(videochat_realtime_lobby_command_requires_moderation($allowCommand), 'allow command must require moderation');

    $ownerConnection = [
        'user_id' => 10,
        'role' => 'user',
        'room_id' => 'room-secure',
        'requested_call_id' => 'call-secure',
        'active_call_id' => 'call-secure',
        'call_role' => 'participant',
    ];
    $ownerAuthority = videochat_realtime_authorize_lobby_moderation_command($ownerConnection, $allowCommand, 'room-secure', $openDatabase);
    videochat_realtime_lobby_security_assert((bool) ($ownerAuthority['ok'] ?? false), 'DB owner should be authorized even if connection role is stale');
    videochat_realtime_lobby_security_assert((string) ($ownerAuthority['call_role'] ?? '') === 'owner', 'owner authority must come from DB call role');

    $moderatorConnection = [
        'user_id' => 40,
        'role' => 'user',
        'room_id' => 'room-secure',
        'requested_call_id' => 'call-secure',
        'active_call_id' => 'call-secure',
        'call_role' => 'participant',
    ];
    $moderatorAuthority = videochat_realtime_authorize_lobby_moderation_command($moderatorConnection, $allowCommand, 'room-secure', $openDatabase);
    videochat_realtime_lobby_security_assert((bool) ($moderatorAuthority['ok'] ?? false), 'DB moderator should be authorized even if connection call_role is stale');
    videochat_realtime_lobby_security_assert((string) ($moderatorAuthority['call_role'] ?? '') === 'moderator', 'moderator authority must come from DB call role');

    $adminConnection = [
        'user_id' => 30,
        'role' => 'user',
        'room_id' => 'room-secure',
        'requested_call_id' => 'call-secure',
        'active_call_id' => 'call-secure',
        'call_role' => 'participant',
    ];
    $adminAuthority = videochat_realtime_authorize_lobby_moderation_command($adminConnection, $allowCommand, 'room-secure', $openDatabase);
    videochat_realtime_lobby_security_assert((bool) ($adminAuthority['ok'] ?? false), 'DB admin should be authorized even if connection role is stale');
    videochat_realtime_lobby_security_assert((string) ($adminAuthority['role'] ?? '') === 'admin', 'admin authority must come from DB global role');

    $forgedRoleConnection = [
        'user_id' => 50,
        'role' => 'admin',
        'raw_role' => 'moderator',
        'room_id' => 'room-secure',
        'requested_call_id' => 'call-secure',
        'active_call_id' => 'call-secure',
        'call_role' => 'owner',
        'can_moderate_call' => true,
    ];
    $forgedRoleAuthority = videochat_realtime_authorize_lobby_moderation_command($forgedRoleConnection, $allowCommand, 'room-secure', $openDatabase);
    videochat_realtime_lobby_security_assert(!(bool) ($forgedRoleAuthority['ok'] ?? true), 'forged role/call_role must not authorize lobby moderation');
    videochat_realtime_lobby_security_assert((string) ($forgedRoleAuthority['error'] ?? '') === 'forbidden', 'forged role denial reason mismatch');
    videochat_realtime_lobby_security_assert((string) ($forgedRoleAuthority['role'] ?? '') === 'user', 'forged role must be replaced by DB role');
    videochat_realtime_lobby_security_assert((string) ($forgedRoleAuthority['call_role'] ?? '') === 'participant', 'forged call role must be replaced by DB call role');

    $forgedCallConnection = [
        'user_id' => 50,
        'role' => 'user',
        'room_id' => 'room-secure',
        'requested_call_id' => 'call-other',
        'active_call_id' => 'call-other',
        'call_role' => 'owner',
        'can_moderate_call' => true,
    ];
    $forgedCallAuthority = videochat_realtime_authorize_lobby_moderation_command($forgedCallConnection, $allowCommand, 'room-secure', $openDatabase);
    videochat_realtime_lobby_security_assert(!(bool) ($forgedCallAuthority['ok'] ?? true), 'owner of another call must not moderate this room lobby');
    videochat_realtime_lobby_security_assert((string) ($forgedCallAuthority['error'] ?? '') === 'forbidden', 'forged call denial reason mismatch');
    videochat_realtime_lobby_security_assert((string) ($forgedCallAuthority['call_id'] ?? '') === 'call-secure', 'forged call id must be rebound to target room context');

    fwrite(STDOUT, "[realtime-lobby-security-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[realtime-lobby-security-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
