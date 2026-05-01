<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/marketplace/call_app_marketplace.php';

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
