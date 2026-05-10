<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../domain/realtime/realtime_lobby.php';

function videochat_owner_transfer_lifecycle_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-owner-transfer-lifecycle-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_owner_transfer_lifecycle_seed_user(PDO $pdo, string $email, string $displayName): int
{
    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_owner_transfer_lifecycle_assert($roleId > 0, 'expected seeded user role');
    $passwordHash = password_hash('owner-transfer-lifecycle-123', PASSWORD_DEFAULT);
    videochat_owner_transfer_lifecycle_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash failed');

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
    videochat_owner_transfer_lifecycle_assert($userId > 0, 'inserted user id should be positive');
    return $userId;
}

function videochat_owner_transfer_lifecycle_owner_count(PDO $pdo, string $callId): int
{
    $query = $pdo->prepare(
        "SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id AND source = 'internal' AND call_role = 'owner'"
    );
    $query->execute([':call_id' => $callId]);
    return (int) $query->fetchColumn();
}

function videochat_owner_transfer_lifecycle_connection(
    PDO $pdo,
    array &$presenceState,
    string $roomId,
    int $userId,
    string $displayName,
    string $suffix
): array {
    $connection = videochat_presence_connection_descriptor(
        [
            'id' => $userId,
            'display_name' => $displayName,
            'role' => 'user',
        ],
        'sess-owner-transfer-lifecycle-' . $suffix,
        'conn-owner-transfer-lifecycle-' . $suffix,
        'socket-owner-transfer-lifecycle-' . $suffix,
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

function videochat_owner_transfer_lifecycle_queue_user(array &$lobbyState, string $roomId, int $userId, string $displayName): void
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

function videochat_owner_transfer_lifecycle_command(string $type, string $roomId, int $targetUserId): array
{
    $command = videochat_lobby_decode_client_frame(json_encode([
        'type' => $type,
        'room_id' => $roomId,
        'target_user_id' => $targetUserId,
    ], JSON_UNESCAPED_SLASHES));
    videochat_owner_transfer_lifecycle_assert((bool) ($command['ok'] ?? false), "{$type} should decode");
    return $command;
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-owner-transfer-lifecycle-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-owner-transfer-lifecycle-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $oldOwnerUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'user'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    videochat_owner_transfer_lifecycle_assert($oldOwnerUserId > 0, 'expected seeded normal owner user');

    $newOwnerUserId = videochat_owner_transfer_lifecycle_seed_user($pdo, 'owner-transfer-lifecycle-new@example.com', 'Owner Transfer Lifecycle New Owner');
    $waitingUserId = videochat_owner_transfer_lifecycle_seed_user($pdo, 'owner-transfer-lifecycle-waiting@example.com', 'Owner Transfer Lifecycle Waiting');

    $created = videochat_create_call($pdo, $oldOwnerUserId, [
        'title' => 'Owner Transfer Lifecycle Contract',
        'starts_at' => '2026-06-10T09:00:00Z',
        'ends_at' => '2026-06-10T10:00:00Z',
        'internal_participant_user_ids' => [$newOwnerUserId, $waitingUserId],
    ]);
    videochat_owner_transfer_lifecycle_assert((bool) ($created['ok'] ?? false), 'setup call should be created');
    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    $roomId = (string) (($created['call'] ?? [])['room_id'] ?? '');
    videochat_owner_transfer_lifecycle_assert($callId !== '' && $roomId !== '', 'created call should expose ids');

    $transfer = videochat_update_call_participant_role($pdo, $callId, $newOwnerUserId, 'owner', $oldOwnerUserId, 'user');
    videochat_owner_transfer_lifecycle_assert((bool) ($transfer['ok'] ?? false), 'normal owner should transfer ownership');
    videochat_owner_transfer_lifecycle_assert(videochat_owner_transfer_lifecycle_owner_count($pdo, $callId) === 1, 'transfer should leave exactly one owner row');

    $newOwnerUpdate = videochat_update_call($pdo, $callId, $newOwnerUserId, 'user', [
        'title' => 'Owner Transfer Lifecycle Administered',
    ]);
    videochat_owner_transfer_lifecycle_assert((bool) ($newOwnerUpdate['ok'] ?? false), 'new owner should administer call settings after transfer');

    $oldOwnerUpdate = videochat_update_call($pdo, $callId, $oldOwnerUserId, 'user', [
        'title' => 'Old Owner Should Not Administer',
    ]);
    videochat_owner_transfer_lifecycle_assert(!(bool) ($oldOwnerUpdate['ok'] ?? true), 'old non-admin owner should not administer after transfer');
    videochat_owner_transfer_lifecycle_assert((string) ($oldOwnerUpdate['reason'] ?? '') === 'forbidden', 'old owner update should be forbidden');

    $oldOwnerRegain = videochat_update_call_participant_role($pdo, $callId, $oldOwnerUserId, 'owner', $oldOwnerUserId, 'user');
    videochat_owner_transfer_lifecycle_assert(!(bool) ($oldOwnerRegain['ok'] ?? true), 'old non-admin owner must not regain owner rights');
    videochat_owner_transfer_lifecycle_assert((string) ($oldOwnerRegain['reason'] ?? '') === 'forbidden', 'old owner regain should be forbidden');

    $presenceState = videochat_presence_state_init();
    $lobbyState = videochat_lobby_state_init();
    $oldOwnerConnection = videochat_owner_transfer_lifecycle_connection($pdo, $presenceState, $roomId, $oldOwnerUserId, 'Old Owner', 'old-owner');
    $newOwnerConnection = videochat_owner_transfer_lifecycle_connection($pdo, $presenceState, $roomId, $newOwnerUserId, 'New Owner', 'new-owner');
    videochat_owner_transfer_lifecycle_queue_user($lobbyState, $roomId, $waitingUserId, 'Waiting User');

    $oldOwnerAllow = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $oldOwnerConnection,
        videochat_owner_transfer_lifecycle_command('lobby/allow', $roomId, $waitingUserId)
    );
    videochat_owner_transfer_lifecycle_assert(!(bool) ($oldOwnerAllow['ok'] ?? true), 'old non-admin owner should not moderate lobby after transfer');

    $newOwnerAllow = videochat_lobby_apply_command(
        $lobbyState,
        $presenceState,
        $newOwnerConnection,
        videochat_owner_transfer_lifecycle_command('lobby/allow', $roomId, $waitingUserId)
    );
    videochat_owner_transfer_lifecycle_assert((bool) ($newOwnerAllow['ok'] ?? false), 'new owner should moderate lobby after transfer');

    $cancelled = videochat_cancel_call($pdo, $callId, $newOwnerUserId, 'user', [
        'cancel_reason' => 'owner_transfer_lifecycle',
        'cancel_message' => 'Owner-transfer lifecycle terminal-state proof.',
    ]);
    videochat_owner_transfer_lifecycle_assert((bool) ($cancelled['ok'] ?? false), 'new owner should be able to cancel after transfer');
    $cancelledUpdate = videochat_update_call($pdo, $callId, $newOwnerUserId, 'user', [
        'title' => 'Cancelled Should Stay Immutable',
    ]);
    videochat_owner_transfer_lifecycle_assert(!(bool) ($cancelledUpdate['ok'] ?? true), 'cancelled call update should stay blocked after transfer');
    videochat_owner_transfer_lifecycle_assert((string) (($cancelledUpdate['errors'] ?? [])['status'] ?? '') === 'immutable_for_edit', 'cancelled update status error mismatch');
    $cancelledOwnerTransfer = videochat_update_call_participant_role($pdo, $callId, $oldOwnerUserId, 'owner', $newOwnerUserId, 'user');
    videochat_owner_transfer_lifecycle_assert(!(bool) ($cancelledOwnerTransfer['ok'] ?? true), 'cancelled call owner transfer should stay blocked after transfer');
    videochat_owner_transfer_lifecycle_assert((string) (($cancelledOwnerTransfer['errors'] ?? [])['status'] ?? '') === 'immutable_for_edit', 'cancelled owner-transfer status error mismatch');

    $endedCall = videochat_create_call($pdo, $oldOwnerUserId, [
        'title' => 'Owner Transfer Lifecycle Ended',
        'starts_at' => '2026-06-11T09:00:00Z',
        'ends_at' => '2026-06-11T10:00:00Z',
        'internal_participant_user_ids' => [$newOwnerUserId],
    ]);
    videochat_owner_transfer_lifecycle_assert((bool) ($endedCall['ok'] ?? false), 'ended setup call should be created');
    $endedCallId = (string) (($endedCall['call'] ?? [])['id'] ?? '');
    $endedTransfer = videochat_update_call_participant_role($pdo, $endedCallId, $newOwnerUserId, 'owner', $oldOwnerUserId, 'user');
    videochat_owner_transfer_lifecycle_assert((bool) ($endedTransfer['ok'] ?? false), 'ended setup transfer should succeed before terminal transition');
    $setEnded = $pdo->prepare('UPDATE calls SET status = :status WHERE id = :id');
    $setEnded->execute([
        ':status' => 'ended',
        ':id' => $endedCallId,
    ]);
    $endedUpdate = videochat_update_call($pdo, $endedCallId, $newOwnerUserId, 'user', [
        'title' => 'Ended Should Stay Immutable',
    ]);
    videochat_owner_transfer_lifecycle_assert(!(bool) ($endedUpdate['ok'] ?? true), 'ended call update should stay blocked after transfer');
    videochat_owner_transfer_lifecycle_assert((string) (($endedUpdate['errors'] ?? [])['status'] ?? '') === 'immutable_for_edit', 'ended update status error mismatch');
    $endedOwnerTransfer = videochat_update_call_participant_role($pdo, $endedCallId, $oldOwnerUserId, 'owner', $newOwnerUserId, 'user');
    videochat_owner_transfer_lifecycle_assert(!(bool) ($endedOwnerTransfer['ok'] ?? true), 'ended call owner transfer should stay blocked after transfer');
    videochat_owner_transfer_lifecycle_assert((string) (($endedOwnerTransfer['errors'] ?? [])['status'] ?? '') === 'immutable_for_edit', 'ended owner-transfer status error mismatch');

    $deletedCall = videochat_create_call($pdo, $oldOwnerUserId, [
        'title' => 'Owner Transfer Lifecycle Deleted',
        'starts_at' => '2026-06-12T09:00:00Z',
        'ends_at' => '2026-06-12T10:00:00Z',
        'internal_participant_user_ids' => [$newOwnerUserId],
    ]);
    videochat_owner_transfer_lifecycle_assert((bool) ($deletedCall['ok'] ?? false), 'deleted setup call should be created');
    $deletedCallId = (string) (($deletedCall['call'] ?? [])['id'] ?? '');
    $deletedTransfer = videochat_update_call_participant_role($pdo, $deletedCallId, $newOwnerUserId, 'owner', $oldOwnerUserId, 'user');
    videochat_owner_transfer_lifecycle_assert((bool) ($deletedTransfer['ok'] ?? false), 'deleted setup transfer should succeed before delete');
    $deleteResult = videochat_delete_call($pdo, $deletedCallId, $newOwnerUserId, 'user');
    videochat_owner_transfer_lifecycle_assert((bool) ($deleteResult['ok'] ?? false), 'new owner should delete after transfer');
    $deletedUpdate = videochat_update_call($pdo, $deletedCallId, $newOwnerUserId, 'user', [
        'title' => 'Deleted Should Stay Missing',
    ]);
    videochat_owner_transfer_lifecycle_assert(!(bool) ($deletedUpdate['ok'] ?? true), 'deleted call update should stay blocked after transfer');
    videochat_owner_transfer_lifecycle_assert((string) ($deletedUpdate['reason'] ?? '') === 'not_found', 'deleted update should be not_found');
    $deletedOwnerTransfer = videochat_update_call_participant_role($pdo, $deletedCallId, $oldOwnerUserId, 'owner', $newOwnerUserId, 'user');
    videochat_owner_transfer_lifecycle_assert(!(bool) ($deletedOwnerTransfer['ok'] ?? true), 'deleted call owner transfer should stay blocked after transfer');
    videochat_owner_transfer_lifecycle_assert((string) ($deletedOwnerTransfer['reason'] ?? '') === 'not_found', 'deleted owner-transfer should be not_found');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-owner-transfer-lifecycle-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-owner-transfer-lifecycle-contract] ERROR: {$error->getMessage()}\n");
    exit(1);
}
