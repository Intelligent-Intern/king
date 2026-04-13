<?php

declare(strict_types=1);

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../call_management.php';
require_once __DIR__ . '/../call_directory.php';

function videochat_call_update_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-update-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-call-update-' . bin2hex(random_bytes(6)) . '.sqlite';
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
    videochat_call_update_assert($adminUserId > 0, 'expected seeded admin user');

    $userUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'user'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    videochat_call_update_assert($userUserId > 0, 'expected seeded user user');

    $moderatorRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'moderator' LIMIT 1")->fetchColumn();
    videochat_call_update_assert($moderatorRoleId > 0, 'expected moderator role');
    $moderatorPassword = password_hash('moderator123', PASSWORD_DEFAULT);
    videochat_call_update_assert(is_string($moderatorPassword) && $moderatorPassword !== '', 'moderator password hash failed');
    $createModerator = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $createModerator->execute([
        ':email' => 'moderator-update-call@intelligent-intern.com',
        ':display_name' => 'Moderator Update Call',
        ':password_hash' => $moderatorPassword,
        ':role_id' => $moderatorRoleId,
        ':updated_at' => gmdate('c'),
    ]);
    $moderatorUserId = (int) $pdo->lastInsertId();
    videochat_call_update_assert($moderatorUserId > 0, 'expected inserted moderator user');

    $created = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'Before Update',
        'starts_at' => '2026-06-10T09:00:00Z',
        'ends_at' => '2026-06-10T10:00:00Z',
        'internal_participant_user_ids' => [$userUserId],
        'external_participants' => [
            ['email' => 'first-guest@example.com', 'display_name' => 'First Guest'],
        ],
    ]);
    videochat_call_update_assert($created['ok'] === true, 'setup create should succeed');
    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    videochat_call_update_assert($callId !== '', 'setup call id should be non-empty');

    $emptyUpdate = videochat_update_call($pdo, $callId, $adminUserId, 'admin', []);
    videochat_call_update_assert($emptyUpdate['ok'] === false, 'empty update payload should fail');
    videochat_call_update_assert($emptyUpdate['reason'] === 'validation_failed', 'empty update reason mismatch');
    videochat_call_update_assert(
        (string) (($emptyUpdate['errors'] ?? [])['payload'] ?? '') === 'at_least_one_supported_field_required',
        'empty update payload error mismatch'
    );

    $resendRequestedUpdate = videochat_update_call($pdo, $callId, $adminUserId, 'admin', [
        'resend_invites' => true,
        'title' => 'Attempted Resend',
    ]);
    videochat_call_update_assert($resendRequestedUpdate['ok'] === false, 'resend_invites request should fail');
    videochat_call_update_assert($resendRequestedUpdate['reason'] === 'validation_failed', 'resend_invites reason mismatch');
    videochat_call_update_assert(
        (string) (($resendRequestedUpdate['errors'] ?? [])['resend_invites'] ?? '') === 'global_invite_resend_not_supported_use_explicit_action',
        'resend_invites validation error mismatch'
    );

    $forbiddenUpdate = videochat_update_call($pdo, $callId, $userUserId, 'user', [
        'title' => 'User Should Not Edit',
    ]);
    videochat_call_update_assert($forbiddenUpdate['ok'] === false, 'non-owner user update should fail');
    videochat_call_update_assert($forbiddenUpdate['reason'] === 'forbidden', 'non-owner user update reason mismatch');

    $moderatorUpdate = videochat_update_call($pdo, $callId, $moderatorUserId, 'moderator', [
        'title' => 'Edited by Moderator',
    ]);
    videochat_call_update_assert($moderatorUpdate['ok'] === true, 'moderator update should succeed');
    videochat_call_update_assert($moderatorUpdate['reason'] === 'updated', 'moderator update reason mismatch');
    videochat_call_update_assert(
        (string) (($moderatorUpdate['call'] ?? [])['title'] ?? '') === 'Edited by Moderator',
        'moderator update title mismatch'
    );

    $ownerUpdate = videochat_update_call($pdo, $callId, $adminUserId, 'admin', [
        'title' => 'After Update',
        'starts_at' => '2026-06-10T11:00:00Z',
        'ends_at' => '2026-06-10T12:00:00Z',
        'internal_participant_user_ids' => [$moderatorUserId],
        'external_participants' => [
            ['email' => 'second-guest@example.com', 'display_name' => 'Second Guest'],
        ],
    ]);
    videochat_call_update_assert($ownerUpdate['ok'] === true, 'owner update should succeed');
    videochat_call_update_assert($ownerUpdate['reason'] === 'updated', 'owner update reason mismatch');
    videochat_call_update_assert(
        (string) (($ownerUpdate['call'] ?? [])['title'] ?? '') === 'After Update',
        'owner update title mismatch'
    );
    videochat_call_update_assert(
        (string) (($ownerUpdate['call'] ?? [])['starts_at'] ?? '') === '2026-06-10T11:00:00+00:00',
        'owner update starts_at mismatch'
    );
    videochat_call_update_assert(
        (string) (($ownerUpdate['call'] ?? [])['ends_at'] ?? '') === '2026-06-10T12:00:00+00:00',
        'owner update ends_at mismatch'
    );
    videochat_call_update_assert(
        (int) ((($ownerUpdate['call'] ?? [])['participants']['totals'] ?? [])['total'] ?? 0) === 3,
        'owner update participant total mismatch'
    );
    videochat_call_update_assert(
        (int) ((($ownerUpdate['call'] ?? [])['participants']['totals'] ?? [])['internal'] ?? 0) === 2,
        'owner update internal participant total mismatch'
    );
    videochat_call_update_assert(
        (int) ((($ownerUpdate['call'] ?? [])['participants']['totals'] ?? [])['external'] ?? 0) === 1,
        'owner update external participant total mismatch'
    );
    videochat_call_update_assert(
        ((($ownerUpdate['invite_dispatch'] ?? [])['global_resend_triggered'] ?? null) === false),
        'owner update must not trigger global invite resend'
    );
    videochat_call_update_assert(
        ((($ownerUpdate['invite_dispatch'] ?? [])['explicit_action_required'] ?? null) === true),
        'owner update should require explicit invite action'
    );

    $callRowQuery = $pdo->prepare('SELECT title, starts_at, ends_at FROM calls WHERE id = :id LIMIT 1');
    $callRowQuery->execute([':id' => $callId]);
    $callRow = $callRowQuery->fetch();
    videochat_call_update_assert(is_array($callRow), 'updated call row should exist');
    videochat_call_update_assert((string) ($callRow['title'] ?? '') === 'After Update', 'updated call title persistence mismatch');
    videochat_call_update_assert((string) ($callRow['starts_at'] ?? '') === '2026-06-10T11:00:00+00:00', 'updated call starts_at persistence mismatch');
    videochat_call_update_assert((string) ($callRow['ends_at'] ?? '') === '2026-06-10T12:00:00+00:00', 'updated call ends_at persistence mismatch');

    $participantRows = $pdo->prepare(
        <<<'SQL'
SELECT email, source
FROM call_participants
WHERE call_id = :call_id
ORDER BY
    CASE source
        WHEN 'internal' THEN 0
        ELSE 1
    END ASC,
    email ASC
SQL
    );
    $participantRows->execute([':call_id' => $callId]);
    $participants = $participantRows->fetchAll();
    videochat_call_update_assert(is_array($participants) && count($participants) === 3, 'updated participant rows count mismatch');
    videochat_call_update_assert(
        (string) ($participants[0]['email'] ?? '') === 'admin@intelligent-intern.com',
        'updated participants should retain owner'
    );
    videochat_call_update_assert(
        (string) ($participants[1]['email'] ?? '') === 'moderator-update-call@intelligent-intern.com',
        'updated participants should include new internal participant'
    );
    videochat_call_update_assert(
        (string) ($participants[2]['email'] ?? '') === 'second-guest@example.com',
        'updated participants should include replacement external participant'
    );

    $cancelCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Immutable Cancelled',
        'starts_at' => '2026-06-11T09:00:00Z',
        'ends_at' => '2026-06-11T10:00:00Z',
    ]);
    videochat_call_update_assert($cancelCall['ok'] === true, 'cancel setup call create should succeed');
    $cancelCallId = (string) (($cancelCall['call'] ?? [])['id'] ?? '');
    $setCancelled = $pdo->prepare(
        'UPDATE calls SET status = :status, cancelled_at = :cancelled_at, cancel_reason = :cancel_reason WHERE id = :id'
    );
    $setCancelled->execute([
        ':status' => 'cancelled',
        ':cancelled_at' => gmdate('c'),
        ':cancel_reason' => 'cancelled',
        ':id' => $cancelCallId,
    ]);

    $cancelledUpdate = videochat_update_call($pdo, $cancelCallId, $adminUserId, 'admin', [
        'title' => 'Should Not Update Cancelled',
    ]);
    videochat_call_update_assert($cancelledUpdate['ok'] === false, 'cancelled call update should fail');
    videochat_call_update_assert($cancelledUpdate['reason'] === 'validation_failed', 'cancelled call update reason mismatch');
    videochat_call_update_assert(
        (string) (($cancelledUpdate['errors'] ?? [])['status'] ?? '') === 'immutable_for_edit',
        'cancelled call immutable status error mismatch'
    );

    $missingUpdate = videochat_update_call($pdo, 'call_missing_contract', $adminUserId, 'admin', [
        'title' => 'Missing',
    ]);
    videochat_call_update_assert($missingUpdate['ok'] === false, 'missing call update should fail');
    videochat_call_update_assert($missingUpdate['reason'] === 'not_found', 'missing call update reason mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-update-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-update-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
