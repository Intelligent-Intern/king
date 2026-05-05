<?php

declare(strict_types=1);

require_once __DIR__ . '/governance_organization_memberships.php';

function videochat_handle_governance_grant_routes(
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
        return $errorResponse(405, 'method_not_allowed', 'Use a supported method for this governance grants resource.', [
            'allowed_methods' => $allowedMethods,
        ]);
    }

    try {
        $pdo = $openDatabase();
        $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
        $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($tenantId <= 0 || $actorUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid tenant session is required.', [
                'reason' => 'invalid_tenant_context',
            ]);
        }

        if ($method === 'GET' && !$hasIdentifier) {
            $permission = videochat_tenancy_governance_grant_permission_decision($pdo, $apiAuthContext, 'read');
            if (!(bool) ($permission['ok'] ?? false)) {
                return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
            }
            $rows = videochat_tenancy_governance_grant_public_rows(
                $pdo,
                $tenantId,
                videochat_tenancy_list_governance_grants($pdo, $tenantId)
            );

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'rows' => $rows,
                    'included' => ['grants' => $rows],
                ],
                'grants' => $rows,
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'GET' && $hasIdentifier) {
            $row = videochat_tenancy_fetch_governance_grant($pdo, $tenantId, $identifier);
            if (!is_array($row)) {
                return $errorResponse(404, 'governance_resource_not_found', 'Governance resource was not found.', [
                    'entity' => 'grants',
                ]);
            }
            $permission = videochat_tenancy_governance_grant_permission_decision(
                $pdo,
                $apiAuthContext,
                'read',
                (string) ($row['public_id'] ?? '*')
            );
            if (!(bool) ($permission['ok'] ?? false)) {
                return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
            }
            $publicRow = videochat_tenancy_governance_grant_public_row($pdo, $tenantId, $row);

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'row' => $publicRow,
                    'included' => ['grants' => [$publicRow]],
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'POST' && !$hasIdentifier) {
            [$payload, $decodeError] = $decodeJsonBody($request);
            if (!is_array($payload)) {
                return $errorResponse(400, 'governance_invalid_request_body', 'Governance payload must be a JSON object.', [
                    'reason' => $decodeError,
                ]);
            }
            $permission = videochat_tenancy_governance_grant_permission_decision($pdo, $apiAuthContext, 'create');
            if (!(bool) ($permission['ok'] ?? false)) {
                return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
            }
            $result = videochat_tenancy_create_governance_grant($pdo, $tenantId, $actorUserId, $payload);
            if (!(bool) ($result['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $result);
            }
            $row = videochat_tenancy_governance_grant_public_row($pdo, $tenantId, is_array($result['row'] ?? null) ? $result['row'] : []);

            return $jsonResponse(201, [
                'status' => 'ok',
                'result' => [
                    'state' => 'created',
                    'row' => $row,
                    'included' => ['grants' => [$row]],
                ],
                'time' => gmdate('c'),
            ]);
        }

        $existing = videochat_tenancy_fetch_governance_grant($pdo, $tenantId, $identifier);
        if (!is_array($existing)) {
            return $errorResponse(404, 'governance_resource_not_found', 'Governance resource was not found.', [
                'entity' => 'grants',
            ]);
        }
        $action = $method === 'DELETE' ? 'delete' : 'update';
        $permission = videochat_tenancy_governance_grant_permission_decision(
            $pdo,
            $apiAuthContext,
            $action,
            (string) ($existing['public_id'] ?? '*')
        );
        if (!(bool) ($permission['ok'] ?? false)) {
            return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
        }

        if ($method === 'DELETE') {
            $result = videochat_tenancy_delete_governance_grant($pdo, $tenantId, $identifier);
            if (!(bool) ($result['ok'] ?? false)) {
                return videochat_tenancy_governance_validation_response($errorResponse, $result);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'deleted',
                    'id' => (string) ($existing['public_id'] ?? $identifier),
                ],
                'time' => gmdate('c'),
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'governance_invalid_request_body', 'Governance payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }
        $result = videochat_tenancy_update_governance_grant($pdo, $tenantId, $identifier, $payload);
        if (!(bool) ($result['ok'] ?? false)) {
            return videochat_tenancy_governance_validation_response($errorResponse, $result);
        }
        $row = videochat_tenancy_governance_grant_public_row($pdo, $tenantId, is_array($result['row'] ?? null) ? $result['row'] : []);

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'updated',
                'row' => $row,
                'included' => ['grants' => [$row]],
            ],
            'time' => gmdate('c'),
        ]);
    } catch (Throwable) {
        return $errorResponse(500, 'governance_operation_failed', 'Governance operation failed.', [
            'reason' => 'internal_error',
        ]);
    }
}

function videochat_tenancy_governance_grant_permission_decision(
    PDO $pdo,
    array $authContext,
    string $action,
    string $resourceId = '*'
): array {
    $tenant = is_array($authContext['tenant'] ?? null) ? $authContext['tenant'] : [];
    $permissions = is_array($tenant['permissions'] ?? null) ? $tenant['permissions'] : [];
    $tenantId = (int) ($tenant['id'] ?? ($tenant['tenant_id'] ?? 0));
    $userId = (int) (($authContext['user']['id'] ?? 0));
    if ($tenantId <= 0 || $userId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_context'];
    }

    $normalizedAction = videochat_tenancy_normalize_grant_action($action);
    if ($normalizedAction === '') {
        return ['ok' => false, 'reason' => 'invalid_action'];
    }

    $grantKey = 'governance.grants.' . $normalizedAction;
    if (
        (bool) ($permissions['platform_admin'] ?? false)
        || (bool) ($permissions['tenant_admin'] ?? false)
        || (bool) ($permissions['manage_permission_grants'] ?? false)
        || (bool) ($permissions[$grantKey] ?? false)
        || ($normalizedAction === 'read' && (bool) ($permissions['governance.read'] ?? false))
    ) {
        return ['ok' => true, 'reason' => 'tenant_permission_alias'];
    }

    foreach ([$resourceId, '*'] as $candidateResourceId) {
        foreach ([$normalizedAction, 'manage'] as $candidateAction) {
            $grant = videochat_tenancy_user_has_resource_permission(
                $pdo,
                $tenantId,
                $userId,
                'permission_grant',
                trim($candidateResourceId) !== '' ? $candidateResourceId : '*',
                $candidateAction
            );
            if ((bool) ($grant['ok'] ?? false)) {
                return ['ok' => true, 'reason' => 'resource_grant', 'grant' => $grant['grant'] ?? null];
            }
        }
    }

    return ['ok' => false, 'reason' => 'not_granted'];
}

function videochat_tenancy_governance_first_relation(array $payload, string $key): ?array
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    $value = array_key_exists($key, $relationships) ? $relationships[$key] : ($payload[$key] ?? null);
    if (is_array($value) && array_is_list($value)) {
        $first = $value[0] ?? null;
        return is_array($first) ? $first : null;
    }

    return is_array($value) ? $value : null;
}

function videochat_tenancy_governance_relation_text(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $value = trim((string) $row[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function videochat_tenancy_governance_normalize_permission_key(string $value): string
{
    $trimmed = trim($value);
    if (str_starts_with($trimmed, 'permission:')) {
        $parts = explode(':', $trimmed);
        $trimmed = (string) end($parts);
    }

    return trim($trimmed);
}

function videochat_tenancy_governance_resource_type_from_segment(string $segment): string
{
    $normalized = strtolower(trim(str_replace('-', '_', $segment)));
    return match ($normalized) {
        'groups' => 'group',
        'organizations' => 'organization',
        'users' => 'user',
        'roles' => 'role',
        'grants', 'permission_grants' => 'permission_grant',
        'data_portability' => 'tenant_export_import_job',
        default => rtrim($normalized, 's'),
    };
}

function videochat_tenancy_governance_parse_permission(array $payload): array
{
    $permission = videochat_tenancy_governance_first_relation($payload, 'permission') ?? [];
    $permissionKey = videochat_tenancy_governance_normalize_permission_key(videochat_tenancy_governance_relation_text(
        $permission,
        ['key', 'id', 'name', 'value']
    ));
    if ($permissionKey === '') {
        $permissionKey = videochat_tenancy_governance_normalize_permission_key((string) ($payload['permission_key'] ?? ''));
    }

    $parts = array_values(array_filter(explode('.', $permissionKey), static fn (string $part): bool => trim($part) !== ''));
    $action = $parts !== [] ? videochat_tenancy_normalize_grant_action((string) end($parts)) : '';
    if ($action === '' && array_key_exists('action', $payload)) {
        $action = videochat_tenancy_normalize_grant_action((string) $payload['action']);
    }
    if ($action === '') {
        return ['ok' => false, 'errors' => ['permission' => 'invalid_action']];
    }

    $resourceType = 'workspace';
    if (count($parts) >= 2) {
        $resourceType = videochat_tenancy_governance_resource_type_from_segment($parts[count($parts) - 2]);
    }
    if (array_key_exists('resource_type', $payload) && trim((string) $payload['resource_type']) !== '') {
        $resourceType = videochat_tenancy_normalize_resource_type((string) $payload['resource_type']);
    }

    return [
        'ok' => true,
        'permission_key' => $permissionKey !== '' ? $permissionKey : $resourceType . '.' . $action,
        'resource_type' => $resourceType,
        'action' => $action,
    ];
}

function videochat_tenancy_governance_resolve_resource(PDO $pdo, int $tenantId, array $payload, string $defaultType): array
{
    $resource = videochat_tenancy_governance_first_relation($payload, 'resource');
    if (!is_array($resource)) {
        return [
            'ok' => true,
            'resource_type' => $defaultType,
            'resource_id' => trim((string) ($payload['resource_id'] ?? '*')) ?: '*',
        ];
    }

    $entityKey = strtolower(trim((string) ($resource['entity_key'] ?? '')));
    $identifier = videochat_tenancy_governance_relation_text($resource, ['id', 'key', 'value', 'name']);
    if ($identifier === '') {
        return ['ok' => false, 'errors' => ['resource' => 'required']];
    }

    if ($entityKey === 'groups') {
        $row = videochat_tenancy_fetch_governance_group($pdo, $tenantId, $identifier);
        return is_array($row)
            ? ['ok' => true, 'resource_type' => 'group', 'resource_id' => (string) ($row['public_id'] ?? $identifier)]
            : ['ok' => false, 'errors' => ['resource' => 'not_found']];
    }
    if ($entityKey === 'organizations') {
        $row = videochat_tenancy_fetch_governance_organization($pdo, $tenantId, $identifier);
        return is_array($row)
            ? ['ok' => true, 'resource_type' => 'organization', 'resource_id' => (string) ($row['public_id'] ?? $identifier)]
            : ['ok' => false, 'errors' => ['resource' => 'not_found']];
    }
    if ($entityKey === 'modules') {
        return ['ok' => true, 'resource_type' => 'module', 'resource_id' => preg_replace('/^module:/', '', $identifier)];
    }
    if ($entityKey === 'permissions') {
        return ['ok' => true, 'resource_type' => 'permission', 'resource_id' => videochat_tenancy_governance_normalize_permission_key($identifier)];
    }

    return [
        'ok' => true,
        'resource_type' => $defaultType,
        'resource_id' => $identifier,
    ];
}

function videochat_tenancy_governance_resolve_subject(PDO $pdo, int $tenantId, array $payload): array
{
    $subject = videochat_tenancy_governance_first_relation($payload, 'subject');
    $subjectType = strtolower(trim((string) ($payload['subject_type'] ?? '')));
    if (is_array($subject)) {
        $entityKey = strtolower(trim((string) ($subject['entity_key'] ?? '')));
        $subjectType = match ($entityKey) {
            'users' => 'user',
            'groups' => 'group',
            'organizations' => 'organization',
            default => $subjectType,
        };
    }
    if (!in_array($subjectType, ['user', 'group', 'organization'], true)) {
        return ['ok' => false, 'errors' => ['subject' => 'required']];
    }

    $identifier = is_array($subject)
        ? videochat_tenancy_governance_relation_text($subject, ['id', 'key', 'value', 'name'])
        : trim((string) ($payload[$subjectType . '_id'] ?? ''));
    if ($identifier === '') {
        return ['ok' => false, 'errors' => ['subject' => 'required']];
    }

    if ($subjectType === 'user') {
        $userIds = videochat_tenancy_governance_validate_user_ids($pdo, $tenantId, [(int) $identifier], 'subject');
        return (bool) ($userIds['ok'] ?? false)
            ? ['ok' => true, 'subject_type' => 'user', 'user_id' => (int) $identifier, 'group_id' => null, 'organization_id' => null]
            : ['ok' => false, 'errors' => ['subject' => 'not_found']];
    }
    if ($subjectType === 'group') {
        $group = videochat_tenancy_fetch_governance_group($pdo, $tenantId, $identifier);
        return is_array($group)
            ? ['ok' => true, 'subject_type' => 'group', 'user_id' => null, 'group_id' => (int) ($group['database_id'] ?? 0), 'organization_id' => null]
            : ['ok' => false, 'errors' => ['subject' => 'not_found']];
    }

    $organization = videochat_tenancy_fetch_governance_organization($pdo, $tenantId, $identifier);
    return is_array($organization)
        ? ['ok' => true, 'subject_type' => 'organization', 'user_id' => null, 'group_id' => null, 'organization_id' => (int) ($organization['database_id'] ?? 0)]
        : ['ok' => false, 'errors' => ['subject' => 'not_found']];
}

function videochat_tenancy_governance_grant_status(array $row): string
{
    $revokedAt = trim((string) ($row['revoked_at'] ?? ''));
    if ($revokedAt !== '') {
        return 'archived';
    }

    $now = gmdate('c');
    $from = trim((string) ($row['valid_from'] ?? ''));
    if ($from !== '' && strtotime($from) > strtotime($now)) {
        return 'draft';
    }
    $until = trim((string) ($row['valid_until'] ?? ''));
    if ($until !== '' && strtotime($until) <= strtotime($now)) {
        return 'archived';
    }

    return 'active';
}

function videochat_tenancy_governance_validate_grant_payload(PDO $pdo, int $tenantId, array $payload, ?array $existing = null): array
{
    if (is_array($existing)) {
        $payload += [
            'subject_type' => (string) ($existing['subject_type'] ?? ''),
            'user_id' => (string) ($existing['user_id'] ?? ''),
            'group_id' => (string) ($existing['group_id'] ?? ''),
            'organization_id' => (string) ($existing['organization_id'] ?? ''),
            'permission_key' => (string) ($existing['permission_key'] ?? ''),
            'resource_type' => (string) ($existing['resource_type'] ?? ''),
            'resource_id' => (string) ($existing['resource_id'] ?? ''),
            'action' => (string) ($existing['action'] ?? ''),
        ];
    }

    $subject = videochat_tenancy_governance_resolve_subject($pdo, $tenantId, $payload);
    $permission = videochat_tenancy_governance_parse_permission($payload);
    if (!(bool) ($subject['ok'] ?? false) || !(bool) ($permission['ok'] ?? false)) {
        return [
            'ok' => false,
            'errors' => [
                ...(is_array($subject['errors'] ?? null) ? $subject['errors'] : []),
                ...(is_array($permission['errors'] ?? null) ? $permission['errors'] : []),
            ],
        ];
    }

    $resource = videochat_tenancy_governance_resolve_resource($pdo, $tenantId, $payload, (string) $permission['resource_type']);
    if (!(bool) ($resource['ok'] ?? false)) {
        return ['ok' => false, 'errors' => is_array($resource['errors'] ?? null) ? $resource['errors'] : ['resource' => 'invalid']];
    }

    $errors = [];
    $validFrom = trim((string) ($payload['valid_from'] ?? ($existing['valid_from'] ?? '')));
    $validUntil = trim((string) ($payload['valid_until'] ?? ($existing['valid_until'] ?? '')));
    foreach (['valid_from' => $validFrom, 'valid_until' => $validUntil] as $field => $value) {
        if ($value !== '' && strtotime($value) === false) {
            $errors[$field] = 'invalid_datetime';
        }
    }
    if ($validFrom !== '' && $validUntil !== '' && strtotime($validUntil) <= strtotime($validFrom)) {
        $errors['valid_until'] = 'must_be_after_valid_from';
    }

    $status = strtolower(trim((string) ($payload['status'] ?? ($existing ? videochat_tenancy_governance_grant_status($existing) : 'active'))));
    if (!in_array($status, ['active', 'archived', 'disabled', 'draft'], true)) {
        $errors['status'] = 'invalid';
    }
    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors];
    }

    $label = trim((string) ($payload['name'] ?? ($payload['label'] ?? ($existing['label'] ?? ''))));
    $description = trim((string) ($payload['description'] ?? ($existing['description'] ?? '')));
    $revokedAt = in_array($status, ['archived', 'disabled'], true) ? (string) ($existing['revoked_at'] ?? gmdate('c')) : null;

    return [
        'ok' => true,
        'data' => [
            ...$subject,
            'resource_type' => (string) $resource['resource_type'],
            'resource_id' => (string) $resource['resource_id'],
            'action' => (string) $permission['action'],
            'permission_key' => (string) $permission['permission_key'],
            'label' => $label,
            'description' => $description,
            'valid_from' => $validFrom !== '' ? $validFrom : null,
            'valid_until' => $validUntil !== '' ? $validUntil : null,
            'revoked_at' => $revokedAt,
        ],
    ];
}

function videochat_tenancy_fetch_governance_grant(PDO $pdo, int $tenantId, string $identifier): ?array
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
FROM permission_grants
WHERE tenant_id = :tenant_id
  AND (lower(public_id) = lower(:identifier){$numericClause})
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

    return is_array($row) ? videochat_tenancy_governance_ensure_grant_public_id($pdo, $tenantId, $row) : null;
}

function videochat_tenancy_list_governance_grants(PDO $pdo, int $tenantId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT *
FROM permission_grants
WHERE tenant_id = :tenant_id
ORDER BY revoked_at IS NOT NULL ASC, updated_at DESC, id DESC
SQL
    );
    $query->execute([':tenant_id' => $tenantId]);

    return array_map(
        static fn (array $row): array => videochat_tenancy_governance_ensure_grant_public_id($pdo, $tenantId, $row),
        $query->fetchAll(PDO::FETCH_ASSOC) ?: []
    );
}

function videochat_tenancy_governance_ensure_grant_public_id(PDO $pdo, int $tenantId, array $row): array
{
    $publicId = trim((string) ($row['public_id'] ?? ''));
    $databaseId = (int) ($row['id'] ?? 0);
    if ($publicId !== '' || $tenantId <= 0 || $databaseId <= 0) {
        return $row;
    }

    $publicId = videochat_tenancy_generate_public_id();
    $update = $pdo->prepare('UPDATE permission_grants SET public_id = :public_id WHERE tenant_id = :tenant_id AND id = :id');
    $update->execute([
        ':public_id' => $publicId,
        ':tenant_id' => $tenantId,
        ':id' => $databaseId,
    ]);
    $row['public_id'] = $publicId;

    return $row;
}

function videochat_tenancy_governance_grant_subject_summary(PDO $pdo, int $tenantId, array $row): array
{
    $subjectType = (string) ($row['subject_type'] ?? '');
    if ($subjectType === 'user') {
        $user = videochat_admin_fetch_user_by_id($pdo, (int) ($row['user_id'] ?? 0), $tenantId);
        return is_array($user) ? videochat_tenancy_governance_user_summary_row($user) : [];
    }
    if ($subjectType === 'group') {
        $group = videochat_tenancy_fetch_governance_group($pdo, $tenantId, (string) ((int) ($row['group_id'] ?? 0)));
        return is_array($group) ? [
            'entity_key' => 'groups',
            'id' => (string) ($group['public_id'] ?? ''),
            'key' => (string) ($group['public_id'] ?? ''),
            'name' => (string) ($group['name'] ?? ''),
            'status' => (string) ($group['status'] ?? 'active'),
        ] : [];
    }
    $organization = videochat_tenancy_fetch_governance_organization($pdo, $tenantId, (string) ((int) ($row['organization_id'] ?? 0)));
    return is_array($organization) ? [
        'entity_key' => 'organizations',
        'id' => (string) ($organization['public_id'] ?? ''),
        'key' => (string) ($organization['public_id'] ?? ''),
        'name' => (string) ($organization['name'] ?? ''),
        'status' => (string) ($organization['status'] ?? 'active'),
    ] : [];
}

function videochat_tenancy_governance_grant_resource_summary(PDO $pdo, int $tenantId, array $row): array
{
    $resourceType = (string) ($row['resource_type'] ?? '');
    $resourceId = (string) ($row['resource_id'] ?? '*');
    if ($resourceType === 'group' && $resourceId !== '*') {
        $group = videochat_tenancy_fetch_governance_group($pdo, $tenantId, $resourceId);
        if (is_array($group)) {
            return ['entity_key' => 'groups', 'id' => (string) ($group['public_id'] ?? ''), 'key' => (string) ($group['public_id'] ?? ''), 'name' => (string) ($group['name'] ?? ''), 'status' => (string) ($group['status'] ?? 'active')];
        }
    }
    if ($resourceType === 'organization' && $resourceId !== '*') {
        $organization = videochat_tenancy_fetch_governance_organization($pdo, $tenantId, $resourceId);
        if (is_array($organization)) {
            return ['entity_key' => 'organizations', 'id' => (string) ($organization['public_id'] ?? ''), 'key' => (string) ($organization['public_id'] ?? ''), 'name' => (string) ($organization['name'] ?? ''), 'status' => (string) ($organization['status'] ?? 'active')];
        }
    }
    $entityKey = $resourceType === 'module' ? 'modules' : ($resourceType === 'permission' ? 'permissions' : 'resources');
    return [
        'entity_key' => $entityKey,
        'id' => $resourceType === 'module' ? 'module:' . $resourceId : $resourceId,
        'key' => $resourceId,
        'name' => $resourceId,
        'status' => 'active',
    ];
}

function videochat_tenancy_governance_grant_public_row(PDO $pdo, int $tenantId, array $row): array
{
    $publicId = trim((string) ($row['public_id'] ?? ''));
    $permissionKey = trim((string) ($row['permission_key'] ?? ''));
    if ($permissionKey === '') {
        $permissionKey = (string) ($row['resource_type'] ?? 'resource') . '.' . (string) ($row['action'] ?? 'read');
    }
    $subject = videochat_tenancy_governance_grant_subject_summary($pdo, $tenantId, $row);
    $resource = videochat_tenancy_governance_grant_resource_summary($pdo, $tenantId, $row);
    $name = trim((string) ($row['label'] ?? ''));
    if ($name === '') {
        $subjectName = trim((string) ($subject['name'] ?? (string) ($row['subject_type'] ?? 'subject')));
        $name = $subjectName . ' -> ' . $permissionKey;
    }

    return [
        'id' => $publicId,
        'public_id' => $publicId,
        'name' => $name,
        'key' => $permissionKey,
        'description' => (string) ($row['description'] ?? ''),
        'subject_type' => (string) ($row['subject_type'] ?? ''),
        'resource_type' => (string) ($row['resource_type'] ?? ''),
        'resource_id' => (string) ($row['resource_id'] ?? ''),
        'action' => (string) ($row['action'] ?? ''),
        'permission_key' => $permissionKey,
        'status' => videochat_tenancy_governance_grant_status($row),
        'valid_from' => (string) ($row['valid_from'] ?? ''),
        'valid_until' => (string) ($row['valid_until'] ?? ''),
        'updatedAt' => (string) ($row['updated_at'] ?? ''),
        'relationships' => [
            'subject' => $subject !== [] ? [$subject] : [],
            'permission' => [[
                'entity_key' => 'permissions',
                'id' => 'permission:governance:' . $permissionKey,
                'key' => $permissionKey,
                'name' => $permissionKey,
                'status' => 'active',
            ]],
            'resource' => $resource !== [] ? [$resource] : [],
        ],
    ];
}

function videochat_tenancy_governance_grant_public_rows(PDO $pdo, int $tenantId, array $rows): array
{
    return array_map(
        static fn (array $row): array => videochat_tenancy_governance_grant_public_row($pdo, $tenantId, $row),
        $rows
    );
}

function videochat_tenancy_create_governance_grant(PDO $pdo, int $tenantId, int $actorUserId, array $payload): array
{
    $validation = videochat_tenancy_governance_validate_grant_payload($pdo, $tenantId, $payload);
    if (!(bool) ($validation['ok'] ?? false)) {
        return $validation;
    }
    $data = (array) $validation['data'];
    $now = gmdate('c');
    $publicId = videochat_tenancy_generate_public_id();
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO permission_grants(
    tenant_id, public_id, resource_type, resource_id, action, subject_type,
    user_id, group_id, organization_id, valid_from, valid_until, revoked_at,
    created_by_user_id, label, description, permission_key, created_at, updated_at
) VALUES(
    :tenant_id, :public_id, :resource_type, :resource_id, :action, :subject_type,
    :user_id, :group_id, :organization_id, :valid_from, :valid_until, :revoked_at,
    :created_by_user_id, :label, :description, :permission_key, :created_at, :updated_at
)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => $publicId,
        ':resource_type' => (string) $data['resource_type'],
        ':resource_id' => (string) $data['resource_id'],
        ':action' => (string) $data['action'],
        ':subject_type' => (string) $data['subject_type'],
        ':user_id' => $data['user_id'] ?? null,
        ':group_id' => $data['group_id'] ?? null,
        ':organization_id' => $data['organization_id'] ?? null,
        ':valid_from' => $data['valid_from'] ?? null,
        ':valid_until' => $data['valid_until'] ?? null,
        ':revoked_at' => $data['revoked_at'] ?? null,
        ':created_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
        ':label' => (string) $data['label'],
        ':description' => (string) $data['description'],
        ':permission_key' => (string) $data['permission_key'],
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return ['ok' => true, 'row' => videochat_tenancy_fetch_governance_grant($pdo, $tenantId, $publicId)];
}

function videochat_tenancy_update_governance_grant(PDO $pdo, int $tenantId, string $identifier, array $payload): array
{
    $existing = videochat_tenancy_fetch_governance_grant($pdo, $tenantId, $identifier);
    if (!is_array($existing)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $validation = videochat_tenancy_governance_validate_grant_payload($pdo, $tenantId, $payload, $existing);
    if (!(bool) ($validation['ok'] ?? false)) {
        return $validation;
    }
    $data = (array) $validation['data'];
    $update = $pdo->prepare(
        <<<'SQL'
UPDATE permission_grants
SET resource_type = :resource_type,
    resource_id = :resource_id,
    action = :action,
    subject_type = :subject_type,
    user_id = :user_id,
    group_id = :group_id,
    organization_id = :organization_id,
    valid_from = :valid_from,
    valid_until = :valid_until,
    revoked_at = :revoked_at,
    label = :label,
    description = :description,
    permission_key = :permission_key,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND id = :id
SQL
    );
    $update->execute([
        ':resource_type' => (string) $data['resource_type'],
        ':resource_id' => (string) $data['resource_id'],
        ':action' => (string) $data['action'],
        ':subject_type' => (string) $data['subject_type'],
        ':user_id' => $data['user_id'] ?? null,
        ':group_id' => $data['group_id'] ?? null,
        ':organization_id' => $data['organization_id'] ?? null,
        ':valid_from' => $data['valid_from'] ?? null,
        ':valid_until' => $data['valid_until'] ?? null,
        ':revoked_at' => $data['revoked_at'] ?? null,
        ':label' => (string) $data['label'],
        ':description' => (string) $data['description'],
        ':permission_key' => (string) $data['permission_key'],
        ':updated_at' => gmdate('c'),
        ':tenant_id' => $tenantId,
        ':id' => (int) ($existing['id'] ?? 0),
    ]);

    return ['ok' => true, 'row' => videochat_tenancy_fetch_governance_grant($pdo, $tenantId, (string) ($existing['public_id'] ?? $identifier))];
}

function videochat_tenancy_delete_governance_grant(PDO $pdo, int $tenantId, string $identifier): array
{
    $existing = videochat_tenancy_fetch_governance_grant($pdo, $tenantId, $identifier);
    if (!is_array($existing)) {
        return ['ok' => false, 'reason' => 'not_found'];
    }
    $delete = $pdo->prepare('DELETE FROM permission_grants WHERE tenant_id = :tenant_id AND id = :id');
    $delete->execute([
        ':tenant_id' => $tenantId,
        ':id' => (int) ($existing['id'] ?? 0),
    ]);

    return ['ok' => true, 'row' => $existing];
}
