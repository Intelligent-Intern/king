<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/users/user_emails.php';
require_once __DIR__ . '/../domain/users/onboarding_progress.php';
require_once __DIR__ . '/../support/tenant_context.php';
require_once __DIR__ . '/../support/localization.php';

function videochat_auth_session_is_transient_sqlite_lock(Throwable $error): bool
{
    $message = strtolower($error->getMessage());
    return str_contains($message, 'database is locked')
        || str_contains($message, 'database schema is locked');
}

function videochat_handle_auth_session_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    array &$activeWebsocketsBySession,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase,
    callable $issueSessionId
): ?array {
    if ($path === '/api/auth/login') {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/auth/login.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        $email = '';
        $password = '';
        $loginPayload = null;

        $authorization = videochat_request_header_value($request, 'authorization');
        if (
            $authorization !== ''
            && preg_match('/^\s*Basic\s+(.+)\s*$/i', $authorization, $matches) === 1
        ) {
            $decoded = base64_decode(trim((string) ($matches[1] ?? '')), true);
            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$basicEmail, $basicPassword] = explode(':', $decoded, 2);
                $email = strtolower(trim($basicEmail));
                $password = $basicPassword;
            }
        }

        if ($email === '' || trim($password) === '') {
            [$payload, $decodeError] = $decodeJsonBody($request);
            if (!is_array($payload)) {
                return $errorResponse(400, 'auth_invalid_request_body', 'Login payload must be a non-empty JSON object.', [
                    'reason' => $decodeError,
                ]);
            }
            $loginPayload = $payload;

            $email = strtolower(trim((string) ($payload['email'] ?? '')));
            $password = (string) ($payload['password'] ?? '');
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || trim($password) === '') {
            return $errorResponse(422, 'auth_validation_failed', 'Email and password are required.', [
                'fields' => [
                    'email' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? 'ok' : 'required_email',
                    'password' => trim($password) !== '' ? 'ok' : 'required_password',
                ],
            ]);
        }

        $maxLoginAttempts = 5;
        for ($loginAttempt = 1; $loginAttempt <= $maxLoginAttempts; $loginAttempt += 1) {
            try {
            $pdo = $openDatabase();
            $userQuery = $pdo->prepare(
                <<<'SQL'
SELECT
    users.id,
    users.email,
    users.display_name,
    users.password_hash,
    users.status,
    users.time_format,
    users.date_format,
    users.theme,
    users.locale,
    users.theme_editor_enabled,
    users.avatar_path,
    users.post_logout_landing_url,
    roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower(:email)
   OR EXISTS (
       SELECT 1
       FROM user_emails
       WHERE user_emails.user_id = users.id
         AND lower(user_emails.email) = lower(:email)
         AND user_emails.is_verified = 1
   )
ORDER BY
    CASE
        WHEN lower(users.email) = lower(:email) THEN 0
        ELSE 1
    END ASC,
    users.id ASC
LIMIT 1
SQL
            );
            $userQuery->execute([':email' => $email]);
            $user = $userQuery->fetch();

            $storedHash = is_array($user) && is_string($user['password_hash'] ?? null)
                ? trim((string) $user['password_hash'])
                : '';
            $userIsActive = is_array($user) && ((string) ($user['status'] ?? 'disabled')) === 'active';
            if (
                !is_array($user)
                || !$userIsActive
                || $storedHash === ''
                || !password_verify($password, $storedHash)
            ) {
                return $errorResponse(401, 'auth_invalid_credentials', 'Invalid email or password.');
            }

            if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
                $rehash = password_hash($password, PASSWORD_DEFAULT);
                if (!is_string($rehash) || $rehash === '') {
                    throw new RuntimeException('Could not refresh password hash.');
                }

                $rehashQuery = $pdo->prepare(
                    'UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id'
                );
                $rehashQuery->execute([
                    ':password_hash' => $rehash,
                    ':updated_at' => gmdate('c'),
                    ':id' => (int) $user['id'],
                ]);
            }

            $ttlSeconds = (int) (getenv('VIDEOCHAT_SESSION_TTL_SECONDS') ?: 43_200);
            if ($ttlSeconds < 60) {
                $ttlSeconds = 60;
            } elseif ($ttlSeconds > 2_592_000) {
                $ttlSeconds = 2_592_000;
            }

            $sessionId = $issueSessionId();
            $issuedAt = gmdate('c');
            $expiresAt = gmdate('c', time() + $ttlSeconds);
            $clientIp = trim((string) ($request['remote_address'] ?? ''));
            $userAgent = substr(videochat_request_header_value($request, 'user-agent'), 0, 500);
            $tenantContext = null;
            if (is_array($loginPayload)) {
                $tenantPublicId = trim((string) ($loginPayload['tenant_uuid'] ?? ($loginPayload['tenant_public_id'] ?? '')));
                if ($tenantPublicId !== '') {
                    $tenantContext = videochat_tenant_context_for_public_id($pdo, (int) $user['id'], $tenantPublicId);
                } elseif (isset($loginPayload['tenant_id']) && is_scalar($loginPayload['tenant_id'])) {
                    $tenantContext = videochat_tenant_context_for_user($pdo, (int) $user['id'], (int) $loginPayload['tenant_id']);
                }
            }
            if ($tenantContext === null) {
                $tenantContext = videochat_tenant_context_for_user($pdo, (int) $user['id']);
            }
            if ($tenantContext === null) {
                return $errorResponse(403, 'tenant_membership_required', 'A valid active tenant membership is required.', [
                    'reason' => 'tenant_membership_inactive',
                ]);
            }
            $tenantPayload = videochat_tenant_auth_payload($tenantContext);

            $hasActiveTenantColumn = videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id');
            $sessionSql = $hasActiveTenantColumn
                ? 'INSERT INTO sessions(id, user_id, active_tenant_id, issued_at, expires_at, revoked_at, client_ip, user_agent) VALUES(:id, :user_id, :active_tenant_id, :issued_at, :expires_at, NULL, :client_ip, :user_agent)'
                : 'INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent) VALUES(:id, :user_id, :issued_at, :expires_at, NULL, :client_ip, :user_agent)';
            $sessionParams = [
                ':id' => $sessionId,
                ':user_id' => (int) $user['id'],
                ':issued_at' => $issuedAt,
                ':expires_at' => $expiresAt,
                ':client_ip' => $clientIp === '' ? null : $clientIp,
                ':user_agent' => $userAgent === '' ? null : $userAgent,
            ];
            if ($hasActiveTenantColumn) {
                $sessionParams[':active_tenant_id'] = (int) ($tenantPayload['id'] ?? 0);
            }
            $sessionInsert = $pdo->prepare($sessionSql);
            $sessionInsert->execute($sessionParams);

            $accountType = videochat_user_account_type((string) $user['email'], $user['password_hash'] ?? null);
            $localization = videochat_localization_payload($pdo, $user['locale'] ?? null);
            $onboarding = videochat_fetch_onboarding_progress($pdo, (int) $user['id'], (int) ($tenantPayload['id'] ?? 0));

            return $jsonResponse(200, [
                'status' => 'ok',
                'session' => [
                    'id' => $sessionId,
                    'token' => $sessionId,
                    'token_type' => 'session_id',
                    'issued_at' => $issuedAt,
                    'expires_at' => $expiresAt,
                    'expires_in_seconds' => $ttlSeconds,
                    'active_tenant_id' => (int) ($tenantPayload['id'] ?? 0),
                ],
                'user' => [
                    'id' => (int) $user['id'],
                    'email' => (string) $user['email'],
                    'display_name' => (string) $user['display_name'],
                    'role' => (string) ($user['role_slug'] ?? 'user'),
                    'status' => (string) $user['status'],
                    'time_format' => (string) ($user['time_format'] ?? '24h'),
                    'date_format' => (string) ($user['date_format'] ?? 'dmy_dot'),
                    'theme' => (string) ($user['theme'] ?? 'dark'),
                    'locale' => (string) ($localization['locale'] ?? 'en'),
                    'direction' => (string) ($localization['direction'] ?? 'ltr'),
                    'supported_locales' => is_array($localization['supported_locales'] ?? null) ? $localization['supported_locales'] : [],
                    'can_edit_themes' => (string) ($user['role_slug'] ?? 'user') === 'admin'
                        || ((int) ($user['theme_editor_enabled'] ?? 0)) === 1
                        || (bool) (($tenantPayload['permissions']['edit_themes'] ?? false)),
                    'avatar_path' => is_string($user['avatar_path'] ?? null) ? (string) $user['avatar_path'] : null,
                    'post_logout_landing_url' => is_string($user['post_logout_landing_url'] ?? null)
                        ? trim((string) $user['post_logout_landing_url'])
                        : '',
                    'onboarding_completed_tours' => $onboarding['completed_tours'],
                    'onboarding_badges' => $onboarding['badges'],
                    'account_type' => $accountType,
                    'is_guest' => $accountType === 'guest',
                    'tenant' => $tenantPayload,
                ],
                'tenant' => $tenantPayload,
                'time' => gmdate('c'),
            ]);
            } catch (Throwable $error) {
                if (
                    $loginAttempt < $maxLoginAttempts
                    && videochat_auth_session_is_transient_sqlite_lock($error)
                ) {
                    usleep(100_000 * $loginAttempt);
                    continue;
                }

                error_log('[video-chat][auth] login failed: ' . get_class($error) . ': ' . $error->getMessage());
                return $errorResponse(500, 'auth_login_failed', 'Login failed due to a backend error.', [
                    'reason' => 'internal_error',
                ]);
            }
        }

        return $errorResponse(500, 'auth_login_failed', 'Login failed due to a backend error.', [
            'reason' => 'internal_error',
        ]);
    }

    if ($path === '/api/auth/session-state') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/auth/session-state.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $probe = videochat_authenticate_request($pdo, $request, 'rest');
        } catch (Throwable) {
            return $errorResponse(500, 'auth_session_probe_failed', 'Session probe failed due to a backend error.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($probe['ok'] ?? false)) {
            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'unauthenticated',
                    'reason' => (string) ($probe['reason'] ?? 'invalid_session'),
                ],
                'session' => null,
                'user' => null,
                'tenant' => null,
                'time' => gmdate('c'),
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'authenticated',
                'reason' => 'ready',
            ],
            'session' => $probe['session'] ?? null,
            'user' => $probe['user'] ?? null,
            'tenant' => $probe['tenant'] ?? null,
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/auth/session') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/auth/session.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        try {
            $freshContext = videochat_authenticate_request($openDatabase(), $request, 'rest');
        } catch (Throwable) {
            return $errorResponse(500, 'auth_session_probe_failed', 'Session probe failed due to a backend error.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($freshContext['ok'] ?? false)) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => (string) ($freshContext['reason'] ?? 'invalid_session'),
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'session' => $freshContext['session'] ?? null,
            'user' => $freshContext['user'] ?? null,
            'tenant' => $freshContext['tenant'] ?? null,
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/auth/email-change/confirm') {
        if (!in_array($method, ['GET', 'POST'], true)) {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or POST for /api/auth/email-change/confirm.', [
                'allowed_methods' => ['GET', 'POST'],
            ]);
        }

        $token = '';
        $query = videochat_request_query_params($request);
        if (is_scalar($query['token'] ?? null)) {
            $token = trim((string) $query['token']);
        }

        if ($token === '') {
            [$payload, $decodeError] = $decodeJsonBody($request);
            if (!is_array($payload)) {
                return $errorResponse(400, 'email_change_confirm_invalid_request_body', 'Email confirmation payload must be a non-empty JSON object.', [
                    'reason' => $decodeError,
                ]);
            }
            $token = trim((string) ($payload['token'] ?? ''));
        }

        if ($token === '') {
            return $errorResponse(422, 'email_change_confirm_validation_failed', 'Email confirmation token is required.', [
                'fields' => ['token' => 'required'],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $confirmation = videochat_consume_email_change_token($pdo, $token);
            if (!(bool) ($confirmation['ok'] ?? false)) {
                $reason = (string) ($confirmation['reason'] ?? 'internal_error');
                $errors = is_array($confirmation['errors'] ?? null) ? $confirmation['errors'] : [];
                if ($reason === 'validation_failed') {
                    return $errorResponse(422, 'email_change_confirm_validation_failed', 'Email confirmation token is invalid.', [
                        'fields' => $errors,
                    ]);
                }
                if ($reason === 'not_found') {
                    return $errorResponse(404, 'email_change_confirm_not_found', 'Email confirmation token is invalid or unknown.', [
                        'fields' => $errors,
                    ]);
                }
                if ($reason === 'expired') {
                    return $errorResponse(410, 'email_change_confirm_expired', 'Email confirmation token has expired.', [
                        'fields' => $errors,
                    ]);
                }
                if ($reason === 'conflict') {
                    return $errorResponse(409, 'email_change_confirm_conflict', 'Email confirmation token cannot be consumed.', [
                        'fields' => $errors,
                    ]);
                }

                return $errorResponse(500, 'email_change_confirm_failed', 'Email confirmation failed due to a backend error.', [
                    'reason' => $reason,
                ]);
            }

            $confirmedUser = is_array($confirmation['user'] ?? null) ? $confirmation['user'] : null;
            $confirmedUserId = (int) (($confirmedUser['id'] ?? 0));
            if ($confirmedUserId <= 0 || !is_array($confirmedUser)) {
                return $errorResponse(500, 'email_change_confirm_failed', 'Email confirmation failed due to a backend error.', [
                    'reason' => 'invalid_confirmed_user',
                ]);
            }

            $sessionIssue = videochat_issue_session_for_user(
                $pdo,
                $confirmedUserId,
                $issueSessionId,
                null,
                trim((string) ($request['remote_address'] ?? '')),
                videochat_request_header_value($request, 'user-agent'),
                null,
                (int) (($apiAuthContext['tenant'] ?? [])['id'] ?? (($apiAuthContext['session'] ?? [])['active_tenant_id'] ?? 0))
            );
            if (!(bool) ($sessionIssue['ok'] ?? false)) {
                return $errorResponse(500, 'email_change_confirm_failed', 'Email confirmation failed due to a backend error.', [
                    'reason' => (string) ($sessionIssue['reason'] ?? 'session_issue_failed'),
                ]);
            }

            $role = is_string($confirmedUser['role'] ?? null) ? strtolower(trim((string) $confirmedUser['role'])) : 'user';
            $redirectPath = $role === 'admin'
                ? '/admin/users?edit_user_id=' . rawurlencode((string) $confirmedUserId) . '&email_verified=1'
                : '/user/dashboard?email_verified=1';

            return $jsonResponse(200, [
                'status' => 'ok',
                'session' => $sessionIssue['session'],
                'user' => $confirmedUser,
                'result' => [
                    'state' => 'confirmed',
                    'email' => (is_array($confirmation['email_row'] ?? null) ? ($confirmation['email_row']['email'] ?? null) : null),
                    'consumed_at' => $confirmation['consumed_at'] ?? gmdate('c'),
                    'redirect_path' => $redirectPath,
                    'edit_user_id' => $confirmedUserId,
                ],
                'time' => gmdate('c'),
            ]);
        } catch (Throwable) {
            return $errorResponse(500, 'email_change_confirm_failed', 'Email confirmation failed due to a backend error.', [
                'reason' => 'internal_error',
            ]);
        }
    }

    if ($path === '/api/auth/tenant') {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/auth/tenant.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'tenant_switch_invalid_request_body', 'Tenant switch payload must be a JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        $currentUserId = (int) ($apiAuthContext['user']['id'] ?? 0);
        $currentSessionId = is_string($apiAuthContext['session']['id'] ?? null)
            ? trim((string) $apiAuthContext['session']['id'])
            : '';
        if ($currentUserId <= 0 || $currentSessionId === '') {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'missing_session',
            ]);
        }

        try {
            $pdo = $openDatabase();
            $tenantContext = null;
            $tenantPublicId = trim((string) ($payload['tenant_uuid'] ?? ($payload['tenant_public_id'] ?? '')));
            if ($tenantPublicId !== '') {
                $tenantContext = videochat_tenant_context_for_public_id($pdo, $currentUserId, $tenantPublicId);
            } elseif (isset($payload['tenant_id']) && is_scalar($payload['tenant_id'])) {
                $tenantContext = videochat_tenant_context_for_user($pdo, $currentUserId, (int) $payload['tenant_id']);
            }
            if ($tenantContext === null) {
                return $errorResponse(403, 'tenant_switch_forbidden', 'The requested tenant is not available for this session.', [
                    'reason' => 'tenant_membership_inactive',
                ]);
            }

            $tenantPayload = videochat_tenant_auth_payload($tenantContext);
            if (!videochat_tenant_update_session($pdo, $currentSessionId, (int) ($tenantPayload['id'] ?? 0))) {
                return $errorResponse(500, 'tenant_switch_failed', 'Tenant switch failed due to a backend error.', [
                    'reason' => 'session_update_failed',
                ]);
            }

            $freshContext = videochat_validate_session_token($pdo, $currentSessionId);
            return $jsonResponse(200, [
                'status' => 'ok',
                'session' => $freshContext['session'] ?? null,
                'user' => $freshContext['user'] ?? null,
                'tenant' => $freshContext['tenant'] ?? $tenantPayload,
                'result' => ['state' => 'tenant_switched'],
                'time' => gmdate('c'),
            ]);
        } catch (Throwable) {
            return $errorResponse(500, 'tenant_switch_failed', 'Tenant switch failed due to a backend error.', [
                'reason' => 'internal_error',
            ]);
        }
    }

    if ($path === '/api/auth/refresh') {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/auth/refresh.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        $currentSessionToken = is_string($apiAuthContext['token'] ?? null)
            ? trim((string) $apiAuthContext['token'])
            : '';
        $currentSessionId = is_string($apiAuthContext['session']['id'] ?? null)
            ? trim((string) $apiAuthContext['session']['id'])
            : '';
        $effectiveCurrentSessionId = $currentSessionToken !== '' ? $currentSessionToken : $currentSessionId;
        $currentUserId = (int) ($apiAuthContext['user']['id'] ?? 0);
        if ($effectiveCurrentSessionId === '' || $currentUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'missing_session',
            ]);
        }

        try {
            $pdo = $openDatabase();
            $rotation = videochat_rotate_session_token(
                $pdo,
                $effectiveCurrentSessionId,
                $currentUserId,
                $issueSessionId,
                null,
                trim((string) ($request['remote_address'] ?? '')),
                videochat_request_header_value($request, 'user-agent')
            );
            if (!(bool) ($rotation['ok'] ?? false)) {
                $reason = (string) ($rotation['reason'] ?? 'rotation_failed');
                if ($reason === 'session_not_rotatable') {
                    return $errorResponse(409, 'auth_refresh_conflict', 'Session token could not be rotated.', [
                        'reason' => $reason,
                    ]);
                }

                return $errorResponse(500, 'auth_refresh_failed', 'Session refresh failed due to a backend error.', [
                    'reason' => $reason,
                ]);
            }

            $newSession = is_array($rotation['new_session'] ?? null)
                ? $rotation['new_session']
                : null;
            if (!is_array($newSession)) {
                return $errorResponse(500, 'auth_refresh_failed', 'Session refresh failed due to a backend error.', [
                    'reason' => 'missing_rotated_session',
                ]);
            }

            $closedSockets = videochat_close_tracked_websockets_for_session(
                $activeWebsocketsBySession,
                $effectiveCurrentSessionId
            );

            return $jsonResponse(200, [
                'status' => 'ok',
                'session' => [
                    ...$newSession,
                    'replaces_session_id' => $effectiveCurrentSessionId,
                ],
                'user' => $apiAuthContext['user'] ?? null,
                'tenant' => $apiAuthContext['tenant'] ?? null,
                'result' => [
                    'replaced_session_id' => $effectiveCurrentSessionId,
                    'revocation_state' => 'rotated',
                    'revoked_at' => $rotation['revoked_at'] ?? gmdate('c'),
                    'websocket_disconnects' => $closedSockets,
                ],
                'time' => gmdate('c'),
            ]);
        } catch (Throwable) {
            return $errorResponse(500, 'auth_refresh_failed', 'Session refresh failed due to a backend error.', [
                'reason' => 'internal_error',
            ]);
        }
    }

    if ($path === '/api/auth/logout') {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/auth/logout.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        $sessionToken = is_string($apiAuthContext['token'] ?? null)
            ? trim((string) $apiAuthContext['token'])
            : '';
        $sessionId = is_string($apiAuthContext['session']['id'] ?? null)
            ? trim((string) $apiAuthContext['session']['id'])
            : '';
        $effectiveSessionId = $sessionToken !== '' ? $sessionToken : $sessionId;
        if ($effectiveSessionId === '') {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'missing_session',
            ]);
        }

        try {
            $pdo = $openDatabase();
            $revocation = videochat_revoke_session($pdo, $effectiveSessionId);
            if (!(bool) ($revocation['ok'] ?? false)) {
                $reason = (string) ($revocation['reason'] ?? 'invalid_session');
                return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                    'reason' => $reason,
                ]);
            }

            $closedSockets = videochat_close_tracked_websockets_for_session(
                $activeWebsocketsBySession,
                $effectiveSessionId
            );

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'session_id' => $effectiveSessionId,
                    'revocation_state' => (string) ($revocation['reason'] ?? 'revoked'),
                    'revoked_at' => $revocation['revoked_at'] ?? gmdate('c'),
                    'websocket_disconnects' => $closedSockets,
                    'post_logout_landing_url' => is_string(($apiAuthContext['user'] ?? [])['post_logout_landing_url'] ?? null)
                        ? trim((string) (($apiAuthContext['user'] ?? [])['post_logout_landing_url']))
                        : '',
                ],
                'time' => gmdate('c'),
            ]);
        } catch (Throwable $error) {
            return $errorResponse(500, 'auth_logout_failed', 'Logout failed due to a backend error.', [
                'reason' => 'internal_error',
            ]);
        }
    }

    return null;
}
