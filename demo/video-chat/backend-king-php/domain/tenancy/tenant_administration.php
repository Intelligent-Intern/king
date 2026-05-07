<?php

declare(strict_types=1);

require_once __DIR__ . '/../../support/tenant_context.php';
require_once __DIR__ . '/permission_grants.php';

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

function videochat_tenancy_generate_public_id(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function videochat_tenancy_governance_entity_config(string $entity): ?array
{
    return match (trim($entity)) {
        'groups' => [
            'resource_type' => 'group',
            'legacy_permission' => 'manage_groups',
            'granular_root' => 'governance.groups',
        ],
        'organizations' => [
            'resource_type' => 'organization',
            'legacy_permission' => 'manage_organizations',
            'granular_root' => 'governance.organizations',
        ],
        default => null,
    };
}
function videochat_tenancy_governance_permission_decision(
    PDO $pdo,
    array $authContext,
    string $entity,
    string $action,
    string $resourceId = '*'
): array {
    $config = videochat_tenancy_governance_entity_config($entity);
    $tenant = is_array($authContext['tenant'] ?? null) ? $authContext['tenant'] : [];
    $permissions = is_array($tenant['permissions'] ?? null) ? $tenant['permissions'] : [];
    $tenantId = (int) ($tenant['id'] ?? ($tenant['tenant_id'] ?? 0));
    $userId = (int) (($authContext['user']['id'] ?? 0));
    if (!is_array($config) || $tenantId <= 0 || $userId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_context'];
    }

    $normalizedAction = videochat_tenancy_normalize_grant_action($action);
    if ($normalizedAction === '') {
        return ['ok' => false, 'reason' => 'invalid_action'];
    }

    $granularKey = (string) $config['granular_root'] . '.' . $normalizedAction;
    $legacyPermission = (string) $config['legacy_permission'];
    $hasLegacyPermission = (bool) ($permissions['platform_admin'] ?? false)
        || (bool) ($permissions['tenant_admin'] ?? false)
        || (bool) ($permissions[$legacyPermission] ?? false)
        || (bool) ($permissions[$granularKey] ?? false)
        || ($normalizedAction === 'read' && (bool) ($permissions['governance.read'] ?? false));
    if ($hasLegacyPermission) {
        return [
            'ok' => true,
            'reason' => 'tenant_permission_alias',
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ];
    }

    $resourceType = (string) $config['resource_type'];
    $trimmedResourceId = trim($resourceId) !== '' ? trim($resourceId) : '*';
    $checks = [
        [$trimmedResourceId, $normalizedAction],
        [$trimmedResourceId, 'manage'],
        ['*', $normalizedAction],
        ['*', 'manage'],
    ];
    $seen = [];
    foreach ($checks as [$candidateResourceId, $candidateAction]) {
        $fingerprint = $candidateResourceId . ':' . $candidateAction;
        if (isset($seen[$fingerprint])) {
            continue;
        }
        $seen[$fingerprint] = true;
        $grant = videochat_tenancy_user_has_resource_permission(
            $pdo,
            $tenantId,
            $userId,
            $resourceType,
            $candidateResourceId,
            $candidateAction
        );
        if ((bool) ($grant['ok'] ?? false)) {
            return [
                'ok' => true,
                'reason' => 'resource_grant',
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'grant' => $grant['grant'] ?? null,
            ];
        }
    }

    return [
        'ok' => false,
        'reason' => 'not_granted',
        'tenant_id' => $tenantId,
        'user_id' => $userId,
    ];
}
function videochat_tenancy_governance_resource_id(array $row): string
{
    $publicId = trim((string) ($row['public_id'] ?? ''));
    return $publicId !== '' ? $publicId : (string) ((int) ($row['database_id'] ?? ($row['id'] ?? 0)));
}

function videochat_tenancy_governance_status_result(mixed $value, string $default = 'active'): array
{
    $raw = strtolower(trim((string) $value));
    if ($raw === '') {
        $raw = $default;
    }
    if (in_array($raw, ['active', 'archived'], true)) {
        return ['ok' => true, 'status' => $raw, 'error' => null];
    }
    if (in_array($raw, ['disabled', 'draft'], true)) {
        return ['ok' => true, 'status' => 'archived', 'error' => null];
    }

    return ['ok' => false, 'status' => $default, 'error' => 'expected_active_or_archived'];
}

function videochat_tenancy_first_identifier_from_value(mixed $value): string
{
    if (is_int($value) || is_float($value) || is_string($value)) {
        return trim((string) $value);
    }
    if (!is_array($value)) {
        return '';
    }
    if (array_is_list($value)) {
        return $value === [] ? '' : videochat_tenancy_first_identifier_from_value($value[0]);
    }
    foreach (['id', 'public_id', 'uuid', 'database_id', 'value'] as $key) {
        if (array_key_exists($key, $value)) {
            $identifier = videochat_tenancy_first_identifier_from_value($value[$key]);
            if ($identifier !== '') {
                return $identifier;
            }
        }
    }

    return '';
}

function videochat_tenancy_payload_identifier(array $payload, array $keys): array
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $payload)) {
            return [
                'provided' => true,
                'identifier' => videochat_tenancy_first_identifier_from_value($payload[$key]),
            ];
        }
    }

    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $relationships)) {
            return [
                'provided' => true,
                'identifier' => videochat_tenancy_first_identifier_from_value($relationships[$key]),
            ];
        }
    }

    return ['provided' => false, 'identifier' => ''];
}
function videochat_tenancy_fetch_governance_organization(PDO $pdo, int $tenantId, string $identifier): ?array
{
    $trimmed = trim($identifier);
    if ($tenantId <= 0 || $trimmed === '') {
        return null;
    }
    $numericId = ctype_digit($trimmed) ? (int) $trimmed : 0;
    $numericClause = $numericId > 0 ? ' OR organizations.id = :numeric_id' : '';
    $query = $pdo->prepare(
        <<<SQL
SELECT
    organizations.id,
    organizations.parent_organization_id,
    parent.public_id AS parent_public_id,
    organizations.public_id,
    organizations.name,
    organizations.status,
    organizations.created_at,
    organizations.updated_at
FROM organizations
LEFT JOIN organizations AS parent ON parent.id = organizations.parent_organization_id
WHERE organizations.tenant_id = :tenant_id
  AND (lower(organizations.public_id) = lower(:identifier){$numericClause})
LIMIT 1
SQL
    );
    $params = [
        ':tenant_id' => $tenantId,
        ':identifier' => $trimmed,
    ];
    if ($numericId > 0) {
        $params[':numeric_id'] = $numericId;
    }
    $query->execute($params);
    $row = $query->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? videochat_tenancy_governance_organization_payload($row) : null;
}

function videochat_tenancy_fetch_governance_group(PDO $pdo, int $tenantId, string $identifier): ?array
{
    $trimmed = trim($identifier);
    if ($tenantId <= 0 || $trimmed === '') {
        return null;
    }
    $numericId = ctype_digit($trimmed) ? (int) $trimmed : 0;
    $numericClause = $numericId > 0 ? ' OR "groups".id = :numeric_id' : '';
    $query = $pdo->prepare(
        <<<SQL
SELECT
    "groups".id,
    "groups".organization_id,
    organizations.public_id AS organization_public_id,
    "groups".public_id,
    "groups".name,
    "groups".status,
    "groups".created_at,
    "groups".updated_at
FROM "groups"
LEFT JOIN organizations ON organizations.id = "groups".organization_id
WHERE "groups".tenant_id = :tenant_id
  AND (lower("groups".public_id) = lower(:identifier){$numericClause})
LIMIT 1
SQL
    );
    $params = [
        ':tenant_id' => $tenantId,
        ':identifier' => $trimmed,
    ];
    if ($numericId > 0) {
        $params[':numeric_id'] = $numericId;
    }
    $query->execute($params);
    $row = $query->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? videochat_tenancy_governance_group_payload($row) : null;
}

function videochat_tenancy_fetch_governance_entity(PDO $pdo, string $entity, int $tenantId, string $identifier): ?array
{
    return match ($entity) {
        'groups' => videochat_tenancy_fetch_governance_group($pdo, $tenantId, $identifier),
        'organizations' => videochat_tenancy_fetch_governance_organization($pdo, $tenantId, $identifier),
        default => null,
    };
}

function videochat_tenancy_governance_organization_payload(array $row): array
{
    $publicId = trim((string) ($row['public_id'] ?? ''));
    $id = $publicId !== '' ? $publicId : (string) ((int) ($row['id'] ?? 0));
    $parentPublicId = trim((string) ($row['parent_public_id'] ?? ''));

    return [
        'id' => $id,
        'database_id' => (int) ($row['id'] ?? 0),
        'public_id' => $publicId,
        'parent_organization_id' => $parentPublicId,
        'parent_organization_database_id' => isset($row['parent_organization_id']) ? (int) $row['parent_organization_id'] : null,
        'name' => (string) ($row['name'] ?? ''),
        'key' => $publicId,
        'description' => '',
        'status' => (string) ($row['status'] ?? 'active'),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'updatedAt' => (string) ($row['updated_at'] ?? ''),
    ];
}

function videochat_tenancy_governance_group_payload(array $row): array
{
    $publicId = trim((string) ($row['public_id'] ?? ''));
    $id = $publicId !== '' ? $publicId : (string) ((int) ($row['id'] ?? 0));
    $organizationPublicId = trim((string) ($row['organization_public_id'] ?? ''));

    return [
        'id' => $id,
        'database_id' => (int) ($row['id'] ?? 0),
        'public_id' => $publicId,
        'organization_id' => $organizationPublicId,
        'organization_database_id' => isset($row['organization_id']) ? (int) $row['organization_id'] : null,
        'name' => (string) ($row['name'] ?? ''),
        'key' => $publicId,
        'description' => '',
        'status' => (string) ($row['status'] ?? 'active'),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'updatedAt' => (string) ($row['updated_at'] ?? ''),
    ];
}

function videochat_tenancy_list_governance_entities(PDO $pdo, string $entity, int $tenantId): array
{
    if ($tenantId <= 0) {
        return [];
    }
    if ($entity === 'organizations') {
        $query = $pdo->prepare(
            <<<'SQL'
SELECT
    organizations.id,
    organizations.parent_organization_id,
    parent.public_id AS parent_public_id,
    organizations.public_id,
    organizations.name,
    organizations.status,
    organizations.created_at,
    organizations.updated_at
FROM organizations
LEFT JOIN organizations AS parent ON parent.id = organizations.parent_organization_id
WHERE organizations.tenant_id = :tenant_id
ORDER BY organizations.parent_organization_id IS NOT NULL ASC, lower(organizations.name) ASC, organizations.id ASC
SQL
        );
        $query->execute([':tenant_id' => $tenantId]);

        return array_map(
            static fn (array $row): array => videochat_tenancy_governance_organization_payload($row),
            $query->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }
    if ($entity === 'groups') {
        $query = $pdo->prepare(
            <<<'SQL'
SELECT
    "groups".id,
    "groups".organization_id,
    organizations.public_id AS organization_public_id,
    "groups".public_id,
    "groups".name,
    "groups".status,
    "groups".created_at,
    "groups".updated_at
FROM "groups"
LEFT JOIN organizations ON organizations.id = "groups".organization_id
WHERE "groups".tenant_id = :tenant_id
ORDER BY lower("groups".name) ASC, "groups".id ASC
SQL
        );
        $query->execute([':tenant_id' => $tenantId]);

        return array_map(
            static fn (array $row): array => videochat_tenancy_governance_group_payload($row),
            $query->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    return [];
}

function videochat_tenancy_resolve_organization_database_id(PDO $pdo, int $tenantId, string $identifier): ?int
{
    $trimmed = trim($identifier);
    if ($trimmed === '') {
        return null;
    }
    $row = videochat_tenancy_fetch_governance_organization($pdo, $tenantId, $trimmed);
    if (!is_array($row)) {
        return -1;
    }

    return (int) ($row['database_id'] ?? 0);
}

function videochat_tenancy_organization_parent_would_cycle(PDO $pdo, int $tenantId, int $organizationId, int $parentId): bool
{
    if ($tenantId <= 0 || $organizationId <= 0 || $parentId <= 0) {
        return false;
    }
    $currentId = $parentId;
    for ($depth = 0; $depth < 100; $depth++) {
        if ($currentId === $organizationId) {
            return true;
        }
        $query = $pdo->prepare('SELECT parent_organization_id FROM organizations WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
        $query->execute([
            ':tenant_id' => $tenantId,
            ':id' => $currentId,
        ]);
        $next = $query->fetchColumn();
        if ($next === false || $next === null || (int) $next <= 0) {
            return false;
        }
        $currentId = (int) $next;
    }

    return true;
}

function videochat_tenancy_validate_governance_name_status(array $payload, ?array $existing = null): array
{
    $errors = [];
    $hasExisting = is_array($existing);
    $name = array_key_exists('name', $payload)
        ? trim((string) $payload['name'])
        : trim((string) ($existing['name'] ?? ''));
    if ($name === '') {
        $errors['name'] = 'required';
    } elseif (mb_strlen($name) > 160) {
        $errors['name'] = 'too_long';
    }

    $statusValue = array_key_exists('status', $payload) ? $payload['status'] : ($existing['status'] ?? 'active');
    $status = videochat_tenancy_governance_status_result($statusValue, $hasExisting ? (string) ($existing['status'] ?? 'active') : 'active');
    if (!(bool) ($status['ok'] ?? false)) {
        $errors['status'] = $status['error'] ?? 'invalid';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'name' => $name,
        'status' => (string) ($status['status'] ?? 'active'),
    ];
}

function videochat_tenancy_create_governance_organization(PDO $pdo, int $tenantId, int $actorUserId, array $payload): array
{
    $validation = videochat_tenancy_validate_governance_name_status($payload);
    $parent = videochat_tenancy_payload_identifier($payload, ['parent_organization_id', 'parent_organization', 'parent']);
    $parentId = null;
    if ((bool) ($parent['provided'] ?? false) && trim((string) ($parent['identifier'] ?? '')) !== '') {
        $parentId = videochat_tenancy_resolve_organization_database_id($pdo, $tenantId, (string) $parent['identifier']);
        if ($parentId === -1) {
            $validation['errors']['parent_organization'] = 'not_found';
            $validation['ok'] = false;
        }
    }
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'errors' => $validation['errors'] ?? []];
    }

    $now = gmdate('c');
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organizations(tenant_id, parent_organization_id, public_id, name, status, created_by_user_id, created_at, updated_at)
VALUES(:tenant_id, :parent_organization_id, :public_id, :name, :status, :created_by_user_id, :created_at, :updated_at)
SQL
    );
    $publicId = videochat_tenancy_generate_public_id();
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':parent_organization_id' => $parentId,
        ':public_id' => $publicId,
        ':name' => (string) $validation['name'],
        ':status' => (string) $validation['status'],
        ':created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return [
        'ok' => true,
        'row' => videochat_tenancy_fetch_governance_organization($pdo, $tenantId, $publicId),
    ];
}

function videochat_tenancy_update_governance_organization(PDO $pdo, int $tenantId, string $identifier, array $payload): array
{
    $existing = videochat_tenancy_fetch_governance_organization($pdo, $tenantId, $identifier);
    if (!is_array($existing)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $validation = videochat_tenancy_validate_governance_name_status($payload, $existing);
    $parent = videochat_tenancy_payload_identifier($payload, ['parent_organization_id', 'parent_organization', 'parent']);
    $parentId = (int) ($existing['parent_organization_database_id'] ?? 0) ?: null;
    if ((bool) ($parent['provided'] ?? false)) {
        $parentIdentifier = trim((string) ($parent['identifier'] ?? ''));
        $parentId = null;
        if ($parentIdentifier !== '') {
            $resolvedParentId = videochat_tenancy_resolve_organization_database_id($pdo, $tenantId, $parentIdentifier);
            if ($resolvedParentId === -1) {
                $validation['errors']['parent_organization'] = 'not_found';
                $validation['ok'] = false;
            } else {
                $parentId = $resolvedParentId;
            }
        }
    }
    $organizationId = (int) ($existing['database_id'] ?? 0);
    if (is_int($parentId) && $parentId > 0 && videochat_tenancy_organization_parent_would_cycle($pdo, $tenantId, $organizationId, $parentId)) {
        $validation['errors']['parent_organization'] = 'cycle';
        $validation['ok'] = false;
    }
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'errors' => $validation['errors'] ?? []];
    }

    $update = $pdo->prepare(
        <<<'SQL'
UPDATE organizations
SET parent_organization_id = :parent_organization_id,
    name = :name,
    status = :status,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND id = :id
SQL
    );
    $update->execute([
        ':parent_organization_id' => $parentId,
        ':name' => (string) $validation['name'],
        ':status' => (string) $validation['status'],
        ':updated_at' => gmdate('c'),
        ':tenant_id' => $tenantId,
        ':id' => $organizationId,
    ]);

    return [
        'ok' => true,
        'row' => videochat_tenancy_fetch_governance_organization($pdo, $tenantId, (string) ($existing['public_id'] ?? $identifier)),
    ];
}

function videochat_tenancy_delete_governance_organization(PDO $pdo, int $tenantId, string $identifier): array
{
    $existing = videochat_tenancy_fetch_governance_organization($pdo, $tenantId, $identifier);
    if (!is_array($existing)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $childCount = $pdo->prepare('SELECT COUNT(*) FROM organizations WHERE tenant_id = :tenant_id AND parent_organization_id = :id');
    $childCount->execute([
        ':tenant_id' => $tenantId,
        ':id' => (int) ($existing['database_id'] ?? 0),
    ]);
    if ((int) $childCount->fetchColumn() > 0) {
        return ['ok' => false, 'errors' => ['parent_organization' => 'has_child_organizations']];
    }

    $delete = $pdo->prepare('DELETE FROM organizations WHERE tenant_id = :tenant_id AND id = :id');
    $delete->execute([
        ':tenant_id' => $tenantId,
        ':id' => (int) ($existing['database_id'] ?? 0),
    ]);

    return ['ok' => true, 'row' => $existing];
}

function videochat_tenancy_create_governance_group(PDO $pdo, int $tenantId, int $actorUserId, array $payload): array
{
    $validation = videochat_tenancy_validate_governance_name_status($payload);
    $organization = videochat_tenancy_payload_identifier($payload, ['organization_id', 'organization']);
    $organizationId = null;
    if ((bool) ($organization['provided'] ?? false) && trim((string) ($organization['identifier'] ?? '')) !== '') {
        $organizationId = videochat_tenancy_resolve_organization_database_id($pdo, $tenantId, (string) $organization['identifier']);
        if ($organizationId === -1) {
            $validation['errors']['organization'] = 'not_found';
            $validation['ok'] = false;
        }
    }
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'errors' => $validation['errors'] ?? []];
    }

    $now = gmdate('c');
    $publicId = videochat_tenancy_generate_public_id();
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO "groups"(tenant_id, organization_id, public_id, name, status, created_by_user_id, created_at, updated_at)
VALUES(:tenant_id, :organization_id, :public_id, :name, :status, :created_by_user_id, :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':public_id' => $publicId,
        ':name' => (string) $validation['name'],
        ':status' => (string) $validation['status'],
        ':created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return [
        'ok' => true,
        'row' => videochat_tenancy_fetch_governance_group($pdo, $tenantId, $publicId),
    ];
}

function videochat_tenancy_update_governance_group(PDO $pdo, int $tenantId, string $identifier, array $payload): array
{
    $existing = videochat_tenancy_fetch_governance_group($pdo, $tenantId, $identifier);
    if (!is_array($existing)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $validation = videochat_tenancy_validate_governance_name_status($payload, $existing);
    $organization = videochat_tenancy_payload_identifier($payload, ['organization_id', 'organization']);
    $organizationId = (int) ($existing['organization_database_id'] ?? 0) ?: null;
    if ((bool) ($organization['provided'] ?? false)) {
        $organizationIdentifier = trim((string) ($organization['identifier'] ?? ''));
        $organizationId = null;
        if ($organizationIdentifier !== '') {
            $resolvedOrganizationId = videochat_tenancy_resolve_organization_database_id($pdo, $tenantId, $organizationIdentifier);
            if ($resolvedOrganizationId === -1) {
                $validation['errors']['organization'] = 'not_found';
                $validation['ok'] = false;
            } else {
                $organizationId = $resolvedOrganizationId;
            }
        }
    }
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'errors' => $validation['errors'] ?? []];
    }

    $update = $pdo->prepare(
        <<<'SQL'
UPDATE "groups"
SET organization_id = :organization_id,
    name = :name,
    status = :status,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND id = :id
SQL
    );
    $update->execute([
        ':organization_id' => $organizationId,
        ':name' => (string) $validation['name'],
        ':status' => (string) $validation['status'],
        ':updated_at' => gmdate('c'),
        ':tenant_id' => $tenantId,
        ':id' => (int) ($existing['database_id'] ?? 0),
    ]);

    return [
        'ok' => true,
        'row' => videochat_tenancy_fetch_governance_group($pdo, $tenantId, (string) ($existing['public_id'] ?? $identifier)),
    ];
}

function videochat_tenancy_delete_governance_group(PDO $pdo, int $tenantId, string $identifier): array
{
    $existing = videochat_tenancy_fetch_governance_group($pdo, $tenantId, $identifier);
    if (!is_array($existing)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }

    $delete = $pdo->prepare('DELETE FROM "groups" WHERE tenant_id = :tenant_id AND id = :id');
    $delete->execute([
        ':tenant_id' => $tenantId,
        ':id' => (int) ($existing['database_id'] ?? 0),
    ]);

    return ['ok' => true, 'row' => $existing];
}

function videochat_tenancy_create_governance_entity(PDO $pdo, string $entity, int $tenantId, int $actorUserId, array $payload): array
{
    return match ($entity) {
        'groups' => videochat_tenancy_create_governance_group($pdo, $tenantId, $actorUserId, $payload),
        'organizations' => videochat_tenancy_create_governance_organization($pdo, $tenantId, $actorUserId, $payload),
        default => ['ok' => false, 'reason' => 'unsupported_entity'],
    };
}

function videochat_tenancy_update_governance_entity(PDO $pdo, string $entity, int $tenantId, string $identifier, array $payload): array
{
    return match ($entity) {
        'groups' => videochat_tenancy_update_governance_group($pdo, $tenantId, $identifier, $payload),
        'organizations' => videochat_tenancy_update_governance_organization($pdo, $tenantId, $identifier, $payload),
        default => ['ok' => false, 'reason' => 'unsupported_entity'],
    };
}

function videochat_tenancy_delete_governance_entity(PDO $pdo, string $entity, int $tenantId, string $identifier): array
{
    return match ($entity) {
        'groups' => videochat_tenancy_delete_governance_group($pdo, $tenantId, $identifier),
        'organizations' => videochat_tenancy_delete_governance_organization($pdo, $tenantId, $identifier),
        default => ['ok' => false, 'reason' => 'unsupported_entity'],
    };
}
