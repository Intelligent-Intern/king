<?php

declare(strict_types=1);

require_once __DIR__ . '/permission_grants.php';
require_once __DIR__ . '/../users/user_management_contract.php';

function videochat_tenancy_governance_user_summary_row(array $row): array
{
    $userId = (int) ($row['id'] ?? ($row['user_id'] ?? 0));
    $email = trim((string) ($row['email'] ?? ''));
    $displayName = trim((string) ($row['display_name'] ?? ($row['name'] ?? '')));
    $name = $displayName !== '' ? $displayName : ($email !== '' ? $email : (string) $userId);

    return [
        'entity_key' => 'users',
        'id' => (string) $userId,
        'key' => $email !== '' ? $email : (string) $userId,
        'name' => $name,
        'display_name' => $displayName,
        'email' => $email,
        'role' => (string) ($row['role'] ?? ($row['role_slug'] ?? 'user')),
        'status' => (string) ($row['status'] ?? 'active'),
        'updatedAt' => (string) ($row['updatedAt'] ?? ($row['updated_at'] ?? '')),
    ];
}

function videochat_tenancy_governance_user_summary_rows(array $rows): array
{
    $summaries = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $summary = videochat_tenancy_governance_user_summary_row($row);
        if ((int) ($summary['id'] ?? 0) > 0) {
            $summaries[] = $summary;
        }
    }

    return $summaries;
}

function videochat_tenancy_governance_user_summary_permission_decision(PDO $pdo, array $authContext): array
{
    $tenant = is_array($authContext['tenant'] ?? null) ? $authContext['tenant'] : [];
    $permissions = is_array($tenant['permissions'] ?? null) ? $tenant['permissions'] : [];
    $tenantId = (int) ($tenant['id'] ?? ($tenant['tenant_id'] ?? 0));
    $userId = (int) (($authContext['user']['id'] ?? 0));
    if ($tenantId <= 0 || $userId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_context'];
    }

    foreach ([
        'platform_admin',
        'tenant_admin',
        'manage_users',
        'manage_groups',
        'governance.read',
        'governance.groups.read',
        'governance.groups.create',
        'governance.groups.update',
    ] as $permissionKey) {
        if ((bool) ($permissions[$permissionKey] ?? false)) {
            return ['ok' => true, 'reason' => 'tenant_permission_alias'];
        }
    }

    foreach (['read', 'create', 'update', 'manage'] as $action) {
        $grant = videochat_tenancy_user_has_resource_permission($pdo, $tenantId, $userId, 'group', '*', $action);
        if ((bool) ($grant['ok'] ?? false)) {
            return ['ok' => true, 'reason' => 'resource_grant', 'grant' => $grant['grant'] ?? null];
        }
    }

    return ['ok' => false, 'reason' => 'not_granted'];
}

function videochat_tenancy_governance_payload_has_members(array $payload): bool
{
    if (array_key_exists('members', $payload)) {
        return true;
    }
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];

    return array_key_exists('members', $relationships);
}

function videochat_tenancy_governance_member_id_result(array $payload): array
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    $rawMembers = array_key_exists('members', $relationships) ? $relationships['members'] : ($payload['members'] ?? []);
    if (!is_array($rawMembers)) {
        return ['ok' => false, 'ids' => []];
    }

    $ids = [];
    $invalid = false;
    foreach ($rawMembers as $member) {
        $raw = '';
        if (is_scalar($member)) {
            $raw = trim((string) $member);
        } elseif (is_array($member)) {
            foreach (['id', 'user_id', 'database_id', 'value'] as $key) {
                if (array_key_exists($key, $member)) {
                    $raw = trim((string) $member[$key]);
                    break;
                }
            }
        }

        if ($raw === '' || !ctype_digit($raw) || (int) $raw <= 0) {
            $invalid = true;
            continue;
        }
        $ids[(int) $raw] = true;
    }

    return [
        'ok' => !$invalid,
        'ids' => array_keys($ids),
    ];
}

function videochat_tenancy_governance_validate_group_members(PDO $pdo, int $tenantId, array $payload): array
{
    if (!videochat_tenancy_governance_payload_has_members($payload)) {
        return ['ok' => true, 'ids' => []];
    }

    $memberIds = videochat_tenancy_governance_member_id_result($payload);
    if (!(bool) ($memberIds['ok'] ?? false)) {
        return ['ok' => false, 'errors' => ['members' => 'invalid_user_reference']];
    }
    $ids = array_values(array_filter((array) ($memberIds['ids'] ?? []), static fn ($id): bool => (int) $id > 0));
    if ($ids === []) {
        return ['ok' => true, 'ids' => []];
    }

    $placeholders = [];
    $params = [':tenant_id' => $tenantId];
    foreach ($ids as $index => $id) {
        $name = ':user_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = (int) $id;
    }
    $query = $pdo->prepare(
        sprintf(
            <<<'SQL'
SELECT users.id
FROM users
INNER JOIN tenant_memberships ON tenant_memberships.user_id = users.id
WHERE tenant_memberships.tenant_id = :tenant_id
  AND tenant_memberships.status = 'active'
  AND users.id IN (%s)
SQL,
            implode(', ', $placeholders)
        )
    );
    $query->execute($params);
    $validIds = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN) ?: []);
    sort($validIds);
    $expectedIds = $ids;
    sort($expectedIds);
    if ($validIds !== $expectedIds) {
        return ['ok' => false, 'errors' => ['members' => 'not_found_or_not_in_tenant']];
    }

    return ['ok' => true, 'ids' => $ids];
}

function videochat_tenancy_governance_sync_group_members(PDO $pdo, int $tenantId, int $groupId, array $payload): array
{
    if (!videochat_tenancy_governance_payload_has_members($payload)) {
        return ['ok' => true];
    }

    $validation = videochat_tenancy_governance_validate_group_members($pdo, $tenantId, $payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return $validation;
    }

    $now = gmdate('c');
    $disable = $pdo->prepare(
        <<<'SQL'
UPDATE group_memberships
SET status = 'disabled',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND group_id = :group_id
  AND subject_type = 'user'
SQL
    );
    $disable->execute([
        ':updated_at' => $now,
        ':tenant_id' => $tenantId,
        ':group_id' => $groupId,
    ]);

    $activate = $pdo->prepare(
        <<<'SQL'
UPDATE group_memberships
SET status = 'active',
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND group_id = :group_id
  AND subject_type = 'user'
  AND user_id = :user_id
SQL
    );
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO group_memberships(tenant_id, group_id, subject_type, user_id, organization_id, status, created_at, updated_at)
VALUES(:tenant_id, :group_id, 'user', :user_id, NULL, 'active', :created_at, :updated_at)
SQL
    );
    foreach ((array) ($validation['ids'] ?? []) as $userId) {
        $activate->execute([
            ':updated_at' => $now,
            ':tenant_id' => $tenantId,
            ':group_id' => $groupId,
            ':user_id' => (int) $userId,
        ]);
        if ($activate->rowCount() > 0) {
            continue;
        }
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':group_id' => $groupId,
            ':user_id' => (int) $userId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    return ['ok' => true];
}

function videochat_tenancy_governance_group_member_map(PDO $pdo, int $tenantId, array $groupIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn (int $id): bool => $id > 0)));
    if ($tenantId <= 0 || $ids === []) {
        return [];
    }

    $placeholders = [];
    $params = [':tenant_id' => $tenantId];
    foreach ($ids as $index => $id) {
        $name = ':group_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(
        sprintf(
            <<<'SQL'
SELECT
    group_memberships.group_id,
    users.id,
    users.email,
    users.display_name,
    users.status,
    users.updated_at,
    roles.slug AS role
FROM group_memberships
INNER JOIN users ON users.id = group_memberships.user_id
INNER JOIN roles ON roles.id = users.role_id
WHERE group_memberships.tenant_id = :tenant_id
  AND group_memberships.group_id IN (%s)
  AND group_memberships.subject_type = 'user'
  AND group_memberships.status = 'active'
ORDER BY lower(users.display_name) ASC, users.id ASC
SQL,
            implode(', ', $placeholders)
        )
    );
    $query->execute($params);

    $membersByGroup = array_fill_keys($ids, []);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $groupId = (int) ($row['group_id'] ?? 0);
        if ($groupId <= 0) {
            continue;
        }
        $membersByGroup[$groupId][] = videochat_tenancy_governance_user_summary_row($row);
    }

    return $membersByGroup;
}

function videochat_tenancy_governance_organization_summary_map(PDO $pdo, int $tenantId, array $organizationIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $organizationIds), static fn (int $id): bool => $id > 0)));
    if ($tenantId <= 0 || $ids === []) {
        return [];
    }

    $placeholders = [];
    $params = [':tenant_id' => $tenantId];
    foreach ($ids as $index => $id) {
        $name = ':organization_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(
        sprintf(
            <<<'SQL'
SELECT id, public_id, name, status, updated_at
FROM organizations
WHERE tenant_id = :tenant_id
  AND id IN (%s)
ORDER BY lower(name) ASC, id ASC
SQL,
            implode(', ', $placeholders)
        )
    );
    $query->execute($params);

    $summaries = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $organizationId = (int) ($row['id'] ?? 0);
        $publicId = trim((string) ($row['public_id'] ?? ''));
        if ($organizationId <= 0 || $publicId === '') {
            continue;
        }
        $summaries[$organizationId] = [
            'entity_key' => 'organizations',
            'id' => $publicId,
            'key' => $publicId,
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return $summaries;
}

function videochat_tenancy_governance_group_payload_has_permissions(array $payload): bool
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    return array_key_exists('permissions', $relationships) || array_key_exists('permissions', $payload);
}

function videochat_tenancy_governance_group_permission_key(array $row): string
{
    foreach (['key', 'id', 'name', 'value'] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        if (str_starts_with($value, 'permission:')) {
            $parts = explode(':', $value);
            return trim((string) end($parts));
        }
        return $value;
    }

    return '';
}

function videochat_tenancy_governance_group_permission_data(array $row): array
{
    $permissionKey = videochat_tenancy_governance_group_permission_key($row);
    $parts = array_values(array_filter(explode('.', $permissionKey), static fn (string $part): bool => trim($part) !== ''));
    $action = $parts !== [] ? videochat_tenancy_normalize_grant_action((string) end($parts)) : '';
    if ($permissionKey === '' || $action === '') {
        return ['ok' => false, 'permission_key' => $permissionKey, 'errors' => ['permissions' => 'invalid_permission']];
    }
    $resourceSegment = count($parts) >= 2 ? (string) $parts[count($parts) - 2] : 'workspace';
    $resourceType = match (strtolower(trim(str_replace('-', '_', $resourceSegment)))) {
        'groups' => 'group',
        'organizations' => 'organization',
        'users' => 'user',
        'grants', 'permission_grants' => 'permission_grant',
        default => rtrim(strtolower(trim($resourceSegment)), 's'),
    };

    return [
        'ok' => true,
        'permission_key' => $permissionKey,
        'resource_type' => $resourceType !== '' ? $resourceType : 'workspace',
        'action' => $action,
    ];
}

function videochat_tenancy_governance_group_permission_values(array $payload): array
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    $permissions = array_key_exists('permissions', $relationships) ? $relationships['permissions'] : ($payload['permissions'] ?? []);
    return is_array($permissions) ? $permissions : [];
}

function videochat_tenancy_governance_validate_group_permissions(array $payload): array
{
    if (!videochat_tenancy_governance_group_payload_has_permissions($payload)) {
        return ['ok' => true, 'permissions' => []];
    }

    $permissions = [];
    foreach (videochat_tenancy_governance_group_permission_values($payload) as $row) {
        if (!is_array($row)) {
            return ['ok' => false, 'errors' => ['permissions' => 'invalid_permission']];
        }
        $data = videochat_tenancy_governance_group_permission_data($row);
        if (!(bool) ($data['ok'] ?? false)) {
            return ['ok' => false, 'errors' => is_array($data['errors'] ?? null) ? $data['errors'] : ['permissions' => 'invalid_permission']];
        }
        $permissions[(string) $data['permission_key']] = $data;
    }

    return ['ok' => true, 'permissions' => array_values($permissions)];
}

function videochat_tenancy_governance_sync_group_permissions(PDO $pdo, int $tenantId, int $groupId, int $actorUserId, array $payload): array
{
    if (!videochat_tenancy_governance_group_payload_has_permissions($payload)) {
        return ['ok' => true];
    }
    $validation = videochat_tenancy_governance_validate_group_permissions($payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return $validation;
    }

    $delete = $pdo->prepare(
        "DELETE FROM permission_grants WHERE tenant_id = :tenant_id AND subject_type = 'group' AND group_id = :group_id AND source = 'group_permissions'"
    );
    $delete->execute([':tenant_id' => $tenantId, ':group_id' => $groupId]);
    $exists = $pdo->prepare(
        <<<'SQL'
SELECT id
FROM permission_grants
WHERE tenant_id = :tenant_id
  AND subject_type = 'group'
  AND group_id = :group_id
  AND resource_type = :resource_type
  AND resource_id = '*'
  AND action = :action
  AND (revoked_at IS NULL OR revoked_at = '')
LIMIT 1
SQL
    );
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO permission_grants(
    tenant_id, public_id, resource_type, resource_id, action, subject_type, group_id,
    created_by_user_id, label, description, permission_key, source, created_at, updated_at
) VALUES(
    :tenant_id, :public_id, :resource_type, '*', :action, 'group', :group_id,
    :created_by_user_id, :label, '', :permission_key, 'group_permissions', :created_at, :updated_at
)
SQL
    );
    $now = gmdate('c');
    foreach ((array) ($validation['permissions'] ?? []) as $permission) {
        $exists->execute([
            ':tenant_id' => $tenantId,
            ':group_id' => $groupId,
            ':resource_type' => (string) $permission['resource_type'],
            ':action' => (string) $permission['action'],
        ]);
        if ($exists->fetchColumn() !== false) {
            continue;
        }
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':public_id' => videochat_tenancy_generate_public_id(),
            ':resource_type' => (string) $permission['resource_type'],
            ':action' => (string) $permission['action'],
            ':group_id' => $groupId,
            ':created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ':label' => (string) $permission['permission_key'],
            ':permission_key' => (string) $permission['permission_key'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    return ['ok' => true];
}

function videochat_tenancy_governance_group_permission_map(PDO $pdo, int $tenantId, array $groupIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn (int $id): bool => $id > 0)));
    if ($tenantId <= 0 || $ids === []) {
        return [];
    }
    $placeholders = [];
    $params = [':tenant_id' => $tenantId];
    foreach ($ids as $index => $id) {
        $name = ':group_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(sprintf(
        "SELECT group_id, permission_key, resource_type, action FROM permission_grants WHERE tenant_id = :tenant_id AND subject_type = 'group' AND group_id IN (%s) AND resource_id = '*' AND (revoked_at IS NULL OR revoked_at = '') ORDER BY permission_key ASC, id ASC",
        implode(', ', $placeholders)
    ));
    $query->execute($params);
    $permissionsByGroup = array_fill_keys($ids, []);
    $seen = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $groupId = (int) ($row['group_id'] ?? 0);
        $permissionKey = trim((string) ($row['permission_key'] ?? ''));
        if ($permissionKey === '') {
            $permissionKey = (string) ($row['resource_type'] ?? 'resource') . '.' . (string) ($row['action'] ?? 'read');
        }
        $fingerprint = $groupId . ':' . $permissionKey;
        if ($groupId <= 0 || isset($seen[$fingerprint])) {
            continue;
        }
        $seen[$fingerprint] = true;
        $permissionsByGroup[$groupId][] = [
            'entity_key' => 'permissions',
            'id' => 'permission:governance:' . $permissionKey,
            'key' => $permissionKey,
            'name' => $permissionKey,
            'status' => 'active',
        ];
    }

    return $permissionsByGroup;
}

function videochat_tenancy_governance_group_payload_has_modules(array $payload): bool
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    return array_key_exists('modules', $relationships) || array_key_exists('modules', $payload);
}

function videochat_tenancy_governance_group_module_key(array $row): string
{
    foreach (['key', 'id', 'name', 'value'] as $key) {
        $value = trim((string) ($row[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        return preg_replace('/^module:/', '', $value);
    }

    return '';
}

function videochat_tenancy_governance_validate_group_modules(array $payload): array
{
    if (!videochat_tenancy_governance_group_payload_has_modules($payload)) {
        return ['ok' => true, 'modules' => []];
    }

    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    $rawModules = array_key_exists('modules', $relationships) ? $relationships['modules'] : ($payload['modules'] ?? []);
    if (!is_array($rawModules)) {
        return ['ok' => false, 'errors' => ['modules' => 'invalid_module_reference']];
    }

    $modules = [];
    foreach ($rawModules as $row) {
        if (!is_array($row)) {
            return ['ok' => false, 'errors' => ['modules' => 'invalid_module_reference']];
        }
        $moduleKey = videochat_tenancy_governance_group_module_key($row);
        if ($moduleKey === '' || preg_match('/^[a-z0-9_.-]+$/i', $moduleKey) !== 1) {
            return ['ok' => false, 'errors' => ['modules' => 'invalid_module_reference']];
        }
        $modules[$moduleKey] = [
            'module_key' => $moduleKey,
            'permission_key' => 'module.' . $moduleKey . '.read',
        ];
    }

    return ['ok' => true, 'modules' => array_values($modules)];
}

function videochat_tenancy_governance_sync_group_modules(PDO $pdo, int $tenantId, int $groupId, int $actorUserId, array $payload): array
{
    if (!videochat_tenancy_governance_group_payload_has_modules($payload)) {
        return ['ok' => true];
    }
    $validation = videochat_tenancy_governance_validate_group_modules($payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return $validation;
    }

    $delete = $pdo->prepare(
        "DELETE FROM permission_grants WHERE tenant_id = :tenant_id AND subject_type = 'group' AND group_id = :group_id AND source = 'group_modules'"
    );
    $delete->execute([':tenant_id' => $tenantId, ':group_id' => $groupId]);
    $exists = $pdo->prepare(
        <<<'SQL'
SELECT id
FROM permission_grants
WHERE tenant_id = :tenant_id
  AND subject_type = 'group'
  AND group_id = :group_id
  AND resource_type = 'module'
  AND resource_id = :resource_id
  AND action = 'read'
  AND (revoked_at IS NULL OR revoked_at = '')
LIMIT 1
SQL
    );
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO permission_grants(
    tenant_id, public_id, resource_type, resource_id, action, subject_type, group_id,
    created_by_user_id, label, description, permission_key, source, created_at, updated_at
) VALUES(
    :tenant_id, :public_id, 'module', :resource_id, 'read', 'group', :group_id,
    :created_by_user_id, :label, '', :permission_key, 'group_modules', :created_at, :updated_at
)
SQL
    );
    $now = gmdate('c');
    foreach ((array) ($validation['modules'] ?? []) as $module) {
        $exists->execute([
            ':tenant_id' => $tenantId,
            ':group_id' => $groupId,
            ':resource_id' => (string) $module['module_key'],
        ]);
        if ($exists->fetchColumn() !== false) {
            continue;
        }
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':public_id' => videochat_tenancy_generate_public_id(),
            ':resource_id' => (string) $module['module_key'],
            ':group_id' => $groupId,
            ':created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ':label' => (string) $module['module_key'],
            ':permission_key' => (string) $module['permission_key'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    return ['ok' => true];
}

function videochat_tenancy_governance_group_module_map(PDO $pdo, int $tenantId, array $groupIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn (int $id): bool => $id > 0)));
    if ($tenantId <= 0 || $ids === []) {
        return [];
    }
    $placeholders = [];
    $params = [':tenant_id' => $tenantId];
    foreach ($ids as $index => $id) {
        $name = ':group_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(sprintf(
        "SELECT group_id, resource_id FROM permission_grants WHERE tenant_id = :tenant_id AND subject_type = 'group' AND group_id IN (%s) AND resource_type = 'module' AND action = 'read' AND source = 'group_modules' AND (revoked_at IS NULL OR revoked_at = '') ORDER BY resource_id ASC, id ASC",
        implode(', ', $placeholders)
    ));
    $query->execute($params);
    $modulesByGroup = array_fill_keys($ids, []);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $groupId = (int) ($row['group_id'] ?? 0);
        $moduleKey = trim((string) ($row['resource_id'] ?? ''));
        if ($groupId <= 0 || $moduleKey === '') {
            continue;
        }
        $modulesByGroup[$groupId][] = [
            'entity_key' => 'modules',
            'id' => 'module:' . $moduleKey,
            'key' => $moduleKey,
            'name' => $moduleKey,
            'status' => 'active',
        ];
    }

    return $modulesByGroup;
}

function videochat_tenancy_governance_enrich_group_relationships(PDO $pdo, int $tenantId, array $row): array
{
    $groupId = (int) ($row['database_id'] ?? ($row['id'] ?? 0));
    $members = videochat_tenancy_governance_group_member_map($pdo, $tenantId, [$groupId]);
    $permissions = videochat_tenancy_governance_group_permission_map($pdo, $tenantId, [$groupId]);
    $modules = videochat_tenancy_governance_group_module_map($pdo, $tenantId, [$groupId]);
    $organizationId = (int) ($row['organization_database_id'] ?? 0);
    $organizations = videochat_tenancy_governance_organization_summary_map($pdo, $tenantId, [$organizationId]);
    $row['relationships'] = [
        ...(is_array($row['relationships'] ?? null) ? $row['relationships'] : []),
        'organization' => isset($organizations[$organizationId]) ? [$organizations[$organizationId]] : [],
        'members' => $members[$groupId] ?? [],
        'permissions' => $permissions[$groupId] ?? [],
        'modules' => $modules[$groupId] ?? [],
    ];

    return $row;
}

function videochat_tenancy_governance_enrich_group_rows(PDO $pdo, int $tenantId, array $rows): array
{
    $groupIds = array_map(static fn (array $row): int => (int) ($row['database_id'] ?? 0), $rows);
    $organizationIds = array_map(static fn (array $row): int => (int) ($row['organization_database_id'] ?? 0), $rows);
    $members = videochat_tenancy_governance_group_member_map($pdo, $tenantId, $groupIds);
    $permissions = videochat_tenancy_governance_group_permission_map($pdo, $tenantId, $groupIds);
    $modules = videochat_tenancy_governance_group_module_map($pdo, $tenantId, $groupIds);
    $organizations = videochat_tenancy_governance_organization_summary_map($pdo, $tenantId, $organizationIds);

    return array_map(static function (array $row) use ($members, $permissions, $modules, $organizations): array {
        $groupId = (int) ($row['database_id'] ?? 0);
        $organizationId = (int) ($row['organization_database_id'] ?? 0);
        $row['relationships'] = [
            ...(is_array($row['relationships'] ?? null) ? $row['relationships'] : []),
            'organization' => isset($organizations[$organizationId]) ? [$organizations[$organizationId]] : [],
            'members' => $members[$groupId] ?? [],
            'permissions' => $permissions[$groupId] ?? [],
            'modules' => $modules[$groupId] ?? [],
        ];
        return $row;
    }, $rows);
}
