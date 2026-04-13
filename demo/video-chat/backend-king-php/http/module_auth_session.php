<?php

declare(strict_types=1);

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
        if (!in_array($method, ['GET', 'POST'], true)) {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or POST for /api/auth/login.', [
                'allowed_methods' => ['GET', 'POST'],
            ]);
        }

        $email = '';
        $password = '';

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
            $query = videochat_request_query_params($request);
            $queryEmail = is_scalar($query['email'] ?? null)
                ? strtolower(trim((string) $query['email']))
                : '';
            $queryPassword = is_scalar($query['password'] ?? null)
                ? (string) $query['password']
                : '';
            if ($queryEmail !== '' && trim($queryPassword) !== '') {
                $email = $queryEmail;
                $password = $queryPassword;
            }
        }

        if ($email === '' || trim($password) === '') {
            [$payload, $decodeError] = $decodeJsonBody($request);
            if (!is_array($payload)) {
                return $errorResponse(400, 'auth_invalid_request_body', 'Login payload must be a non-empty JSON object.', [
                    'reason' => $decodeError,
                ]);
            }

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
    users.theme,
    users.avatar_path,
    roles.slug AS role_slug
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower(:email)
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

            $sessionInsert = $pdo->prepare(
                <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, :client_ip, :user_agent)
SQL
            );
            $sessionInsert->execute([
                ':id' => $sessionId,
                ':user_id' => (int) $user['id'],
                ':issued_at' => $issuedAt,
                ':expires_at' => $expiresAt,
                ':client_ip' => $clientIp === '' ? null : $clientIp,
                ':user_agent' => $userAgent === '' ? null : $userAgent,
            ]);

            return $jsonResponse(200, [
                'status' => 'ok',
                'session' => [
                    'id' => $sessionId,
                    'token' => $sessionId,
                    'token_type' => 'session_id',
                    'issued_at' => $issuedAt,
                    'expires_at' => $expiresAt,
                    'expires_in_seconds' => $ttlSeconds,
                ],
                'user' => [
                    'id' => (int) $user['id'],
                    'email' => (string) $user['email'],
                    'display_name' => (string) $user['display_name'],
                    'role' => (string) ($user['role_slug'] ?? 'user'),
                    'status' => (string) $user['status'],
                    'time_format' => (string) ($user['time_format'] ?? '24h'),
                    'theme' => (string) ($user['theme'] ?? 'dark'),
                    'avatar_path' => is_string($user['avatar_path'] ?? null) ? (string) $user['avatar_path'] : null,
                ],
                'time' => gmdate('c'),
            ]);
        } catch (Throwable $error) {
            return $errorResponse(500, 'auth_login_failed', 'Login failed due to a backend error.', [
                'reason' => 'internal_error',
            ]);
        }
    }

    if ($path === '/api/auth/session') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/auth/session.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'session' => $apiAuthContext['session'] ?? null,
            'user' => $apiAuthContext['user'] ?? null,
            'time' => gmdate('c'),
        ]);
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
