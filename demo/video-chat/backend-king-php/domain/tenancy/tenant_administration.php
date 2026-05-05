<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/tenant_context.php';

function videochat_tenancy_require_admin(array $authContext): array
{
    $tenant = is_array($authContext['tenant'] ?? null) ? $authContext['tenant'] : [];
    $permissions = is_array($tenant['permissions'] ?? null) ? $tenant['permissions'] : [];
    $role = strtolower(trim((string) ($tenant['role'] ?? 'member')));
    $allowed = in_array($role, ['owner', 'admin'], true)
        || (bool) ($permissions['tenant_admin'] ?? false)
        || (bool) ($permissions['platform_admin'] ?? false);

    return [
        'ok' => $allowed,
        'tenant_id' => (int) ($tenant['id'] ?? 0),
        'reason' => $allowed ? 'ok' : 'tenant_admin_required',
    ];
}

function videochat_tenancy_list_user_tenants(PDO $pdo, int $userId): array
{
    if ($userId <= 0) {
        return [];
    }

    $query = $pdo->prepare(
        <<<'SQL'
SELECT tenants.id, tenants.public_id, tenants.slug, tenants.label, tenant_memberships.membership_role, tenant_memberships.permissions_json
FROM tenant_memberships
INNER JOIN tenants ON tenants.id = tenant_memberships.tenant_id
WHERE tenant_memberships.user_id = :user_id
  AND tenant_memberships.status = 'active'
  AND tenants.status = 'active'
ORDER BY tenant_memberships.default_membership DESC, lower(tenants.label) ASC, tenants.id ASC
SQL
    );
    $query->execute([':user_id' => $userId]);

    $rows = [];
    foreach ($query as $row) {
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'tenant_id' => (int) ($row['id'] ?? 0),
            'uuid' => (string) ($row['public_id'] ?? ''),
            'public_id' => (string) ($row['public_id'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'role' => videochat_tenant_normalize_role((string) ($row['membership_role'] ?? 'member')),
        ];
    }

    return $rows;
}

function videochat_tenancy_list_organizations(PDO $pdo, int $tenantId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT id, parent_organization_id, public_id, name, status, created_at, updated_at
FROM organizations
WHERE tenant_id = :tenant_id
ORDER BY parent_organization_id IS NOT NULL ASC, lower(name) ASC, id ASC
SQL
    );
    $query->execute([':tenant_id' => $tenantId]);

    $rows = [];
    foreach ($query as $row) {
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'parent_organization_id' => isset($row['parent_organization_id']) ? (int) $row['parent_organization_id'] : null,
            'public_id' => (string) ($row['public_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $rows;
}

function videochat_tenancy_list_groups(PDO $pdo, int $tenantId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT id, organization_id, public_id, name, status, created_at, updated_at
FROM "groups"
WHERE tenant_id = :tenant_id
ORDER BY lower(name) ASC, id ASC
SQL
    );
    $query->execute([':tenant_id' => $tenantId]);

    $rows = [];
    foreach ($query as $row) {
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'organization_id' => isset($row['organization_id']) ? (int) $row['organization_id'] : null,
            'public_id' => (string) ($row['public_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $rows;
}
