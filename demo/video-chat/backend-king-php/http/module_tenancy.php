<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/tenancy/tenant_administration.php';
require_once __DIR__ . '/../domain/tenancy/tenant_portability.php';

function videochat_handle_tenancy_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    if ($path === '/api/admin/tenancy/export' || $path === '/api/admin/tenancy/import/dry-run') {
        $admin = videochat_tenancy_require_admin($apiAuthContext);
        if (!(bool) ($admin['ok'] ?? false)) {
            return $errorResponse(403, 'tenant_admin_required', 'Tenant administration requires an active tenant admin membership.', [
                'reason' => (string) ($admin['reason'] ?? 'tenant_admin_required'),
            ]);
        }
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for tenant portability endpoints.', [
                'allowed_methods' => ['POST'],
            ]);
        }
        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'tenant_portability_invalid_request_body', 'Tenant portability payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }
        try {
            $pdo = $openDatabase();
            $tenantId = (int) ($admin['tenant_id'] ?? 0);
            $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
            $result = $path === '/api/admin/tenancy/export'
                ? videochat_tenant_export_bundle($pdo, $tenantId, $actorUserId, $payload)
                : videochat_tenant_import_dry_run($pdo, $tenantId, $actorUserId, is_array($payload['bundle'] ?? null) ? (array) $payload['bundle'] : $payload);
        } catch (Throwable) {
            return $errorResponse(500, 'tenant_portability_failed', 'Tenant portability operation failed.', [
                'reason' => 'internal_error',
            ]);
        }
        if (!(bool) ($result['ok'] ?? false)) {
            return $errorResponse(422, 'tenant_portability_validation_failed', 'Tenant portability payload failed validation.', [
                'fields' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
            ]);
        }
        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => $result,
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/tenants') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/tenants.', [
                'allowed_methods' => ['GET'],
            ]);
        }
        $userId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($userId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }
        try {
            $pdo = $openDatabase();
            return $jsonResponse(200, [
                'status' => 'ok',
                'tenant' => $apiAuthContext['tenant'] ?? null,
                'tenants' => videochat_tenancy_list_user_tenants($pdo, $userId),
                'time' => gmdate('c'),
            ]);
        } catch (Throwable) {
            return $errorResponse(500, 'tenants_list_failed', 'Tenant list could not be loaded.', [
                'reason' => 'internal_error',
            ]);
        }
    }

    if ($path !== '/api/admin/tenancy/context') {
        return null;
    }
    if ($method !== 'GET') {
        return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/admin/tenancy/context.', [
            'allowed_methods' => ['GET'],
        ]);
    }

    $admin = videochat_tenancy_require_admin($apiAuthContext);
    if (!(bool) ($admin['ok'] ?? false)) {
        return $errorResponse(403, 'tenant_admin_required', 'Tenant administration requires an active tenant admin membership.', [
            'reason' => (string) ($admin['reason'] ?? 'tenant_admin_required'),
        ]);
    }

    try {
        $pdo = $openDatabase();
        $tenantId = (int) ($admin['tenant_id'] ?? 0);

        return $jsonResponse(200, [
            'status' => 'ok',
            'tenant' => $apiAuthContext['tenant'] ?? null,
            'organizations' => videochat_tenancy_list_organizations($pdo, $tenantId),
            'groups' => videochat_tenancy_list_groups($pdo, $tenantId),
            'time' => gmdate('c'),
        ]);
    } catch (Throwable) {
        return $errorResponse(500, 'tenant_context_failed', 'Tenant context could not be loaded.', [
            'reason' => 'internal_error',
        ]);
    }
}
