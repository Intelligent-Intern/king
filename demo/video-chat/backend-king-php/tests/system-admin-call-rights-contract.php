<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';

function videochat_system_admin_call_rights_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[system-admin-call-rights-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_system_admin_call_rights_create_user(PDO $pdo, string $email, string $displayName, int $roleId, ?string $password): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $passwordHash = null;
    if ($password !== null) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        videochat_system_admin_call_rights_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash should be generated');
    }
    $insert->execute([
        ':email' => strtolower(trim($email)),
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_system_admin_call_rights_assert($userId > 0, 'created user id should be positive');
    return $userId;
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[system-admin-call-rights-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-system-admin-call-rights-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $defaultTenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1")->fetchColumn();
    $userRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    $systemAdminId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $regularUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();

    videochat_system_admin_call_rights_assert($defaultTenantId > 0, 'default tenant should exist');
    videochat_system_admin_call_rights_assert($adminRoleId > 0 && $userRoleId > 0, 'admin and user roles should exist');
    videochat_system_admin_call_rights_assert($systemAdminId > 0, 'seeded system admin should exist');
    videochat_system_admin_call_rights_assert($regularUserId > 0, 'seeded regular user should exist');

    $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    )->execute([
        ':public_id' => '10000000-0000-4000-8000-000000000005',
        ':slug' => 'system-admin-foreign-org',
        ':label' => 'System Admin Foreign Organization',
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
    $foreignTenantId = (int) $pdo->lastInsertId();
    videochat_system_admin_call_rights_assert($foreignTenantId > 0, 'foreign tenant should be inserted');

    $foreignOwnerId = videochat_system_admin_call_rights_create_user(
        $pdo,
        'system-admin-call-owner@example.test',
        'Foreign Call Owner',
        $userRoleId,
        'owner-pass'
    );
    $foreignParticipantId = videochat_system_admin_call_rights_create_user(
        $pdo,
        'system-admin-call-participant@example.test',
        'Foreign Call Participant',
        $userRoleId,
        'participant-pass'
    );
    $foreignSecondParticipantId = videochat_system_admin_call_rights_create_user(
        $pdo,
        'system-admin-call-second-participant@example.test',
        'Foreign Call Second Participant',
        $userRoleId,
        'participant-pass'
    );
    videochat_tenant_attach_user($pdo, $foreignOwnerId, $foreignTenantId, 'owner');
    videochat_tenant_attach_user($pdo, $foreignParticipantId, $foreignTenantId, 'member');
    videochat_tenant_attach_user($pdo, $foreignSecondParticipantId, $foreignTenantId, 'member');

    $adminForeignMembership = $pdo->prepare(
        'SELECT COUNT(*) FROM tenant_memberships WHERE tenant_id = :tenant_id AND user_id = :user_id AND status = \'active\''
    );
    $adminForeignMembership->execute([
        ':tenant_id' => $foreignTenantId,
        ':user_id' => $systemAdminId,
    ]);
    videochat_system_admin_call_rights_assert((int) $adminForeignMembership->fetchColumn() === 0, 'system admin should not need foreign tenant membership');

    $created = videochat_create_call($pdo, $foreignOwnerId, [
        'title' => 'Foreign Organization Admin Slice',
        'starts_at' => gmdate('c', time() - 600),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => [$foreignParticipantId],
        'external_participants' => [],
    ], $foreignTenantId);
    videochat_system_admin_call_rights_assert((bool) ($created['ok'] ?? false), 'foreign organization call should be created');
    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    videochat_system_admin_call_rights_assert($callId !== '', 'foreign call id should be present');

    $adminParticipantCount = $pdo->prepare('SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id AND user_id = :user_id');
    $adminParticipantCount->execute([
        ':call_id' => $callId,
        ':user_id' => $systemAdminId,
    ]);
    videochat_system_admin_call_rights_assert((int) $adminParticipantCount->fetchColumn() === 0, 'system admin should not need guest-list participant row');

    videochat_system_admin_call_rights_assert(
        videochat_user_has_system_admin_call_rights($pdo, $systemAdminId, 'admin'),
        'seeded admin should be recognized as trusted system admin'
    );
    $adminFetch = videochat_get_call_for_user($pdo, $callId, $systemAdminId, 'admin', $defaultTenantId);
    videochat_system_admin_call_rights_assert((bool) ($adminFetch['ok'] ?? false), 'system admin should fetch foreign-tenant call through default tenant context');
    videochat_system_admin_call_rights_assert(
        (int) (($adminFetch['call'] ?? [])['tenant_id'] ?? 0) === $foreignTenantId,
        'system admin fetch should return the foreign tenant call'
    );
    videochat_system_admin_call_rights_assert(
        (bool) (($adminFetch['call'] ?? [])['my_participation'] ?? true) === false,
        'system admin access should not depend on call participation'
    );

    $adminUpdate = videochat_update_call($pdo, $callId, $systemAdminId, 'admin', [
        'title' => 'Foreign Organization Admin Slice Updated',
    ], $defaultTenantId);
    videochat_system_admin_call_rights_assert((bool) ($adminUpdate['ok'] ?? false), 'system admin should update foreign-tenant call through default tenant context');
    videochat_system_admin_call_rights_assert(
        (string) (($adminUpdate['call'] ?? [])['title'] ?? '') === 'Foreign Organization Admin Slice Updated',
        'system admin update should return updated title'
    );

    $adminRoleUpdate = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $foreignParticipantId,
        'moderator',
        $systemAdminId,
        'admin',
        $defaultTenantId
    );
    videochat_system_admin_call_rights_assert((bool) ($adminRoleUpdate['ok'] ?? false), 'system admin should manage foreign-tenant call participants');
    $participantRole = $pdo->prepare('SELECT call_role FROM call_participants WHERE call_id = :call_id AND user_id = :user_id LIMIT 1');
    $participantRole->execute([
        ':call_id' => $callId,
        ':user_id' => $foreignParticipantId,
    ]);
    videochat_system_admin_call_rights_assert((string) $participantRole->fetchColumn() === 'moderator', 'system admin participant role update should persist');

    $adminParticipantUpdate = videochat_update_call($pdo, $callId, $systemAdminId, 'admin', [
        'internal_participant_user_ids' => [$foreignParticipantId, $foreignSecondParticipantId],
    ], $defaultTenantId);
    videochat_system_admin_call_rights_assert((bool) ($adminParticipantUpdate['ok'] ?? false), 'system admin should update foreign-tenant participant list through default tenant context');
    videochat_system_admin_call_rights_assert(
        (int) ((($adminParticipantUpdate['call'] ?? [])['participants']['totals'] ?? [])['internal'] ?? 0) === 3,
        'system admin participant-list update should keep owner plus two foreign participants'
    );

    $adminOwnerTransfer = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $foreignSecondParticipantId,
        'owner',
        $systemAdminId,
        'admin',
        $defaultTenantId
    );
    videochat_system_admin_call_rights_assert((bool) ($adminOwnerTransfer['ok'] ?? false), 'system admin should transfer owner on foreign-tenant call');
    $transferredOwnerUserId = (int) $pdo->query(
        'SELECT owner_user_id FROM calls WHERE id = ' . $pdo->quote($callId) . ' LIMIT 1'
    )->fetchColumn();
    videochat_system_admin_call_rights_assert($transferredOwnerUserId === $foreignSecondParticipantId, 'system admin owner transfer should persist');
    $adminAfterOwnerTransfer = videochat_get_call_for_user($pdo, $callId, $systemAdminId, 'admin', $defaultTenantId);
    videochat_system_admin_call_rights_assert((bool) ($adminAfterOwnerTransfer['ok'] ?? false), 'system admin rights should remain after owner transfer');

    $forgedRegularFetch = videochat_get_call_for_user($pdo, $callId, $regularUserId, 'admin');
    videochat_system_admin_call_rights_assert(!(bool) ($forgedRegularFetch['ok'] ?? true), 'regular user must not simulate system admin through role string');
    videochat_system_admin_call_rights_assert(
        !videochat_user_has_system_admin_call_rights($pdo, $regularUserId, 'admin'),
        'regular user with forged role string should not have system-admin call rights'
    );

    $temporaryAdminId = videochat_system_admin_call_rights_create_user(
        $pdo,
        'guest+systemadmincallrights@videochat.local',
        'Temporary Admin-Shaped Guest',
        $adminRoleId,
        null
    );
    videochat_tenant_attach_user($pdo, $temporaryAdminId, $defaultTenantId, 'member');
    videochat_system_admin_call_rights_assert(
        !videochat_user_has_system_admin_call_rights($pdo, $temporaryAdminId, 'admin'),
        'temporary account must not receive system-admin call rights even with admin role data'
    );

    $temporaryFetch = videochat_get_call_for_user($pdo, $callId, $temporaryAdminId, 'admin');
    videochat_system_admin_call_rights_assert(!(bool) ($temporaryFetch['ok'] ?? true), 'temporary account must not join foreign call as system admin');
    videochat_system_admin_call_rights_assert(
        (string) ($temporaryFetch['reason'] ?? '') === 'forbidden',
        'temporary account foreign-call denial reason should be forbidden when tenant scope is absent'
    );
    $temporaryRoleUpdate = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $foreignParticipantId,
        'participant',
        $temporaryAdminId,
        'admin'
    );
    videochat_system_admin_call_rights_assert(!(bool) ($temporaryRoleUpdate['ok'] ?? true), 'temporary account must not manage foreign call as system admin');
    videochat_system_admin_call_rights_assert((string) ($temporaryRoleUpdate['reason'] ?? '') === 'forbidden', 'temporary account manage denial reason mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[system-admin-call-rights-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[system-admin-call-rights-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
