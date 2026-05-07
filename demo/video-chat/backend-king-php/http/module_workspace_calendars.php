<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/workspace/workspace_calendars.php';
require_once __DIR__ . '/../support/auth_request.php';

function videochat_workspace_calendar_auth_user_id(array $apiAuthContext): int
{
    return (int) (($apiAuthContext['user']['id'] ?? 0));
}

function videochat_workspace_calendar_query_int(array $queryParams, string $key, int $fallback, int $min, int $max): int
{
    $value = filter_var($queryParams[$key] ?? $fallback, FILTER_VALIDATE_INT);
    if (!is_int($value)) {
        return $fallback;
    }

    return max($min, min($max, $value));
}

function videochat_handle_workspace_calendar_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    if ($path === '/api/calendars') {
        $authenticatedUserId = videochat_workspace_calendar_auth_user_id($apiAuthContext);
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        if ($method === 'GET') {
            $queryParams = videochat_request_query_params($request);
            $page = videochat_workspace_calendar_query_int($queryParams, 'page', 1, 1, 100000);
            $pageSize = videochat_workspace_calendar_query_int($queryParams, 'page_size', 10, 1, 100);
            $query = trim((string) ($queryParams['query'] ?? ($queryParams['q'] ?? '')));

            try {
                $pdo = $openDatabase();
                $listing = videochat_workspace_calendar_list(
                    $pdo,
                    $authenticatedUserId,
                    videochat_tenant_id_from_auth_context($apiAuthContext),
                    $query,
                    $page,
                    $pageSize
                );
            } catch (Throwable) {
                return $errorResponse(500, 'workspace_calendars_load_failed', 'Could not load calendars.', [
                    'reason' => 'internal_error',
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'calendars' => $listing['rows'],
                'pagination' => [
                    'query' => $query,
                    'page' => $listing['page'],
                    'page_size' => $listing['page_size'],
                    'total' => $listing['total'],
                    'page_count' => $listing['page_count'],
                    'returned' => count($listing['rows']),
                    'has_prev' => $listing['page'] > 1,
                    'has_next' => $listing['page_count'] > 0 && $listing['page'] < $listing['page_count'],
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or POST for /api/calendars.', [
                'allowed_methods' => ['GET', 'POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'workspace_calendar_invalid_request_body', 'Calendar payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $saveResult = videochat_workspace_calendar_save(
                $pdo,
                $authenticatedUserId,
                videochat_tenant_id_from_auth_context($apiAuthContext),
                $payload
            );
        } catch (Throwable) {
            return $errorResponse(500, 'workspace_calendar_save_failed', 'Could not save calendar.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($saveResult['ok'] ?? false)) {
            return $errorResponse(422, 'workspace_calendar_validation_failed', 'Calendar payload failed validation.', [
                'fields' => is_array($saveResult['errors'] ?? null) ? $saveResult['errors'] : [],
            ]);
        }

        return $jsonResponse(201, [
            'status' => 'ok',
            'calendar' => $saveResult['calendar'] ?? null,
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/calendars/([A-Fa-f0-9-]{36})$#', $path, $matches) === 1) {
        $authenticatedUserId = videochat_workspace_calendar_auth_user_id($apiAuthContext);
        $calendarId = videochat_workspace_calendar_normalize_id((string) ($matches[1] ?? ''));
        if ($authenticatedUserId <= 0 || $calendarId === '') {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        if ($method === 'PATCH') {
            [$payload, $decodeError] = $decodeJsonBody($request);
            if (!is_array($payload)) {
                return $errorResponse(400, 'workspace_calendar_invalid_request_body', 'Calendar payload must be a JSON object.', [
                    'reason' => $decodeError,
                ]);
            }

            try {
                $pdo = $openDatabase();
                $saveResult = videochat_workspace_calendar_save(
                    $pdo,
                    $authenticatedUserId,
                    videochat_tenant_id_from_auth_context($apiAuthContext),
                    $payload,
                    $calendarId
                );
            } catch (Throwable) {
                return $errorResponse(500, 'workspace_calendar_save_failed', 'Could not save calendar.', [
                    'reason' => 'internal_error',
                ]);
            }

            if (!(bool) ($saveResult['ok'] ?? false)) {
                $errors = is_array($saveResult['errors'] ?? null) ? $saveResult['errors'] : [];
                $status = ($errors['calendar'] ?? '') === 'not_found' ? 404 : (($errors['calendar'] ?? '') === 'owner_required' ? 403 : 422);
                return $errorResponse($status, 'workspace_calendar_validation_failed', 'Calendar payload failed validation.', [
                    'fields' => $errors,
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'calendar' => $saveResult['calendar'] ?? null,
                'time' => gmdate('c'),
            ]);
        }

        if ($method === 'DELETE') {
            try {
                $pdo = $openDatabase();
                $deleteResult = videochat_workspace_calendar_archive(
                    $pdo,
                    $authenticatedUserId,
                    videochat_tenant_id_from_auth_context($apiAuthContext),
                    $calendarId
                );
            } catch (Throwable) {
                return $errorResponse(500, 'workspace_calendar_delete_failed', 'Could not delete calendar.', [
                    'reason' => 'internal_error',
                ]);
            }

            if (!(bool) ($deleteResult['ok'] ?? false)) {
                $errors = is_array($deleteResult['errors'] ?? null) ? $deleteResult['errors'] : [];
                $status = ($errors['calendar'] ?? '') === 'not_found' ? 404 : 403;
                return $errorResponse($status, 'workspace_calendar_delete_rejected', 'Calendar could not be deleted.', [
                    'fields' => $errors,
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'time' => gmdate('c'),
            ]);
        }

        return $errorResponse(405, 'method_not_allowed', 'Use PATCH or DELETE for /api/calendars/{id}.', [
            'allowed_methods' => ['PATCH', 'DELETE'],
        ]);
    }

    return null;
}
