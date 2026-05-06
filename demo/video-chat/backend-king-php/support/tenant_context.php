<?php

declare(strict_types=1);

require_once __DIR__ . '/tenant_migrations.php';

function videochat_tenant_normalize_role(string $role): string
{
    $normalized = strtolower(trim($role));
    return in_array($normalized, ['owner', 'admin', 'member'], true) ? $normalized : 'member';
}

function videochat_tenant_permissions_for_membership(string $tenantRole, string $globalRole, string $permissionsJson): array
{
    $decoded = json_decode($permissionsJson !== '' ? $permissionsJson : '{}', true);
    $explicit = is_array($decoded) ? $decoded : [];
    $normalizedTenantRole = videochat_tenant_normalize_role($tenantRole);
    $isTenantAdmin = in_array($normalizedTenantRole, ['owner', 'admin'], true);
    $isPlatformAdmin = strtolower(trim($globalRole)) === 'admin';

    return [
        ...$explicit,
        'platform_admin' => (bool) (($explicit['platform_admin'] ?? false) || $isPlatformAdmin),
        'tenant_admin' => (bool) (($explicit['tenant_admin'] ?? false) || $isTenantAdmin || $isPlatformAdmin),
        'manage_users' => (bool) (($explicit['manage_users'] ?? false) || $isTenantAdmin || $isPlatformAdmin),
        'manage_organizations' => (bool) (($explicit['manage_organizations'] ?? false) || $isTenantAdmin || $isPlatformAdmin),
        'manage_groups' => (bool) (($explicit['manage_groups'] ?? false) || $isTenantAdmin || $isPlatformAdmin),
        'manage_permission_grants' => (bool) (($explicit['manage_permission_grants'] ?? false) || $isTenantAdmin || $isPlatformAdmin),
        'edit_themes' => (bool) (($explicit['edit_themes'] ?? false) || $isTenantAdmin || $isPlatformAdmin),
        'export_import' => (bool) (($explicit['export_import'] ?? false) || $isTenantAdmin || $isPlatformAdmin),
    ];
}

function videochat_tenant_context_from_membership_row(array $row): array
{
    $globalRole = is_string($row['global_role'] ?? null) ? (string) $row['global_role'] : 'user';
    $membershipRole = videochat_tenant_normalize_role((string) ($row['membership_role'] ?? 'member'));
    $permissions = videochat_tenant_permissions_for_membership(
        $membershipRole,
        $globalRole,
        is_string($row['permissions_json'] ?? null) ? (string) $row['permissions_json'] : '{}'
    );

    return [
        'id' => (int) ($row['tenant_id'] ?? 0),
        'tenant_id' => (int) ($row['tenant_id'] ?? 0),
        'uuid' => (string) ($row['public_id'] ?? ''),
        'public_id' => (string) ($row['public_id'] ?? ''),
        'slug' => (string) ($row['slug'] ?? ''),
        'label' => (string) ($row['label'] ?? ''),
        'role' => $membershipRole,
        'membership_id' => (int) ($row['membership_id'] ?? 0),
        'permissions' => $permissions,
    ];
}

function videochat_tenant_context_for_user(PDO $pdo, int $userId, ?int $preferredTenantId = null): ?array
{
    if ($userId <= 0) {
        return null;
    }
    if (videochat_tenant_table_has_column($pdo, 'tenant_memberships', 'tenant_id') === false) {
        return null;
    }

    videochat_tenant_backfill_default_memberships($pdo);
    $tenantPredicate = '';
    $params = [':user_id' => $userId];
    if (is_int($preferredTenantId) && $preferredTenantId > 0) {
        $tenantPredicate = 'AND tenants.id = :tenant_id';
        $params[':tenant_id'] = $preferredTenantId;
    }

    $query = $pdo->prepare(
        <<<SQL
SELECT
    tenants.id AS tenant_id,
    tenants.public_id,
    tenants.slug,
    tenants.label,
    tenant_memberships.id AS membership_id,
    tenant_memberships.membership_role,
    tenant_memberships.permissions_json,
    roles.slug AS global_role
FROM tenant_memberships
INNER JOIN tenants ON tenants.id = tenant_memberships.tenant_id
INNER JOIN users ON users.id = tenant_memberships.user_id
INNER JOIN roles ON roles.id = users.role_id
WHERE tenant_memberships.user_id = :user_id
  AND tenant_memberships.status = 'active'
  AND tenants.status = 'active'
  {$tenantPredicate}
ORDER BY
  CASE WHEN tenant_memberships.default_membership = 1 THEN 0 ELSE 1 END ASC,
  tenants.id ASC
LIMIT 1
SQL
    );
    $query->execute($params);
    $row = $query->fetch();
    if (!is_array($row)) {
        return null;
    }

    return videochat_tenant_context_from_membership_row($row);
}

function videochat_tenant_context_for_public_id(PDO $pdo, int $userId, string $tenantPublicId): ?array
{
    $trimmed = strtolower(trim($tenantPublicId));
    if ($userId <= 0 || $trimmed === '') {
        return null;
    }

    $query = $pdo->prepare('SELECT id FROM tenants WHERE lower(public_id) = lower(:public_id) AND status = \'active\' LIMIT 1');
    $query->execute([':public_id' => $trimmed]);
    $tenantId = (int) $query->fetchColumn();
    if ($tenantId <= 0) {
        return null;
    }

    return videochat_tenant_context_for_user($pdo, $userId, $tenantId);
}

function videochat_tenant_auth_payload(?array $tenant): ?array
{
    if (!is_array($tenant) || (int) ($tenant['id'] ?? 0) <= 0) {
        return null;
    }

    return [
        'id' => (int) $tenant['id'],
        'tenant_id' => (int) $tenant['id'],
        'uuid' => (string) ($tenant['uuid'] ?? $tenant['public_id'] ?? ''),
        'public_id' => (string) ($tenant['public_id'] ?? $tenant['uuid'] ?? ''),
        'slug' => (string) ($tenant['slug'] ?? ''),
        'label' => (string) ($tenant['label'] ?? ''),
        'role' => videochat_tenant_normalize_role((string) ($tenant['role'] ?? 'member')),
        'permissions' => is_array($tenant['permissions'] ?? null) ? $tenant['permissions'] : [],
    ];
}

function videochat_tenant_id_from_auth_context(array $authContext): int
{
    $tenant = is_array($authContext['tenant'] ?? null) ? $authContext['tenant'] : [];
    $tenantId = (int) ($tenant['id'] ?? ($tenant['tenant_id'] ?? 0));
    return $tenantId > 0 ? $tenantId : 0;
}

function videochat_tenant_user_is_member(PDO $pdo, int $userId, int $tenantId): bool
{
    if ($userId <= 0 || $tenantId <= 0 || videochat_tenant_table_has_column($pdo, 'tenant_memberships', 'tenant_id') === false) {
        return false;
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT 1
FROM tenant_memberships
WHERE user_id = :user_id
  AND tenant_id = :tenant_id
  AND status = 'active'
LIMIT 1
SQL
    );
    $query->execute([
        ':user_id' => $userId,
        ':tenant_id' => $tenantId,
    ]);

    return $query->fetchColumn() !== false;
}

function videochat_tenant_default_id(PDO $pdo): int
{
    if (videochat_tenant_table_has_column($pdo, 'tenants', 'id') === false) {
        return 0;
    }
    $query = $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1");
    return $query === false ? 0 : (int) $query->fetchColumn();
}

function videochat_tenant_attach_user(PDO $pdo, int $userId, int $tenantId, string $role = 'member'): void
{
    if ($userId <= 0 || $tenantId <= 0 || videochat_tenant_table_has_column($pdo, 'tenant_memberships', 'tenant_id') === false) {
        return;
    }
    if (videochat_tenant_user_is_member($pdo, $userId, $tenantId)) {
        return;
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenant_memberships(tenant_id, user_id, membership_role, permissions_json, status, default_membership, created_at, updated_at)
VALUES(:tenant_id, :user_id, :membership_role, '{}', 'active', 0, :created_at, :updated_at)
SQL
    );
    $now = gmdate('c');
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':membership_role' => videochat_tenant_normalize_role($role),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function videochat_tenant_update_session(PDO $pdo, string $sessionId, int $tenantId): bool
{
    $trimmedSessionId = trim($sessionId);
    if ($trimmedSessionId === '' || $tenantId <= 0 || !videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id')) {
        return false;
    }

    $update = $pdo->prepare('UPDATE sessions SET active_tenant_id = :tenant_id WHERE id = :session_id');
    $update->execute([
        ':tenant_id' => $tenantId,
        ':session_id' => $trimmedSessionId,
    ]);

    return $update->rowCount() === 1;
}
