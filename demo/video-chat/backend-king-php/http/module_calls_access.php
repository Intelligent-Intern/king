<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_access.php';

function videochat_call_access_route_user_id(array $authContext): int
{
    return is_numeric($authContext['user']['id'] ?? null) ? (int) $authContext['user']['id'] : 0;
}

function videochat_call_access_route_session_id(array $authContext): string
{
    if (is_string($authContext['session']['id'] ?? null) || is_numeric($authContext['session']['id'] ?? null)) {
        return trim((string) $authContext['session']['id']);
    }

    if (is_string($authContext['token'] ?? null) || is_numeric($authContext['token'] ?? null)) {
        return trim((string) $authContext['token']);
    }

    return '';
}

function videochat_call_route_access_session_binding(PDO $pdo, array $apiAuthContext): ?array
{
    $sessionId = videochat_call_access_route_session_id($apiAuthContext);
    if ($sessionId === '') {
        return null;
    }

    return videochat_fetch_call_access_session_binding($pdo, $sessionId);
}

function videochat_call_route_ref_matches_access_binding(string $callRef, array $binding): bool
{
    $normalizedRef = strtolower(trim($callRef));
    if ($normalizedRef === '') {
        return false;
    }

    foreach (['call_id', 'access_id'] as $field) {
        $value = strtolower(trim((string) ($binding[$field] ?? '')));
        if ($value !== '' && hash_equals($value, $normalizedRef)) {
            return true;
        }
    }

    return false;
}

function videochat_call_access_resolved_target_user_id(array $resolveResult): int
{
    $targetUser = is_array($resolveResult['target_user'] ?? null) ? $resolveResult['target_user'] : [];
    return is_numeric($targetUser['id'] ?? null) ? (int) $targetUser['id'] : 0;
}

/**
 * @return array<int, string>
 */
function videochat_call_access_client_authority_fields(array $payload): array
{
    $blockedFields = [
        'access_link',
        'account_user_id',
        'authenticated_user_id',
        'call',
        'call_id',
        'is_admin',
        'is_guest',
        'org_id',
        'organization',
        'organization_id',
        'participant_user_id',
        'permissions',
        'role',
        'roles',
        'room_id',
        'target_user_id',
        'tenant',
        'tenant_id',
        'tenant_permissions',
        'user_id',
    ];
    $blocked = [];
    foreach ($blockedFields as $field) {
        if (array_key_exists($field, $payload)) {
            $blocked[] = $field;
        }
    }

    return $blocked;
}

/**
 * @return array<int, string>
 */
function videochat_call_access_request_query_authority_fields(array $request): array
{
    $query = videochat_request_query_params($request);
    return videochat_call_access_client_authority_fields($query);
}

function videochat_call_access_authority_field_response(
    array $fields,
    callable $errorResponse
): array {
    $details = [];
    foreach ($fields as $field) {
        $details[$field] = 'server_authoritative';
    }

    return $errorResponse(
        422,
        'call_access_validation_failed',
        'Call access requests cannot override server-bound identity or call context.',
        [
            'fields' => $details,
            'reason' => 'client_authority_fields_rejected',
        ]
    );
}

function videochat_call_access_normalize_origin(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }
    if (strtolower($trimmed) === 'null') {
        return 'null';
    }

    $parsed = parse_url($trimmed);
    if (!is_array($parsed)) {
        return '';
    }
    $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
    $host = strtolower((string) ($parsed['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return '';
    }
    $port = is_numeric($parsed['port'] ?? null) ? ':' . (int) $parsed['port'] : '';

    return $scheme . '://' . $host . $port;
}

/**
 * @return array<int, string>
 */
function videochat_call_access_allowed_state_change_origins(array $request): array
{
    $allowed = [];
    $add = static function (string $origin) use (&$allowed): void {
        $normalized = videochat_call_access_normalize_origin($origin);
        if ($normalized !== '' && $normalized !== 'null' && !in_array($normalized, $allowed, true)) {
            $allowed[] = $normalized;
        }
    };

    $configured = trim((string) (getenv('VIDEOCHAT_FRONTEND_ORIGIN') ?: ''));
    if ($configured !== '') {
        $add($configured);
    }

    $host = videochat_request_header_value($request, 'host');
    if ($host !== '') {
        $proto = strtolower(videochat_request_header_value($request, 'x-forwarded-proto'));
        if (!in_array($proto, ['http', 'https'], true)) {
            $proto = 'http';
        }
        $add($proto . '://' . $host);
        $add(($proto === 'https' ? 'http' : 'https') . '://' . $host);
    }

    $environment = strtolower(trim((string) (getenv('VIDEOCHAT_KING_ENV') ?: 'development')));
    if ($environment !== 'production') {
        foreach ([
            'http://127.0.0.1:4174',
            'http://localhost:4174',
            'http://127.0.0.1:5176',
            'http://localhost:5176',
        ] as $localOrigin) {
            $add($localOrigin);
        }
    }

    return $allowed;
}

/**
 * @return array{ok: bool, reason: string}
 */
function videochat_call_access_state_change_origin_check(array $request): array
{
    $origin = videochat_request_header_value($request, 'origin');
    if ($origin === '') {
        $referer = videochat_request_header_value($request, 'referer');
        if ($referer === '') {
            return ['ok' => true, 'reason' => 'no_browser_origin'];
        }
        $origin = $referer;
    }

    $normalizedOrigin = videochat_call_access_normalize_origin($origin);
    if ($normalizedOrigin === '' || $normalizedOrigin === 'null') {
        return ['ok' => false, 'reason' => 'invalid_origin'];
    }
    if (!in_array($normalizedOrigin, videochat_call_access_allowed_state_change_origins($request), true)) {
        return ['ok' => false, 'reason' => 'cross_origin_state_change'];
    }

    return ['ok' => true, 'reason' => 'origin_allowed'];
}

function videochat_call_access_csrf_origin_response(array $originCheck, callable $errorResponse): array
{
    return $errorResponse(403, 'csrf_origin_forbidden', 'Cross-origin account update requests are not allowed.', [
        'reason' => (string) ($originCheck['reason'] ?? 'cross_origin_state_change'),
    ]);
}

/**
 * @return array{ok: bool, reason: string, context: array<string, mixed>}
 */
function videochat_call_access_session_auth_context(PDO $pdo, array $request, array $apiAuthContext): array
{
    if ((bool) ($apiAuthContext['ok'] ?? false) && videochat_call_access_route_user_id($apiAuthContext) > 0) {
        return [
            'ok' => true,
            'reason' => 'provided',
            'context' => $apiAuthContext,
        ];
    }

    if (videochat_extract_session_token($request, 'rest') === '') {
        return [
            'ok' => true,
            'reason' => 'anonymous',
            'context' => [],
        ];
    }

    $authContext = videochat_authenticate_request($pdo, $request, 'rest');
    if (!(bool) ($authContext['ok'] ?? false)) {
        return [
            'ok' => false,
            'reason' => (string) ($authContext['reason'] ?? 'invalid_session'),
            'context' => [],
        ];
    }

    return [
        'ok' => true,
        'reason' => 'authenticated',
        'context' => $authContext,
    ];
}

function videochat_handle_call_access_routes(
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

    if ($path === '/api/call-access/review-flags') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/call-access/review-flags.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $filters = videochat_request_query_params($request);
            $result = videochat_call_access_list_review_flags_for_user(
                $pdo,
                videochat_call_access_route_user_id($apiAuthContext),
                (string) (($apiAuthContext['user'] ?? [])['role'] ?? ''),
                $filters
            );
        } catch (Throwable) {
            return $errorResponse(500, 'call_access_review_failed', 'Could not load review flags.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($result['ok'] ?? false)) {
            $reason = (string) ($result['reason'] ?? 'review_query_failed');
            if ($reason === 'forbidden') {
                return $errorResponse(403, 'call_access_review_forbidden', 'Only system admins can view call access review flags.', [
                    'reason' => $reason,
                ]);
            }

            return $errorResponse(500, 'call_access_review_failed', 'Could not load review flags.', [
                'reason' => $reason,
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'flags' => is_array($result['flags'] ?? null) ? array_values($result['flags']) : [],
                'total' => (int) ($result['total'] ?? 0),
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/call-access/review-flags/([A-Za-z0-9._:-]{1,120})$#', $path, $reviewFlagMatch) === 1) {
        if ($method !== 'PATCH') {
            return $errorResponse(405, 'method_not_allowed', 'Use PATCH for /api/call-access/review-flags/{id}.', [
                'allowed_methods' => ['PATCH'],
            ]);
        }

        $originCheck = videochat_call_access_state_change_origin_check($request);
        if (!(bool) ($originCheck['ok'] ?? false)) {
            return videochat_call_access_csrf_origin_response($originCheck, $errorResponse);
        }
        [$payload, $payloadError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'call_access_review_invalid_request_body', 'Review flag payload must be a JSON object.', [
                'reason' => $payloadError ?? 'invalid_json',
            ]);
        }

        try {
            $pdo = $openDatabase();
            $result = videochat_call_access_handle_review_flag_for_user(
                $pdo,
                (string) ($reviewFlagMatch[1] ?? ''),
                videochat_call_access_route_user_id($apiAuthContext),
                (string) (($apiAuthContext['user'] ?? [])['role'] ?? ''),
                (string) ($payload['status'] ?? ''),
                [
                    'note' => (string) ($payload['note'] ?? ''),
                ]
            );
        } catch (Throwable) {
            return $errorResponse(500, 'call_access_review_failed', 'Could not update review flag.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($result['ok'] ?? false)) {
            $reason = (string) ($result['reason'] ?? 'review_update_failed');
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'call_access_review_validation_failed', 'Review flag payload failed validation.', [
                    'fields' => is_array($result['errors'] ?? null) ? $result['errors'] : [],
                ]);
            }
            if ($reason === 'not_found') {
                return $errorResponse(404, 'call_access_review_not_found', 'Review flag does not exist.', []);
            }
            if ($reason === 'forbidden') {
                return $errorResponse(403, 'call_access_review_forbidden', 'Only system admins can handle call access review flags.', [
                    'reason' => $reason,
                ]);
            }

            return $errorResponse(500, 'call_access_review_failed', 'Could not update review flag.', [
                'reason' => $reason,
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'flag' => is_array($result['flag'] ?? null) ? $result['flag'] : null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/call-access/([A-Fa-f0-9-]{36})/join$#', $path, $publicAccessMatch) === 1) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/call-access/{id}/join.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        $accessId = (string) ($publicAccessMatch[1] ?? '');
        $queryAuthorityFields = videochat_call_access_request_query_authority_fields($request);
        if ($queryAuthorityFields !== []) {
            return videochat_call_access_authority_field_response($queryAuthorityFields, $errorResponse);
        }

        try {
            $pdo = $openDatabase();
            $resolveResult = videochat_resolve_call_access_public($pdo, $accessId);
            $joinAuthContext = videochat_call_access_session_auth_context($pdo, $request, $apiAuthContext);
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

        if (!(bool) ($joinAuthContext['ok'] ?? false)) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required when session credentials are presented.', [
                'reason' => (string) ($joinAuthContext['reason'] ?? 'invalid_session'),
            ]);
        }

        $effectiveJoinAuthContext = is_array($joinAuthContext['context'] ?? null) ? $joinAuthContext['context'] : [];
        $authenticatedJoinUserId = videochat_call_access_route_user_id($effectiveJoinAuthContext);
        $linkKind = videochat_call_access_link_kind(
            is_array($resolveResult['access_link'] ?? null) ? $resolveResult['access_link'] : null
        );
        $targetUserId = videochat_call_access_resolved_target_user_id($resolveResult);
        if ($authenticatedJoinUserId > 0 && $linkKind === 'personal' && $targetUserId > 0 && $targetUserId !== $authenticatedJoinUserId) {
            videochat_call_access_record_duplicate_personalized_link_review(
                $pdo,
                is_array($resolveResult['access_link'] ?? null) ? $resolveResult['access_link'] : [],
                is_array($resolveResult['call'] ?? null) ? $resolveResult['call'] : [],
                is_array($resolveResult['target_user'] ?? null) ? $resolveResult['target_user'] : null,
                $authenticatedJoinUserId,
                'join_opened',
                ['session_id' => videochat_call_access_route_session_id($effectiveJoinAuthContext)]
            );

            return $errorResponse(403, 'call_access_forbidden', 'Call access link is not available for your session.', [
                'mismatch' => 'strong_personalized_link',
                'fields' => [
                    'auth' => 'not_bound_to_current_user',
                    'host_name' => 'not_verified',
                ],
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'resolved',
                'access_link' => $resolveResult['access_link'] ?? null,
                'link_kind' => $linkKind,
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
            if (array_key_exists('verified_user_id', $payload)) {
                $sessionOptions['verified_user_id'] = $payload['verified_user_id'];
            }
            if (array_key_exists('verified_session_id', $payload)) {
                $sessionOptions['verified_session_id'] = $payload['verified_session_id'];
            }
            if (array_key_exists('host_name', $payload)) {
                $sessionOptions['host_name'] = $payload['host_name'];
            }

            $payloadAuthorityFields = videochat_call_access_client_authority_fields($payload);
            if ($payloadAuthorityFields !== []) {
                return videochat_call_access_authority_field_response($payloadAuthorityFields, $errorResponse);
            }
        }

        $queryAuthorityFields = videochat_call_access_request_query_authority_fields($request);
        if ($queryAuthorityFields !== []) {
            return videochat_call_access_authority_field_response($queryAuthorityFields, $errorResponse);
        }

        try {
            $pdo = $openDatabase();
            $sessionAuthContext = videochat_call_access_session_auth_context($pdo, $request, $apiAuthContext);
            if (!(bool) ($sessionAuthContext['ok'] ?? false)) {
                return $errorResponse(401, 'auth_failed', 'A valid session token is required when session credentials are presented.', [
                    'reason' => (string) ($sessionAuthContext['reason'] ?? 'invalid_session'),
                ]);
            }
            $effectiveAuthContext = is_array($sessionAuthContext['context'] ?? null) ? $sessionAuthContext['context'] : [];
            $authenticatedUserId = videochat_call_access_route_user_id($effectiveAuthContext);
            $authenticatedSessionId = videochat_call_access_route_session_id($effectiveAuthContext);
            if ($authenticatedUserId > 0) {
                $sessionOptions['authenticated_user_id'] = $authenticatedUserId;
            }
            if ($authenticatedSessionId !== '') {
                $sessionOptions['authenticated_session_id'] = $authenticatedSessionId;
            }
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
                    'fields' => is_array($issueResult['errors'] ?? null) ? $issueResult['errors'] : [],
                ]);
            }
            if ($reason === 'forbidden') {
                $fields = is_array($issueResult['errors'] ?? null) ? $issueResult['errors'] : [];
                $details = ['fields' => $fields];
                if (array_key_exists('host_name', $fields)) {
                    $details['mismatch'] = 'strong_personalized_link';
                    if ((string) ($fields['host_name'] ?? '') === 'wrong_host_name') {
                        $details['review'] = 'manual_review_required';
                    }
                }

                return $errorResponse(403, 'call_access_forbidden', 'Call access link is not allowed for this call participant.', [
                    ...$details,
                ]);
            }
            if ($reason === 'rate_limited') {
                $fields = is_array($issueResult['errors'] ?? null) ? $issueResult['errors'] : [];
                $details = ['fields' => $fields];
                if (array_key_exists('host_name', $fields)) {
                    $details['mismatch'] = 'strong_personalized_link';
                }

                return $errorResponse(429, 'call_access_rate_limited', 'Call access confirmation is rate-limited.', [
                    ...$details,
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

    if (preg_match('#^/api/call-access/([A-Fa-f0-9-]{36})/account-update-confirmation$#', $path, $accountUpdateMatch) === 1) {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/call-access/{id}/account-update-confirmation.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        $originCheck = videochat_call_access_state_change_origin_check($request);
        if (!(bool) ($originCheck['ok'] ?? false)) {
            return videochat_call_access_csrf_origin_response($originCheck, $errorResponse);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'call_access_invalid_request_body', 'Account update confirmation payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }
        $payloadAuthorityFields = videochat_call_access_client_authority_fields($payload);
        if ($payloadAuthorityFields !== []) {
            return videochat_call_access_authority_field_response($payloadAuthorityFields, $errorResponse);
        }
        $queryAuthorityFields = videochat_call_access_request_query_authority_fields($request);
        if ($queryAuthorityFields !== []) {
            return videochat_call_access_authority_field_response($queryAuthorityFields, $errorResponse);
        }

        try {
            $pdo = $openDatabase();
            $authContext = videochat_call_access_session_auth_context($pdo, $request, $apiAuthContext);
            if (!(bool) ($authContext['ok'] ?? false)) {
                return $errorResponse(401, 'auth_failed', 'A valid session token is required when session credentials are presented.', [
                    'reason' => (string) ($authContext['reason'] ?? 'invalid_session'),
                ]);
            }
            $effectiveAuthContext = is_array($authContext['context'] ?? null) ? $authContext['context'] : [];
            $authenticatedUserId = videochat_call_access_route_user_id($effectiveAuthContext);
            if ($authenticatedUserId <= 0) {
                return $errorResponse(401, 'auth_failed', 'A valid logged-in account is required.', [
                    'reason' => 'invalid_user_context',
                ]);
            }

            $requestResult = videochat_call_access_request_account_update_confirmation(
                $pdo,
                (string) ($accountUpdateMatch[1] ?? ''),
                $authenticatedUserId,
                $payload,
                ['session_id' => videochat_call_access_route_session_id($effectiveAuthContext)]
            );
        } catch (Throwable) {
            return $errorResponse(500, 'call_access_account_update_confirmation_failed', 'Could not create account update confirmation.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($requestResult['ok'] ?? false)) {
            $reason = (string) ($requestResult['reason'] ?? 'internal_error');
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'call_access_validation_failed', 'Account update confirmation payload failed validation.', [
                    'fields' => is_array($requestResult['errors'] ?? null) ? $requestResult['errors'] : [],
                ]);
            }
            if ($reason === 'not_found') {
                return $errorResponse(404, 'call_access_not_found', 'Call access link does not exist.', [
                    'fields' => is_array($requestResult['errors'] ?? null) ? $requestResult['errors'] : [],
                ]);
            }
            if ($reason === 'forbidden') {
                return $errorResponse(403, 'call_access_forbidden', 'Account update confirmation is not allowed.', [
                    'fields' => is_array($requestResult['errors'] ?? null) ? $requestResult['errors'] : [],
                ]);
            }
            if ($reason === 'rate_limited') {
                return $errorResponse(429, 'call_access_rate_limited', 'Account update confirmation is rate-limited.', [
                    'fields' => is_array($requestResult['errors'] ?? null) ? $requestResult['errors'] : [],
                    'retry_after_seconds' => (int) ($requestResult['retry_after_seconds'] ?? 0),
                ]);
            }

            return $errorResponse(500, 'call_access_account_update_confirmation_failed', 'Could not create account update confirmation.', [
                'reason' => 'internal_error',
            ]);
        }

        $appEnv = (string) (getenv('VIDEOCHAT_KING_ENV') ?: 'development');

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'pending_confirmation',
                'recipient_email' => $requestResult['recipient_email'] ?? null,
                'recipient_user_id' => $requestResult['recipient_user_id'] ?? null,
                'sent_to_logged_in_account' => (bool) ($requestResult['sent_to_logged_in_account'] ?? false),
                'sent_to_link_account' => (bool) ($requestResult['sent_to_link_account'] ?? true),
                'expires_at' => $requestResult['expires_at'] ?? null,
                'expires_in_seconds' => is_numeric($requestResult['expires_in_seconds'] ?? null) ? (int) $requestResult['expires_in_seconds'] : null,
                'debug_confirmation_token' => $appEnv === 'production'
                    ? null
                    : ($requestResult['token'] ?? null),
                'debug_confirmation_url' => $appEnv === 'production'
                    ? null
                    : ($requestResult['confirmation_url'] ?? null),
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/call-access/account-update-confirmations/([A-Za-z0-9._-]{20,200})/confirm$#', $path, $accountConfirmMatch) === 1) {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/call-access/account-update-confirmations/{token}/confirm.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        $originCheck = videochat_call_access_state_change_origin_check($request);
        if (!(bool) ($originCheck['ok'] ?? false)) {
            return videochat_call_access_csrf_origin_response($originCheck, $errorResponse);
        }
        $queryAuthorityFields = videochat_call_access_request_query_authority_fields($request);
        if ($queryAuthorityFields !== []) {
            return videochat_call_access_authority_field_response($queryAuthorityFields, $errorResponse);
        }

        try {
            $pdo = $openDatabase();
            $authContext = videochat_call_access_session_auth_context($pdo, $request, $apiAuthContext);
            if (!(bool) ($authContext['ok'] ?? false)) {
                return $errorResponse(401, 'auth_failed', 'A valid session token is required when session credentials are presented.', [
                    'reason' => (string) ($authContext['reason'] ?? 'invalid_session'),
                ]);
            }
            $effectiveAuthContext = is_array($authContext['context'] ?? null) ? $authContext['context'] : [];
            $authenticatedUserId = videochat_call_access_route_user_id($effectiveAuthContext);
            if ($authenticatedUserId <= 0) {
                return $errorResponse(401, 'auth_failed', 'A valid logged-in account is required.', [
                    'reason' => 'invalid_user_context',
                ]);
            }
            $confirmResult = videochat_call_access_confirm_account_update(
                $pdo,
                (string) ($accountConfirmMatch[1] ?? ''),
                $authenticatedUserId
            );
        } catch (Throwable) {
            return $errorResponse(500, 'call_access_account_update_confirm_failed', 'Could not confirm account update.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($confirmResult['ok'] ?? false)) {
            $reason = (string) ($confirmResult['reason'] ?? 'internal_error');
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'call_access_validation_failed', 'Confirmation token is invalid.', [
                    'fields' => is_array($confirmResult['errors'] ?? null) ? $confirmResult['errors'] : [],
                ]);
            }
            if ($reason === 'not_found') {
                return $errorResponse(404, 'call_access_confirmation_not_found', 'Confirmation token does not exist.', []);
            }
            if ($reason === 'forbidden') {
                return $errorResponse(403, 'call_access_forbidden', 'Confirmation token is not available for your account.', [
                    'fields' => is_array($confirmResult['errors'] ?? null) ? $confirmResult['errors'] : [],
                ]);
            }
            if ($reason === 'expired') {
                return $errorResponse(410, 'call_access_confirmation_expired', 'Confirmation token has expired.', []);
            }
            if ($reason === 'conflict') {
                return $errorResponse(409, 'call_access_conflict', 'Confirmation token has already been used.', [
                    'fields' => is_array($confirmResult['errors'] ?? null) ? $confirmResult['errors'] : [],
                ]);
            }

            return $errorResponse(500, 'call_access_account_update_confirm_failed', 'Could not confirm account update.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'confirmed',
                'user' => $confirmResult['user'] ?? null,
                'consumed_at' => $confirmResult['consumed_at'] ?? null,
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
            $resolveResult = videochat_resolve_call_access_for_user($pdo, $accessId, $authenticatedUserId, $authenticatedUserRole, videochat_tenant_id_from_auth_context($apiAuthContext));
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
                $callResolution = videochat_get_call_for_user($pdo, strtolower(trim($accessId)), $authenticatedUserId, $authenticatedUserRole, videochat_tenant_id_from_auth_context($apiAuthContext));

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

    return null;
}
