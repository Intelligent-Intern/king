<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/marketplace/call_app_marketplace.php';
require_once __DIR__ . '/../domain/call_apps/call_app_marketplace_entitlements.php';
require_once __DIR__ . '/../support/tenant_context.php';

function videochat_marketplace_tenant_admin_decision(array $apiAuthContext): array
{
    $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
    $actorUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
    $tenant = is_array($apiAuthContext['tenant'] ?? null) ? $apiAuthContext['tenant'] : [];
    $permissions = is_array($tenant['permissions'] ?? null) ? $tenant['permissions'] : [];
    $tenantRole = strtolower(trim((string) ($tenant['role'] ?? '')));
    $isAdmin = (bool) ($permissions['tenant_admin'] ?? false)
        || (bool) ($permissions['platform_admin'] ?? false)
        || in_array($tenantRole, ['owner', 'admin'], true);

    if ($tenantId <= 0 || $actorUserId <= 0) {
        return ['ok' => false, 'reason' => 'invalid_tenant_context', 'tenant_id' => $tenantId, 'actor_user_id' => $actorUserId];
    }
    if (!$isAdmin) {
        return ['ok' => false, 'reason' => 'tenant_admin_required', 'tenant_id' => $tenantId, 'actor_user_id' => $actorUserId];
    }

    return ['ok' => true, 'tenant_id' => $tenantId, 'actor_user_id' => $actorUserId];
}

function videochat_marketplace_optional_json_body(array $request, callable $decodeJsonBody): array
{
    $body = $request['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return [[], null];
    }

    return $decodeJsonBody($request);
}

function videochat_handle_marketplace_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    if ($path === '/api/marketplace/call-apps') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/marketplace/call-apps.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $refresh = videochat_call_app_refresh_catalog($pdo);
            $queryParams = videochat_request_query_params($request);
            $apps = videochat_call_app_list_catalog(
                $pdo,
                (string) ($queryParams['query'] ?? ''),
                (string) ($queryParams['category'] ?? 'all')
            );
        } catch (Throwable) {
            return $errorResponse(500, 'call_app_catalog_discovery_failed', 'Could not discover Call Apps.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'apps' => $apps,
            'discovery' => [
                'source' => 'semantic_dns_mcp',
                'ok' => (bool) ($refresh['ok'] ?? false),
                'invalid' => is_array($refresh['invalid'] ?? null) ? $refresh['invalid'] : [],
                'refreshed_at' => (string) ($refresh['refreshed_at'] ?? ''),
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/marketplace/call-apps/([A-Za-z0-9._-]+)$#', $path, $catalogMatch) === 1) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/marketplace/call-apps/{app_key}.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        $appKey = rawurldecode((string) ($catalogMatch[1] ?? ''));
        try {
            $pdo = $openDatabase();
            $refresh = videochat_call_app_refresh_catalog($pdo);
            $entry = videochat_call_app_fetch_catalog_entry($pdo, $appKey);
        } catch (Throwable) {
            return $errorResponse(500, 'call_app_catalog_fetch_failed', 'Could not load Call App catalog entry.', [
                'reason' => 'internal_error',
            ]);
        }
        if (!is_array($entry)) {
            return $errorResponse(404, 'call_app_not_found', 'The requested Call App does not exist.', [
                'app_key' => $appKey,
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'app' => $entry,
            'discovery' => [
                'source' => 'semantic_dns_mcp',
                'ok' => (bool) ($refresh['ok'] ?? false),
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/marketplace/call-apps/([A-Za-z0-9._-]+)/orders$#', $path, $orderMatch) === 1) {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for Call App orders.', [
                'allowed_methods' => ['POST'],
            ]);
        }
        $admin = videochat_marketplace_tenant_admin_decision($apiAuthContext);
        if (!(bool) ($admin['ok'] ?? false)) {
            return $errorResponse(403, 'call_app_order_forbidden', 'Call App orders require tenant administration rights.', [
                'reason' => (string) ($admin['reason'] ?? 'tenant_admin_required'),
            ]);
        }
        [$payload, $decodeError] = videochat_marketplace_optional_json_body($request, $decodeJsonBody);
        if (!is_array($payload)) {
            return $errorResponse(400, 'call_app_order_invalid_request_body', 'Call App order payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }
        try {
            $pdo = $openDatabase();
            videochat_call_app_refresh_catalog($pdo);
            $result = videochat_call_app_create_organization_order(
                $pdo,
                (int) ($admin['tenant_id'] ?? 0),
                (int) ($admin['actor_user_id'] ?? 0),
                rawurldecode((string) ($orderMatch[1] ?? '')),
                $payload
            );
        } catch (Throwable) {
            return $errorResponse(500, 'call_app_order_failed', 'Could not order Call App.', [
                'reason' => 'internal_error',
            ]);
        }
        if (!(bool) ($result['ok'] ?? false)) {
            $reason = (string) ($result['reason'] ?? 'internal_error');
            $status = $reason === 'app_not_found' ? 404 : ($reason === 'validation_failed' ? 422 : 400);
            return $errorResponse($status, 'call_app_order_failed', 'Could not order Call App.', [
                'reason' => $reason,
                'fields' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
            ]);
        }

        return $jsonResponse(201, ['status' => 'ok', 'result' => $result, 'time' => gmdate('c')]);
    }

    if (preg_match('#^/api/marketplace/call-apps/([A-Za-z0-9._-]+)/installations$#', $path, $installMatch) === 1) {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for Call App installations.', [
                'allowed_methods' => ['POST'],
            ]);
        }
        $admin = videochat_marketplace_tenant_admin_decision($apiAuthContext);
        if (!(bool) ($admin['ok'] ?? false)) {
            return $errorResponse(403, 'call_app_installation_forbidden', 'Call App installation changes require tenant administration rights.', [
                'reason' => (string) ($admin['reason'] ?? 'tenant_admin_required'),
            ]);
        }
        [$payload, $decodeError] = videochat_marketplace_optional_json_body($request, $decodeJsonBody);
        if (!is_array($payload)) {
            return $errorResponse(400, 'call_app_installation_invalid_request_body', 'Call App installation payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }
        try {
            $pdo = $openDatabase();
            videochat_call_app_refresh_catalog($pdo);
            $result = videochat_call_app_create_organization_installation(
                $pdo,
                (int) ($admin['tenant_id'] ?? 0),
                (int) ($admin['actor_user_id'] ?? 0),
                rawurldecode((string) ($installMatch[1] ?? '')),
                $payload
            );
        } catch (Throwable) {
            return $errorResponse(500, 'call_app_installation_failed', 'Could not install Call App.', [
                'reason' => 'internal_error',
            ]);
        }
        if (!(bool) ($result['ok'] ?? false)) {
            $reason = (string) ($result['reason'] ?? 'internal_error');
            $status = $reason === 'app_not_found' ? 404 : ($reason === 'validation_failed' ? 422 : 409);
            return $errorResponse($status, 'call_app_installation_failed', 'Could not install Call App.', [
                'reason' => $reason,
                'fields' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
            ]);
        }

        return $jsonResponse(201, ['status' => 'ok', 'result' => $result, 'time' => gmdate('c')]);
    }

    if (preg_match('#^/api/marketplace/call-apps/([A-Za-z0-9._-]+)/installations/([A-Za-z0-9._:-]+)$#', $path, $installationMatch) === 1) {
        if ($method !== 'PATCH') {
            return $errorResponse(405, 'method_not_allowed', 'Use PATCH for Call App installation status.', [
                'allowed_methods' => ['PATCH'],
            ]);
        }
        $admin = videochat_marketplace_tenant_admin_decision($apiAuthContext);
        if (!(bool) ($admin['ok'] ?? false)) {
            return $errorResponse(403, 'call_app_installation_forbidden', 'Call App installation changes require tenant administration rights.', [
                'reason' => (string) ($admin['reason'] ?? 'tenant_admin_required'),
            ]);
        }
        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'call_app_installation_invalid_request_body', 'Call App installation payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }
        try {
            $pdo = $openDatabase();
            videochat_call_app_refresh_catalog($pdo);
            $result = videochat_call_app_update_organization_installation(
                $pdo,
                (int) ($admin['tenant_id'] ?? 0),
                rawurldecode((string) ($installationMatch[1] ?? '')),
                rawurldecode((string) ($installationMatch[2] ?? '')),
                $payload
            );
        } catch (Throwable) {
            return $errorResponse(500, 'call_app_installation_update_failed', 'Could not update Call App installation.', [
                'reason' => 'internal_error',
            ]);
        }
        if (!(bool) ($result['ok'] ?? false)) {
            $reason = (string) ($result['reason'] ?? 'internal_error');
            $status = in_array($reason, ['app_not_found', 'installation_not_found'], true) ? 404 : 422;
            return $errorResponse($status, 'call_app_installation_update_failed', 'Could not update Call App installation.', [
                'reason' => $reason,
                'fields' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
            ]);
        }

        return $jsonResponse(200, ['status' => 'ok', 'result' => $result, 'time' => gmdate('c')]);
    }

    if ($path === '/api/admin/marketplace/apps') {
        if ($method === 'GET') {
            $queryParams = videochat_request_query_params($request);
            $filters = videochat_call_app_marketplace_filters($queryParams);
            if (!(bool) ($filters['ok'] ?? false)) {
                return $errorResponse(422, 'marketplace_call_app_list_validation_failed', 'Invalid marketplace app query parameters.', [
                    'fields' => $filters['errors'] ?? [],
                ]);
            }

            try {
                $pdo = $openDatabase();
                $listing = videochat_admin_list_call_apps(
                    $pdo,
                    (string) ($filters['query'] ?? ''),
                    (int) ($filters['page'] ?? 1),
                    (int) ($filters['page_size'] ?? 10),
                    (string) ($filters['category'] ?? 'all')
                );
            } catch (Throwable) {
                return $errorResponse(500, 'marketplace_call_app_list_failed', 'Could not load marketplace apps.', [
                    'reason' => 'internal_error',
                ]);
            }

            $rows = is_array($listing['rows'] ?? null) ? $listing['rows'] : [];
            try {
                videochat_call_app_refresh_catalog($pdo);
                $catalogApps = videochat_call_app_list_catalog($pdo, '', 'all');
                $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);
                if ($tenantId > 0) {
                    $catalogApps = videochat_call_app_attach_organization_state($pdo, $tenantId, $catalogApps);
                }
                $rows = videochat_admin_call_app_attach_catalog_entries($rows, $catalogApps);
            } catch (Throwable) {
                $rows = videochat_admin_call_app_attach_catalog_entries($rows, []);
            }
            $total = (int) ($listing['total'] ?? 0);
            $pageCount = (int) ($listing['page_count'] ?? 1);
            $page = (int) ($filters['page'] ?? 1);
            $pageSize = (int) ($filters['page_size'] ?? 10);

            return $jsonResponse(200, [
                'status' => 'ok',
                'apps' => $rows,
                'filters' => [
                    'query' => (string) ($filters['query'] ?? ''),
                    'category' => (string) ($filters['category'] ?? 'all'),
                ],
                'categories' => videochat_call_app_marketplace_categories(),
                'pagination' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => $total,
                    'page_count' => $pageCount,
                    'returned' => count($rows),
                    'has_prev' => $page > 1,
                    'has_next' => $pageCount > 0 && $page < $pageCount,
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or POST for /api/admin/marketplace/apps.', [
                'allowed_methods' => ['GET', 'POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'marketplace_call_app_invalid_request_body', 'Marketplace app payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $createResult = videochat_admin_create_call_app($pdo, $payload);
        } catch (Throwable) {
            return $errorResponse(500, 'marketplace_call_app_create_failed', 'Could not create marketplace app.', [
                'reason' => 'internal_error',
            ]);
        }

        $reason = (string) ($createResult['reason'] ?? 'internal_error');
        if (!(bool) ($createResult['ok'] ?? false)) {
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'marketplace_call_app_validation_failed', 'Marketplace app payload failed validation.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : [],
                ]);
            }
            if ($reason === 'conflict') {
                return $errorResponse(409, 'marketplace_call_app_conflict', 'A marketplace app with that name and manufacturer already exists.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : ['name' => 'already_exists'],
                ]);
            }

            return $errorResponse(500, 'marketplace_call_app_create_failed', 'Could not create marketplace app.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(201, [
            'status' => 'ok',
            'result' => [
                'state' => 'created',
                'app' => $createResult['app'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/admin/marketplace/apps/(\d+)$#', $path, $matches) === 1) {
        $appId = (int) ($matches[1] ?? 0);

        if ($method === 'GET') {
            try {
                $pdo = $openDatabase();
                $app = videochat_admin_fetch_call_app($pdo, $appId);
            } catch (Throwable) {
                return $errorResponse(500, 'marketplace_call_app_fetch_failed', 'Could not load marketplace app.', [
                    'reason' => 'internal_error',
                ]);
            }

            if (!is_array($app)) {
                return $errorResponse(404, 'marketplace_call_app_not_found', 'The requested marketplace app does not exist.', [
                    'app_id' => $appId,
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'app' => $app,
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'PATCH') {
            [$payload, $decodeError] = $decodeJsonBody($request);
            if (!is_array($payload)) {
                return $errorResponse(400, 'marketplace_call_app_invalid_request_body', 'Marketplace app payload must be a non-empty JSON object.', [
                    'reason' => $decodeError,
                ]);
            }

            try {
                $pdo = $openDatabase();
                $updateResult = videochat_admin_update_call_app($pdo, $appId, $payload);
            } catch (Throwable) {
                return $errorResponse(500, 'marketplace_call_app_update_failed', 'Could not update marketplace app.', [
                    'reason' => 'internal_error',
                ]);
            }

            $reason = (string) ($updateResult['reason'] ?? 'internal_error');
            if (!(bool) ($updateResult['ok'] ?? false)) {
                if ($reason === 'not_found') {
                    return $errorResponse(404, 'marketplace_call_app_not_found', 'The requested marketplace app does not exist.', [
                        'app_id' => $appId,
                    ]);
                }
                if ($reason === 'validation_failed') {
                    return $errorResponse(422, 'marketplace_call_app_validation_failed', 'Marketplace app payload failed validation.', [
                        'fields' => is_array($updateResult['errors'] ?? null) ? $updateResult['errors'] : [],
                    ]);
                }
                if ($reason === 'conflict') {
                    return $errorResponse(409, 'marketplace_call_app_conflict', 'A marketplace app with that name and manufacturer already exists.', [
                        'fields' => is_array($updateResult['errors'] ?? null) ? $updateResult['errors'] : ['name' => 'already_exists'],
                    ]);
                }

                return $errorResponse(500, 'marketplace_call_app_update_failed', 'Could not update marketplace app.', [
                    'reason' => 'internal_error',
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'updated',
                    'app' => $updateResult['app'] ?? null,
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'DELETE') {
            try {
                $pdo = $openDatabase();
                $deleteResult = videochat_admin_delete_call_app($pdo, $appId);
            } catch (Throwable) {
                return $errorResponse(500, 'marketplace_call_app_delete_failed', 'Could not delete marketplace app.', [
                    'reason' => 'internal_error',
                ]);
            }

            if (!(bool) ($deleteResult['ok'] ?? false)) {
                return $errorResponse(404, 'marketplace_call_app_not_found', 'The requested marketplace app does not exist.', [
                    'app_id' => $appId,
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'deleted',
                    'app' => $deleteResult['app'] ?? null,
                ],
                'time' => gmdate('c'),
            ]);
        }

        return $errorResponse(405, 'method_not_allowed', 'Use GET, PATCH or DELETE for /api/admin/marketplace/apps/{id}.', [
            'allowed_methods' => ['GET', 'PATCH', 'DELETE'],
        ]);
    }

    return null;
}
