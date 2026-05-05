<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';

function tenant_migration_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[tenant-migration-foundation-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-tenant-migration-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE public_id = '00000000-0000-4000-8000-000000000001' AND slug = 'default' LIMIT 1")->fetchColumn();
    tenant_migration_assert($tenantId > 0, 'default tenant must be created');

    $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $membershipCount = (int) $pdo->query('SELECT COUNT(*) FROM tenant_memberships WHERE tenant_id = ' . $tenantId)->fetchColumn();
    tenant_migration_assert($userCount > 0, 'bootstrap must seed users');
    tenant_migration_assert($membershipCount === $userCount, 'all users must be default-tenant members after bootstrap');

    $rootOrgId = (int) $pdo->query('SELECT id FROM organizations WHERE tenant_id = ' . $tenantId . ' AND parent_organization_id IS NULL LIMIT 1')->fetchColumn();
    $defaultGroupId = (int) $pdo->query('SELECT id FROM "groups" WHERE tenant_id = ' . $tenantId . ' LIMIT 1')->fetchColumn();
    tenant_migration_assert($rootOrgId > 0, 'default root organization must exist');
    tenant_migration_assert($defaultGroupId > 0, 'default group must exist');

    foreach (['rooms', 'calls', 'invite_codes', 'appointment_blocks', 'appointment_calendar_settings', 'workspace_theme_presets', 'website_leads'] as $tableName) {
        tenant_migration_assert(videochat_tenant_table_has_column($pdo, $tableName, 'tenant_id'), "{$tableName} must have tenant_id");
    }
    tenant_migration_assert(videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id'), 'sessions must have active_tenant_id');

    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    tenant_migration_assert($adminUserId > 0, 'seeded admin user missing');
    $pdo->prepare(
        'INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent) VALUES(:id, :user_id, :issued_at, :expires_at, NULL, NULL, NULL)'
    )->execute([
        ':id' => 'sess_tenant_migration_default',
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', time() - 10),
        ':expires_at' => gmdate('c', time() + 3600),
    ]);

    $auth = videochat_validate_session_token($pdo, 'sess_tenant_migration_default');
    tenant_migration_assert((bool) ($auth['ok'] ?? false), 'legacy session without active_tenant_id should resolve default tenant');
    tenant_migration_assert((int) (($auth['tenant'] ?? [])['id'] ?? 0) === $tenantId, 'auth tenant should be default tenant');

    @unlink($databasePath);
    fwrite(STDOUT, "[tenant-migration-foundation-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[tenant-migration-foundation-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
