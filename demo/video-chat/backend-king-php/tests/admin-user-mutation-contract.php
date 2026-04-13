<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/users/user_management.php';

function videochat_admin_user_mutation_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[admin-user-mutation-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-admin-user-mutation-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $createValidationFail = videochat_admin_create_user($pdo, [
        'email' => 'not-an-email',
        'display_name' => '',
        'role' => 'invalid-role',
        'password' => '123',
    ]);
    videochat_admin_user_mutation_assert($createValidationFail['ok'] === false, 'invalid create payload should fail');
    videochat_admin_user_mutation_assert($createValidationFail['reason'] === 'validation_failed', 'invalid create reason mismatch');
    videochat_admin_user_mutation_assert(
        (string) ($createValidationFail['errors']['email'] ?? '') === 'required_valid_email',
        'invalid create email error mismatch'
    );

    $createResult = videochat_admin_create_user($pdo, [
        'email' => 'ops-user@intelligent-intern.com',
        'display_name' => 'Ops User',
        'role' => 'moderator',
        'password' => 'ops-user-password',
        'status' => 'active',
        'time_format' => '12h',
        'theme' => 'dark',
        'avatar_path' => '/avatars/ops-user.png',
    ]);
    videochat_admin_user_mutation_assert($createResult['ok'] === true, 'valid create should succeed');
    videochat_admin_user_mutation_assert($createResult['reason'] === 'created', 'valid create reason mismatch');
    $createdUser = $createResult['user'] ?? null;
    videochat_admin_user_mutation_assert(is_array($createdUser), 'valid create should return user payload');
    $createdUserId = (int) ($createdUser['id'] ?? 0);
    videochat_admin_user_mutation_assert($createdUserId > 0, 'created user id should be positive');
    videochat_admin_user_mutation_assert((string) ($createdUser['role'] ?? '') === 'moderator', 'created user role mismatch');

    $duplicateCreate = videochat_admin_create_user($pdo, [
        'email' => 'ops-user@intelligent-intern.com',
        'display_name' => 'Ops User Duplicate',
        'role' => 'user',
        'password' => 'ops-user-password',
    ]);
    videochat_admin_user_mutation_assert($duplicateCreate['ok'] === false, 'duplicate create should fail');
    videochat_admin_user_mutation_assert($duplicateCreate['reason'] === 'email_conflict', 'duplicate create reason mismatch');
    videochat_admin_user_mutation_assert(
        (string) ($duplicateCreate['errors']['email'] ?? '') === 'already_exists',
        'duplicate create email conflict mismatch'
    );

    $updateValidationFail = videochat_admin_update_user($pdo, $createdUserId, [
        'status' => 'waiting',
    ]);
    videochat_admin_user_mutation_assert($updateValidationFail['ok'] === false, 'invalid update payload should fail');
    videochat_admin_user_mutation_assert($updateValidationFail['reason'] === 'validation_failed', 'invalid update reason mismatch');
    videochat_admin_user_mutation_assert(
        (string) ($updateValidationFail['errors']['status'] ?? '') === 'must_be_active_or_disabled',
        'invalid update status error mismatch'
    );

    $updateResult = videochat_admin_update_user($pdo, $createdUserId, [
        'display_name' => 'Ops User Updated',
        'role' => 'user',
        'status' => 'active',
        'theme' => 'light',
        'time_format' => '24h',
    ]);
    videochat_admin_user_mutation_assert($updateResult['ok'] === true, 'valid update should succeed');
    videochat_admin_user_mutation_assert($updateResult['reason'] === 'updated', 'valid update reason mismatch');
    videochat_admin_user_mutation_assert(
        (string) (($updateResult['user'] ?? [])['display_name'] ?? '') === 'Ops User Updated',
        'updated display_name mismatch'
    );
    videochat_admin_user_mutation_assert(
        (string) (($updateResult['user'] ?? [])['role'] ?? '') === 'user',
        'updated role mismatch'
    );

    $adminUserQuery = $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'admin'
ORDER BY users.id ASC
LIMIT 1
SQL
    );
    $adminUserId = (int) $adminUserQuery->fetchColumn();
    videochat_admin_user_mutation_assert($adminUserId > 0, 'seeded admin user should exist');

    $conflictUpdate = videochat_admin_update_user($pdo, $createdUserId, [
        'email' => 'admin@intelligent-intern.com',
    ]);
    videochat_admin_user_mutation_assert($conflictUpdate['ok'] === false, 'duplicate update email should fail');
    videochat_admin_user_mutation_assert($conflictUpdate['reason'] === 'email_conflict', 'duplicate update reason mismatch');

    $notFoundUpdate = videochat_admin_update_user($pdo, 999999, [
        'display_name' => 'Should Not Exist',
    ]);
    videochat_admin_user_mutation_assert($notFoundUpdate['ok'] === false, 'update on missing user should fail');
    videochat_admin_user_mutation_assert($notFoundUpdate['reason'] === 'not_found', 'missing user update reason mismatch');

    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'admin-user-mutation-contract')
SQL
    );
    $insertSession->execute([
        ':id' => 'sess_ops_user_contract',
        ':user_id' => $createdUserId,
        ':issued_at' => gmdate('c', time() - 10),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);

    $deactivateResult = videochat_admin_deactivate_user($pdo, $createdUserId);
    videochat_admin_user_mutation_assert($deactivateResult['ok'] === true, 'deactivate should succeed');
    videochat_admin_user_mutation_assert($deactivateResult['reason'] === 'deactivated', 'deactivate reason mismatch');
    videochat_admin_user_mutation_assert(
        (string) (($deactivateResult['user'] ?? [])['status'] ?? '') === 'disabled',
        'deactivated user status should be disabled'
    );
    videochat_admin_user_mutation_assert(
        (int) ($deactivateResult['revoked_sessions'] ?? 0) >= 1,
        'deactivate should revoke active sessions'
    );

    $sessionCheck = $pdo->prepare('SELECT revoked_at FROM sessions WHERE id = :id LIMIT 1');
    $sessionCheck->execute([':id' => 'sess_ops_user_contract']);
    $sessionRow = $sessionCheck->fetch();
    videochat_admin_user_mutation_assert(is_array($sessionRow), 'session row should exist');
    videochat_admin_user_mutation_assert(
        is_string($sessionRow['revoked_at'] ?? null) && trim((string) $sessionRow['revoked_at']) !== '',
        'deactivate should stamp revoked_at on active sessions'
    );

    $deactivateAgain = videochat_admin_deactivate_user($pdo, $createdUserId);
    videochat_admin_user_mutation_assert($deactivateAgain['ok'] === true, 'second deactivate should still succeed');
    videochat_admin_user_mutation_assert($deactivateAgain['reason'] === 'already_disabled', 'second deactivate reason mismatch');
    videochat_admin_user_mutation_assert(
        (int) ($deactivateAgain['revoked_sessions'] ?? -1) === 0,
        'second deactivate should not revoke additional sessions'
    );

    $deactivateMissing = videochat_admin_deactivate_user($pdo, 999999);
    videochat_admin_user_mutation_assert($deactivateMissing['ok'] === false, 'deactivate missing user should fail');
    videochat_admin_user_mutation_assert($deactivateMissing['reason'] === 'not_found', 'deactivate missing user reason mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[admin-user-mutation-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[admin-user-mutation-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
