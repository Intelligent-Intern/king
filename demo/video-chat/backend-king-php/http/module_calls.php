<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/realtime/chat_archive.php';

function videochat_handle_call_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase,
    ?callable $issueSessionId = null
): ?array {
    $effectiveSessionIssuer = $issueSessionId;
    if (!is_callable($effectiveSessionIssuer)) {
        $effectiveSessionIssuer = static function (): string {
            try {
                return 'sess_' . bin2hex(random_bytes(20));
            } catch (Throwable) {
                return 'sess_' . hash('sha256', uniqid('videochat', true) . microtime(true));
            }
        };
    }

    if (preg_match('#^/api/call-access/([A-Fa-f0-9-]{36})/join$#', $path, $publicAccessMatch) === 1) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/call-access/{id}/join.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        $accessId = (string) ($publicAccessMatch[1] ?? '');
        try {
            $pdo = $openDatabase();
            $resolveResult = videochat_resolve_call_access_public($pdo, $accessId);
        } catch (Throwable) {
            return $errorResponse(500, 'call_access_resolve_failed', 'Could not resolve call access.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($resolveResult['ok'] ?? false)) {
            $reason = (string) ($resolveResult['reason'] ?? 'internal_error');
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'call_access_validation_failed', 'Call access id is invalid.', [
                    'fields' => is_array($resolveResult['errors'] ?? null) ? $resolveResult['errors'] : [],
                ]);
            }
            if ($reason === 'not_found') {
                return $errorResponse(404, 'call_access_not_found', 'Call access link does not exist.', [
                    'access_id' => strtolower(trim($accessId)),
                ]);
            }
            if ($reason === 'expired') {
                return $errorResponse(410, 'call_access_expired', 'Call access link has expired.', [
                    'access_id' => strtolower(trim($accessId)),
                ]);
            }
            if ($reason === 'conflict') {
                return $errorResponse(409, 'call_access_conflict', 'Call access cannot be used for the current call state.', [
                    'access_id' => strtolower(trim($accessId)),
                    'fields' => is_array($resolveResult['errors'] ?? null) ? $resolveResult['errors'] : [],
                ]);
            }

            return $errorResponse(500, 'call_access_resolve_failed', 'Could not resolve call access.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'resolved',
                'access_link' => $resolveResult['access_link'] ?? null,
                'link_kind' => videochat_call_access_link_kind(
                    is_array($resolveResult['access_link'] ?? null) ? $resolveResult['access_link'] : null
                ),
                'call' => $resolveResult['call'] ?? null,
                'target_user' => $resolveResult['target_user'] ?? null,
                'target_hint' => $resolveResult['target_hint'] ?? ['participant_email' => null],
                'join_path' => '/join/' . strtolower(trim($accessId)),
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/call-access/([A-Fa-f0-9-]{36})/session$#', $path, $publicSessionMatch) === 1) {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/call-access/{id}/session.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        $accessId = (string) ($publicSessionMatch[1] ?? '');
        $sessionOptions = [];
        $rawBody = $request['body'] ?? '';
        if (is_string($rawBody) && trim($rawBody) !== '') {
            [$payload, $decodeError] = $decodeJsonBody($request);
            if (!is_array($payload)) {
                return $errorResponse(400, 'call_access_invalid_request_body', 'Call access session payload must be a JSON object.', [
                    'reason' => $decodeError,
                ]);
            }
            if (array_key_exists('guest_name', $payload)) {
                $sessionOptions['guest_name'] = $payload['guest_name'];
            }
        }

        try {
            $pdo = $openDatabase();
            $issueResult = videochat_issue_session_for_call_access(
                $pdo,
                $accessId,
                $effectiveSessionIssuer,
                [
                    'client_ip' => trim((string) ($request['remote_address'] ?? '')),
                    'user_agent' => videochat_request_header_value($request, 'user-agent'),
                ],
                $sessionOptions
            );
        } catch (Throwable) {
            return $errorResponse(500, 'call_access_session_failed', 'Could not start call session.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($issueResult['ok'] ?? false)) {
            $reason = (string) ($issueResult['reason'] ?? 'internal_error');
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'call_access_validation_failed', 'Call access id is invalid.', [
                    'fields' => is_array($issueResult['errors'] ?? null) ? $issueResult['errors'] : [],
                ]);
            }
            if ($reason === 'not_found') {
                return $errorResponse(404, 'call_access_not_found', 'Call access link or mapped user does not exist.', [
                    'access_id' => strtolower(trim($accessId)),
                    'fields' => is_array($issueResult['errors'] ?? null) ? $issueResult['errors'] : [],
                ]);
            }
            if ($reason === 'expired') {
                return $errorResponse(410, 'call_access_expired', 'Call access link has expired.', [
                    'access_id' => strtolower(trim($accessId)),
                ]);
            }
            if ($reason === 'conflict') {
                return $errorResponse(409, 'call_access_conflict', 'Call access cannot be used for the current call state.', [
                    'access_id' => strtolower(trim($accessId)),
                    'fields' => is_array($issueResult['errors'] ?? null) ? $issueResult['errors'] : [],
                ]);
            }
            if ($reason === 'forbidden') {
                return $errorResponse(403, 'call_access_forbidden', 'Call access link is not allowed for this call participant.', [
                    'access_id' => strtolower(trim($accessId)),
                    'fields' => is_array($issueResult['errors'] ?? null) ? $issueResult['errors'] : [],
                ]);
            }

            return $errorResponse(500, 'call_access_session_failed', 'Could not start call session.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'session_started',
                'session' => $issueResult['session'] ?? null,
                'user' => $issueResult['user'] ?? null,
                'access_link' => $issueResult['access_link'] ?? null,
                'link_kind' => videochat_call_access_link_kind(
                    is_array($issueResult['access_link'] ?? null) ? $issueResult['access_link'] : null
                ),
                'call' => $issueResult['call'] ?? null,
                'join_path' => '/join/' . strtolower(trim($accessId)),
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/call-access/([A-Fa-f0-9-]{36})$#', $path, $accessMatch) === 1) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/call-access/{id}.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        $accessId = (string) ($accessMatch[1] ?? '');
        try {
            $pdo = $openDatabase();
            $resolveResult = videochat_resolve_call_access_for_user(
                $pdo,
                $accessId,
                $authenticatedUserId,
                $authenticatedUserRole
            );
        } catch (Throwable) {
            return $errorResponse(500, 'call_access_resolve_failed', 'Could not resolve call access.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($resolveResult['ok'] ?? false)) {
            $reason = (string) ($resolveResult['reason'] ?? 'internal_error');
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'call_access_validation_failed', 'Call access id is invalid.', [
                    'fields' => is_array($resolveResult['errors'] ?? null) ? $resolveResult['errors'] : [],
                ]);
            }
            if ($reason === 'not_found') {
                $callResolution = videochat_get_call_for_user(
                    $pdo,
                    strtolower(trim($accessId)),
                    $authenticatedUserId,
                    $authenticatedUserRole
                );

                if ((bool) ($callResolution['ok'] ?? false)) {
                    return $jsonResponse(200, [
                        'status' => 'ok',
                        'result' => [
                            'state' => 'resolved',
                            'access_link' => null,
                            'call' => $callResolution['call'] ?? null,
                            'resolved_as' => 'call_id',
                        ],
                        'time' => gmdate('c'),
                    ]);
                }

                return $errorResponse(404, 'call_access_not_found', 'Call access link does not exist.', [
                    'access_id' => strtolower(trim($accessId)),
                ]);
            }
            if ($reason === 'expired') {
                return $errorResponse(410, 'call_access_expired', 'Call access link has expired.', [
                    'access_id' => strtolower(trim($accessId)),
                ]);
            }
            if ($reason === 'forbidden') {
                return $errorResponse(403, 'call_access_forbidden', 'You are not allowed to use this call access link.', [
                    'access_id' => strtolower(trim($accessId)),
                ]);
            }

            return $errorResponse(500, 'call_access_resolve_failed', 'Could not resolve call access.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'resolved',
                'access_link' => $resolveResult['access_link'] ?? null,
                'call' => $resolveResult['call'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/calls/resolve/([A-Za-z0-9._-]{1,200})$#', $path, $resolveMatch) === 1) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/calls/resolve/{ref}.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        $callRef = trim((string) ($resolveMatch[1] ?? ''));
        $isUuidRef = preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $callRef) === 1;

        try {
            $pdo = $openDatabase();
            $callResolution = videochat_get_call_for_user(
                $pdo,
                $callRef,
                $authenticatedUserId,
                $authenticatedUserRole
            );

            if ((bool) ($callResolution['ok'] ?? false)) {
                return $jsonResponse(200, [
                    'status' => 'ok',
                    'result' => [
                        'state' => 'resolved',
                        'resolved_as' => 'call_id',
                        'access_link' => null,
                        'call' => $callResolution['call'] ?? null,
                    ],
                    'time' => gmdate('c'),
                ]);
            }

            $callReason = (string) ($callResolution['reason'] ?? 'internal_error');
            if ($callReason === 'forbidden') {
                return $jsonResponse(200, [
                    'status' => 'ok',
                    'result' => [
                        'state' => 'forbidden',
                        'resolved_as' => 'call_id',
                        'reason' => 'calls_forbidden',
                        'access_link' => null,
                        'call' => null,
                    ],
                    'time' => gmdate('c'),
                ]);
            }
            if ($callReason !== 'not_found') {
                return $errorResponse(500, 'calls_resolve_failed', 'Could not resolve call route reference.', [
                    'reason' => 'internal_error',
                ]);
            }

            if ($isUuidRef) {
                $accessResolution = videochat_resolve_call_access_for_user(
                    $pdo,
                    strtolower($callRef),
                    $authenticatedUserId,
                    $authenticatedUserRole
                );

                if ((bool) ($accessResolution['ok'] ?? false)) {
                    return $jsonResponse(200, [
                        'status' => 'ok',
                        'result' => [
                            'state' => 'resolved',
                            'resolved_as' => 'access_id',
                            'access_link' => $accessResolution['access_link'] ?? null,
                            'call' => $accessResolution['call'] ?? null,
                        ],
                        'time' => gmdate('c'),
                    ]);
                }

                $accessReason = (string) ($accessResolution['reason'] ?? 'internal_error');
                if ($accessReason === 'expired') {
                    return $jsonResponse(200, [
                        'status' => 'ok',
                        'result' => [
                            'state' => 'expired',
                            'resolved_as' => 'access_id',
                            'reason' => 'call_access_expired',
                            'access_link' => null,
                            'call' => null,
                        ],
                        'time' => gmdate('c'),
                    ]);
                }
                if ($accessReason === 'forbidden') {
                    return $jsonResponse(200, [
                        'status' => 'ok',
                        'result' => [
                            'state' => 'forbidden',
                            'resolved_as' => 'access_id',
                            'reason' => 'call_access_forbidden',
                            'access_link' => null,
                            'call' => null,
                        ],
                        'time' => gmdate('c'),
                    ]);
                }
                if (!in_array($accessReason, ['not_found', 'validation_failed'], true)) {
                    return $errorResponse(500, 'calls_resolve_failed', 'Could not resolve call route reference.', [
                        'reason' => 'internal_error',
                    ]);
                }
            }
        } catch (Throwable) {
            return $errorResponse(500, 'calls_resolve_failed', 'Could not resolve call route reference.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'not_found',
                'resolved_as' => '',
                'reason' => 'route_call_ref_not_found',
                'access_link' => null,
                'call' => null,
            ],
            'time' => gmdate('c'),
        ]);
    }

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

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/chat-archive$#', $path, $chatArchiveMatch) === 1) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/calls/{id}/chat-archive.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        try {
            $pdo = $openDatabase();
            $archiveResult = videochat_chat_archive_fetch(
                $pdo,
                (string) ($chatArchiveMatch[1] ?? ''),
                $authenticatedUserId,
                $authenticatedUserRole,
                videochat_request_query_params($request)
            );
        } catch (Throwable) {
            return $errorResponse(500, 'chat_archive_load_failed', 'Could not load chat archive.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($archiveResult['ok'] ?? false)) {
            $reason = (string) ($archiveResult['reason'] ?? 'internal_error');
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'chat_archive_validation_failed', 'Chat archive request failed validation.', [
                    'fields' => is_array($archiveResult['errors'] ?? null) ? $archiveResult['errors'] : [],
                ]);
            }
            if ($reason === 'not_found') {
                return $errorResponse(404, 'chat_archive_not_found', 'The requested chat archive does not exist.', [
                    'call_id' => (string) ($chatArchiveMatch[1] ?? ''),
                ]);
            }
            if ($reason === 'forbidden') {
                return $errorResponse(403, 'chat_archive_forbidden', 'You are not allowed to view this chat archive.', [
                    'call_id' => (string) ($chatArchiveMatch[1] ?? ''),
                    'fields' => is_array($archiveResult['errors'] ?? null) ? $archiveResult['errors'] : [],
                ]);
            }

            return $errorResponse(500, 'chat_archive_load_failed', 'Could not load chat archive.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'loaded',
                'archive' => $archiveResult['archive'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/access-link$#', $path, $accessLinkMatch) === 1) {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/calls/{id}/access-link.', [
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

        $callId = (string) ($accessLinkMatch[1] ?? '');
        $options = [];
        $rawBody = $request['body'] ?? '';
        if (is_string($rawBody) && trim($rawBody) !== '') {
            [$payload, $decodeError] = $decodeJsonBody($request);
            if (!is_array($payload)) {
                return $errorResponse(400, 'call_access_invalid_request_body', 'Call access payload must be a JSON object.', [
                    'reason' => $decodeError,
                ]);
            }
            if (array_key_exists('participant_user_id', $payload)) {
                $options['participant_user_id'] = $payload['participant_user_id'];
            }
            if (array_key_exists('participant_email', $payload)) {
                $options['participant_email'] = $payload['participant_email'];
            }
            if (array_key_exists('link_kind', $payload)) {
                $options['link_kind'] = $payload['link_kind'];
            }
        }

        try {
            $pdo = $openDatabase();
            $accessResult = videochat_create_call_access_link_for_user(
                $pdo,
                $callId,
                $authenticatedUserId,
                $authenticatedUserRole,
                $options
            );
        } catch (Throwable) {
            return $errorResponse(500, 'call_access_create_failed', 'Could not create call access link.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($accessResult['ok'] ?? false)) {
            $reason = (string) ($accessResult['reason'] ?? 'internal_error');
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'call_access_validation_failed', 'Call access payload failed validation.', [
                    'fields' => is_array($accessResult['errors'] ?? null) ? $accessResult['errors'] : [],
                ]);
            }
            if ($reason === 'not_found') {
                return $errorResponse(404, 'calls_not_found', 'The requested call does not exist.', [
                    'call_id' => $callId,
                ]);
            }
            if ($reason === 'forbidden') {
                return $errorResponse(403, 'calls_forbidden', 'You are not allowed to access this call.', [
                    'call_id' => $callId,
                ]);
            }

            return $errorResponse(500, 'call_access_create_failed', 'Could not create call access link.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'ready',
                'access_link' => $accessResult['access_link'] ?? null,
                'link_kind' => videochat_call_access_link_kind(
                    is_array($accessResult['access_link'] ?? null) ? $accessResult['access_link'] : null
                ),
                'call' => $accessResult['call'] ?? null,
                'join_path' => '/join/' . strtolower(trim((string) (($accessResult['access_link']['id'] ?? '')))),
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

        if ($method === 'DELETE') {
            try {
                $pdo = $openDatabase();
                $deleteResult = videochat_delete_call(
                    $pdo,
                    $callId,
                    $authenticatedUserId,
                    $authenticatedUserRole
                );
            } catch (Throwable) {
                return $errorResponse(500, 'calls_delete_failed', 'Could not delete call.', [
                    'reason' => 'internal_error',
                ]);
            }

            $deleteReason = (string) ($deleteResult['reason'] ?? 'internal_error');
            if (!(bool) ($deleteResult['ok'] ?? false)) {
                if ($deleteReason === 'not_found') {
                    return $errorResponse(404, 'calls_not_found', 'The requested call does not exist.', [
                        'call_id' => $callId,
                    ]);
                }
                if ($deleteReason === 'forbidden') {
                    return $errorResponse(403, 'calls_forbidden', 'You are not allowed to delete this call.', [
                        'call_id' => $callId,
                    ]);
                }

                return $errorResponse(500, 'calls_delete_failed', 'Could not delete call.', [
                    'reason' => 'internal_error',
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'deleted',
                    'call' => $deleteResult['call'] ?? null,
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method !== 'PATCH') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET, PATCH, or DELETE for /api/calls/{id}.', [
                'allowed_methods' => ['GET', 'PATCH', 'DELETE'],
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
