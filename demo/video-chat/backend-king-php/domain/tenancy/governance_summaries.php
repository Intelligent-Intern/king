<?php

declare(strict_types=1);

require_once __DIR__ . '/governance_group_memberships.php';
require_once __DIR__ . '/governance_permission_grants.php';
require_once __DIR__ . '/governance_policies.php';
require_once __DIR__ . '/governance_portability_jobs.php';
require_once __DIR__ . '/governance_roles.php';
require_once __DIR__ . '/tenant_administration.php';

function videochat_tenancy_governance_summary_entity(string $entity): string
{
    return match (strtolower(trim($entity))) {
        'user' => 'users',
        'group' => 'groups',
        'organization' => 'organizations',
        'role' => 'roles',
        'grant' => 'grants',
        'policy' => 'policies',
        'tenant_export_import_job', 'tenant-export-import-job', 'data_portability' => 'data-portability',
        default => strtolower(trim($entity)),
    };
}

function videochat_tenancy_governance_summary_ids(mixed $ids): array
{
    if (!is_array($ids)) {
        return [];
    }
    $normalized = [];
    foreach ($ids as $id) {
        $value = trim((string) $id);
        if ($value !== '') {
            $normalized[$value] = true;
        }
        if (count($normalized) >= 100) {
            break;
        }
    }

    return array_keys($normalized);
}

function videochat_tenancy_governance_summary_alias_key(mixed $value): string
{
    return strtolower(trim((string) $value));
}

function videochat_tenancy_governance_summary_lower_ids(array $ids): array
{
    return array_values(array_unique(array_filter(
        array_map('videochat_tenancy_governance_summary_alias_key', $ids),
        static fn (string $id): bool => $id !== ''
    )));
}

function videochat_tenancy_governance_summary_numeric_ids(array $ids): array
{
    $numericIds = [];
    foreach ($ids as $id) {
        $value = trim((string) $id);
        if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
            $numericIds[(int) $value] = true;
        }
    }

    return array_keys($numericIds);
}

function videochat_tenancy_governance_summary_placeholders(array &$params, string $prefix, array $values): string
{
    $placeholders = [];
    foreach (array_values($values) as $index => $value) {
        $name = ':' . $prefix . '_' . $index;
        $placeholders[] = $name;
        $params[$name] = $value;
    }

    return implode(', ', $placeholders);
}

function videochat_tenancy_governance_summary_ordered_entries(array $ids, array $entries): array
{
    $byAlias = [];
    foreach ($entries as $entry) {
        $row = is_array($entry['row'] ?? null) ? (array) $entry['row'] : [];
        if (trim((string) ($row['id'] ?? '')) === '' || trim((string) ($row['entity_key'] ?? '')) === '') {
            continue;
        }
        foreach ((array) ($entry['aliases'] ?? []) as $alias) {
            $key = videochat_tenancy_governance_summary_alias_key($alias);
            if ($key !== '' && !isset($byAlias[$key])) {
                $byAlias[$key] = $row;
            }
        }
    }

    $ordered = [];
    $seen = [];
    foreach ($ids as $id) {
        $row = $byAlias[videochat_tenancy_governance_summary_alias_key($id)] ?? null;
        if (!is_array($row)) {
            continue;
        }
        $fingerprint = (string) ($row['entity_key'] ?? '') . ':' . (string) ($row['id'] ?? '');
        if (isset($seen[$fingerprint])) {
            continue;
        }
        $seen[$fingerprint] = true;
        $ordered[] = $row;
    }

    return $ordered;
}

function videochat_tenancy_governance_summary_permission_decision(PDO $pdo, array $authContext, string $entity): array
{
    return match ($entity) {
        'users' => videochat_tenancy_governance_user_summary_permission_decision($pdo, $authContext),
        'groups' => videochat_tenancy_governance_permission_decision($pdo, $authContext, 'groups', 'read'),
        'organizations' => videochat_tenancy_governance_permission_decision($pdo, $authContext, 'organizations', 'read'),
        'roles' => videochat_tenancy_governance_role_permission_decision($pdo, $authContext, 'read'),
        'grants' => videochat_tenancy_governance_grant_permission_decision($pdo, $authContext, 'read'),
        'policies' => videochat_tenancy_governance_policy_permission_decision($pdo, $authContext, 'read'),
        'data-portability' => videochat_tenancy_governance_portability_permission_decision($pdo, $authContext, 'read'),
        default => ['ok' => false, 'reason' => 'unsupported_entity'],
    };
}

function videochat_tenancy_governance_summary_row(string $entity, array $row): array
{
    $id = trim((string) ($row['id'] ?? ($row['public_id'] ?? ($row['key'] ?? ''))));
    $key = trim((string) ($row['key'] ?? ($row['email'] ?? $id)));
    $name = trim((string) ($row['name'] ?? ($row['display_name'] ?? ($row['email'] ?? $key))));

    return [
        'entity_key' => $entity,
        'id' => $id,
        'key' => $key !== '' ? $key : $id,
        'name' => $name !== '' ? $name : $id,
        'description' => (string) ($row['description'] ?? ''),
        'status' => (string) ($row['status'] ?? 'active'),
        'updatedAt' => (string) ($row['updatedAt'] ?? ($row['updated_at'] ?? '')),
    ];
}

function videochat_tenancy_governance_summary_user_rows(PDO $pdo, int $tenantId, array $ids): array
{
    $numericIds = videochat_tenancy_governance_summary_numeric_ids($ids);
    if ($tenantId <= 0 || $numericIds === []) {
        return [];
    }

    $params = [];
    $idSql = videochat_tenancy_governance_summary_placeholders($params, 'user_id', $numericIds);
    $tenantJoin = '';
    $tenantWhere = '';
    if (videochat_tenant_table_has_column($pdo, 'tenant_memberships', 'tenant_id')) {
        $tenantJoin = 'INNER JOIN tenant_memberships ON tenant_memberships.user_id = users.id';
        $tenantWhere = "AND tenant_memberships.tenant_id = :tenant_id AND tenant_memberships.status = 'active'";
        $params[':tenant_id'] = $tenantId;
    }
    $query = $pdo->prepare(
        <<<SQL
SELECT
    users.id,
    users.email,
    users.display_name,
    users.status,
    users.updated_at,
    roles.slug AS role
FROM users
INNER JOIN roles ON roles.id = users.role_id
{$tenantJoin}
WHERE users.id IN ({$idSql})
  {$tenantWhere}
ORDER BY lower(users.display_name) ASC, users.id ASC
SQL
    );
    $query->execute($params);
    $entries = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $summary = videochat_tenancy_governance_user_summary_row($row);
        $entries[] = ['row' => $summary, 'aliases' => [$summary['id'], $summary['key'], $summary['email'] ?? '']];
    }

    return videochat_tenancy_governance_summary_ordered_entries($ids, $entries);
}

function videochat_tenancy_governance_summary_governance_entity_rows(PDO $pdo, int $tenantId, string $entity, array $ids): array
{
    $lowerIds = videochat_tenancy_governance_summary_lower_ids($ids);
    $numericIds = videochat_tenancy_governance_summary_numeric_ids($ids);
    if ($tenantId <= 0 || ($lowerIds === [] && $numericIds === [])) {
        return [];
    }

    $params = [':tenant_id' => $tenantId];
    $conditions = [];
    if ($lowerIds !== []) {
        $column = $entity === 'groups' ? '"groups".public_id' : 'organizations.public_id';
        $conditions[] = 'lower(' . $column . ') IN (' . videochat_tenancy_governance_summary_placeholders($params, $entity . '_public_id', $lowerIds) . ')';
    }
    if ($numericIds !== []) {
        $column = $entity === 'groups' ? '"groups".id' : 'organizations.id';
        $conditions[] = $column . ' IN (' . videochat_tenancy_governance_summary_placeholders($params, $entity . '_id', $numericIds) . ')';
    }
    $where = implode(' OR ', $conditions);

    if ($entity === 'groups') {
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
  AND ({$where})
ORDER BY lower("groups".name) ASC, "groups".id ASC
SQL
        );
    } else {
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
  AND ({$where})
ORDER BY organizations.parent_organization_id IS NOT NULL ASC, lower(organizations.name) ASC, organizations.id ASC
SQL
        );
    }
    $query->execute($params);

    $entries = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $payload = $entity === 'groups'
            ? videochat_tenancy_governance_group_payload($row)
            : videochat_tenancy_governance_organization_payload($row);
        $summary = videochat_tenancy_governance_summary_row($entity, $payload);
        $entries[] = ['row' => $summary, 'aliases' => [$summary['id'], $summary['key'], $payload['database_id'] ?? '']];
    }

    return videochat_tenancy_governance_summary_ordered_entries($ids, $entries);
}

function videochat_tenancy_governance_summary_keyed_table_rows(PDO $pdo, int $tenantId, string $entity, string $table, array $ids): array
{
    $lowerIds = videochat_tenancy_governance_summary_lower_ids($ids);
    $numericIds = videochat_tenancy_governance_summary_numeric_ids($ids);
    if ($tenantId <= 0 || ($lowerIds === [] && $numericIds === [])) {
        return [];
    }

    $params = [':tenant_id' => $tenantId];
    $conditions = [];
    if ($lowerIds !== []) {
        $conditions[] = 'lower(public_id) IN (' . videochat_tenancy_governance_summary_placeholders($params, $entity . '_public_identifier', $lowerIds) . ')';
        $conditions[] = 'lower(key) IN (' . videochat_tenancy_governance_summary_placeholders($params, $entity . '_key_identifier', $lowerIds) . ')';
    }
    if ($numericIds !== []) {
        $conditions[] = 'id IN (' . videochat_tenancy_governance_summary_placeholders($params, $entity . '_id', $numericIds) . ')';
    }
    $where = implode(' OR ', $conditions);
    $query = $pdo->prepare(
        <<<SQL
SELECT id, public_id, key, name, description, status, created_at, updated_at
FROM {$table}
WHERE tenant_id = :tenant_id
  AND ({$where})
ORDER BY status = 'archived' ASC, lower(name) ASC, id ASC
SQL
    );
    $query->execute($params);

    $entries = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $publicId = trim((string) ($row['public_id'] ?? ''));
        $summary = videochat_tenancy_governance_summary_row($entity, [
            'id' => $publicId,
            'key' => trim((string) ($row['key'] ?? '')) !== '' ? (string) $row['key'] : $publicId,
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
        ]);
        $entries[] = ['row' => $summary, 'aliases' => [$summary['id'], $summary['key'], $row['id'] ?? '']];
    }

    return videochat_tenancy_governance_summary_ordered_entries($ids, $entries);
}

function videochat_tenancy_governance_summary_grant_rows(PDO $pdo, int $tenantId, array $ids): array
{
    $lowerIds = videochat_tenancy_governance_summary_lower_ids($ids);
    $numericIds = videochat_tenancy_governance_summary_numeric_ids($ids);
    if ($tenantId <= 0 || ($lowerIds === [] && $numericIds === [])) {
        return [];
    }

    $params = [':tenant_id' => $tenantId];
    $conditions = [];
    if ($lowerIds !== []) {
        $conditions[] = 'lower(public_id) IN (' . videochat_tenancy_governance_summary_placeholders($params, 'grant_public_id', $lowerIds) . ')';
    }
    if ($numericIds !== []) {
        $conditions[] = 'id IN (' . videochat_tenancy_governance_summary_placeholders($params, 'grant_id', $numericIds) . ')';
    }
    $where = implode(' OR ', $conditions);
    $query = $pdo->prepare(
        <<<SQL
SELECT *
FROM permission_grants
WHERE tenant_id = :tenant_id
  AND ({$where})
ORDER BY revoked_at IS NOT NULL ASC, updated_at DESC, id DESC
SQL
    );
    $query->execute($params);

    $entries = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $row = videochat_tenancy_governance_ensure_grant_public_id($pdo, $tenantId, $row);
        $publicId = trim((string) ($row['public_id'] ?? ''));
        $permissionKey = trim((string) ($row['permission_key'] ?? ''));
        if ($permissionKey === '') {
            $permissionKey = (string) ($row['resource_type'] ?? 'resource') . '.' . (string) ($row['action'] ?? 'read');
        }
        $name = trim((string) ($row['label'] ?? ''));
        $summary = videochat_tenancy_governance_summary_row('grants', [
            'id' => $publicId,
            'key' => $permissionKey,
            'name' => $name !== '' ? $name : $permissionKey,
            'description' => (string) ($row['description'] ?? ''),
            'status' => videochat_tenancy_governance_grant_status($row),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
        ]);
        $entries[] = ['row' => $summary, 'aliases' => [$summary['id'], $row['id'] ?? '']];
    }

    return videochat_tenancy_governance_summary_ordered_entries($ids, $entries);
}

function videochat_tenancy_governance_summary_portability_rows(PDO $pdo, int $tenantId, array $ids): array
{
    $lowerIds = videochat_tenancy_governance_summary_lower_ids($ids);
    if ($tenantId <= 0 || $lowerIds === []) {
        return [];
    }

    $entries = [];
    foreach (['tenant_export_jobs' => 'export', 'tenant_import_jobs' => 'import'] as $table => $jobType) {
        $params = [':tenant_id' => $tenantId];
        $idSql = videochat_tenancy_governance_summary_placeholders($params, $jobType . '_job_id', $lowerIds);
        $query = $pdo->prepare(
            <<<SQL
SELECT id, status, failure_reason, updated_at, created_at
FROM {$table}
WHERE tenant_id = :tenant_id
  AND lower(id) IN ({$idSql})
ORDER BY updated_at DESC, id ASC
SQL
        );
        $query->execute($params);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = trim((string) ($row['id'] ?? ''));
            $status = (string) ($row['status'] ?? 'queued');
            $summary = videochat_tenancy_governance_summary_row('data-portability', [
                'id' => $id,
                'key' => $id,
                'name' => ucfirst($jobType) . ' ' . $status,
                'description' => (string) ($row['failure_reason'] ?? ''),
                'status' => $status,
                'updatedAt' => (string) ($row['updated_at'] ?? ''),
            ]);
            $entries[] = ['row' => $summary, 'aliases' => [$id]];
        }
    }

    return videochat_tenancy_governance_summary_ordered_entries($ids, $entries);
}

function videochat_tenancy_governance_summary_rows(PDO $pdo, int $tenantId, string $entity, array $ids): array
{
    return match ($entity) {
        'users' => videochat_tenancy_governance_summary_user_rows($pdo, $tenantId, $ids),
        'groups', 'organizations' => videochat_tenancy_governance_summary_governance_entity_rows($pdo, $tenantId, $entity, $ids),
        'roles' => videochat_tenancy_governance_summary_keyed_table_rows($pdo, $tenantId, 'roles', 'governance_roles', $ids),
        'grants' => videochat_tenancy_governance_summary_grant_rows($pdo, $tenantId, $ids),
        'policies' => videochat_tenancy_governance_summary_keyed_table_rows($pdo, $tenantId, 'policies', 'governance_policies', $ids),
        'data-portability' => videochat_tenancy_governance_summary_portability_rows($pdo, $tenantId, $ids),
        default => [],
    };
}

function videochat_tenancy_governance_summary_first_row(array $rows): ?array
{
    $first = array_values($rows)[0] ?? null;
    return is_array($first) ? $first : null;
}

function videochat_tenancy_governance_summary_requests(array $payload): array
{
    $rawRequests = is_array($payload['requests'] ?? null) ? $payload['requests'] : [$payload];
    $requests = [];
    foreach ($rawRequests as $request) {
        if (!is_array($request)) {
            continue;
        }
        $entity = videochat_tenancy_governance_summary_entity((string) ($request['entity_key'] ?? $request['entity'] ?? ''));
        $ids = videochat_tenancy_governance_summary_ids($request['ids'] ?? []);
        if ($entity !== '' && $ids !== []) {
            $requests[] = ['entity_key' => $entity, 'ids' => $ids];
        }
    }

    return $requests;
}

function videochat_handle_governance_summary_routes(
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): array {
    if ($method !== 'POST') {
        return $errorResponse(405, 'method_not_allowed', 'Use POST for governance summaries.', [
            'allowed_methods' => ['POST'],
        ]);
    }

    [$payload, $decodeError] = $decodeJsonBody($request);
    if (!is_array($payload)) {
        return $errorResponse(400, 'governance_invalid_request_body', 'Governance payload must be a JSON object.', ['reason' => $decodeError]);
    }
    $requests = videochat_tenancy_governance_summary_requests($payload);
    if ($requests === []) {
        return $errorResponse(422, 'governance_validation_failed', 'Governance payload failed validation.', ['fields' => ['requests' => 'required']]);
    }

    try {
        $pdo = $openDatabase();
        $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
        if ($tenantId <= 0 || (int) (($apiAuthContext['user']['id'] ?? 0)) <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid tenant session is required.', ['reason' => 'invalid_tenant_context']);
        }

        $included = [];
        foreach ($requests as $summaryRequest) {
            $entity = (string) $summaryRequest['entity_key'];
            $permission = videochat_tenancy_governance_summary_permission_decision($pdo, $apiAuthContext, $entity);
            if (!(bool) ($permission['ok'] ?? false)) {
                return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
            }
            $included[$entity] = videochat_tenancy_governance_summary_rows($pdo, $tenantId, $entity, (array) $summaryRequest['ids']);
        }

        return $jsonResponse(200, ['status' => 'ok', 'result' => ['included' => $included], 'included' => $included, 'time' => gmdate('c')]);
    } catch (Throwable) {
        return $errorResponse(500, 'governance_operation_failed', 'Governance operation failed.', ['reason' => 'internal_error']);
    }
}
