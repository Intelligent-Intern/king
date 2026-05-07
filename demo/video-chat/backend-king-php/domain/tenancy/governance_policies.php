<?php

declare(strict_types=1);

require_once __DIR__ . '/tenant_administration.php';
require_once __DIR__ . '/governance_permission_grants.php';

function videochat_tenancy_governance_policy_permission_decision(
    PDO $pdo,
    array $authContext,
    string $action,
    string $resourceId = '*'
): array {
    $tenant = is_array($authContext['tenant'] ?? null) ? $authContext['tenant'] : [];
    $permissions = is_array($tenant['permissions'] ?? null) ? $tenant['permissions'] : [];
    $tenantId = (int) ($tenant['id'] ?? ($tenant['tenant_id'] ?? 0));
    $userId = (int) (($authContext['user']['id'] ?? 0));
    $normalizedAction = videochat_tenancy_normalize_grant_action($action);
    if ($tenantId <= 0 || $userId <= 0 || $normalizedAction === '') {
        return ['ok' => false, 'reason' => 'invalid_context'];
    }

    if (
        (bool) ($permissions['platform_admin'] ?? false)
        || (bool) ($permissions['tenant_admin'] ?? false)
        || (bool) ($permissions['governance.policies.' . $normalizedAction] ?? false)
        || ($normalizedAction === 'read' && (bool) ($permissions['governance.read'] ?? false))
    ) {
        return ['ok' => true, 'reason' => 'tenant_permission_alias'];
    }

    $resource = trim($resourceId) !== '' ? trim($resourceId) : '*';
    foreach ([[$resource, $normalizedAction], [$resource, 'manage'], ['*', $normalizedAction], ['*', 'manage']] as [$candidateResource, $candidateAction]) {
        $grant = videochat_tenancy_user_has_resource_permission($pdo, $tenantId, $userId, 'policy', $candidateResource, $candidateAction);
        if ((bool) ($grant['ok'] ?? false)) {
            return ['ok' => true, 'reason' => 'resource_grant', 'grant' => $grant['grant'] ?? null];
        }
    }

    return ['ok' => false, 'reason' => 'not_granted'];
}

function videochat_tenancy_governance_policy_source(array $policy): string
{
    return 'policy:' . trim((string) ($policy['public_id'] ?? ''));
}

function videochat_tenancy_governance_policy_values(array $payload, string $key): array
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    $values = array_key_exists($key, $relationships) ? $relationships[$key] : ($payload[$key] ?? []);
    return is_array($values) ? $values : [];
}

function videochat_tenancy_governance_policy_has_relation(array $payload, string $key): bool
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    return array_key_exists($key, $relationships) || array_key_exists($key, $payload);
}

function videochat_tenancy_governance_policy_identifier(mixed $value): string
{
    if (is_scalar($value)) {
        return trim((string) $value);
    }
    if (is_array($value)) {
        return videochat_tenancy_governance_relation_text($value, ['id', 'key', 'value', 'name']);
    }
    return '';
}

function videochat_tenancy_governance_policy_validate_key(PDO $pdo, int $tenantId, string $key, int $exceptId = 0): ?string
{
    if ($key === '') {
        return null;
    }
    if (mb_strlen($key) > 120 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
        return 'invalid';
    }
    $query = $pdo->prepare(
        'SELECT id FROM governance_policies WHERE tenant_id = :tenant_id AND lower(key) = lower(:key) AND id <> :except_id LIMIT 1'
    );
    $query->execute([':tenant_id' => $tenantId, ':key' => $key, ':except_id' => $exceptId]);
    return $query->fetchColumn() === false ? null : 'duplicate';
}

function videochat_tenancy_governance_validate_policy_payload(PDO $pdo, int $tenantId, array $payload, ?array $existing = null): array
{
    $validation = videochat_tenancy_validate_governance_name_status($payload, $existing);
    $key = array_key_exists('key', $payload) ? trim((string) $payload['key']) : trim((string) ($existing['key'] ?? ''));
    $description = array_key_exists('description', $payload)
        ? trim((string) $payload['description'])
        : trim((string) ($existing['description'] ?? ''));
    if (mb_strlen($description) > 2000) {
        $validation['errors']['description'] = 'too_long';
        $validation['ok'] = false;
    }
    $keyError = videochat_tenancy_governance_policy_validate_key($pdo, $tenantId, $key, (int) ($existing['id'] ?? 0));
    if ($keyError !== null) {
        $validation['errors']['key'] = $keyError;
        $validation['ok'] = false;
    }

    return [
        ...$validation,
        'key' => $key,
        'description' => $description,
    ];
}

function videochat_tenancy_governance_policy_group_ids(PDO $pdo, int $tenantId, array $payload): array
{
    $ids = [];
    foreach (videochat_tenancy_governance_policy_values($payload, 'groups') as $value) {
        $identifier = videochat_tenancy_governance_policy_identifier($value);
        $group = videochat_tenancy_fetch_governance_group($pdo, $tenantId, $identifier);
        if (!is_array($group)) {
            return ['ok' => false, 'errors' => ['groups' => 'not_found']];
        }
        $ids[(int) ($group['database_id'] ?? 0)] = true;
    }
    return ['ok' => true, 'ids' => array_keys($ids)];
}

function videochat_tenancy_governance_policy_organization_ids(PDO $pdo, int $tenantId, array $payload): array
{
    $ids = [];
    foreach (videochat_tenancy_governance_policy_values($payload, 'organizations') as $value) {
        $identifier = videochat_tenancy_governance_policy_identifier($value);
        $organization = videochat_tenancy_fetch_governance_organization($pdo, $tenantId, $identifier);
        if (!is_array($organization)) {
            return ['ok' => false, 'errors' => ['organizations' => 'not_found']];
        }
        $ids[(int) ($organization['database_id'] ?? 0)] = true;
    }
    return ['ok' => true, 'ids' => array_keys($ids)];
}

function videochat_tenancy_governance_policy_permissions(array $payload): array
{
    $permissions = [];
    foreach (videochat_tenancy_governance_policy_values($payload, 'permissions') as $value) {
        $permissionPayload = is_array($value)
            ? ['relationships' => ['permission' => [$value]]]
            : ['permission_key' => (string) $value];
        $permission = videochat_tenancy_governance_parse_permission($permissionPayload);
        if (!(bool) ($permission['ok'] ?? false)) {
            return ['ok' => false, 'errors' => ['permissions' => 'invalid_permission']];
        }
        $permissions[(string) $permission['permission_key']] = [
            'permission_key' => (string) $permission['permission_key'],
            'resource_type' => (string) $permission['resource_type'],
            'action' => (string) $permission['action'],
        ];
    }
    return ['ok' => true, 'permissions' => array_values($permissions)];
}

function videochat_tenancy_fetch_governance_policy(PDO $pdo, int $tenantId, string $identifier): ?array
{
    $trimmed = trim($identifier);
    if ($tenantId <= 0 || $trimmed === '') {
        return null;
    }
    $numericId = ctype_digit($trimmed) ? (int) $trimmed : 0;
    $numericClause = $numericId > 0 ? ' OR id = :numeric_id' : '';
    $query = $pdo->prepare(
        <<<SQL
SELECT *
FROM governance_policies
WHERE tenant_id = :tenant_id
  AND (lower(public_id) = lower(:identifier) OR lower(key) = lower(:identifier){$numericClause})
LIMIT 1
SQL
    );
    $params = [':tenant_id' => $tenantId, ':identifier' => $trimmed];
    if ($numericId > 0) {
        $params[':numeric_id'] = $numericId;
    }
    $query->execute($params);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function videochat_tenancy_list_governance_policies(PDO $pdo, int $tenantId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT *
FROM governance_policies
WHERE tenant_id = :tenant_id
ORDER BY status = 'archived' ASC, lower(name) ASC, id ASC
SQL
    );
    $query->execute([':tenant_id' => $tenantId]);
    return $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function videochat_tenancy_governance_policy_group_rows(PDO $pdo, int $tenantId, array $policyIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $policyIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $params = [':tenant_id' => $tenantId];
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $name = ':policy_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(sprintf(
        'SELECT policy_id, "groups".public_id, "groups".name, "groups".status FROM governance_policy_groups INNER JOIN "groups" ON "groups".id = governance_policy_groups.group_id WHERE governance_policy_groups.tenant_id = :tenant_id AND policy_id IN (%s) ORDER BY lower("groups".name) ASC',
        implode(', ', $placeholders)
    ));
    $query->execute($params);
    $rows = array_fill_keys($ids, []);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[(int) ($row['policy_id'] ?? 0)][] = [
            'entity_key' => 'groups',
            'id' => (string) ($row['public_id'] ?? ''),
            'key' => (string) ($row['public_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
        ];
    }
    return $rows;
}

function videochat_tenancy_governance_policy_organization_rows(PDO $pdo, int $tenantId, array $policyIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $policyIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $params = [':tenant_id' => $tenantId];
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $name = ':policy_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(sprintf(
        'SELECT policy_id, organizations.public_id, organizations.name, organizations.status FROM governance_policy_organizations INNER JOIN organizations ON organizations.id = governance_policy_organizations.organization_id WHERE governance_policy_organizations.tenant_id = :tenant_id AND policy_id IN (%s) ORDER BY lower(organizations.name) ASC',
        implode(', ', $placeholders)
    ));
    $query->execute($params);
    $rows = array_fill_keys($ids, []);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[(int) ($row['policy_id'] ?? 0)][] = [
            'entity_key' => 'organizations',
            'id' => (string) ($row['public_id'] ?? ''),
            'key' => (string) ($row['public_id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
        ];
    }
    return $rows;
}

function videochat_tenancy_governance_policy_permission_rows(PDO $pdo, int $tenantId, array $policyIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $policyIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $params = [':tenant_id' => $tenantId];
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $name = ':policy_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(sprintf(
        'SELECT policy_id, permission_key FROM governance_policy_permissions WHERE tenant_id = :tenant_id AND policy_id IN (%s) ORDER BY permission_key ASC',
        implode(', ', $placeholders)
    ));
    $query->execute($params);
    $rows = array_fill_keys($ids, []);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $permissionKey = (string) ($row['permission_key'] ?? '');
        $rows[(int) ($row['policy_id'] ?? 0)][] = [
            'entity_key' => 'permissions',
            'id' => 'permission:governance:' . $permissionKey,
            'key' => $permissionKey,
            'name' => $permissionKey,
            'status' => 'active',
        ];
    }
    return $rows;
}

function videochat_tenancy_governance_policy_public_rows(PDO $pdo, int $tenantId, array $rows): array
{
    $policyIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
    $groups = videochat_tenancy_governance_policy_group_rows($pdo, $tenantId, $policyIds);
    $organizations = videochat_tenancy_governance_policy_organization_rows($pdo, $tenantId, $policyIds);
    $permissions = videochat_tenancy_governance_policy_permission_rows($pdo, $tenantId, $policyIds);
    return array_map(static function (array $row) use ($groups, $organizations, $permissions): array {
        $policyId = (int) ($row['id'] ?? 0);
        $publicId = (string) ($row['public_id'] ?? '');
        return [
            'id' => $publicId,
            'key' => trim((string) ($row['key'] ?? '')) !== '' ? (string) $row['key'] : $publicId,
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'relationships' => [
                'groups' => $groups[$policyId] ?? [],
                'organizations' => $organizations[$policyId] ?? [],
                'permissions' => $permissions[$policyId] ?? [],
            ],
        ];
    }, $rows);
}

function videochat_tenancy_governance_policy_replace_ids(PDO $pdo, string $table, string $column, int $tenantId, int $policyId, array $ids): void
{
    $delete = $pdo->prepare("DELETE FROM {$table} WHERE tenant_id = :tenant_id AND policy_id = :policy_id");
    $delete->execute([':tenant_id' => $tenantId, ':policy_id' => $policyId]);
    $insert = $pdo->prepare("INSERT OR IGNORE INTO {$table}(tenant_id, policy_id, {$column}) VALUES(:tenant_id, :policy_id, :value_id)");
    foreach ($ids as $id) {
        $insert->execute([':tenant_id' => $tenantId, ':policy_id' => $policyId, ':value_id' => (int) $id]);
    }
}

function videochat_tenancy_governance_policy_replace_permissions(PDO $pdo, int $tenantId, int $policyId, array $permissions): void
{
    $delete = $pdo->prepare('DELETE FROM governance_policy_permissions WHERE tenant_id = :tenant_id AND policy_id = :policy_id');
    $delete->execute([':tenant_id' => $tenantId, ':policy_id' => $policyId]);
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO governance_policy_permissions(tenant_id, policy_id, permission_key, resource_type, action)
VALUES(:tenant_id, :policy_id, :permission_key, :resource_type, :action)
SQL
    );
    foreach ($permissions as $permission) {
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':policy_id' => $policyId,
            ':permission_key' => (string) $permission['permission_key'],
            ':resource_type' => (string) $permission['resource_type'],
            ':action' => (string) $permission['action'],
        ]);
    }
}

function videochat_tenancy_governance_policy_sync_relationships(PDO $pdo, int $tenantId, array $policy, array $payload): array
{
    $policyId = (int) ($policy['id'] ?? 0);
    if ($policyId <= 0) {
        return ['ok' => false, 'errors' => ['policy' => 'not_found']];
    }
    if (videochat_tenancy_governance_policy_has_relation($payload, 'groups')) {
        $groups = videochat_tenancy_governance_policy_group_ids($pdo, $tenantId, $payload);
        if (!(bool) ($groups['ok'] ?? false)) {
            return $groups;
        }
        videochat_tenancy_governance_policy_replace_ids($pdo, 'governance_policy_groups', 'group_id', $tenantId, $policyId, (array) $groups['ids']);
    }
    if (videochat_tenancy_governance_policy_has_relation($payload, 'organizations')) {
        $organizations = videochat_tenancy_governance_policy_organization_ids($pdo, $tenantId, $payload);
        if (!(bool) ($organizations['ok'] ?? false)) {
            return $organizations;
        }
        videochat_tenancy_governance_policy_replace_ids($pdo, 'governance_policy_organizations', 'organization_id', $tenantId, $policyId, (array) $organizations['ids']);
    }
    if (videochat_tenancy_governance_policy_has_relation($payload, 'permissions')) {
        $permissions = videochat_tenancy_governance_policy_permissions($payload);
        if (!(bool) ($permissions['ok'] ?? false)) {
            return $permissions;
        }
        videochat_tenancy_governance_policy_replace_permissions($pdo, $tenantId, $policyId, (array) $permissions['permissions']);
    }
    return ['ok' => true];
}

function videochat_tenancy_governance_sync_policy_grants(PDO $pdo, int $tenantId, int $actorUserId, array $policy): array
{
    $policyId = (int) ($policy['id'] ?? 0);
    $source = videochat_tenancy_governance_policy_source($policy);
    $delete = $pdo->prepare('DELETE FROM permission_grants WHERE tenant_id = :tenant_id AND source = :source');
    $delete->execute([':tenant_id' => $tenantId, ':source' => $source]);
    if ($policyId <= 0 || (string) ($policy['status'] ?? 'active') !== 'active') {
        return ['ok' => true];
    }
    $groups = $pdo->prepare('SELECT group_id FROM governance_policy_groups WHERE tenant_id = :tenant_id AND policy_id = :policy_id');
    $groups->execute([':tenant_id' => $tenantId, ':policy_id' => $policyId]);
    $groupIds = array_map('intval', $groups->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $organizations = $pdo->prepare('SELECT organization_id FROM governance_policy_organizations WHERE tenant_id = :tenant_id AND policy_id = :policy_id');
    $organizations->execute([':tenant_id' => $tenantId, ':policy_id' => $policyId]);
    $organizationIds = array_map('intval', $organizations->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $permissions = $pdo->prepare('SELECT permission_key, resource_type, action FROM governance_policy_permissions WHERE tenant_id = :tenant_id AND policy_id = :policy_id');
    $permissions->execute([':tenant_id' => $tenantId, ':policy_id' => $policyId]);
    $permissionRows = $permissions->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $exists = $pdo->prepare(
        <<<'SQL'
SELECT id
FROM permission_grants
WHERE tenant_id = :tenant_id
  AND subject_type = :subject_type
  AND COALESCE(group_id, 0) = :group_id
  AND COALESCE(organization_id, 0) = :organization_id
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
    tenant_id, public_id, resource_type, resource_id, action, subject_type, group_id, organization_id,
    created_by_user_id, label, description, permission_key, source, created_at, updated_at
) VALUES(
    :tenant_id, :public_id, :resource_type, '*', :action, :subject_type, :group_id, :organization_id,
    :created_by_user_id, :label, :description, :permission_key, :source, :created_at, :updated_at
)
SQL
    );
    $now = gmdate('c');
    foreach ($permissionRows as $permission) {
        foreach ([['group', $groupIds], ['organization', $organizationIds]] as [$subjectType, $subjectIds]) {
            foreach ($subjectIds as $subjectId) {
                $groupId = $subjectType === 'group' ? $subjectId : 0;
                $organizationId = $subjectType === 'organization' ? $subjectId : 0;
                $exists->execute([
                    ':tenant_id' => $tenantId,
                    ':subject_type' => $subjectType,
                    ':group_id' => $groupId,
                    ':organization_id' => $organizationId,
                    ':resource_type' => (string) $permission['resource_type'],
                    ':action' => (string) $permission['action'],
                ]);
                if ($exists->fetchColumn() !== false) {
                    continue;
                }
                $permissionKey = (string) ($permission['permission_key'] ?? '');
                $insert->execute([
                    ':tenant_id' => $tenantId,
                    ':public_id' => videochat_tenancy_generate_public_id(),
                    ':resource_type' => (string) $permission['resource_type'],
                    ':action' => (string) $permission['action'],
                    ':subject_type' => $subjectType,
                    ':group_id' => $groupId > 0 ? $groupId : null,
                    ':organization_id' => $organizationId > 0 ? $organizationId : null,
                    ':created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                    ':label' => trim((string) ($policy['name'] ?? 'Policy')) . ': ' . $permissionKey,
                    ':description' => (string) ($policy['description'] ?? ''),
                    ':permission_key' => $permissionKey,
                    ':source' => $source,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            }
        }
    }
    return ['ok' => true];
}

function videochat_tenancy_create_governance_policy(PDO $pdo, int $tenantId, int $actorUserId, array $payload): array
{
    $validation = videochat_tenancy_governance_validate_policy_payload($pdo, $tenantId, $payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'errors' => $validation['errors'] ?? []];
    }
    $pdo->beginTransaction();
    try {
        $now = gmdate('c');
        $publicId = videochat_tenancy_generate_public_id();
        $insert = $pdo->prepare(
            <<<'SQL'
INSERT INTO governance_policies(tenant_id, public_id, key, name, description, status, created_by_user_id, created_at, updated_at)
VALUES(:tenant_id, :public_id, :key, :name, :description, :status, :created_by_user_id, :created_at, :updated_at)
SQL
        );
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':public_id' => $publicId,
            ':key' => (string) $validation['key'],
            ':name' => (string) $validation['name'],
            ':description' => (string) $validation['description'],
            ':status' => (string) $validation['status'],
            ':created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $policy = videochat_tenancy_fetch_governance_policy($pdo, $tenantId, $publicId);
        $sync = is_array($policy) ? videochat_tenancy_governance_policy_sync_relationships($pdo, $tenantId, $policy, $payload) : ['ok' => false, 'errors' => ['policy' => 'not_found']];
        if (!(bool) ($sync['ok'] ?? false)) {
            $pdo->rollBack();
            return $sync;
        }
        $grantSync = videochat_tenancy_governance_sync_policy_grants($pdo, $tenantId, $actorUserId, $policy);
        if (!(bool) ($grantSync['ok'] ?? false)) {
            $pdo->rollBack();
            return $grantSync;
        }
        $pdo->commit();
        return ['ok' => true, 'row' => videochat_tenancy_fetch_governance_policy($pdo, $tenantId, $publicId)];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function videochat_tenancy_update_governance_policy(PDO $pdo, int $tenantId, int $actorUserId, string $identifier, array $payload): array
{
    $existing = videochat_tenancy_fetch_governance_policy($pdo, $tenantId, $identifier);
    if (!is_array($existing)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $validation = videochat_tenancy_governance_validate_policy_payload($pdo, $tenantId, $payload, $existing);
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'errors' => $validation['errors'] ?? []];
    }
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            <<<'SQL'
UPDATE governance_policies
SET key = :key, name = :name, description = :description, status = :status, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :id
SQL
        );
        $update->execute([
            ':key' => (string) $validation['key'],
            ':name' => (string) $validation['name'],
            ':description' => (string) $validation['description'],
            ':status' => (string) $validation['status'],
            ':updated_at' => gmdate('c'),
            ':tenant_id' => $tenantId,
            ':id' => (int) ($existing['id'] ?? 0),
        ]);
        $policy = videochat_tenancy_fetch_governance_policy($pdo, $tenantId, (string) ($existing['public_id'] ?? $identifier));
        $sync = is_array($policy) ? videochat_tenancy_governance_policy_sync_relationships($pdo, $tenantId, $policy, $payload) : ['ok' => false, 'errors' => ['policy' => 'not_found']];
        if (!(bool) ($sync['ok'] ?? false)) {
            $pdo->rollBack();
            return $sync;
        }
        $grantSync = videochat_tenancy_governance_sync_policy_grants($pdo, $tenantId, $actorUserId, $policy);
        if (!(bool) ($grantSync['ok'] ?? false)) {
            $pdo->rollBack();
            return $grantSync;
        }
        $pdo->commit();
        return ['ok' => true, 'row' => videochat_tenancy_fetch_governance_policy($pdo, $tenantId, (string) ($existing['public_id'] ?? $identifier))];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function videochat_tenancy_delete_governance_policy(PDO $pdo, int $tenantId, string $identifier): array
{
    $existing = videochat_tenancy_fetch_governance_policy($pdo, $tenantId, $identifier);
    if (!is_array($existing)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $pdo->beginTransaction();
    try {
        $deleteGrants = $pdo->prepare('DELETE FROM permission_grants WHERE tenant_id = :tenant_id AND source = :source');
        $deleteGrants->execute([':tenant_id' => $tenantId, ':source' => videochat_tenancy_governance_policy_source($existing)]);
        $delete = $pdo->prepare('DELETE FROM governance_policies WHERE tenant_id = :tenant_id AND id = :id');
        $delete->execute([':tenant_id' => $tenantId, ':id' => (int) ($existing['id'] ?? 0)]);
        $pdo->commit();
        return ['ok' => true, 'row' => $existing];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function videochat_handle_governance_policy_routes(
    string $method,
    string $identifier,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): array {
    $hasIdentifier = trim($identifier) !== '';
    $allowedMethods = $hasIdentifier ? ['GET', 'PUT', 'PATCH', 'DELETE'] : ['GET', 'POST'];
    if (!in_array($method, $allowedMethods, true)) {
        return $errorResponse(405, 'method_not_allowed', 'Use a supported method for governance policies.', ['allowed_methods' => $allowedMethods]);
    }
    try {
        $pdo = $openDatabase();
        $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
        $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($tenantId <= 0 || $actorUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid tenant session is required.', ['reason' => 'invalid_tenant_context']);
        }
        if ($method === 'GET' && !$hasIdentifier) {
            $permission = videochat_tenancy_governance_policy_permission_decision($pdo, $apiAuthContext, 'read');
            if (!(bool) ($permission['ok'] ?? false)) {
                return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
            }
            $rows = videochat_tenancy_governance_policy_public_rows($pdo, $tenantId, videochat_tenancy_list_governance_policies($pdo, $tenantId));
            return $jsonResponse(200, ['status' => 'ok', 'result' => ['rows' => $rows, 'included' => ['policies' => $rows]], 'policies' => $rows, 'time' => gmdate('c')]);
        }
        if ($method === 'GET') {
            $row = videochat_tenancy_fetch_governance_policy($pdo, $tenantId, $identifier);
            if (!is_array($row)) {
                return $errorResponse(404, 'governance_resource_not_found', 'Governance resource was not found.', ['entity' => 'policies']);
            }
            $permission = videochat_tenancy_governance_policy_permission_decision($pdo, $apiAuthContext, 'read', (string) ($row['public_id'] ?? '*'));
            if (!(bool) ($permission['ok'] ?? false)) {
                return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
            }
            $rows = videochat_tenancy_governance_policy_public_rows($pdo, $tenantId, [$row]);
            return $jsonResponse(200, ['status' => 'ok', 'result' => ['row' => $rows[0] ?? null, 'included' => ['policies' => $rows]], 'time' => gmdate('c')]);
        }
        $action = $method === 'POST' ? 'create' : ($method === 'DELETE' ? 'delete' : 'update');
        $existing = $hasIdentifier ? videochat_tenancy_fetch_governance_policy($pdo, $tenantId, $identifier) : null;
        if ($hasIdentifier && !is_array($existing)) {
            return $errorResponse(404, 'governance_resource_not_found', 'Governance resource was not found.', ['entity' => 'policies']);
        }
        $permission = videochat_tenancy_governance_policy_permission_decision($pdo, $apiAuthContext, $action, (string) ($existing['public_id'] ?? '*'));
        if (!(bool) ($permission['ok'] ?? false)) {
            return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
        }
        if ($method === 'DELETE') {
            $result = videochat_tenancy_delete_governance_policy($pdo, $tenantId, $identifier);
            return $jsonResponse(200, ['status' => 'ok', 'result' => ['state' => 'deleted', 'id' => (string) ($existing['public_id'] ?? $identifier)], 'time' => gmdate('c')]);
        }
        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'governance_invalid_request_body', 'Governance payload must be a JSON object.', ['reason' => $decodeError]);
        }
        $result = $method === 'POST'
            ? videochat_tenancy_create_governance_policy($pdo, $tenantId, $actorUserId, $payload)
            : videochat_tenancy_update_governance_policy($pdo, $tenantId, $actorUserId, $identifier, $payload);
        if (!(bool) ($result['ok'] ?? false)) {
            return videochat_tenancy_governance_validation_response($errorResponse, $result);
        }
        $rows = videochat_tenancy_governance_policy_public_rows($pdo, $tenantId, [is_array($result['row'] ?? null) ? $result['row'] : []]);
        return $jsonResponse($method === 'POST' ? 201 : 200, ['status' => 'ok', 'result' => ['state' => $method === 'POST' ? 'created' : 'updated', 'row' => $rows[0] ?? null, 'included' => ['policies' => $rows]], 'time' => gmdate('c')]);
    } catch (Throwable) {
        return $errorResponse(500, 'governance_operation_failed', 'Governance operation failed.', ['reason' => 'internal_error']);
    }
}
