<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/call_apps/call_app_sessions.php';
require_once __DIR__ . '/../support/tenant_context.php';

function videochat_call_app_module_json_body(array $request, ?callable $decodeJsonBody): array
{
    if ($decodeJsonBody === null) {
        $body = $request['body'] ?? '';
        $decoded = is_string($body) ? json_decode($body, true) : null;
        return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
    }

    return $decodeJsonBody($request);
}

function videochat_call_app_module_can_manage_call(array $call, int $actorUserId, string $actorRole): bool
{
    $ownerUserId = (int) (($call['owner'] ?? [])['user_id'] ?? 0);
    return videochat_can_edit_call($actorRole, $actorUserId, $ownerUserId);
}

function videochat_call_app_module_call_response_error(array $callResolution, string $callId, callable $errorResponse): ?array
{
    if ((bool) ($callResolution['ok'] ?? false)) {
        return null;
    }

    $reason = (string) ($callResolution['reason'] ?? 'internal_error');
    if ($reason === 'not_found') {
        return $errorResponse(404, 'calls_not_found', 'The requested call does not exist.', ['call_id' => $callId]);
    }
    if ($reason === 'forbidden') {
        return $errorResponse(403, 'calls_forbidden', 'You are not allowed to view this call.', ['call_id' => $callId]);
    }

    return $errorResponse(500, 'call_app_call_resolution_failed', 'Could not resolve call for Call App operation.', [
        'reason' => 'internal_error',
    ]);
}

function videochat_handle_call_app_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase,
    ?callable $decodeJsonBody = null
): ?array {
    $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
    $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
    $tenantId = videochat_tenant_id_from_auth_context($apiAuthContext);

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/call-apps/available$#', $path, $availabilityMatch) === 1) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/calls/{id}/call-apps/available.', [
                'allowed_methods' => ['GET'],
            ]);
        }
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
            $callError = videochat_call_app_module_call_response_error($callResolution, $callId, $errorResponse);
            if ($callError !== null) {
                return $callError;
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

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/call-app-sessions$#', $path, $sessionCollectionMatch) === 1) {
        if (!in_array($method, ['GET', 'POST'], true)) {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or POST for /api/calls/{id}/call-app-sessions.', [
                'allowed_methods' => ['GET', 'POST'],
            ]);
        }
        if ($authenticatedUserId <= 0 || $tenantId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token and tenant context are required.', [
                'reason' => 'invalid_user_or_tenant_context',
            ]);
        }

        $callId = (string) ($sessionCollectionMatch[1] ?? '');
        try {
            $pdo = $openDatabase();
            videochat_call_app_refresh_catalog($pdo);
            $callResolution = videochat_get_call_for_user($pdo, $callId, $authenticatedUserId, $authenticatedUserRole, $tenantId);
            $callError = videochat_call_app_module_call_response_error($callResolution, $callId, $errorResponse);
            if ($callError !== null) {
                return $callError;
            }
            $call = is_array($callResolution['call'] ?? null) ? $callResolution['call'] : [];
            if ($method === 'GET') {
                $query = videochat_request_query_params($request);
                $includeRemoved = (string) ($query['include_removed'] ?? '') === '1';
                return $jsonResponse(200, [
                    'status' => 'ok',
                    'result' => [
                        'call_id' => $callId,
                        'tenant_id' => $tenantId,
                        'sessions' => videochat_call_app_list_sessions_for_call($pdo, $tenantId, $callId, $includeRemoved),
                    ],
                    'time' => gmdate('c'),
                ]);
            }

            if (!videochat_call_app_module_can_manage_call($call, $authenticatedUserId, $authenticatedUserRole)) {
                return $errorResponse(403, 'call_app_session_forbidden', 'Only the call owner or an administrator can attach Call Apps.', [
                    'call_id' => $callId,
                ]);
            }
            [$payload, $decodeError] = videochat_call_app_module_json_body($request, $decodeJsonBody);
            if (!is_array($payload)) {
                return $errorResponse(400, 'call_app_session_invalid_request_body', 'Call App session payload must be a JSON object.', [
                    'reason' => $decodeError,
                ]);
            }
            $result = videochat_call_app_create_session(
                $pdo,
                $tenantId,
                $callId,
                $authenticatedUserId,
                (string) ($payload['app_key'] ?? ''),
                (string) ($payload['default_app_policy'] ?? 'blocked_by_default')
            );
        } catch (Throwable) {
            return $errorResponse(500, 'call_app_session_failed', 'Could not process Call App session.', [
                'reason' => 'internal_error',
            ]);
        }
        if (!(bool) ($result['ok'] ?? false)) {
            $reason = (string) ($result['reason'] ?? 'internal_error');
            $status = $reason === 'app_not_available' ? 409 : ($reason === 'validation_failed' ? 422 : 400);
            return $errorResponse($status, 'call_app_session_failed', 'Could not create Call App session.', [
                'reason' => $reason,
                'fields' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
            ]);
        }

        return $jsonResponse((string) ($result['state'] ?? '') === 'created' ? 201 : 200, [
            'status' => 'ok',
            'result' => $result,
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/call-app-sessions/([A-Za-z0-9._:-]+)$#', $path, $sessionMatch) === 1) {
        if (!in_array($method, ['PATCH', 'DELETE'], true)) {
            return $errorResponse(405, 'method_not_allowed', 'Use PATCH or DELETE for /api/call-app-sessions/{session_id}.', [
                'allowed_methods' => ['PATCH', 'DELETE'],
            ]);
        }
        if ($authenticatedUserId <= 0 || $tenantId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token and tenant context are required.', [
                'reason' => 'invalid_user_or_tenant_context',
            ]);
        }

        $sessionId = rawurldecode((string) ($sessionMatch[1] ?? ''));
        try {
            $pdo = $openDatabase();
            $sessionRecord = videochat_call_app_fetch_session_record($pdo, $tenantId, $sessionId);
            if (!is_array($sessionRecord)) {
                return $errorResponse(404, 'call_app_session_not_found', 'The requested Call App session does not exist.', [
                    'session_id' => $sessionId,
                ]);
            }
            $callId = (string) ($sessionRecord['call_id'] ?? '');
            $callResolution = videochat_get_call_for_user($pdo, $callId, $authenticatedUserId, $authenticatedUserRole, $tenantId);
            $callError = videochat_call_app_module_call_response_error($callResolution, $callId, $errorResponse);
            if ($callError !== null) {
                return $callError;
            }
            $call = is_array($callResolution['call'] ?? null) ? $callResolution['call'] : [];
            if (!videochat_call_app_module_can_manage_call($call, $authenticatedUserId, $authenticatedUserRole)) {
                return $errorResponse(403, 'call_app_session_forbidden', 'Only the call owner or an administrator can update Call Apps.', [
                    'session_id' => $sessionId,
                ]);
            }

            if ($method === 'DELETE') {
                $result = videochat_call_app_remove_session($pdo, $tenantId, $sessionId, $authenticatedUserId);
            } else {
                [$payload, $decodeError] = videochat_call_app_module_json_body($request, $decodeJsonBody);
                if (!is_array($payload)) {
                    return $errorResponse(400, 'call_app_session_invalid_request_body', 'Call App session payload must be a JSON object.', [
                        'reason' => $decodeError,
                    ]);
                }
                $result = videochat_call_app_update_session($pdo, $tenantId, $sessionId, $authenticatedUserId, $payload);
            }
        } catch (Throwable) {
            return $errorResponse(500, 'call_app_session_failed', 'Could not update Call App session.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($result['ok'] ?? false)) {
            $reason = (string) ($result['reason'] ?? 'internal_error');
            $status = $reason === 'session_not_found' ? 404 : ($reason === 'validation_failed' ? 422 : 409);
            return $errorResponse($status, 'call_app_session_failed', 'Could not update Call App session.', [
                'reason' => $reason,
                'fields' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
            ]);
        }

        return $jsonResponse(200, ['status' => 'ok', 'result' => $result, 'time' => gmdate('c')]);
    }

    return null;
}
