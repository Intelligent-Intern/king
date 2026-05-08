<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';

function videochat_owner_moderation_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-owner-moderation-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_owner_moderation_seed_user(PDO $pdo, string $email, string $displayName): int
{
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_owner_moderation_assert($roleId > 0, 'expected seeded user role');
    $passwordHash = password_hash('owner-moderation-123', PASSWORD_DEFAULT);
    videochat_owner_moderation_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash failed');

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower($email),
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_owner_moderation_assert($userId > 0, 'inserted user id should be positive');
    return $userId;
}

function videochat_owner_moderation_connection(
    PDO $pdo,
    array &$presenceState,
    string $roomId,
    int $userId,
    string $displayName,
    string $globalRole,
    string $connectionSuffix
): array {
    $connection = videochat_presence_connection_descriptor(
        [
            'id' => $userId,
            'display_name' => $displayName,
            'role' => $globalRole,
        ],
        'sess-' . $connectionSuffix,
        'conn-' . $connectionSuffix,
        'socket-' . $connectionSuffix,
        $roomId
    );
    $context = videochat_call_role_context_for_room_user($pdo, $roomId, $userId);
    $connection['active_call_id'] = (string) ($context['call_id'] ?? '');
    $connection['call_role'] = (string) ($context['call_role'] ?? 'participant');
    $connection['effective_call_role'] = (string) ($context['effective_call_role'] ?? $connection['call_role']);
    $connection['can_moderate_call'] = (bool) ($context['can_moderate'] ?? false);
    $connection['can_manage_call_owner'] = (bool) ($context['can_manage_owner'] ?? false);

    $join = videochat_presence_join_room($presenceState, $connection, $roomId);
    return (array) ($join['connection'] ?? $connection);
}

function videochat_owner_moderation_static_connection(
    array &$presenceState,
    string $roomId,
    int $userId,
    string $displayName,
    string $globalRole,
    string $callRole,
    string $connectionSuffix
): array {
    $connection = videochat_presence_connection_descriptor(
        [
            'id' => $userId,
            'display_name' => $displayName,
            'role' => $globalRole,
        ],
        'sess-static-' . $connectionSuffix,
        'conn-static-' . $connectionSuffix,
        'socket-static-' . $connectionSuffix,
        $roomId
    );
    $connection['active_call_id'] = $roomId;
    $connection['call_role'] = $callRole;
    $connection['effective_call_role'] = $callRole;
    $connection['can_moderate_call'] = in_array($callRole, ['owner', 'moderator'], true);
    $connection['can_manage_call_owner'] = $callRole === 'owner';

    $join = videochat_presence_join_room($presenceState, $connection, $roomId);
    return (array) ($join['connection'] ?? $connection);
}

function videochat_owner_moderation_queue_user(array &$lobbyState, string $roomId, int $userId, string $displayName): void
{
    videochat_lobby_ensure_room_state($lobbyState, $roomId);
    $lobbyState['rooms'][$roomId]['queued_by_user'][$userId] = [
        'user_id' => $userId,
        'display_name' => $displayName,
        'role' => 'user',
        'requested_unix_ms' => 1_780_500_000_000,
        'requested_at' => '2026-06-01T00:00:00+00:00',
    ];
}

function videochat_owner_moderation_admit_user(array &$lobbyState, string $roomId, int $userId, string $displayName): void
{
    videochat_lobby_ensure_room_state($lobbyState, $roomId);
    $lobbyState['rooms'][$roomId]['admitted_by_user'][$userId] = [
        'user_id' => $userId,
        'display_name' => $displayName,
        'role' => 'user',
        'admitted_unix_ms' => 1_780_500_000_000,
        'admitted_at' => '2026-06-01T00:00:00+00:00',
        'admitted_by' => [
            'user_id' => 0,
            'display_name' => 'setup',
            'role' => 'user',
        ],
    ];
}

function videochat_owner_moderation_command(string $type, string $roomId, int $targetUserId): array
{
    $command = videochat_lobby_decode_client_frame(json_encode([
        'type' => $type,
        'room_id' => $roomId,
        'target_user_id' => $targetUserId,
    ], JSON_UNESCAPED_SLASHES));
    videochat_owner_moderation_assert((bool) ($command['ok'] ?? false), "{$type} should decode");
    return $command;
}

function videochat_owner_moderation_owner_count(PDO $pdo, string $callId): int
{
    $query = $pdo->prepare(
        "SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id AND source = 'internal' AND call_role = 'owner'"
    );
    $query->execute([':call_id' => $callId]);
    return (int) $query->fetchColumn();
}

try {
    $staticPresenceState = videochat_presence_state_init();
    $staticLobbyState = videochat_lobby_state_init();
    $staticRoomId = 'owner-moderation-static';
    $staticOwner = videochat_owner_moderation_static_connection(
        $staticPresenceState,
        $staticRoomId,
        101,
        'Static Owner',
        'user',
        'owner',
        'owner'
    );
    $staticParticipant = videochat_owner_moderation_static_connection(
        $staticPresenceState,
        $staticRoomId,
        102,
        'Static Participant',
        'user',
        'participant',
        'participant'
    );

    videochat_owner_moderation_queue_user($staticLobbyState, $staticRoomId, 103, 'Static Waiting');
    $staticParticipantReject = videochat_lobby_apply_command(
        $staticLobbyState,
        $staticPresenceState,
        $staticParticipant,
        videochat_owner_moderation_command('lobby/reject', $staticRoomId, 103)
    );
    videochat_owner_moderation_assert(!(bool) ($staticParticipantReject['ok'] ?? true), 'static participant must not reject');
    videochat_owner_moderation_assert((string) ($staticParticipantReject['error'] ?? '') === 'forbidden', 'static participant reject error mismatch');

    $staticOwnerReject = videochat_lobby_apply_command(
        $staticLobbyState,
        $staticPresenceState,
        $staticOwner,
        videochat_owner_moderation_command('lobby/reject', $staticRoomId, 103)
    );
    videochat_owner_moderation_assert((bool) ($staticOwnerReject['ok'] ?? false), 'static owner should reject');
    videochat_owner_moderation_assert((string) ($staticOwnerReject['action'] ?? '') === 'lobby/remove', 'static reject should normalize to remove');

    videochat_owner_moderation_admit_user($staticLobbyState, $staticRoomId, 103, 'Static Waiting');
    $staticOwnerKick = videochat_lobby_apply_command(
        $staticLobbyState,
        $staticPresenceState,
        $staticOwner,
        videochat_owner_moderation_command('lobby/kick', $staticRoomId, 103)
    );
    videochat_owner_moderation_assert((bool) ($staticOwnerKick['ok'] ?? false), 'static owner should kick');
    videochat_owner_moderation_assert((string) ($staticOwnerKick['action'] ?? '') === 'lobby/remove', 'static kick should normalize to remove');

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-owner-moderation-contract] SKIP persistence: pdo_sqlite unavailable\n");
        fwrite(STDOUT, "[call-owner-moderation-contract] PASS\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-owner-moderation-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'admin'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    videochat_owner_moderation_assert($adminUserId > 0, 'expected seeded admin user');

    $ownerUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'user'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    videochat_owner_moderation_assert($ownerUserId > 0, 'expected seeded owner user');

    $participantUserId = videochat_owner_moderation_seed_user($pdo, 'owner-moderation-participant@example.com', 'Owner Moderation Participant');
    $nextOwnerUserId = videochat_owner_moderation_seed_user($pdo, 'owner-moderation-next-owner@example.com', 'Owner Moderation Next Owner');
    $waitingUserId = videochat_owner_moderation_seed_user($pdo, 'owner-moderation-waiting@example.com', 'Owner Moderation Waiting');

    $created = videochat_create_call($pdo, $ownerUserId, [
        'title' => 'Owner Moderation Contract',
        'starts_at' => '2026-06-10T09:00:00Z',
        'ends_at' => '2026-06-10T10:00:00Z',
        'internal_participant_user_ids' => [$participantUserId, $nextOwnerUserId],
    ]);
    videochat_owner_moderation_assert((bool) ($created['ok'] ?? false), 'owner-owned call should be created');
    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    $roomId = (string) (($created['call'] ?? [])['room_id'] ?? '');
    videochat_owner_moderation_assert($callId !== '' && $roomId !== '', 'created call should expose ids');

    $presenceState = videochat_presence_state_init();
    $lobbyState = videochat_lobby_state_init();
    $ownerConnection = videochat_owner_moderation_connection($pdo, $presenceState, $roomId, $ownerUserId, 'Owner User', 'user', 'owner-before');
    $participantConnection = videochat_owner_moderation_connection($pdo, $presenceState, $roomId, $participantUserId, 'Normal Participant', 'user', 'participant');

    videochat_owner_moderation_queue_user($lobbyState, $roomId, $waitingUserId, 'Waiting User');
    $participantAllow = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $participantConnection,
        videochat_owner_moderation_command('lobby/allow', $roomId, $waitingUserId)
    );
    videochat_owner_moderation_assert(!(bool) ($participantAllow['ok'] ?? true), 'normal participant must not admit lobby users');
    videochat_owner_moderation_assert((string) ($participantAllow['error'] ?? '') === 'forbidden', 'normal participant admit error mismatch');

    $ownerAllow = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $ownerConnection,
        videochat_owner_moderation_command('lobby/allow', $roomId, $waitingUserId)
    );
    videochat_owner_moderation_assert((bool) ($ownerAllow['ok'] ?? false), 'owner should admit lobby users');
    videochat_owner_moderation_assert(isset($lobbyState['rooms'][$roomId]['admitted_by_user'][$waitingUserId]), 'admitted user should be tracked');

    videochat_owner_moderation_queue_user($lobbyState, $roomId, $waitingUserId, 'Waiting User');
    $participantReject = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $participantConnection,
        videochat_owner_moderation_command('lobby/reject', $roomId, $waitingUserId)
    );
    videochat_owner_moderation_assert(!(bool) ($participantReject['ok'] ?? true), 'normal participant must not reject lobby users');
    videochat_owner_moderation_assert((string) ($participantReject['error'] ?? '') === 'forbidden', 'normal participant reject error mismatch');

    $ownerReject = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $ownerConnection,
        videochat_owner_moderation_command('lobby/reject', $roomId, $waitingUserId)
    );
    videochat_owner_moderation_assert((bool) ($ownerReject['ok'] ?? false), 'owner should reject lobby users');
    videochat_owner_moderation_assert((string) ($ownerReject['action'] ?? '') === 'lobby/remove', 'reject should persist through remove action');

    videochat_owner_moderation_admit_user($lobbyState, $roomId, $waitingUserId, 'Waiting User');
    $participantKick = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $participantConnection,
        videochat_owner_moderation_command('lobby/kick', $roomId, $waitingUserId)
    );
    videochat_owner_moderation_assert(!(bool) ($participantKick['ok'] ?? true), 'normal participant must not kick admitted users');
    videochat_owner_moderation_assert((string) ($participantKick['error'] ?? '') === 'forbidden', 'normal participant kick error mismatch');

    $ownerKick = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $ownerConnection,
        videochat_owner_moderation_command('lobby/kick', $roomId, $waitingUserId)
    );
    videochat_owner_moderation_assert((bool) ($ownerKick['ok'] ?? false), 'owner should kick admitted users');
    videochat_owner_moderation_assert((string) ($ownerKick['action'] ?? '') === 'lobby/remove', 'kick should persist through remove action');

    $participantOwnerTransfer = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $participantUserId,
        'owner',
        $participantUserId,
        'user'
    );
    videochat_owner_moderation_assert(!(bool) ($participantOwnerTransfer['ok'] ?? true), 'normal participant must not transfer ownership');
    videochat_owner_moderation_assert((string) ($participantOwnerTransfer['reason'] ?? '') === 'forbidden', 'participant transfer error mismatch');

    $ownerTransfer = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $nextOwnerUserId,
        'owner',
        $ownerUserId,
        'user'
    );
    videochat_owner_moderation_assert((bool) ($ownerTransfer['ok'] ?? false), 'current owner should transfer ownership');
    videochat_owner_moderation_assert(videochat_owner_moderation_owner_count($pdo, $callId) === 1, 'transfer should leave exactly one owner participant row');

    $oldOwnerContext = videochat_call_role_context_for_room_user($pdo, $roomId, $ownerUserId);
    videochat_owner_moderation_assert((string) ($oldOwnerContext['call_role'] ?? '') === 'participant', 'old owner should be demoted to participant');
    videochat_owner_moderation_assert(!(bool) ($oldOwnerContext['can_moderate'] ?? true), 'old owner should lose call moderation controls');

    $newOwnerContext = videochat_call_role_context_for_room_user($pdo, $roomId, $nextOwnerUserId);
    videochat_owner_moderation_assert((string) ($newOwnerContext['call_role'] ?? '') === 'owner', 'new owner should resolve owner role');
    videochat_owner_moderation_assert((bool) ($newOwnerContext['can_moderate'] ?? false), 'new owner should gain call moderation controls');

    $oldOwnerAfterTransfer = videochat_owner_moderation_connection($pdo, $presenceState, $roomId, $ownerUserId, 'Owner User', 'user', 'owner-after');
    $newOwnerConnection = videochat_owner_moderation_connection($pdo, $presenceState, $roomId, $nextOwnerUserId, 'Next Owner', 'user', 'next-owner');
    videochat_owner_moderation_queue_user($lobbyState, $roomId, $waitingUserId, 'Waiting User');

    $oldOwnerAfterAllow = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $oldOwnerAfterTransfer,
        videochat_owner_moderation_command('lobby/allow', $roomId, $waitingUserId)
    );
    videochat_owner_moderation_assert(!(bool) ($oldOwnerAfterAllow['ok'] ?? true), 'old non-admin owner must not moderate after transfer');
    videochat_owner_moderation_assert((string) ($oldOwnerAfterAllow['error'] ?? '') === 'forbidden', 'old owner post-transfer error mismatch');

    $newOwnerAllow = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $newOwnerConnection,
        videochat_owner_moderation_command('lobby/allow', $roomId, $waitingUserId)
    );
    videochat_owner_moderation_assert((bool) ($newOwnerAllow['ok'] ?? false), 'new owner should moderate after transfer');

    $adminOwnedCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Admin Owner Moderation Contract',
        'starts_at' => '2026-06-10T11:00:00Z',
        'ends_at' => '2026-06-10T12:00:00Z',
        'internal_participant_user_ids' => [$participantUserId],
    ]);
    videochat_owner_moderation_assert((bool) ($adminOwnedCall['ok'] ?? false), 'admin-owned call should be created');
    $adminCallId = (string) (($adminOwnedCall['call'] ?? [])['id'] ?? '');
    $adminRoomId = (string) (($adminOwnedCall['call'] ?? [])['room_id'] ?? '');
    videochat_owner_moderation_assert($adminCallId !== '' && $adminRoomId !== '', 'admin call should expose ids');

    $adminTransfer = videochat_update_call_participant_role(
        $pdo,
        $adminCallId,
        $participantUserId,
        'owner',
        $adminUserId,
        'admin'
    );
    videochat_owner_moderation_assert((bool) ($adminTransfer['ok'] ?? false), 'admin owner should transfer ownership');
    videochat_owner_moderation_assert(videochat_owner_moderation_owner_count($pdo, $adminCallId) === 1, 'admin transfer should leave exactly one owner participant row');

    $adminPresenceState = videochat_presence_state_init();
    $adminLobbyState = videochat_lobby_state_init();
    $adminAfterTransfer = videochat_owner_moderation_connection($pdo, $adminPresenceState, $adminRoomId, $adminUserId, 'Admin User', 'admin', 'admin-after');
    $adminContext = videochat_call_role_context_for_room_user($pdo, $adminRoomId, $adminUserId);
    videochat_owner_moderation_assert((string) ($adminContext['call_role'] ?? '') === 'participant', 'admin previous owner row should be demoted');
    videochat_owner_moderation_assert(!(bool) ($adminContext['can_moderate'] ?? true), 'call role context should not keep owner controls for demoted admin row');

    videochat_owner_moderation_queue_user($adminLobbyState, $adminRoomId, $waitingUserId, 'Waiting User');
    $adminAllow = videochat_lobby_apply_command(
        $adminLobbyState,
        $adminPresenceState,
        $adminAfterTransfer,
        videochat_owner_moderation_command('lobby/allow', $adminRoomId, $waitingUserId)
    );
    videochat_owner_moderation_assert((bool) ($adminAllow['ok'] ?? false), 'global admin should keep moderation controls after owner transfer');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-owner-moderation-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, "[call-owner-moderation-contract] ERROR: {$error->getMessage()}\n");
    exit(1);
}
