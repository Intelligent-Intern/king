<?php

declare(strict_types=1);

require_once __DIR__ . '/governance_roles.php';

function videochat_tenancy_governance_group_payload_has_roles(array $payload): bool
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    return array_key_exists('roles', $relationships) || array_key_exists('roles', $payload);
}

function videochat_tenancy_governance_group_role_values(array $payload): array
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    $roles = array_key_exists('roles', $relationships) ? $relationships['roles'] : ($payload['roles'] ?? []);
    return is_array($roles) ? $roles : [];
}

function videochat_tenancy_governance_group_role_identifier(mixed $value): string
{
    if (is_scalar($value)) {
        return trim((string) $value);
    }
    if (is_array($value)) {
        return videochat_tenancy_governance_relation_text($value, ['id', 'key', 'value', 'name']);
    }
    return '';
}

function videochat_tenancy_governance_validate_group_roles(PDO $pdo, int $tenantId, array $payload): array
{
    if (!videochat_tenancy_governance_group_payload_has_roles($payload)) {
        return ['ok' => true, 'role_ids' => []];
    }
    $roleIds = [];
    foreach (videochat_tenancy_governance_group_role_values($payload) as $value) {
        $identifier = videochat_tenancy_governance_group_role_identifier($value);
        $role = videochat_tenancy_fetch_governance_role($pdo, $tenantId, $identifier);
        if (!is_array($role)) {
            return ['ok' => false, 'errors' => ['roles' => 'not_found']];
        }
        $roleIds[(int) ($role['id'] ?? 0)] = true;
    }
    return ['ok' => true, 'role_ids' => array_keys($roleIds)];
}

function videochat_tenancy_governance_group_ids_for_role(PDO $pdo, int $tenantId, int $roleId): array
{
    if ($tenantId <= 0 || $roleId <= 0) {
        return [];
    }
    $query = $pdo->prepare('SELECT group_id FROM governance_group_roles WHERE tenant_id = :tenant_id AND role_id = :role_id');
    $query->execute([':tenant_id' => $tenantId, ':role_id' => $roleId]);
    return array_values(array_unique(array_filter(array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn (int $id): bool => $id > 0)));
}

function videochat_tenancy_governance_replace_group_roles(PDO $pdo, int $tenantId, int $groupId, array $roleIds): void
{
    $delete = $pdo->prepare('DELETE FROM governance_group_roles WHERE tenant_id = :tenant_id AND group_id = :group_id');
    $delete->execute([':tenant_id' => $tenantId, ':group_id' => $groupId]);
    $insert = $pdo->prepare('INSERT OR IGNORE INTO governance_group_roles(tenant_id, group_id, role_id) VALUES(:tenant_id, :group_id, :role_id)');
    foreach ($roleIds as $roleId) {
        $insert->execute([':tenant_id' => $tenantId, ':group_id' => $groupId, ':role_id' => (int) $roleId]);
    }
}

function videochat_tenancy_governance_group_role_rows(PDO $pdo, int $tenantId, array $groupIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $params = [':tenant_id' => $tenantId];
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $name = ':group_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(sprintf(
        'SELECT group_id, governance_roles.public_id, governance_roles.key, governance_roles.name, governance_roles.status FROM governance_group_roles INNER JOIN governance_roles ON governance_roles.id = governance_group_roles.role_id WHERE governance_group_roles.tenant_id = :tenant_id AND group_id IN (%s) ORDER BY lower(governance_roles.name) ASC',
        implode(', ', $placeholders)
    ));
    $query->execute($params);
    $rows = array_fill_keys($ids, []);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[(int) ($row['group_id'] ?? 0)][] = [
            'entity_key' => 'roles',
            'id' => (string) ($row['public_id'] ?? ''),
            'key' => trim((string) ($row['key'] ?? '')) !== '' ? (string) $row['key'] : (string) ($row['public_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
        ];
    }
    return $rows;
}

function videochat_tenancy_governance_enrich_group_role_relationships(PDO $pdo, int $tenantId, array $row): array
{
    $groupId = (int) ($row['database_id'] ?? ($row['id'] ?? 0));
    $roles = videochat_tenancy_governance_group_role_rows($pdo, $tenantId, [$groupId]);
    $row['relationships'] = [
        ...(is_array($row['relationships'] ?? null) ? $row['relationships'] : []),
        'roles' => $roles[$groupId] ?? [],
    ];
    return $row;
}

function videochat_tenancy_governance_enrich_group_role_rows(PDO $pdo, int $tenantId, array $rows): array
{
    $groupIds = array_map(static fn (array $row): int => (int) ($row['database_id'] ?? 0), $rows);
    $roles = videochat_tenancy_governance_group_role_rows($pdo, $tenantId, $groupIds);
    return array_map(static function (array $row) use ($roles): array {
        $groupId = (int) ($row['database_id'] ?? 0);
        $row['relationships'] = [
            ...(is_array($row['relationships'] ?? null) ? $row['relationships'] : []),
            'roles' => $roles[$groupId] ?? [],
        ];
        return $row;
    }, $rows);
}

function videochat_tenancy_governance_group_role_permission_rows(PDO $pdo, int $tenantId, int $groupId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT governance_role_permissions.permission_key, governance_role_permissions.resource_type, '*' AS resource_id, governance_role_permissions.action
FROM governance_group_roles
INNER JOIN governance_roles ON governance_roles.id = governance_group_roles.role_id
INNER JOIN governance_role_permissions ON governance_role_permissions.role_id = governance_roles.id
WHERE governance_group_roles.tenant_id = :tenant_id
  AND governance_group_roles.group_id = :group_id
  AND governance_roles.status = 'active'
  AND governance_role_permissions.tenant_id = :tenant_id
UNION
SELECT 'module.' || governance_role_modules.module_key || '.read' AS permission_key, 'module' AS resource_type, governance_role_modules.module_key AS resource_id, 'read' AS action
FROM governance_group_roles
INNER JOIN governance_roles ON governance_roles.id = governance_group_roles.role_id
INNER JOIN governance_role_modules ON governance_role_modules.role_id = governance_roles.id
WHERE governance_group_roles.tenant_id = :tenant_id
  AND governance_group_roles.group_id = :group_id
  AND governance_roles.status = 'active'
  AND governance_role_modules.tenant_id = :tenant_id
ORDER BY permission_key ASC
SQL
    );
    $query->execute([':tenant_id' => $tenantId, ':group_id' => $groupId]);
    return $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function videochat_tenancy_governance_sync_group_role_grants(PDO $pdo, int $tenantId, int $groupId, int $actorUserId): array
{
    if ($tenantId <= 0 || $groupId <= 0) {
        return ['ok' => false, 'errors' => ['group' => 'not_found']];
    }
    $delete = $pdo->prepare("DELETE FROM permission_grants WHERE tenant_id = :tenant_id AND subject_type = 'group' AND group_id = :group_id AND source = 'group_roles'");
    $delete->execute([':tenant_id' => $tenantId, ':group_id' => $groupId]);
    $exists = $pdo->prepare(
        <<<'SQL'
SELECT id
FROM permission_grants
WHERE tenant_id = :tenant_id
  AND subject_type = 'group'
  AND group_id = :group_id
  AND resource_type = :resource_type
  AND resource_id = :resource_id
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
    :tenant_id, :public_id, :resource_type, :resource_id, :action, 'group', :group_id,
    :created_by_user_id, :label, '', :permission_key, 'group_roles', :created_at, :updated_at
)
SQL
    );
    $now = gmdate('c');
    foreach (videochat_tenancy_governance_group_role_permission_rows($pdo, $tenantId, $groupId) as $permission) {
        $exists->execute([
            ':tenant_id' => $tenantId,
            ':group_id' => $groupId,
            ':resource_type' => (string) $permission['resource_type'],
            ':resource_id' => (string) $permission['resource_id'],
            ':action' => (string) $permission['action'],
        ]);
        if ($exists->fetchColumn() !== false) {
            continue;
        }
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':public_id' => videochat_tenancy_generate_public_id(),
            ':resource_type' => (string) $permission['resource_type'],
            ':resource_id' => (string) $permission['resource_id'],
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

function videochat_tenancy_governance_sync_groups_for_role(PDO $pdo, int $tenantId, int $roleId, int $actorUserId): array
{
    foreach (videochat_tenancy_governance_group_ids_for_role($pdo, $tenantId, $roleId) as $groupId) {
        $sync = videochat_tenancy_governance_sync_group_role_grants($pdo, $tenantId, $groupId, $actorUserId);
        if (!(bool) ($sync['ok'] ?? false)) {
            return $sync;
        }
    }
    return ['ok' => true];
}

function videochat_tenancy_governance_sync_group_roles(PDO $pdo, int $tenantId, int $groupId, int $actorUserId, array $payload): array
{
    if (!videochat_tenancy_governance_group_payload_has_roles($payload)) {
        return ['ok' => true];
    }
    $validation = videochat_tenancy_governance_validate_group_roles($pdo, $tenantId, $payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return $validation;
    }
    videochat_tenancy_governance_replace_group_roles($pdo, $tenantId, $groupId, (array) $validation['role_ids']);
    return videochat_tenancy_governance_sync_group_role_grants($pdo, $tenantId, $groupId, $actorUserId);
}

function videochat_tenancy_governance_organization_payload_has_roles(array $payload): bool
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    return array_key_exists('roles', $relationships) || array_key_exists('roles', $payload);
}

function videochat_tenancy_governance_organization_role_values(array $payload): array
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    $roles = array_key_exists('roles', $relationships) ? $relationships['roles'] : ($payload['roles'] ?? []);
    return is_array($roles) ? $roles : [];
}

function videochat_tenancy_governance_validate_organization_roles(PDO $pdo, int $tenantId, array $payload): array
{
    if (!videochat_tenancy_governance_organization_payload_has_roles($payload)) {
        return ['ok' => true, 'role_ids' => []];
    }
    $roleIds = [];
    foreach (videochat_tenancy_governance_organization_role_values($payload) as $value) {
        $identifier = videochat_tenancy_governance_group_role_identifier($value);
        $role = videochat_tenancy_fetch_governance_role($pdo, $tenantId, $identifier);
        if (!is_array($role)) {
            return ['ok' => false, 'errors' => ['roles' => 'not_found']];
        }
        $roleIds[(int) ($role['id'] ?? 0)] = true;
    }
    return ['ok' => true, 'role_ids' => array_keys($roleIds)];
}

function videochat_tenancy_governance_organization_ids_for_role(PDO $pdo, int $tenantId, int $roleId): array
{
    if ($tenantId <= 0 || $roleId <= 0) {
        return [];
    }
    $query = $pdo->prepare('SELECT organization_id FROM governance_organization_roles WHERE tenant_id = :tenant_id AND role_id = :role_id');
    $query->execute([':tenant_id' => $tenantId, ':role_id' => $roleId]);
    return array_values(array_unique(array_filter(array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn (int $id): bool => $id > 0)));
}

function videochat_tenancy_governance_replace_organization_roles(PDO $pdo, int $tenantId, int $organizationId, array $roleIds): void
{
    $delete = $pdo->prepare('DELETE FROM governance_organization_roles WHERE tenant_id = :tenant_id AND organization_id = :organization_id');
    $delete->execute([':tenant_id' => $tenantId, ':organization_id' => $organizationId]);
    $insert = $pdo->prepare('INSERT OR IGNORE INTO governance_organization_roles(tenant_id, organization_id, role_id) VALUES(:tenant_id, :organization_id, :role_id)');
    foreach ($roleIds as $roleId) {
        $insert->execute([':tenant_id' => $tenantId, ':organization_id' => $organizationId, ':role_id' => (int) $roleId]);
    }
}

function videochat_tenancy_governance_organization_role_rows(PDO $pdo, int $tenantId, array $organizationIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $organizationIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $params = [':tenant_id' => $tenantId];
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $name = ':organization_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(sprintf(
        'SELECT organization_id, governance_roles.public_id, governance_roles.key, governance_roles.name, governance_roles.status FROM governance_organization_roles INNER JOIN governance_roles ON governance_roles.id = governance_organization_roles.role_id WHERE governance_organization_roles.tenant_id = :tenant_id AND organization_id IN (%s) ORDER BY lower(governance_roles.name) ASC',
        implode(', ', $placeholders)
    ));
    $query->execute($params);
    $rows = array_fill_keys($ids, []);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[(int) ($row['organization_id'] ?? 0)][] = [
            'entity_key' => 'roles',
            'id' => (string) ($row['public_id'] ?? ''),
            'key' => trim((string) ($row['key'] ?? '')) !== '' ? (string) $row['key'] : (string) ($row['public_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
        ];
    }
    return $rows;
}

function videochat_tenancy_governance_enrich_organization_role_relationships(PDO $pdo, int $tenantId, array $row): array
{
    $organizationId = (int) ($row['database_id'] ?? ($row['id'] ?? 0));
    $roles = videochat_tenancy_governance_organization_role_rows($pdo, $tenantId, [$organizationId]);
    $row['relationships'] = [
        ...(is_array($row['relationships'] ?? null) ? $row['relationships'] : []),
        'roles' => $roles[$organizationId] ?? [],
    ];
    return $row;
}

function videochat_tenancy_governance_enrich_organization_role_rows(PDO $pdo, int $tenantId, array $rows): array
{
    $organizationIds = array_map(static fn (array $row): int => (int) ($row['database_id'] ?? 0), $rows);
    $roles = videochat_tenancy_governance_organization_role_rows($pdo, $tenantId, $organizationIds);
    return array_map(static function (array $row) use ($roles): array {
        $organizationId = (int) ($row['database_id'] ?? 0);
        $row['relationships'] = [
            ...(is_array($row['relationships'] ?? null) ? $row['relationships'] : []),
            'roles' => $roles[$organizationId] ?? [],
        ];
        return $row;
    }, $rows);
}

function videochat_tenancy_governance_organization_role_permission_rows(PDO $pdo, int $tenantId, int $organizationId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT governance_role_permissions.permission_key, governance_role_permissions.resource_type, '*' AS resource_id, governance_role_permissions.action
FROM governance_organization_roles
INNER JOIN governance_roles ON governance_roles.id = governance_organization_roles.role_id
INNER JOIN governance_role_permissions ON governance_role_permissions.role_id = governance_roles.id
WHERE governance_organization_roles.tenant_id = :tenant_id
  AND governance_organization_roles.organization_id = :organization_id
  AND governance_roles.status = 'active'
  AND governance_role_permissions.tenant_id = :tenant_id
UNION
SELECT 'module.' || governance_role_modules.module_key || '.read' AS permission_key, 'module' AS resource_type, governance_role_modules.module_key AS resource_id, 'read' AS action
FROM governance_organization_roles
INNER JOIN governance_roles ON governance_roles.id = governance_organization_roles.role_id
INNER JOIN governance_role_modules ON governance_role_modules.role_id = governance_roles.id
WHERE governance_organization_roles.tenant_id = :tenant_id
  AND governance_organization_roles.organization_id = :organization_id
  AND governance_roles.status = 'active'
  AND governance_role_modules.tenant_id = :tenant_id
ORDER BY permission_key ASC
SQL
    );
    $query->execute([':tenant_id' => $tenantId, ':organization_id' => $organizationId]);
    return $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function videochat_tenancy_governance_sync_organization_role_grants(PDO $pdo, int $tenantId, int $organizationId, int $actorUserId): array
{
    if ($tenantId <= 0 || $organizationId <= 0) {
        return ['ok' => false, 'errors' => ['organization' => 'not_found']];
    }
    $delete = $pdo->prepare("DELETE FROM permission_grants WHERE tenant_id = :tenant_id AND subject_type = 'organization' AND organization_id = :organization_id AND source = 'organization_roles'");
    $delete->execute([':tenant_id' => $tenantId, ':organization_id' => $organizationId]);
    $exists = $pdo->prepare(
        <<<'SQL'
SELECT id
FROM permission_grants
WHERE tenant_id = :tenant_id
  AND subject_type = 'organization'
  AND organization_id = :organization_id
  AND resource_type = :resource_type
  AND resource_id = :resource_id
  AND action = :action
  AND (revoked_at IS NULL OR revoked_at = '')
LIMIT 1
SQL
    );
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO permission_grants(
    tenant_id, public_id, resource_type, resource_id, action, subject_type, organization_id,
    created_by_user_id, label, description, permission_key, source, created_at, updated_at
) VALUES(
    :tenant_id, :public_id, :resource_type, :resource_id, :action, 'organization', :organization_id,
    :created_by_user_id, :label, '', :permission_key, 'organization_roles', :created_at, :updated_at
)
SQL
    );
    $now = gmdate('c');
    foreach (videochat_tenancy_governance_organization_role_permission_rows($pdo, $tenantId, $organizationId) as $permission) {
        $exists->execute([
            ':tenant_id' => $tenantId,
            ':organization_id' => $organizationId,
            ':resource_type' => (string) $permission['resource_type'],
            ':resource_id' => (string) $permission['resource_id'],
            ':action' => (string) $permission['action'],
        ]);
        if ($exists->fetchColumn() !== false) {
            continue;
        }
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':public_id' => videochat_tenancy_generate_public_id(),
            ':resource_type' => (string) $permission['resource_type'],
            ':resource_id' => (string) $permission['resource_id'],
            ':action' => (string) $permission['action'],
            ':organization_id' => $organizationId,
            ':created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ':label' => (string) $permission['permission_key'],
            ':permission_key' => (string) $permission['permission_key'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
    return ['ok' => true];
}

function videochat_tenancy_governance_sync_organizations_for_role(PDO $pdo, int $tenantId, int $roleId, int $actorUserId): array
{
    foreach (videochat_tenancy_governance_organization_ids_for_role($pdo, $tenantId, $roleId) as $organizationId) {
        $sync = videochat_tenancy_governance_sync_organization_role_grants($pdo, $tenantId, $organizationId, $actorUserId);
        if (!(bool) ($sync['ok'] ?? false)) {
            return $sync;
        }
    }
    return ['ok' => true];
}

function videochat_tenancy_governance_sync_organization_roles(PDO $pdo, int $tenantId, int $organizationId, int $actorUserId, array $payload): array
{
    if (!videochat_tenancy_governance_organization_payload_has_roles($payload)) {
        return ['ok' => true];
    }
    $validation = videochat_tenancy_governance_validate_organization_roles($pdo, $tenantId, $payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return $validation;
    }
    videochat_tenancy_governance_replace_organization_roles($pdo, $tenantId, $organizationId, (array) $validation['role_ids']);
    return videochat_tenancy_governance_sync_organization_role_grants($pdo, $tenantId, $organizationId, $actorUserId);
}
