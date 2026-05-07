<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/tenancy/permission_grants.php';

function tenant_grant_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[tenant-permission-grants-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-tenant-grants-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $userId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $groupId = (int) $pdo->query('SELECT id FROM "groups" WHERE tenant_id = ' . $tenantId . ' LIMIT 1')->fetchColumn();
    $organizationId = (int) $pdo->query('SELECT id FROM organizations WHERE tenant_id = ' . $tenantId . ' LIMIT 1')->fetchColumn();
    tenant_grant_assert($tenantId > 0 && $userId > 0 && $groupId > 0 && $organizationId > 0, 'default fixture ids missing');

    $insertGrant = $pdo->prepare(
        <<<'SQL'
INSERT INTO permission_grants(
    tenant_id, resource_type, resource_id, action, subject_type, user_id, group_id, organization_id,
    valid_from, valid_until, revoked_at, created_by_user_id
) VALUES(
    :tenant_id, :resource_type, :resource_id, :action, :subject_type, :user_id, :group_id, :organization_id,
    :valid_from, :valid_until, :revoked_at, :created_by_user_id
)
SQL
    );

    $insertGrant->execute([
        ':tenant_id' => $tenantId,
        ':resource_type' => 'calendar',
        ':resource_id' => 'calendar-default',
        ':action' => 'read',
        ':subject_type' => 'group',
        ':user_id' => null,
        ':group_id' => $groupId,
        ':organization_id' => null,
        ':valid_from' => gmdate('c', time() - 60),
        ':valid_until' => gmdate('c', time() + 3600),
        ':revoked_at' => null,
        ':created_by_user_id' => $userId,
    ]);
    $validGrant = videochat_tenancy_user_has_resource_permission($pdo, $tenantId, $userId, 'calendar', 'calendar-default', 'read');
    tenant_grant_assert((bool) ($validGrant['ok'] ?? false), 'active group grant should allow calendar read');

    $insertGrant->execute([
        ':tenant_id' => $tenantId,
        ':resource_type' => 'calendar',
        ':resource_id' => 'calendar-expired',
        ':action' => 'read',
        ':subject_type' => 'organization',
        ':user_id' => null,
        ':group_id' => null,
        ':organization_id' => $organizationId,
        ':valid_from' => gmdate('c', time() - 3600),
        ':valid_until' => gmdate('c', time() - 60),
        ':revoked_at' => null,
        ':created_by_user_id' => $userId,
    ]);
    $expiredGrant = videochat_tenancy_user_has_resource_permission($pdo, $tenantId, $userId, 'calendar', 'calendar-expired', 'read');
    tenant_grant_assert((bool) ($expiredGrant['ok'] ?? true) === false, 'expired organization grant must fail closed');

    $insertGrant->execute([
        ':tenant_id' => $tenantId,
        ':resource_type' => 'calendar',
        ':resource_id' => 'calendar-revoked',
        ':action' => 'read',
        ':subject_type' => 'user',
        ':user_id' => $userId,
        ':group_id' => null,
        ':organization_id' => null,
        ':valid_from' => null,
        ':valid_until' => gmdate('c', time() + 3600),
        ':revoked_at' => gmdate('c', time() - 1),
        ':created_by_user_id' => $userId,
    ]);
    $revokedGrant = videochat_tenancy_user_has_resource_permission($pdo, $tenantId, $userId, 'calendar', 'calendar-revoked', 'read');
    tenant_grant_assert((bool) ($revokedGrant['ok'] ?? true) === false, 'revoked direct grant must fail closed');

    $pdo->exec("INSERT INTO tenants(public_id, slug, label, status) VALUES('00000000-0000-4000-8000-000000000002', 'other', 'Other Workspace', 'active')");
    $otherTenantId = (int) $pdo->lastInsertId();
    $wrongTenantGrant = videochat_tenancy_user_has_resource_permission($pdo, $otherTenantId, $userId, 'calendar', 'calendar-default', 'read');
    tenant_grant_assert((bool) ($wrongTenantGrant['ok'] ?? true) === false, 'wrong tenant must not inherit default grant');

    @unlink($databasePath);
    fwrite(STDOUT, "[tenant-permission-grants-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[tenant-permission-grants-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
