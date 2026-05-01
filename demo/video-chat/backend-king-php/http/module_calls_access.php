<?php

declare(strict_types=1);

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

    return null;
}
