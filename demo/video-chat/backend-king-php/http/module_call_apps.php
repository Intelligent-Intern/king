<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/call_apps/call_app_availability.php';
require_once __DIR__ . '/../support/tenant_context.php';

function videochat_handle_call_app_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase
): ?array {
    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/call-apps/available$#', $path, $availabilityMatch) !== 1) {
        return null;
    }

    if ($method !== 'GET') {
        return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/calls/{id}/call-apps/available.', [
            'allowed_methods' => ['GET'],
        ]);
    }

    $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
    $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
    $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
    if ($authenticatedUserId <= 0 || $tenantId <= 0) {
        return $errorResponse(401, 'auth_failed', 'A valid session token and tenant context are required.', [
            'reason' => 'invalid_user_or_tenant_context',
        ]);
    }

    $filters = videochat_call_app_availability_filters(videochat_request_query_params($request));
    if (!(bool) ($filters['ok'] ?? false)) {
        return $errorResponse(422, 'call_app_availability_query_invalid', 'Invalid Call App availability query parameters.', [
            'fields' => is_array($filters['errors'] ?? null) ? $filters['errors'] : [],
        ]);
    }

    $callId = (string) ($availabilityMatch[1] ?? '');
    try {
        $pdo = $openDatabase();
        $callResolution = videochat_get_call_for_user($pdo, $callId, $authenticatedUserId, $authenticatedUserRole, $tenantId);
        if (!(bool) ($callResolution['ok'] ?? false)) {
            $reason = (string) ($callResolution['reason'] ?? 'internal_error');
            if ($reason === 'not_found') {
                return $errorResponse(404, 'calls_not_found', 'The requested call does not exist.', [
                    'call_id' => $callId,
                ]);
            }
            if ($reason === 'forbidden') {
                return $errorResponse(403, 'calls_forbidden', 'You are not allowed to view this call.', [
                    'call_id' => $callId,
                ]);
            }

            return $errorResponse(500, 'call_app_availability_failed', 'Could not load Call App availability.', [
                'reason' => 'internal_error',
            ]);
        }

        $refresh = videochat_call_app_refresh_catalog($pdo);
        $available = videochat_call_app_list_available_for_tenant($pdo, $tenantId, $filters);
    } catch (Throwable) {
        return $errorResponse(500, 'call_app_availability_failed', 'Could not load Call App availability.', [
            'reason' => 'internal_error',
        ]);
    }

    $page = (int) ($filters['page'] ?? 1);
    $pageSize = (int) ($filters['page_size'] ?? 12);
    $pageCount = (int) ($available['page_count'] ?? 0);
    $apps = is_array($available['apps'] ?? null) ? $available['apps'] : [];

    return $jsonResponse(200, [
        'status' => 'ok',
        'result' => [
            'call_id' => $callId,
            'tenant_id' => $tenantId,
            'apps' => $apps,
            'filters' => [
                'query' => (string) ($filters['query'] ?? ''),
                'category' => (string) ($filters['category'] ?? 'all'),
            ],
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => (int) ($available['total'] ?? 0),
                'page_count' => $pageCount,
                'returned' => count($apps),
                'has_prev' => $page > 1,
                'has_next' => $pageCount > 0 && $page < $pageCount,
            ],
            'discovery' => [
                'source' => 'semantic_dns_mcp',
                'ok' => (bool) ($refresh['ok'] ?? false),
                'invalid' => is_array($refresh['invalid'] ?? null) ? $refresh['invalid'] : [],
                'refreshed_at' => (string) ($refresh['refreshed_at'] ?? ''),
            ],
        ],
        'time' => gmdate('c'),
    ]);
}
