<?php

declare(strict_types=1);

function videochat_handle_call_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    if ($path === '/api/calls') {
        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        if ($method === 'GET') {
            $queryParams = videochat_request_query_params($request);
            $filters = videochat_calls_list_filters($queryParams, $authenticatedUserRole);
            if (!(bool) ($filters['ok'] ?? false)) {
                return $errorResponse(422, 'calls_list_validation_failed', 'Invalid call list query parameters.', [
                    'fields' => $filters['errors'] ?? [],
                ]);
            }

            try {
                $pdo = $openDatabase();
                $listing = videochat_list_calls($pdo, $authenticatedUserId, $filters);
            } catch (Throwable) {
                return $errorResponse(500, 'calls_list_failed', 'Could not load calls list.', [
                    'reason' => 'internal_error',
                ]);
            }

            $rows = is_array($listing['rows'] ?? null) ? $listing['rows'] : [];
            $total = (int) ($listing['total'] ?? 0);
            $pageCount = (int) ($listing['page_count'] ?? 0);
            $page = (int) ($filters['page'] ?? 1);
            $pageSize = (int) ($filters['page_size'] ?? 10);

            return $jsonResponse(200, [
                'status' => 'ok',
                'calls' => $rows,
                'filters' => [
                    'query' => (string) ($filters['query'] ?? ''),
                    'status' => (string) ($filters['status'] ?? 'all'),
                    'requested_scope' => (string) ($filters['requested_scope'] ?? 'my'),
                    'effective_scope' => (string) ($filters['effective_scope'] ?? 'my'),
                ],
                'pagination' => [
                    'page' => $page,
                    'page_size' => $pageSize,
                    'total' => $total,
                    'page_count' => $pageCount,
                    'returned' => count($rows),
                    'has_prev' => $page > 1,
                    'has_next' => $pageCount > 0 && $page < $pageCount,
                ],
                'sort' => [
                    'primary' => 'starts_at_asc',
                    'secondary' => 'created_at_asc',
                    'tie_breaker' => 'id_asc',
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or POST for /api/calls.', [
                'allowed_methods' => ['GET', 'POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'calls_create_invalid_request_body', 'Call create payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $createResult = videochat_create_call($pdo, $authenticatedUserId, $payload);
        } catch (Throwable) {
            return $errorResponse(500, 'calls_create_failed', 'Could not create call.', [
                'reason' => 'internal_error',
            ]);
        }

        $createReason = (string) ($createResult['reason'] ?? 'internal_error');
        if (!(bool) ($createResult['ok'] ?? false)) {
            if ($createReason === 'validation_failed') {
                return $errorResponse(422, 'calls_create_validation_failed', 'Call create payload failed validation.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : [],
                ]);
            }
            if ($createReason === 'not_found') {
                return $errorResponse(404, 'calls_create_not_found', 'Call owner could not be resolved.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : [],
                ]);
            }

            return $errorResponse(500, 'calls_create_failed', 'Could not create call.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(201, [
            'status' => 'ok',
            'result' => [
                'state' => 'created',
                'call' => $createResult['call'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/cancel$#', $path, $callCancelMatch) === 1) {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/calls/{id}/cancel.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'calls_cancel_invalid_request_body', 'Call cancel payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        $callId = (string) ($callCancelMatch[1] ?? '');
        try {
            $pdo = $openDatabase();
            $cancelResult = videochat_cancel_call(
                $pdo,
                $callId,
                $authenticatedUserId,
                $authenticatedUserRole,
                $payload
            );
        } catch (Throwable) {
            return $errorResponse(500, 'calls_cancel_failed', 'Could not cancel call.', [
                'reason' => 'internal_error',
            ]);
        }

        $cancelReason = (string) ($cancelResult['reason'] ?? 'internal_error');
        if (!(bool) ($cancelResult['ok'] ?? false)) {
            if ($cancelReason === 'validation_failed') {
                $fields = is_array($cancelResult['errors'] ?? null) ? $cancelResult['errors'] : [];
                $statusError = (string) ($fields['status'] ?? '');
                if ($statusError !== '') {
                    return $errorResponse(409, 'calls_cancel_state_conflict', 'Call cannot be cancelled from its current state.', [
                        'fields' => $fields,
                        'call_id' => $callId,
                    ]);
                }

                return $errorResponse(422, 'calls_cancel_validation_failed', 'Call cancel payload failed validation.', [
                    'fields' => $fields,
                ]);
            }
            if ($cancelReason === 'not_found') {
                return $errorResponse(404, 'calls_not_found', 'The requested call does not exist.', [
                    'call_id' => $callId,
                ]);
            }
            if ($cancelReason === 'forbidden') {
                return $errorResponse(403, 'calls_forbidden', 'You are not allowed to cancel this call.', [
                    'call_id' => $callId,
                ]);
            }

            return $errorResponse(500, 'calls_cancel_failed', 'Could not cancel call.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'cancelled',
                'call' => $cancelResult['call'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/participants/(\d+)/role$#', $path, $participantRoleMatch) === 1) {
        if ($method !== 'PATCH') {
            return $errorResponse(405, 'method_not_allowed', 'Use PATCH for /api/calls/{id}/participants/{userId}/role.', [
                'allowed_methods' => ['PATCH'],
            ]);
        }

        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'calls_role_update_invalid_request_body', 'Participant-role payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        $callId = (string) ($participantRoleMatch[1] ?? '');
        $targetUserId = (int) ($participantRoleMatch[2] ?? 0);
        $targetRole = (string) ($payload['role'] ?? ($payload['call_role'] ?? ''));

        try {
            $pdo = $openDatabase();
            $roleUpdateResult = videochat_update_call_participant_role(
                $pdo,
                $callId,
                $targetUserId,
                $targetRole,
                $authenticatedUserId,
                $authenticatedUserRole
            );
        } catch (Throwable) {
            return $errorResponse(500, 'calls_role_update_failed', 'Could not update call participant role.', [
                'reason' => 'internal_error',
            ]);
        }

        $reason = (string) ($roleUpdateResult['reason'] ?? 'internal_error');
        if (!(bool) ($roleUpdateResult['ok'] ?? false)) {
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'calls_role_update_validation_failed', 'Call participant role payload failed validation.', [
                    'fields' => is_array($roleUpdateResult['errors'] ?? null) ? $roleUpdateResult['errors'] : [],
                ]);
            }
            if ($reason === 'not_found') {
                return $errorResponse(404, 'calls_not_found', 'The requested call does not exist.', [
                    'call_id' => $callId,
                ]);
            }
            if ($reason === 'forbidden') {
                return $errorResponse(403, 'calls_forbidden', 'You are not allowed to change call participant roles.', [
                    'call_id' => $callId,
                ]);
            }

            return $errorResponse(500, 'calls_role_update_failed', 'Could not update call participant role.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'participant_role_updated',
                'call' => $roleUpdateResult['call'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})$#', $path, $callMatch) === 1) {
        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        $callId = (string) ($callMatch[1] ?? '');
        if ($method === 'GET') {
            try {
                $pdo = $openDatabase();
                $fetchResult = videochat_get_call_for_user(
                    $pdo,
                    $callId,
                    $authenticatedUserId,
                    $authenticatedUserRole
                );
            } catch (Throwable) {
                return $errorResponse(500, 'calls_fetch_failed', 'Could not load call.', [
                    'reason' => 'internal_error',
                ]);
            }

            if (!(bool) ($fetchResult['ok'] ?? false)) {
                $reason = (string) ($fetchResult['reason'] ?? 'internal_error');
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

                return $errorResponse(500, 'calls_fetch_failed', 'Could not load call.', [
                    'reason' => 'internal_error',
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'call' => $fetchResult['call'] ?? null,
                'time' => gmdate('c'),
            ]);
        }

        if ($method !== 'PATCH') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or PATCH for /api/calls/{id}.', [
                'allowed_methods' => ['GET', 'PATCH'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'calls_update_invalid_request_body', 'Call update payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $updateResult = videochat_update_call(
                $pdo,
                $callId,
                $authenticatedUserId,
                $authenticatedUserRole,
                $payload
            );
        } catch (Throwable) {
            return $errorResponse(500, 'calls_update_failed', 'Could not update call.', [
                'reason' => 'internal_error',
            ]);
        }

        $updateReason = (string) ($updateResult['reason'] ?? 'internal_error');
        if (!(bool) ($updateResult['ok'] ?? false)) {
            if ($updateReason === 'validation_failed') {
                return $errorResponse(422, 'calls_update_validation_failed', 'Call update payload failed validation.', [
                    'fields' => is_array($updateResult['errors'] ?? null) ? $updateResult['errors'] : [],
                ]);
            }
            if ($updateReason === 'not_found') {
                return $errorResponse(404, 'calls_not_found', 'The requested call does not exist.', [
                    'call_id' => $callId,
                ]);
            }
            if ($updateReason === 'forbidden') {
                return $errorResponse(403, 'calls_forbidden', 'You are not allowed to edit this call.', [
                    'call_id' => $callId,
                ]);
            }

            return $errorResponse(500, 'calls_update_failed', 'Could not update call.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'updated',
                'call' => $updateResult['call'] ?? null,
                'invite_dispatch' => $updateResult['invite_dispatch'] ?? [
                    'global_resend_triggered' => false,
                    'explicit_action_required' => true,
                ],
            ],
            'time' => gmdate('c'),
        ]);
    }


    return null;
}
