<?php

declare(strict_types=1);

$host = getenv('VIDEOCHAT_KING_HOST') ?: '127.0.0.1';
$port = (int) (getenv('VIDEOCHAT_KING_PORT') ?: '18080');
$wsPath = getenv('VIDEOCHAT_KING_WS_PATH') ?: '/ws';
$dbPath = getenv('VIDEOCHAT_KING_DB_PATH') ?: (__DIR__ . '/.local/video-chat.sqlite');
$appVersion = getenv('VIDEOCHAT_KING_BACKEND_VERSION') ?: '1.0.6-beta';
$appEnv = getenv('VIDEOCHAT_KING_ENV') ?: 'development';

if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "[video-chat][king-php-backend] invalid port: {$port}\n");
    exit(1);
}

if (!extension_loaded('king')) {
    fwrite(STDERR, "[video-chat][king-php-backend] King extension is not loaded.\n");
    exit(1);
}

$log = static function (string $message): void {
    fwrite(STDERR, '[video-chat][king-php-backend] ' . $message . "\n");
};

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/user_directory.php';

try {
    $databaseRuntime = videochat_bootstrap_sqlite($dbPath);
} catch (Throwable $error) {
    $log('database bootstrap failed: ' . $error->getMessage());
    exit(1);
}

$activeWebsocketsBySession = [];

register_shutdown_function(static function () use ($log): void {
    $error = error_get_last();
    if (is_array($error)) {
        $log(sprintf(
            'shutdown with last error: %s (%s:%d)',
            (string) ($error['message'] ?? 'unknown'),
            (string) ($error['file'] ?? 'n/a'),
            (int) ($error['line'] ?? 0)
        ));
        return;
    }

    $log('shutdown complete.');
});

$jsonResponse = static function (int $status, array $payload): array {
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
};

$errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
    $error = [
        'code' => $code,
        'message' => $message,
    ];
    if ($details !== []) {
        $error['details'] = $details;
    }

    return $jsonResponse($status, [
        'status' => 'error',
        'error' => $error,
        'time' => gmdate('c'),
    ]);
};

$methodFromRequest = static function (array $request): string {
    $method = strtoupper(trim((string) ($request['method'] ?? 'GET')));
    return $method === '' ? 'GET' : $method;
};

$decodeJsonBody = static function (array $request): array {
    $body = $request['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return [null, 'empty_body'];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return [null, 'invalid_json'];
    }

    return [$decoded, null];
};

$openDatabase = static function () use ($dbPath): PDO {
    return videochat_open_sqlite_pdo($dbPath);
};

$issueSessionId = static function (): string {
    try {
        return 'sess_' . bin2hex(random_bytes(20));
    } catch (Throwable $error) {
        return 'sess_' . hash('sha256', uniqid('videochat', true) . microtime(true));
    }
};

$runtimeHealthSummary = static function (): array {
    $moduleStatus = 'unknown';
    $moduleBuild = null;
    $moduleVersion = null;
    $activeRuntimeCount = 0;

    if (function_exists('king_health')) {
        try {
            $moduleHealth = king_health();
            if (is_array($moduleHealth)) {
                $moduleStatus = is_string($moduleHealth['status'] ?? null)
                    ? $moduleHealth['status']
                    : $moduleStatus;
                $moduleBuild = is_string($moduleHealth['build'] ?? null)
                    ? $moduleHealth['build']
                    : null;
                $moduleVersion = is_string($moduleHealth['version'] ?? null)
                    ? $moduleHealth['version']
                    : null;
                $activeRuntimeCount = (int) ($moduleHealth['active_runtime_count'] ?? 0);
            }
        } catch (Throwable $error) {
            $moduleStatus = 'error';
        }
    }

    $systemStatus = 'not_initialized';
    if (function_exists('king_system_health_check')) {
        try {
            $systemHealth = king_system_health_check();
            if (is_array($systemHealth)) {
                $systemStatus = is_string($systemHealth['status'] ?? null)
                    ? $systemHealth['status']
                    : $systemStatus;
            }
        } catch (Throwable $error) {
            $systemStatus = 'error';
        }
    }

    return [
        'module_status' => $moduleStatus,
        'system_status' => $systemStatus,
        'build' => $moduleBuild,
        'module_version' => $moduleVersion,
        'active_runtime_count' => $activeRuntimeCount,
    ];
};

$runtimeEnvelope = static function () use ($appVersion, $appEnv, $databaseRuntime, $wsPath, $runtimeHealthSummary): array {
    return [
        'service' => 'video-chat-backend-king-php',
        'app' => [
            'name' => 'king-video-chat-backend',
            'version' => $appVersion,
            'environment' => $appEnv,
        ],
        'runtime' => [
            'king_version' => function_exists('king_version') ? (string) king_version() : 'n/a',
            'transport' => 'king_http1_server_listen_once',
            'ws_path' => $wsPath,
            'health' => $runtimeHealthSummary(),
        ],
        'database' => $databaseRuntime,
        'auth' => [
            'login_endpoint' => '/api/auth/login',
            'session_endpoint' => '/api/auth/session',
            'logout_endpoint' => '/api/auth/logout',
            'rbac' => [
                'admin_scope_prefix' => '/api/admin/',
                'moderation_scope_prefix' => '/api/moderation/',
                'user_scope_prefix' => '/api/user/',
            ],
            'demo_users' => $databaseRuntime['demo_users'] ?? [],
            'rest_auth' => [
                'authorization_header' => 'Authorization: Bearer <session_token>',
                'fallback_header' => 'X-Session-Id: <session_token>',
            ],
            'websocket_auth' => [
                'query' => ['session', 'token'],
                'headers' => ['Authorization', 'X-Session-Id'],
            ],
        ],
        'time' => gmdate('c'),
    ];
};

$pathFromRequest = static function (array $request): string {
    $path = $request['path'] ?? null;
    if (is_string($path) && $path !== '') {
        return $path;
    }

    $uri = $request['uri'] ?? null;
    if (is_string($uri) && $uri !== '') {
        return (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
    }

    return '/';
};

$log('king_version=' . (function_exists('king_version') ? (string) king_version() : 'n/a'));
$log(sprintf(
    'sqlite bootstrap: schema v%d (%d/%d migrations) at %s',
    (int) ($databaseRuntime['schema_version'] ?? 0),
    (int) ($databaseRuntime['migrations_applied'] ?? 0),
    (int) ($databaseRuntime['migrations_total'] ?? 0),
    (string) ($databaseRuntime['path'] ?? $dbPath)
));
$log('auth demo users: ' . json_encode($databaseRuntime['demo_users'] ?? [], JSON_UNESCAPED_SLASHES));
$log("http endpoint bound: http://{$host}:{$port}/");
$log("websocket endpoint bound: ws://{$host}:{$port}{$wsPath}");
$log('starting King HTTP/1 listener...');

$handler = static function (array $request) use (
    &$activeWebsocketsBySession,
    $jsonResponse,
    $errorResponse,
    $methodFromRequest,
    $decodeJsonBody,
    $openDatabase,
    $issueSessionId,
    $pathFromRequest,
    $runtimeEnvelope,
    $wsPath
): array {
    $path = $pathFromRequest($request);
    $method = $methodFromRequest($request);

    $isPublicEndpoint = static function (string $requestPath) use ($wsPath): bool {
        return in_array(
            $requestPath,
            ['/', '/health', '/api/bootstrap', '/api/runtime', '/api/version', '/api/auth/login'],
            true
        ) && $requestPath !== $wsPath;
    };

    $authenticateRequest = static function (array $authRequest, string $transport) use ($openDatabase): array {
        try {
            $pdo = $openDatabase();
            return videochat_authenticate_request($pdo, $authRequest, $transport);
        } catch (Throwable $error) {
            return [
                'ok' => false,
                'reason' => 'auth_backend_error',
                'token' => '',
                'session' => null,
                'user' => null,
            ];
        }
    };

    $authFailureResponse = static function (string $transport, string $reason) use ($errorResponse): array {
        $status = $reason === 'auth_backend_error' ? 500 : 401;
        $code = $transport === 'websocket' ? 'websocket_auth_failed' : 'auth_failed';
        $message = $status === 500
            ? 'Authentication check failed due to a backend error.'
            : 'A valid session token is required.';

        return $errorResponse($status, $code, $message, [
            'reason' => $reason,
        ]);
    };

    $rbacFailureResponse = static function (string $transport, array $rbacDecision, string $requestPath) use ($errorResponse): array {
        $status = 403;
        $code = $transport === 'websocket' ? 'websocket_forbidden' : 'rbac_forbidden';
        $message = $transport === 'websocket'
            ? 'Session role is not allowed for websocket access.'
            : 'Session role is not allowed for this endpoint.';

        return $errorResponse($status, $code, $message, [
            'reason' => (string) ($rbacDecision['reason'] ?? 'role_not_allowed'),
            'role' => (string) ($rbacDecision['role'] ?? 'unknown'),
            'allowed_roles' => is_array($rbacDecision['allowed_roles'] ?? null) ? array_values($rbacDecision['allowed_roles']) : [],
            'path' => $requestPath,
        ]);
    };

    $apiAuthContext = null;
    if (str_starts_with($path, '/api/') && !$isPublicEndpoint($path)) {
        $apiAuthContext = $authenticateRequest($request, 'rest');
        if (!(bool) ($apiAuthContext['ok'] ?? false)) {
            return $authFailureResponse('rest', (string) ($apiAuthContext['reason'] ?? 'invalid_session'));
        }

        $rbacDecision = videochat_authorize_role_for_path((array) ($apiAuthContext['user'] ?? []), $path);
        if (!(bool) ($rbacDecision['ok'] ?? false)) {
            return $rbacFailureResponse('rest', $rbacDecision, $path);
        }
    }

    if ($path === '/health' || $path === '/api/runtime') {
        $payload = $runtimeEnvelope();
        $payload['status'] = 'ok';
        return $jsonResponse(200, $payload);
    }

    if ($path === '/api/version') {
        $payload = $runtimeEnvelope();
        return $jsonResponse(200, [
            'service' => $payload['service'],
            'app' => $payload['app'],
            'runtime' => [
                'king_version' => $payload['runtime']['king_version'],
                'build' => $payload['runtime']['health']['build'],
                'module_version' => $payload['runtime']['health']['module_version'],
            ],
            'time' => $payload['time'],
        ]);
    }

    if ($path === '/api/auth/login') {
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/auth/login.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'auth_invalid_request_body', 'Login payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
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

    if ($path === '/api/admin/ping') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/admin/ping.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'scope' => 'admin',
            'user' => $apiAuthContext['user'] ?? null,
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/admin/users') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/admin/users.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        $queryParams = videochat_request_query_params($request);
        $filters = videochat_admin_user_list_filters($queryParams);
        if (!(bool) ($filters['ok'] ?? false)) {
            return $errorResponse(422, 'admin_user_list_validation_failed', 'Invalid admin user list query parameters.', [
                'fields' => $filters['errors'] ?? [],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $listing = videochat_admin_list_users(
                $pdo,
                (string) ($filters['query'] ?? ''),
                (int) ($filters['page'] ?? 1),
                (int) ($filters['page_size'] ?? 10)
            );
        } catch (Throwable $error) {
            return $errorResponse(500, 'admin_user_list_failed', 'Could not load admin user list.', [
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
            'users' => $rows,
            'pagination' => [
                'query' => (string) ($filters['query'] ?? ''),
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'page_count' => $pageCount,
                'returned' => count($rows),
                'has_prev' => $page > 1,
                'has_next' => $pageCount > 0 && $page < $pageCount,
            ],
            'sort' => [
                'role_priority' => ['admin', 'moderator', 'user'],
                'secondary' => 'display_name_asc',
                'tie_breaker' => 'id_asc',
            ],
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/moderation/ping') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/moderation/ping.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'scope' => 'moderation',
            'user' => $apiAuthContext['user'] ?? null,
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/user/ping') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/user/ping.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'scope' => 'user',
            'user' => $apiAuthContext['user'] ?? null,
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/' || $path === '/api/bootstrap') {
        return $jsonResponse(200, [
            'service' => 'video-chat-backend-king-php',
            'status' => 'bootstrapped',
            'message' => 'King HTTP and WebSocket scaffold is active.',
            'ws_path' => $wsPath,
            'runtime_endpoint' => '/api/runtime',
            'version_endpoint' => '/api/version',
            'login_endpoint' => '/api/auth/login',
            'session_endpoint' => '/api/auth/session',
            'logout_endpoint' => '/api/auth/logout',
            'admin_probe_endpoint' => '/api/admin/ping',
            'admin_users_endpoint' => '/api/admin/users',
            'moderation_probe_endpoint' => '/api/moderation/ping',
            'user_probe_endpoint' => '/api/user/ping',
            'time' => gmdate('c'),
        ]);
    }

    if ($path === $wsPath) {
        $websocketAuth = $authenticateRequest($request, 'websocket');
        if (!(bool) ($websocketAuth['ok'] ?? false)) {
            return $authFailureResponse('websocket', (string) ($websocketAuth['reason'] ?? 'invalid_session'));
        }
        $websocketRbacDecision = videochat_authorize_role_for_path((array) ($websocketAuth['user'] ?? []), $path);
        if (!(bool) ($websocketRbacDecision['ok'] ?? false)) {
            return $rbacFailureResponse('websocket', $websocketRbacDecision, $path);
        }

        $authSessionId = is_string($websocketAuth['token'] ?? null)
            ? trim((string) $websocketAuth['token'])
            : '';
        if ($authSessionId === '') {
            $authSessionId = is_string($websocketAuth['session']['id'] ?? null)
                ? trim((string) $websocketAuth['session']['id'])
                : '';
        }

        $session = $request['session'] ?? null;
        $streamId = (int) ($request['stream_id'] ?? 0);
        $websocket = king_server_upgrade_to_websocket($session, $streamId);
        if ($websocket === false) {
            return $errorResponse(400, 'websocket_upgrade_failed', 'Could not upgrade request to websocket.');
        }

        $connectionId = videochat_register_active_websocket(
            $activeWebsocketsBySession,
            $authSessionId,
            $websocket
        );

        if ($session !== null && $streamId > 0 && $authSessionId !== '' && $connectionId !== '') {
            king_server_on_cancel(
                $session,
                $streamId,
                static function () use (&$activeWebsocketsBySession, $authSessionId, $connectionId): void {
                    videochat_unregister_active_websocket($activeWebsocketsBySession, $authSessionId, $connectionId);
                }
            );
        }

        king_websocket_send(
            $websocket,
            json_encode([
                'type' => 'system/welcome',
                'message' => 'video-chat King websocket scaffold connected',
                'connection_id' => $connectionId,
                'auth' => [
                    'session' => $websocketAuth['session'] ?? null,
                    'user' => $websocketAuth['user'] ?? null,
                ],
                'time' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES)
        );

        return [
            'status' => 101,
            'headers' => [],
            'body' => '',
        ];
    }

    return $errorResponse(404, 'not_found', 'The requested endpoint does not exist.', [
        'path' => $path,
    ]);
};

$log('starting King HTTP/1 one-shot listener loop...');
while (true) {
    $ok = king_http1_server_listen_once($host, $port, null, $handler);
    if ($ok === false) {
        usleep(50_000);
    }
}

exit(0);
