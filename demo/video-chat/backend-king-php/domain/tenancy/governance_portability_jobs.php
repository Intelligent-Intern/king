<?php

declare(strict_types=1);

require_once __DIR__ . '/governance_permission_grants.php';
require_once __DIR__ . '/tenant_portability.php';

function videochat_tenancy_governance_portability_permission_decision(PDO $pdo, array $authContext, string $action): array
{
    $tenant = is_array($authContext['tenant'] ?? null) ? $authContext['tenant'] : [];
    $permissions = is_array($tenant['permissions'] ?? null) ? $tenant['permissions'] : [];
    $tenantId = (int) ($tenant['id'] ?? ($tenant['tenant_id'] ?? 0));
    $userId = (int) (($authContext['user']['id'] ?? 0));
    if ($tenantId <= 0 || $userId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_context'];
    }

    $normalizedAction = match ($action) {
        'export' => 'read',
        'import' => 'create',
        default => videochat_tenancy_normalize_grant_action($action),
    };
    if ($normalizedAction === '') {
        return ['ok' => false, 'reason' => 'invalid_action'];
    }
    $keyAction = in_array($action, ['export', 'import'], true) ? $action : $normalizedAction;
    if (
        (bool) ($permissions['platform_admin'] ?? false)
        || (bool) ($permissions['tenant_admin'] ?? false)
        || (bool) ($permissions['export_import'] ?? false)
        || (bool) ($permissions['governance.data_portability.' . $keyAction] ?? false)
        || ($normalizedAction === 'read' && (bool) ($permissions['governance.read'] ?? false))
    ) {
        return ['ok' => true, 'reason' => 'tenant_permission_alias'];
    }

    foreach ([$normalizedAction, 'manage'] as $candidateAction) {
        $grant = videochat_tenancy_user_has_resource_permission($pdo, $tenantId, $userId, 'tenant_export_import_job', '*', $candidateAction);
        if ((bool) ($grant['ok'] ?? false)) {
            return ['ok' => true, 'reason' => 'resource_grant', 'grant' => $grant['grant'] ?? null];
        }
    }

    return ['ok' => false, 'reason' => 'not_granted'];
}

function videochat_tenancy_governance_portability_scope(PDO $pdo, int $tenantId, array $payload, int $actorUserId = 0): array
{
    $relationships = is_array($payload['relationships'] ?? null) ? $payload['relationships'] : [];
    $user = is_array($relationships['user'] ?? null) && array_is_list($relationships['user']) ? ($relationships['user'][0] ?? null) : null;
    $organization = is_array($relationships['organization'] ?? null) && array_is_list($relationships['organization']) ? ($relationships['organization'][0] ?? null) : null;

    if (is_array($user)) {
        $userId = (int) ($user['id'] ?? 0);
        $validation = videochat_tenancy_governance_validate_user_ids($pdo, $tenantId, [$userId], 'user');
        if (!(bool) ($validation['ok'] ?? false)) {
            return ['ok' => false, 'errors' => ['user' => 'not_found']];
        }
        return ['ok' => true, 'scope_type' => 'user', 'scope_user_id' => $userId, 'scope_organization_id' => 0];
    }

    if (is_array($organization)) {
        $identifier = trim((string) ($organization['id'] ?? ($organization['key'] ?? '')));
        $row = videochat_tenancy_fetch_governance_organization($pdo, $tenantId, $identifier);
        if (!is_array($row)) {
            return ['ok' => false, 'errors' => ['organization' => 'not_found']];
        }
        return ['ok' => true, 'scope_type' => 'organization', 'scope_user_id' => 0, 'scope_organization_id' => (int) ($row['database_id'] ?? 0)];
    }

    $scopeType = in_array((string) ($payload['scope_type'] ?? 'organization'), ['user', 'organization'], true)
        ? (string) ($payload['scope_type'] ?? 'organization')
        : 'organization';
    if ($scopeType === 'user' && $actorUserId > 0) {
        $validation = videochat_tenancy_governance_validate_user_ids($pdo, $tenantId, [$actorUserId], 'user');
        if (!(bool) ($validation['ok'] ?? false)) {
            return ['ok' => false, 'errors' => ['user' => 'not_found']];
        }
        return ['ok' => true, 'scope_type' => 'user', 'scope_user_id' => $actorUserId, 'scope_organization_id' => 0];
    }

    return ['ok' => true, 'scope_type' => $scopeType, 'scope_user_id' => 0, 'scope_organization_id' => 0];
}

function videochat_tenancy_governance_portability_job_rows(PDO $pdo, int $tenantId): array
{
    $export = $pdo->prepare("SELECT 'export' AS job_type, * FROM tenant_export_jobs WHERE tenant_id = :tenant_id");
    $export->execute([':tenant_id' => $tenantId]);
    $import = $pdo->prepare("SELECT 'import' AS job_type, * FROM tenant_import_jobs WHERE tenant_id = :tenant_id");
    $import->execute([':tenant_id' => $tenantId]);
    $rows = array_merge($export->fetchAll(PDO::FETCH_ASSOC) ?: [], $import->fetchAll(PDO::FETCH_ASSOC) ?: []);
    usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));

    return $rows;
}

function videochat_tenancy_governance_portability_fetch_job(PDO $pdo, int $tenantId, string $identifier): ?array
{
    foreach (['tenant_export_jobs' => 'export', 'tenant_import_jobs' => 'import'] as $table => $jobType) {
        $query = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE tenant_id = :tenant_id AND id = :id LIMIT 1');
        $query->execute([
            ':tenant_id' => $tenantId,
            ':id' => trim($identifier),
        ]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $row['job_type'] = $jobType;
            return $row;
        }
    }

    return null;
}

function videochat_tenancy_governance_portability_public_row(PDO $pdo, int $tenantId, array $row): array
{
    $jobType = (string) ($row['job_type'] ?? 'export');
    $id = (string) ($row['id'] ?? '');
    $status = (string) ($row['status'] ?? 'queued');
    $scopeType = (string) ($row['scope_type'] ?? 'organization');
    $userId = (int) ($row['scope_user_id'] ?? 0);
    $organizationId = (int) ($row['scope_organization_id'] ?? 0);
    $relationships = ['user' => [], 'organization' => []];
    if ($userId > 0) {
        $user = videochat_admin_fetch_user_by_id($pdo, $userId, $tenantId);
        if (is_array($user)) {
            $relationships['user'] = [videochat_tenancy_governance_user_summary_row($user)];
        }
    }
    if ($organizationId > 0) {
        $organizations = videochat_tenancy_governance_organization_summary_map($pdo, $tenantId, [$organizationId]);
        if (isset($organizations[$organizationId])) {
            $relationships['organization'] = [$organizations[$organizationId]];
        }
    }
    $result = videochat_tenancy_governance_portability_public_result((string) ($row['result_json'] ?? '{}'), $jobType);
    $description = (string) ($row['failure_reason'] ?? '');
    $tableCount = is_array($result['tables'] ?? null) ? count((array) $result['tables']) : (int) ($result['table_count'] ?? 0);
    $descriptionKey = '';
    $descriptionParams = ['tables' => $tableCount];
    if ($description === '') {
        if ($jobType === 'export') {
            $descriptionKey = 'governance.data_portability.export_ready';
        } elseif ((bool) ($result['accepted'] ?? false)) {
            $descriptionKey = 'governance.data_portability.import_dry_run_passed';
        } else {
            $descriptionKey = 'governance.data_portability.import_dry_run_failed';
        }
    }

    return [
        'id' => $id,
        'key' => $id,
        'job_type' => $jobType,
        'name' => ucfirst($jobType) . ' ' . $status,
        'description' => $description,
        'description_key' => $descriptionKey,
        'description_params' => $descriptionParams,
        'status' => $status,
        'scope_type' => $scopeType,
        'schema_version' => (string) ($row['schema_version'] ?? ''),
        'result' => $result,
        'updatedAt' => (string) ($row['updated_at'] ?? ''),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'completed_at' => (string) ($row['completed_at'] ?? ''),
        'relationships' => $relationships,
    ];
}

function videochat_tenancy_governance_portability_public_result(string $resultJson, string $jobType): array
{
    $decoded = json_decode($resultJson !== '' ? $resultJson : '{}', true);
    if (!is_array($decoded)) {
        return [];
    }

    unset($decoded['tenant_id'], $decoded['scope_user_id'], $decoded['scope_organization_id']);
    if ($jobType === 'export') {
        return [
            'schema_version' => (string) ($decoded['schema_version'] ?? 'tenant-export.v1'),
            'scope_type' => in_array((string) ($decoded['scope_type'] ?? 'organization'), ['user', 'organization'], true)
                ? (string) $decoded['scope_type']
                : 'organization',
            'tables' => is_array($decoded['tables'] ?? null) ? $decoded['tables'] : [],
            'generated_at' => (string) ($decoded['generated_at'] ?? ''),
        ];
    }

    return [
        'dry_run' => (bool) ($decoded['dry_run'] ?? true),
        'accepted' => (bool) ($decoded['accepted'] ?? false),
        'errors' => is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [],
        'table_count' => (int) ($decoded['table_count'] ?? 0),
    ];
}

function videochat_tenancy_governance_portability_public_rows(PDO $pdo, int $tenantId, array $rows): array
{
    return array_map(
        static fn (array $row): array => videochat_tenancy_governance_portability_public_row($pdo, $tenantId, $row),
        $rows
    );
}

function videochat_handle_governance_portability_routes(
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
    $allowedMethods = $hasIdentifier ? ['GET'] : ['GET', 'POST'];
    if (!in_array($method, $allowedMethods, true)) {
        return $errorResponse(405, 'method_not_allowed', 'Use a supported method for governance data portability jobs.', [
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
            $permission = videochat_tenancy_governance_portability_permission_decision($pdo, $apiAuthContext, 'read');
            if (!(bool) ($permission['ok'] ?? false)) {
                return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
            }
            $rows = videochat_tenancy_governance_portability_public_rows($pdo, $tenantId, videochat_tenancy_governance_portability_job_rows($pdo, $tenantId));
            return $jsonResponse(200, ['status' => 'ok', 'result' => ['rows' => $rows, 'included' => ['data-portability' => $rows]], 'data-portability' => $rows, 'time' => gmdate('c')]);
        }

        if ($method === 'GET') {
            $permission = videochat_tenancy_governance_portability_permission_decision($pdo, $apiAuthContext, 'read');
            if (!(bool) ($permission['ok'] ?? false)) {
                return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
            }
            $row = videochat_tenancy_governance_portability_fetch_job($pdo, $tenantId, $identifier);
            if (!is_array($row)) {
                return $errorResponse(404, 'governance_resource_not_found', 'Governance resource was not found.', ['entity' => 'data-portability']);
            }
            $publicRow = videochat_tenancy_governance_portability_public_row($pdo, $tenantId, $row);
            return $jsonResponse(200, ['status' => 'ok', 'result' => ['row' => $publicRow, 'included' => ['data-portability' => [$publicRow]]], 'time' => gmdate('c')]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'governance_invalid_request_body', 'Governance payload must be a JSON object.', ['reason' => $decodeError]);
        }
        $jobType = strtolower(trim((string) ($payload['job_type'] ?? 'export')));
        if (!in_array($jobType, ['export', 'import'], true)) {
            return $errorResponse(422, 'governance_validation_failed', 'Governance payload failed validation.', ['fields' => ['job_type' => 'invalid']]);
        }
        $permission = videochat_tenancy_governance_portability_permission_decision($pdo, $apiAuthContext, $jobType);
        if (!(bool) ($permission['ok'] ?? false)) {
            return videochat_tenancy_governance_forbidden_response($errorResponse, $permission);
        }
        $scope = videochat_tenancy_governance_portability_scope($pdo, $tenantId, $payload, $actorUserId);
        if (!(bool) ($scope['ok'] ?? false)) {
            return videochat_tenancy_governance_validation_response($errorResponse, $scope);
        }
        $options = [
            'scope_type' => (string) $scope['scope_type'],
            'scope_user_id' => (int) ($scope['scope_user_id'] ?? 0),
            'scope_organization_id' => (int) ($scope['scope_organization_id'] ?? 0),
        ];
        if ($jobType === 'import' && !is_array($payload['bundle'] ?? null)) {
            return $errorResponse(422, 'governance_validation_failed', 'Governance payload failed validation.', [
                'fields' => ['bundle' => 'required'],
            ]);
        }
        $result = $jobType === 'export'
            ? videochat_tenant_export_bundle($pdo, $tenantId, $actorUserId, $options)
            : videochat_tenant_import_dry_run($pdo, $tenantId, $actorUserId, (array) $payload['bundle'], $options);
        if (!(bool) ($result['ok'] ?? false)) {
            return videochat_tenancy_governance_validation_response($errorResponse, $result);
        }
        $row = videochat_tenancy_governance_portability_fetch_job($pdo, $tenantId, (string) ($result['job_id'] ?? ''));
        $publicRow = is_array($row) ? videochat_tenancy_governance_portability_public_row($pdo, $tenantId, $row) : null;
        return $jsonResponse(201, ['status' => 'ok', 'result' => ['state' => 'created', 'row' => $publicRow, 'included' => ['data-portability' => $publicRow ? [$publicRow] : []]], 'time' => gmdate('c')]);
    } catch (Throwable) {
        return $errorResponse(500, 'governance_operation_failed', 'Governance operation failed.', ['reason' => 'internal_error']);
    }
}
