<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';

function videochat_call_access_terminal_join_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-terminal-join-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_access_terminal_join_create_user(PDO $pdo, int $roleId, string $email, string $displayName): int
{
    $passwordHash = password_hash('terminal-join-contract', PASSWORD_DEFAULT);
    videochat_call_access_terminal_join_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash should be generated');

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower(trim($email)),
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_call_access_terminal_join_assert($userId > 0, 'created user id should be positive');
    return $userId;
}

function videochat_call_access_terminal_join_create_call(PDO $pdo, int $ownerUserId, int $tenantId, string $title, string $status): string
{
    $startsAt = $status === 'scheduled' ? gmdate('c', time() + 3600) : gmdate('c', time() - 300);
    $endsAt = $status === 'scheduled' ? gmdate('c', time() + 7200) : gmdate('c', time() + 3600);
    $created = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_access_terminal_join_assert((bool) ($created['ok'] ?? false), "{$title} should be created");

    $call = (array) ($created['call'] ?? []);
    $callId = (string) ($call['id'] ?? '');
    videochat_call_access_terminal_join_assert($callId !== '', "{$title} id should be present");
    videochat_call_access_terminal_join_assert((string) ($call['status'] ?? '') === $status, "{$title} status should be {$status}");
    return $callId;
}

function videochat_call_access_terminal_join_set_status(PDO $pdo, string $callId, string $status): void
{
    $update = $pdo->prepare('UPDATE calls SET status = :status, updated_at = :updated_at WHERE id = :id');
    $update->execute([
        ':id' => $callId,
        ':status' => $status,
        ':updated_at' => gmdate('c'),
    ]);
    videochat_call_access_terminal_join_assert($update->rowCount() === 1, "call status should update to {$status}");
}

function videochat_call_access_terminal_join_assert_allowed(array $decision, string $source, string $label): void
{
    videochat_call_access_terminal_join_assert((bool) ($decision['allowed'] ?? false), "{$label} should be allowed");
    videochat_call_access_terminal_join_assert((string) ($decision['source'] ?? '') === $source, "{$label} source mismatch");
    videochat_call_access_terminal_join_assert((bool) ($decision['can_administer'] ?? false), "{$label} should be able to administer");
}

function videochat_call_access_terminal_join_assert_denied(array $decision, string $reason, string $label): void
{
    videochat_call_access_terminal_join_assert(!(bool) ($decision['allowed'] ?? true), "{$label} should be denied");
    videochat_call_access_terminal_join_assert((string) ($decision['reason'] ?? '') === $reason, "{$label} denial reason mismatch");
    videochat_call_access_terminal_join_assert(!(bool) ($decision['can_administer'] ?? true), "{$label} must not administer");
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-terminal-join-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-terminal-join-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $userRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    $systemAdminId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_access_terminal_join_assert($tenantId > 0 && $userRoleId > 0 && $systemAdminId > 0, 'seed ids should exist');

    $unique = bin2hex(random_bytes(5));
    $ownerUserId = videochat_call_access_terminal_join_create_user($pdo, $userRoleId, 'terminal-owner-' . $unique . '@example.test', 'Terminal Owner');
    $orgAdminUserId = videochat_call_access_terminal_join_create_user($pdo, $userRoleId, 'terminal-org-admin-' . $unique . '@example.test', 'Terminal Org Admin');
    videochat_tenant_attach_user($pdo, $ownerUserId, $tenantId, 'member');
    videochat_tenant_attach_user($pdo, $orgAdminUserId, $tenantId, 'member');

    $organizationInsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organizations(tenant_id, public_id, name, status, created_at, updated_at)
VALUES(:tenant_id, :public_id, :name, 'active', :created_at, :updated_at)
SQL
    );
    $organizationInsert->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => 'terminal-org-' . $unique,
        ':name' => 'Terminal Join Organization',
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
    $organizationId = (int) $pdo->lastInsertId();
    videochat_call_access_terminal_join_assert($organizationId > 0, 'organization should be created');

    $membershipInsert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, :membership_role, 'active', :updated_at)
SQL
    );
    foreach ([[$ownerUserId, 'member'], [$orgAdminUserId, 'admin']] as [$userId, $membershipRole]) {
        $membershipInsert->execute([
            ':tenant_id' => $tenantId,
            ':organization_id' => $organizationId,
            ':user_id' => $userId,
            ':membership_role' => $membershipRole,
            ':updated_at' => gmdate('c'),
        ]);
    }

    foreach (['scheduled', 'active'] as $status) {
        $callId = videochat_call_access_terminal_join_create_call($pdo, $ownerUserId, $tenantId, "Terminal Join {$status}", $status);
        videochat_call_access_terminal_join_assert_allowed(
            videochat_decide_call_access_for_user($pdo, $callId, $systemAdminId, 'admin', $tenantId),
            'system_admin',
            "system admin {$status} join"
        );
        videochat_call_access_terminal_join_assert_allowed(
            videochat_decide_call_access_for_user($pdo, $callId, $orgAdminUserId, 'user', $tenantId),
            'organization_admin',
            "organization admin {$status} join"
        );
    }

    $endedCallId = videochat_call_access_terminal_join_create_call($pdo, $ownerUserId, $tenantId, 'Terminal Join Ended', 'active');
    videochat_call_access_terminal_join_set_status($pdo, $endedCallId, 'ended');
    videochat_call_access_terminal_join_assert_denied(
        videochat_decide_call_access_for_user($pdo, $endedCallId, $systemAdminId, 'admin', $tenantId),
        'call_not_joinable_from_status',
        'system admin ended join'
    );
    videochat_call_access_terminal_join_assert_denied(
        videochat_decide_call_access_for_user($pdo, $endedCallId, $orgAdminUserId, 'user', $tenantId),
        'call_not_joinable_from_status',
        'organization admin ended join'
    );

    $deletedCallId = videochat_call_access_terminal_join_create_call($pdo, $ownerUserId, $tenantId, 'Terminal Join Deleted', 'active');
    $delete = videochat_delete_call($pdo, $deletedCallId, $systemAdminId, 'admin', $tenantId);
    videochat_call_access_terminal_join_assert((bool) ($delete['ok'] ?? false), 'delete should succeed');
    videochat_call_access_terminal_join_assert_denied(
        videochat_decide_call_access_for_user($pdo, $deletedCallId, $systemAdminId, 'admin', $tenantId),
        'not_found',
        'system admin deleted join'
    );
    videochat_call_access_terminal_join_assert_denied(
        videochat_decide_call_access_for_user($pdo, $deletedCallId, $orgAdminUserId, 'user', $tenantId),
        'not_found',
        'organization admin deleted join'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-terminal-join-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-terminal-join-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
