<?php

declare(strict_types=1);

require_once __DIR__ . '/tenant_administration.php';
require_once __DIR__ . '/governance_group_memberships.php';
require_once __DIR__ . '/governance_permission_grants.php';

function videochat_tenancy_governance_role_permission_decision(
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
        || (bool) ($permissions['governance.roles.' . $normalizedAction] ?? false)
        || ($normalizedAction === 'read' && (bool) ($permissions['governance.read'] ?? false))
    ) {
        return ['ok' => true, 'reason' => 'tenant_permission_alias'];
    }

    $resource = trim($resourceId) !== '' ? trim($resourceId) : '*';
    foreach ([[$resource, $normalizedAction], [$resource, 'manage'], ['*', $normalizedAction], ['*', 'manage']] as [$candidateResource, $candidateAction]) {
        $grant = videochat_tenancy_user_has_resource_permission($pdo, $tenantId, $userId, 'role', $candidateResource, $candidateAction);
        if ((bool) ($grant['ok'] ?? false)) {
            return ['ok' => true, 'reason' => 'resource_grant', 'grant' => $grant['grant'] ?? null];
        }
    }

    return ['ok' => false, 'reason' => 'not_granted'];
}

function videochat_tenancy_governance_role_values(array $payload, string $key): array
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    $values = array_key_exists($key, $relationships) ? $relationships[$key] : ($payload[$key] ?? []);
    return is_array($values) ? $values : [];
}

function videochat_tenancy_governance_role_has_relation(array $payload, string $key): bool
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    return array_key_exists($key, $relationships) || array_key_exists($key, $payload);
}

function videochat_tenancy_governance_validate_role_payload(PDO $pdo, int $tenantId, array $payload, ?array $existing = null): array
{
    $validation = videochat_tenancy_validate_governance_name_status($payload, $existing);
    $key = array_key_exists('key', $payload) ? trim((string) $payload['key']) : trim((string) ($existing['key'] ?? ''));
    $description = array_key_exists('description', $payload)
        ? trim((string) $payload['description'])
        : trim((string) ($existing['description'] ?? ''));
    if ($key !== '' && (mb_strlen($key) > 120 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1)) {
        $validation['errors']['key'] = 'invalid';
        $validation['ok'] = false;
    }
    if ($key !== '') {
        $query = $pdo->prepare(
            'SELECT id FROM governance_roles WHERE tenant_id = :tenant_id AND lower(key) = lower(:key) AND id <> :except_id LIMIT 1'
        );
        $query->execute([':tenant_id' => $tenantId, ':key' => $key, ':except_id' => (int) ($existing['id'] ?? 0)]);
        if ($query->fetchColumn() !== false) {
            $validation['errors']['key'] = 'duplicate';
            $validation['ok'] = false;
        }
    }
    if (mb_strlen($description) > 2000) {
        $validation['errors']['description'] = 'too_long';
        $validation['ok'] = false;
    }

    return [
        ...$validation,
        'key' => $key,
        'description' => $description,
    ];
}

function videochat_tenancy_governance_role_permissions(array $payload): array
{
    $permissions = [];
    foreach (videochat_tenancy_governance_role_values($payload, 'permissions') as $value) {
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

function videochat_tenancy_governance_role_modules(array $payload): array
{
    $modules = [];
    foreach (videochat_tenancy_governance_role_values($payload, 'modules') as $value) {
        $moduleKey = '';
        if (is_scalar($value)) {
            $moduleKey = trim((string) $value);
        } elseif (is_array($value)) {
            $moduleKey = videochat_tenancy_governance_relation_text($value, ['key', 'id', 'value', 'name']);
        }
        $moduleKey = preg_replace('/^module:/', '', $moduleKey);
        if (!is_string($moduleKey) || $moduleKey === '' || preg_match('/^[A-Za-z0-9_.:-]+$/', $moduleKey) !== 1) {
            return ['ok' => false, 'errors' => ['modules' => 'invalid_module']];
        }
        $modules[$moduleKey] = $moduleKey;
    }
    return ['ok' => true, 'modules' => array_values($modules)];
}

function videochat_tenancy_fetch_governance_role(PDO $pdo, int $tenantId, string $identifier): ?array
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
FROM governance_roles
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

function videochat_tenancy_list_governance_roles(PDO $pdo, int $tenantId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT *
FROM governance_roles
WHERE tenant_id = :tenant_id
ORDER BY status = 'archived' ASC, lower(name) ASC, id ASC
SQL
    );
    $query->execute([':tenant_id' => $tenantId]);
    return $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function videochat_tenancy_governance_role_permission_rows(PDO $pdo, int $tenantId, array $roleIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $roleIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $params = [':tenant_id' => $tenantId];
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $name = ':role_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(sprintf(
        'SELECT role_id, permission_key FROM governance_role_permissions WHERE tenant_id = :tenant_id AND role_id IN (%s) ORDER BY permission_key ASC',
        implode(', ', $placeholders)
    ));
    $query->execute($params);
    $rows = array_fill_keys($ids, []);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $permissionKey = (string) ($row['permission_key'] ?? '');
        $rows[(int) ($row['role_id'] ?? 0)][] = [
            'entity_key' => 'permissions',
            'id' => 'permission:governance:' . $permissionKey,
            'key' => $permissionKey,
            'name' => $permissionKey,
            'status' => 'active',
        ];
    }
    return $rows;
}

function videochat_tenancy_governance_role_module_rows(PDO $pdo, int $tenantId, array $roleIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $roleIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $params = [':tenant_id' => $tenantId];
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $name = ':role_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(sprintf(
        'SELECT role_id, module_key FROM governance_role_modules WHERE tenant_id = :tenant_id AND role_id IN (%s) ORDER BY module_key ASC',
        implode(', ', $placeholders)
    ));
    $query->execute($params);
    $rows = array_fill_keys($ids, []);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $moduleKey = (string) ($row['module_key'] ?? '');
        $rows[(int) ($row['role_id'] ?? 0)][] = [
            'entity_key' => 'modules',
            'id' => 'module:' . $moduleKey,
            'key' => $moduleKey,
            'name' => $moduleKey,
            'status' => 'active',
        ];
    }
    return $rows;
}

function videochat_tenancy_governance_role_public_rows(PDO $pdo, int $tenantId, array $rows): array
{
    $roleIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
    $permissions = videochat_tenancy_governance_role_permission_rows($pdo, $tenantId, $roleIds);
    $modules = videochat_tenancy_governance_role_module_rows($pdo, $tenantId, $roleIds);
    return array_map(static function (array $row) use ($permissions, $modules): array {
        $roleId = (int) ($row['id'] ?? 0);
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
                'permissions' => $permissions[$roleId] ?? [],
                'modules' => $modules[$roleId] ?? [],
            ],
        ];
    }, $rows);
}

function videochat_tenancy_governance_role_replace_permissions(PDO $pdo, int $tenantId, int $roleId, array $permissions): void
{
    $delete = $pdo->prepare('DELETE FROM governance_role_permissions WHERE tenant_id = :tenant_id AND role_id = :role_id');
    $delete->execute([':tenant_id' => $tenantId, ':role_id' => $roleId]);
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT OR IGNORE INTO governance_role_permissions(tenant_id, role_id, permission_key, resource_type, action)
VALUES(:tenant_id, :role_id, :permission_key, :resource_type, :action)
SQL
    );
    foreach ($permissions as $permission) {
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':role_id' => $roleId,
            ':permission_key' => (string) $permission['permission_key'],
            ':resource_type' => (string) $permission['resource_type'],
            ':action' => (string) $permission['action'],
        ]);
    }
}

function videochat_tenancy_governance_role_replace_modules(PDO $pdo, int $tenantId, int $roleId, array $modules): void
{
    $delete = $pdo->prepare('DELETE FROM governance_role_modules WHERE tenant_id = :tenant_id AND role_id = :role_id');
    $delete->execute([':tenant_id' => $tenantId, ':role_id' => $roleId]);
    $insert = $pdo->prepare('INSERT OR IGNORE INTO governance_role_modules(tenant_id, role_id, module_key) VALUES(:tenant_id, :role_id, :module_key)');
    foreach ($modules as $moduleKey) {
        $insert->execute([':tenant_id' => $tenantId, ':role_id' => $roleId, ':module_key' => (string) $moduleKey]);
    }
}

function videochat_tenancy_governance_role_sync_relationships(PDO $pdo, int $tenantId, array $role, array $payload): array
{
    $roleId = (int) ($role['id'] ?? 0);
    if ($roleId <= 0) {
        return ['ok' => false, 'errors' => ['role' => 'not_found']];
    }
    if (videochat_tenancy_governance_role_has_relation($payload, 'permissions')) {
        $permissions = videochat_tenancy_governance_role_permissions($payload);
        if (!(bool) ($permissions['ok'] ?? false)) {
            return $permissions;
        }
        videochat_tenancy_governance_role_replace_permissions($pdo, $tenantId, $roleId, (array) $permissions['permissions']);
    }
    if (videochat_tenancy_governance_role_has_relation($payload, 'modules')) {
        $modules = videochat_tenancy_governance_role_modules($payload);
        if (!(bool) ($modules['ok'] ?? false)) {
            return $modules;
        }
        videochat_tenancy_governance_role_replace_modules($pdo, $tenantId, $roleId, (array) $modules['modules']);
    }
    return ['ok' => true];
}

function videochat_tenancy_create_governance_role(PDO $pdo, int $tenantId, int $actorUserId, array $payload): array
{
    $validation = videochat_tenancy_governance_validate_role_payload($pdo, $tenantId, $payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'errors' => $validation['errors'] ?? []];
    }
    $pdo->beginTransaction();
    try {
        $now = gmdate('c');
        $publicId = videochat_tenancy_generate_public_id();
        $insert = $pdo->prepare(
            <<<'SQL'
INSERT INTO governance_roles(tenant_id, public_id, key, name, description, status, created_by_user_id, created_at, updated_at)
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
        $role = videochat_tenancy_fetch_governance_role($pdo, $tenantId, $publicId);
        $sync = is_array($role) ? videochat_tenancy_governance_role_sync_relationships($pdo, $tenantId, $role, $payload) : ['ok' => false, 'errors' => ['role' => 'not_found']];
        if (!(bool) ($sync['ok'] ?? false)) {
            $pdo->rollBack();
            return $sync;
        }
        $pdo->commit();
        return ['ok' => true, 'row' => videochat_tenancy_fetch_governance_role($pdo, $tenantId, $publicId)];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function videochat_tenancy_update_governance_role(PDO $pdo, int $tenantId, string $identifier, array $payload): array
{
    $existing = videochat_tenancy_fetch_governance_role($pdo, $tenantId, $identifier);
    if (!is_array($existing)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $validation = videochat_tenancy_governance_validate_role_payload($pdo, $tenantId, $payload, $existing);
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'errors' => $validation['errors'] ?? []];
    }
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            <<<'SQL'
UPDATE governance_roles
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
        $role = videochat_tenancy_fetch_governance_role($pdo, $tenantId, (string) ($existing['public_id'] ?? $identifier));
        $sync = is_array($role) ? videochat_tenancy_governance_role_sync_relationships($pdo, $tenantId, $role, $payload) : ['ok' => false, 'errors' => ['role' => 'not_found']];
        if (!(bool) ($sync['ok'] ?? false)) {
            $pdo->rollBack();
            return $sync;
        }
        $pdo->commit();
        return ['ok' => true, 'row' => videochat_tenancy_fetch_governance_role($pdo, $tenantId, (string) ($existing['public_id'] ?? $identifier))];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function videochat_tenancy_delete_governance_role(PDO $pdo, int $tenantId, string $identifier): array
{
    $existing = videochat_tenancy_fetch_governance_role($pdo, $tenantId, $identifier);
    if (!is_array($existing)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $delete = $pdo->prepare('DELETE FROM governance_roles WHERE tenant_id = :tenant_id AND id = :id');
    $delete->execute([':tenant_id' => $tenantId, ':id' => (int) ($existing['id'] ?? 0)]);
    return ['ok' => true, 'row' => $existing];
}

function videochat_handle_governance_role_routes(
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
        return $errorResponse(405, 'method_not_allowed', 'Use a supported method for governance roles.', ['allowed_methods' => $allowedMethods]);
    }
    try {
        $pdo = $openDatabase();
        $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
        $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($tenantId <= 0 || $actorUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid tenant session is required.', ['reason' => 'invalid_tenant_context']);
        }
        if ($method === 'GET' && !$hasIdentifier) {
            $permission = videochat_tenancy_governance_role_permission_decision($pdo, $apiAuthContext, 'read');
            if (!(bool) ($permission['ok'] ?? false)) {
                return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
            }
            $rows = videochat_tenancy_governance_role_public_rows($pdo, $tenantId, videochat_tenancy_list_governance_roles($pdo, $tenantId));
            return $jsonResponse(200, ['status' => 'ok', 'result' => ['rows' => $rows, 'included' => ['roles' => $rows]], 'roles' => $rows, 'time' => gmdate('c')]);
        }
        if ($method === 'GET') {
            $row = videochat_tenancy_fetch_governance_role($pdo, $tenantId, $identifier);
            if (!is_array($row)) {
                return $errorResponse(404, 'governance_resource_not_found', 'Governance resource was not found.', ['entity' => 'roles']);
            }
            $permission = videochat_tenancy_governance_role_permission_decision($pdo, $apiAuthContext, 'read', (string) ($row['public_id'] ?? '*'));
            if (!(bool) ($permission['ok'] ?? false)) {
                return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
            }
            $rows = videochat_tenancy_governance_role_public_rows($pdo, $tenantId, [$row]);
            return $jsonResponse(200, ['status' => 'ok', 'result' => ['row' => $rows[0] ?? null, 'included' => ['roles' => $rows]], 'time' => gmdate('c')]);
        }

        $action = $method === 'POST' ? 'create' : ($method === 'DELETE' ? 'delete' : 'update');
        $existing = $hasIdentifier ? videochat_tenancy_fetch_governance_role($pdo, $tenantId, $identifier) : null;
        if ($hasIdentifier && !is_array($existing)) {
            return $errorResponse(404, 'governance_resource_not_found', 'Governance resource was not found.', ['entity' => 'roles']);
        }
        $permission = videochat_tenancy_governance_role_permission_decision($pdo, $apiAuthContext, $action, (string) ($existing['public_id'] ?? '*'));
        if (!(bool) ($permission['ok'] ?? false)) {
            return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
        }
        if ($method === 'DELETE') {
            videochat_tenancy_delete_governance_role($pdo, $tenantId, $identifier);
            return $jsonResponse(200, ['status' => 'ok', 'result' => ['state' => 'deleted', 'id' => (string) ($existing['public_id'] ?? $identifier)], 'time' => gmdate('c')]);
        }
        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'governance_invalid_request_body', 'Governance payload must be a JSON object.', ['reason' => $decodeError]);
        }
        $result = $method === 'POST'
            ? videochat_tenancy_create_governance_role($pdo, $tenantId, $actorUserId, $payload)
            : videochat_tenancy_update_governance_role($pdo, $tenantId, $identifier, $payload);
        if (!(bool) ($result['ok'] ?? false)) {
            return videochat_tenancy_governance_validation_response($errorResponse, $result);
        }
        $rows = videochat_tenancy_governance_role_public_rows($pdo, $tenantId, [is_array($result['row'] ?? null) ? $result['row'] : []]);
        return $jsonResponse($method === 'POST' ? 201 : 200, ['status' => 'ok', 'result' => ['state' => $method === 'POST' ? 'created' : 'updated', 'row' => $rows[0] ?? null, 'included' => ['roles' => $rows]], 'time' => gmdate('c')]);
    } catch (Throwable) {
        return $errorResponse(500, 'governance_operation_failed', 'Governance operation failed.', ['reason' => 'internal_error']);
    }
}
