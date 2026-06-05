<?php

declare(strict_types=1);

require_once __DIR__ . '/governance_permission_grants.php';
require_once __DIR__ . '/governance_policies.php';

function videochat_tenancy_governance_compliance_permission_decision(
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
        || (bool) ($permissions['governance.compliance.' . $normalizedAction] ?? false)
        || ($normalizedAction === 'read' && (bool) ($permissions['governance.read'] ?? false))
    ) {
        return ['ok' => true, 'reason' => 'tenant_permission_alias'];
    }

    $resource = trim($resourceId) !== '' ? trim($resourceId) : '*';
    foreach ([[$resource, $normalizedAction], [$resource, 'manage'], ['*', $normalizedAction], ['*', 'manage']] as [$candidateResource, $candidateAction]) {
        $grant = videochat_tenancy_user_has_resource_permission(
            $pdo,
            $tenantId,
            $userId,
            'compliance_rule',
            $candidateResource,
            $candidateAction
        );
        if ((bool) ($grant['ok'] ?? false)) {
            return ['ok' => true, 'reason' => 'resource_grant', 'grant' => $grant['grant'] ?? null];
        }
    }

    return ['ok' => false, 'reason' => 'not_granted'];
}

function videochat_tenancy_governance_compliance_values(array $payload, string $key): array
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    $values = array_key_exists($key, $relationships) ? $relationships[$key] : ($payload[$key] ?? []);
    return is_array($values) ? $values : [];
}

function videochat_tenancy_governance_compliance_has_relation(array $payload, string $key): bool
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    return array_key_exists($key, $relationships) || array_key_exists($key, $payload);
}

function videochat_tenancy_governance_compliance_status(mixed $value, string $default = 'active'): array
{
    $status = strtolower(trim((string) $value));
    if ($status === '') {
        $status = $default;
    }
    if (in_array($status, ['active', 'archived', 'draft', 'disabled'], true)) {
        return ['ok' => true, 'status' => $status, 'error' => null];
    }

    return ['ok' => false, 'status' => $default, 'error' => 'expected_compliance_status'];
}

function videochat_tenancy_governance_compliance_validate_key(PDO $pdo, int $tenantId, string $key, int $exceptId = 0): ?string
{
    if ($key === '') {
        return null;
    }
    if (mb_strlen($key) > 120 || preg_match('/^[A-Za-z0-9._:-]+$/', $key) !== 1) {
        return 'invalid';
    }

    $query = $pdo->prepare(
        'SELECT id FROM governance_compliance_rules WHERE tenant_id = :tenant_id AND lower(key) = lower(:key) AND id <> :except_id LIMIT 1'
    );
    $query->execute([':tenant_id' => $tenantId, ':key' => $key, ':except_id' => $exceptId]);
    return $query->fetchColumn() === false ? null : 'duplicate';
}

function videochat_tenancy_governance_validate_compliance_payload(PDO $pdo, int $tenantId, array $payload, ?array $existing = null): array
{
    $errors = [];
    $name = array_key_exists('name', $payload)
        ? trim((string) $payload['name'])
        : trim((string) ($existing['name'] ?? ''));
    if ($name === '') {
        $errors['name'] = 'required';
    } elseif (mb_strlen($name) > 160) {
        $errors['name'] = 'too_long';
    }

    $key = array_key_exists('key', $payload) ? trim((string) $payload['key']) : trim((string) ($existing['key'] ?? ''));
    $keyError = videochat_tenancy_governance_compliance_validate_key($pdo, $tenantId, $key, (int) ($existing['id'] ?? 0));
    if ($keyError !== null) {
        $errors['key'] = $keyError;
    }

    $severity = strtolower(trim((string) (array_key_exists('severity', $payload) ? $payload['severity'] : ($existing['severity'] ?? 'medium'))));
    if ($severity === '') {
        $severity = 'medium';
    }
    if (!in_array($severity, ['low', 'medium', 'high'], true)) {
        $errors['severity'] = 'invalid';
    }

    $status = videochat_tenancy_governance_compliance_status(
        array_key_exists('status', $payload) ? $payload['status'] : ($existing['status'] ?? 'active'),
        (string) ($existing['status'] ?? 'active')
    );
    if (!(bool) ($status['ok'] ?? false)) {
        $errors['status'] = (string) ($status['error'] ?? 'invalid');
    }

    $description = array_key_exists('description', $payload)
        ? trim((string) $payload['description'])
        : trim((string) ($existing['description'] ?? ''));
    if (mb_strlen($description) > 2000) {
        $errors['description'] = 'too_long';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'key' => $key,
        'name' => $name,
        'severity' => $severity,
        'status' => (string) ($status['status'] ?? 'active'),
        'description' => $description,
    ];
}

function videochat_tenancy_governance_compliance_module_keys(array $payload): array
{
    $keys = [];
    foreach (videochat_tenancy_governance_compliance_values($payload, 'modules') as $value) {
        $moduleKey = '';
        if (is_scalar($value)) {
            $moduleKey = trim((string) $value);
        } elseif (is_array($value)) {
            $moduleKey = videochat_tenancy_governance_relation_text($value, ['key', 'id', 'value', 'name']);
        }
        $moduleKey = (string) preg_replace('/^module:/', '', $moduleKey);
        if ($moduleKey === '' || preg_match('/^[A-Za-z0-9_.:-]+$/', $moduleKey) !== 1) {
            return ['ok' => false, 'errors' => ['modules' => 'invalid_module']];
        }
        $keys[$moduleKey] = $moduleKey;
    }

    return ['ok' => true, 'modules' => array_values($keys)];
}

function videochat_tenancy_governance_compliance_policy_ids(PDO $pdo, int $tenantId, array $payload): array
{
    $ids = [];
    foreach (videochat_tenancy_governance_compliance_values($payload, 'policies') as $value) {
        $identifier = is_array($value)
            ? videochat_tenancy_governance_relation_text($value, ['id', 'key', 'value', 'name'])
            : trim((string) $value);
        $policy = videochat_tenancy_fetch_governance_policy($pdo, $tenantId, $identifier);
        if (!is_array($policy)) {
            return ['ok' => false, 'errors' => ['policies' => 'not_found']];
        }
        $ids[(int) ($policy['id'] ?? 0)] = true;
    }

    return ['ok' => true, 'ids' => array_keys($ids)];
}

function videochat_tenancy_fetch_governance_compliance_rule(PDO $pdo, int $tenantId, string $identifier): ?array
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
FROM governance_compliance_rules
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

function videochat_tenancy_list_governance_compliance_rules(PDO $pdo, int $tenantId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT *
FROM governance_compliance_rules
WHERE tenant_id = :tenant_id
ORDER BY
    CASE status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 WHEN 'disabled' THEN 2 ELSE 3 END,
    CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END,
    lower(name) ASC,
    id ASC
SQL
    );
    $query->execute([':tenant_id' => $tenantId]);
    return $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function videochat_tenancy_governance_compliance_module_rows(PDO $pdo, int $tenantId, array $ruleIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ruleIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $params = [':tenant_id' => $tenantId];
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $name = ':rule_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(sprintf(
        'SELECT rule_id, module_key FROM governance_compliance_rule_modules WHERE tenant_id = :tenant_id AND rule_id IN (%s) ORDER BY module_key ASC',
        implode(', ', $placeholders)
    ));
    $query->execute($params);

    $rows = array_fill_keys($ids, []);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $moduleKey = (string) ($row['module_key'] ?? '');
        $rows[(int) ($row['rule_id'] ?? 0)][] = [
            'entity_key' => 'modules',
            'id' => 'module:' . $moduleKey,
            'key' => $moduleKey,
            'name' => $moduleKey,
            'status' => 'active',
        ];
    }

    return $rows;
}

function videochat_tenancy_governance_compliance_policy_rows(PDO $pdo, int $tenantId, array $ruleIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ruleIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }
    $params = [':tenant_id' => $tenantId];
    $placeholders = [];
    foreach ($ids as $index => $id) {
        $name = ':rule_id_' . $index;
        $placeholders[] = $name;
        $params[$name] = $id;
    }
    $query = $pdo->prepare(sprintf(
        'SELECT rule_id, governance_policies.public_id, governance_policies.key, governance_policies.name, governance_policies.status FROM governance_compliance_rule_policies INNER JOIN governance_policies ON governance_policies.id = governance_compliance_rule_policies.policy_id WHERE governance_compliance_rule_policies.tenant_id = :tenant_id AND rule_id IN (%s) ORDER BY lower(governance_policies.name) ASC',
        implode(', ', $placeholders)
    ));
    $query->execute($params);

    $rows = array_fill_keys($ids, []);
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $publicId = (string) ($row['public_id'] ?? '');
        $rows[(int) ($row['rule_id'] ?? 0)][] = [
            'entity_key' => 'policies',
            'id' => $publicId,
            'key' => trim((string) ($row['key'] ?? '')) !== '' ? (string) $row['key'] : $publicId,
            'name' => (string) ($row['name'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
        ];
    }

    return $rows;
}

function videochat_tenancy_governance_compliance_public_rows(PDO $pdo, int $tenantId, array $rows): array
{
    $ruleIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
    $modules = videochat_tenancy_governance_compliance_module_rows($pdo, $tenantId, $ruleIds);
    $policies = videochat_tenancy_governance_compliance_policy_rows($pdo, $tenantId, $ruleIds);

    return array_map(static function (array $row) use ($modules, $policies): array {
        $ruleId = (int) ($row['id'] ?? 0);
        $publicId = (string) ($row['public_id'] ?? '');
        return [
            'id' => $publicId,
            'key' => trim((string) ($row['key'] ?? '')) !== '' ? (string) $row['key'] : $publicId,
            'name' => (string) ($row['name'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'severity' => (string) ($row['severity'] ?? 'medium'),
            'status' => (string) ($row['status'] ?? 'active'),
            'updatedAt' => (string) ($row['updated_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'relationships' => [
                'modules' => $modules[$ruleId] ?? [],
                'policies' => $policies[$ruleId] ?? [],
            ],
        ];
    }, $rows);
}

function videochat_tenancy_governance_compliance_replace_modules(PDO $pdo, int $tenantId, int $ruleId, array $modules): void
{
    $delete = $pdo->prepare('DELETE FROM governance_compliance_rule_modules WHERE tenant_id = :tenant_id AND rule_id = :rule_id');
    $delete->execute([':tenant_id' => $tenantId, ':rule_id' => $ruleId]);
    $insert = $pdo->prepare(
        'INSERT OR IGNORE INTO governance_compliance_rule_modules(tenant_id, rule_id, module_key) VALUES(:tenant_id, :rule_id, :module_key)'
    );
    foreach ($modules as $moduleKey) {
        $insert->execute([':tenant_id' => $tenantId, ':rule_id' => $ruleId, ':module_key' => (string) $moduleKey]);
    }
}

function videochat_tenancy_governance_compliance_replace_policies(PDO $pdo, int $tenantId, int $ruleId, array $policyIds): void
{
    $delete = $pdo->prepare('DELETE FROM governance_compliance_rule_policies WHERE tenant_id = :tenant_id AND rule_id = :rule_id');
    $delete->execute([':tenant_id' => $tenantId, ':rule_id' => $ruleId]);
    $insert = $pdo->prepare(
        'INSERT OR IGNORE INTO governance_compliance_rule_policies(tenant_id, rule_id, policy_id) VALUES(:tenant_id, :rule_id, :policy_id)'
    );
    foreach ($policyIds as $policyId) {
        $insert->execute([':tenant_id' => $tenantId, ':rule_id' => $ruleId, ':policy_id' => (int) $policyId]);
    }
}

function videochat_tenancy_governance_compliance_sync_relationships(PDO $pdo, int $tenantId, array $rule, array $payload): array
{
    $ruleId = (int) ($rule['id'] ?? 0);
    if ($ruleId <= 0) {
        return ['ok' => false, 'errors' => ['compliance' => 'not_found']];
    }
    if (videochat_tenancy_governance_compliance_has_relation($payload, 'modules')) {
        $modules = videochat_tenancy_governance_compliance_module_keys($payload);
        if (!(bool) ($modules['ok'] ?? false)) {
            return $modules;
        }
        videochat_tenancy_governance_compliance_replace_modules($pdo, $tenantId, $ruleId, (array) $modules['modules']);
    }
    if (videochat_tenancy_governance_compliance_has_relation($payload, 'policies')) {
        $policies = videochat_tenancy_governance_compliance_policy_ids($pdo, $tenantId, $payload);
        if (!(bool) ($policies['ok'] ?? false)) {
            return $policies;
        }
        videochat_tenancy_governance_compliance_replace_policies($pdo, $tenantId, $ruleId, (array) $policies['ids']);
    }

    return ['ok' => true];
}

function videochat_tenancy_create_governance_compliance_rule(PDO $pdo, int $tenantId, int $actorUserId, array $payload): array
{
    $validation = videochat_tenancy_governance_validate_compliance_payload($pdo, $tenantId, $payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'errors' => $validation['errors'] ?? []];
    }

    $pdo->beginTransaction();
    try {
        $now = gmdate('c');
        $publicId = videochat_tenancy_generate_public_id();
        $insert = $pdo->prepare(
            <<<'SQL'
INSERT INTO governance_compliance_rules(
    tenant_id, public_id, key, name, description, severity, status, created_by_user_id, created_at, updated_at
) VALUES(
    :tenant_id, :public_id, :key, :name, :description, :severity, :status, :created_by_user_id, :created_at, :updated_at
)
SQL
        );
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':public_id' => $publicId,
            ':key' => (string) $validation['key'],
            ':name' => (string) $validation['name'],
            ':description' => (string) $validation['description'],
            ':severity' => (string) $validation['severity'],
            ':status' => (string) $validation['status'],
            ':created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $rule = videochat_tenancy_fetch_governance_compliance_rule($pdo, $tenantId, $publicId);
        $sync = is_array($rule)
            ? videochat_tenancy_governance_compliance_sync_relationships($pdo, $tenantId, $rule, $payload)
            : ['ok' => false, 'errors' => ['compliance' => 'not_found']];
        if (!(bool) ($sync['ok'] ?? false)) {
            $pdo->rollBack();
            return $sync;
        }
        $pdo->commit();
        return ['ok' => true, 'row' => videochat_tenancy_fetch_governance_compliance_rule($pdo, $tenantId, $publicId)];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function videochat_tenancy_update_governance_compliance_rule(PDO $pdo, int $tenantId, int $actorUserId, string $identifier, array $payload): array
{
    $existing = videochat_tenancy_fetch_governance_compliance_rule($pdo, $tenantId, $identifier);
    if (!is_array($existing)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $validation = videochat_tenancy_governance_validate_compliance_payload($pdo, $tenantId, $payload, $existing);
    if (!(bool) ($validation['ok'] ?? false)) {
        return ['ok' => false, 'errors' => $validation['errors'] ?? []];
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            <<<'SQL'
UPDATE governance_compliance_rules
SET key = :key,
    name = :name,
    description = :description,
    severity = :severity,
    status = :status,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND id = :id
SQL
        );
        $update->execute([
            ':key' => (string) $validation['key'],
            ':name' => (string) $validation['name'],
            ':description' => (string) $validation['description'],
            ':severity' => (string) $validation['severity'],
            ':status' => (string) $validation['status'],
            ':updated_at' => gmdate('c'),
            ':tenant_id' => $tenantId,
            ':id' => (int) ($existing['id'] ?? 0),
        ]);
        $rule = videochat_tenancy_fetch_governance_compliance_rule($pdo, $tenantId, (string) ($existing['public_id'] ?? $identifier));
        $sync = is_array($rule)
            ? videochat_tenancy_governance_compliance_sync_relationships($pdo, $tenantId, $rule, $payload)
            : ['ok' => false, 'errors' => ['compliance' => 'not_found']];
        if (!(bool) ($sync['ok'] ?? false)) {
            $pdo->rollBack();
            return $sync;
        }
        $pdo->commit();
        return ['ok' => true, 'row' => videochat_tenancy_fetch_governance_compliance_rule($pdo, $tenantId, (string) ($existing['public_id'] ?? $identifier))];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function videochat_tenancy_delete_governance_compliance_rule(PDO $pdo, int $tenantId, string $identifier): array
{
    $existing = videochat_tenancy_fetch_governance_compliance_rule($pdo, $tenantId, $identifier);
    if (!is_array($existing)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }

    $delete = $pdo->prepare('DELETE FROM governance_compliance_rules WHERE tenant_id = :tenant_id AND id = :id');
    $delete->execute([':tenant_id' => $tenantId, ':id' => (int) ($existing['id'] ?? 0)]);
    return ['ok' => true, 'row' => $existing];
}

function videochat_handle_governance_compliance_routes(
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
        return $errorResponse(405, 'method_not_allowed', 'Use a supported method for governance compliance.', [
            'allowed_methods' => $allowedMethods,
        ]);
    }

    try {
        $pdo = $openDatabase();
        $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
        $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($tenantId <= 0 || $actorUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid tenant session is required.', ['reason' => 'invalid_tenant_context']);
        }

        if ($method === 'GET' && !$hasIdentifier) {
            $permission = videochat_tenancy_governance_compliance_permission_decision($pdo, $apiAuthContext, 'read');
            if (!(bool) ($permission['ok'] ?? false)) {
                return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
            }
            $rows = videochat_tenancy_governance_compliance_public_rows(
                $pdo,
                $tenantId,
                videochat_tenancy_list_governance_compliance_rules($pdo, $tenantId)
            );
            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => ['rows' => $rows, 'included' => ['compliance' => $rows]],
                'compliance' => $rows,
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'GET') {
            $row = videochat_tenancy_fetch_governance_compliance_rule($pdo, $tenantId, $identifier);
            if (!is_array($row)) {
                return $errorResponse(404, 'governance_resource_not_found', 'Governance resource was not found.', ['entity' => 'compliance']);
            }
            $permission = videochat_tenancy_governance_compliance_permission_decision($pdo, $apiAuthContext, 'read', (string) ($row['public_id'] ?? '*'));
            if (!(bool) ($permission['ok'] ?? false)) {
                return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
            }
            $rows = videochat_tenancy_governance_compliance_public_rows($pdo, $tenantId, [$row]);
            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => ['row' => $rows[0] ?? null, 'included' => ['compliance' => $rows]],
                'time' => gmdate('c'),
            ]);
        }

        $action = $method === 'POST' ? 'create' : ($method === 'DELETE' ? 'delete' : 'update');
        $existing = $hasIdentifier ? videochat_tenancy_fetch_governance_compliance_rule($pdo, $tenantId, $identifier) : null;
        if ($hasIdentifier && !is_array($existing)) {
            return $errorResponse(404, 'governance_resource_not_found', 'Governance resource was not found.', ['entity' => 'compliance']);
        }
        $permission = videochat_tenancy_governance_compliance_permission_decision($pdo, $apiAuthContext, $action, (string) ($existing['public_id'] ?? '*'));
        if (!(bool) ($permission['ok'] ?? false)) {
            return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
        }
        if ($method === 'DELETE') {
            videochat_tenancy_delete_governance_compliance_rule($pdo, $tenantId, $identifier);
            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => ['state' => 'deleted', 'id' => (string) ($existing['public_id'] ?? $identifier)],
                'time' => gmdate('c'),
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'governance_invalid_request_body', 'Governance payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }
        $result = $method === 'POST'
            ? videochat_tenancy_create_governance_compliance_rule($pdo, $tenantId, $actorUserId, $payload)
            : videochat_tenancy_update_governance_compliance_rule($pdo, $tenantId, $actorUserId, $identifier, $payload);
        if (!(bool) ($result['ok'] ?? false)) {
            return videochat_tenancy_governance_validation_response($errorResponse, $result);
        }
        $rows = videochat_tenancy_governance_compliance_public_rows(
            $pdo,
            $tenantId,
            [is_array($result['row'] ?? null) ? $result['row'] : []]
        );
        return $jsonResponse($method === 'POST' ? 201 : 200, [
            'status' => 'ok',
            'result' => [
                'state' => $method === 'POST' ? 'created' : 'updated',
                'row' => $rows[0] ?? null,
                'included' => ['compliance' => $rows],
            ],
            'time' => gmdate('c'),
        ]);
    } catch (Throwable) {
        return $errorResponse(500, 'governance_operation_failed', 'Governance operation failed.', [
            'reason' => 'internal_error',
        ]);
    }
}
