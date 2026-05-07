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

function videochat_tenancy_governance_summary_rows(PDO $pdo, int $tenantId, string $entity, array $ids): array
{
    $rows = [];
    foreach ($ids as $id) {
        $row = match ($entity) {
            'users' => videochat_admin_fetch_user_by_id($pdo, (int) $id, $tenantId),
            'groups', 'organizations' => videochat_tenancy_fetch_governance_entity($pdo, $entity, $tenantId, (string) $id),
            'roles' => (($role = videochat_tenancy_fetch_governance_role($pdo, $tenantId, (string) $id)) !== null)
                ? videochat_tenancy_governance_summary_first_row(videochat_tenancy_governance_role_public_rows($pdo, $tenantId, [$role]))
                : null,
            'grants' => (($grant = videochat_tenancy_fetch_governance_grant($pdo, $tenantId, (string) $id)) !== null)
                ? videochat_tenancy_governance_grant_public_row($pdo, $tenantId, $grant)
                : null,
            'policies' => (($policy = videochat_tenancy_fetch_governance_policy($pdo, $tenantId, (string) $id)) !== null)
                ? videochat_tenancy_governance_summary_first_row(videochat_tenancy_governance_policy_public_rows($pdo, $tenantId, [$policy]))
                : null,
            'data-portability' => (($job = videochat_tenancy_governance_portability_fetch_job($pdo, $tenantId, (string) $id)) !== null)
                ? videochat_tenancy_governance_portability_public_row($pdo, $tenantId, $job)
                : null,
            default => null,
        };
        if (is_array($row) && trim((string) ($row['id'] ?? '')) !== '') {
            $rows[] = videochat_tenancy_governance_summary_row($entity, $row);
        }
    }

    return $rows;
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
