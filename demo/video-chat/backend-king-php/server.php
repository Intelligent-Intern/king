<?php

declare(strict_types=1);

$host = getenv('VIDEOCHAT_KING_HOST') ?: '127.0.0.1';
$port = (int) (getenv('VIDEOCHAT_KING_PORT') ?: '18080');
$wsPath = getenv('VIDEOCHAT_KING_WS_PATH') ?: '/ws';
$dbPath = getenv('VIDEOCHAT_KING_DB_PATH') ?: (__DIR__ . '/.local/video-chat.sqlite');
$appVersion = getenv('VIDEOCHAT_KING_BACKEND_VERSION') ?: '1.0.6-beta';
$appEnv = getenv('VIDEOCHAT_KING_ENV') ?: 'development';
$avatarStorageRoot = getenv('VIDEOCHAT_AVATAR_STORAGE_ROOT') ?: (dirname($dbPath) . '/avatars');
$avatarMaxBytes = 0;

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
require_once __DIR__ . '/avatar_upload.php';
require_once __DIR__ . '/call_directory.php';
require_once __DIR__ . '/call_management.php';
require_once __DIR__ . '/invite_codes.php';
require_once __DIR__ . '/realtime_chat.php';
require_once __DIR__ . '/realtime_presence.php';
require_once __DIR__ . '/realtime_typing.php';
require_once __DIR__ . '/user_directory.php';
require_once __DIR__ . '/user_management.php';
require_once __DIR__ . '/user_settings.php';

$avatarMaxBytes = videochat_avatar_max_bytes();

try {
    $databaseRuntime = videochat_bootstrap_sqlite($dbPath);
} catch (Throwable $error) {
    $log('database bootstrap failed: ' . $error->getMessage());
    exit(1);
}

$activeWebsocketsBySession = [];
$presenceState = videochat_presence_state_init();
$typingState = videochat_typing_state_init();

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

$runtimeEnvelope = static function () use (
    $appVersion,
    $appEnv,
    $databaseRuntime,
    $wsPath,
    $runtimeHealthSummary,
    $avatarMaxBytes
): array {
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
            'settings_endpoint' => '/api/user/settings',
            'avatar_upload_endpoint' => '/api/user/avatar',
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
            'upload_limits' => [
                'avatar_max_bytes' => $avatarMaxBytes,
                'avatar_allowed_mime_types' => array_keys(videochat_avatar_allowed_mime_to_extension()),
            ],
        ],
        'calls' => [
            'list_endpoint' => '/api/calls',
            'invite_code_create_endpoint' => '/api/invite-codes',
            'invite_code_redeem_endpoint' => '/api/invite-codes/redeem',
            'scope_values' => ['my', 'all'],
            'status_values' => ['all', 'scheduled', 'active', 'ended', 'cancelled'],
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
    &$presenceState,
    &$typingState,
    $jsonResponse,
    $errorResponse,
    $methodFromRequest,
    $decodeJsonBody,
    $openDatabase,
    $issueSessionId,
    $pathFromRequest,
    $runtimeEnvelope,
    $wsPath,
    $avatarStorageRoot,
    $avatarMaxBytes
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
        if ($method === 'GET') {
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

        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or POST for /api/admin/users.', [
                'allowed_methods' => ['GET', 'POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'admin_user_invalid_request_body', 'User create payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $createResult = videochat_admin_create_user($pdo, $payload);
        } catch (Throwable) {
            return $errorResponse(500, 'admin_user_create_failed', 'Could not create user.', [
                'reason' => 'internal_error',
            ]);
        }

        $createReason = (string) ($createResult['reason'] ?? 'internal_error');
        if (!(bool) ($createResult['ok'] ?? false)) {
            if ($createReason === 'validation_failed') {
                return $errorResponse(422, 'admin_user_validation_failed', 'User create payload failed validation.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : [],
                ]);
            }
            if ($createReason === 'email_conflict') {
                return $errorResponse(409, 'admin_user_conflict', 'A user with that email already exists.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : ['email' => 'already_exists'],
                ]);
            }

            return $errorResponse(500, 'admin_user_create_failed', 'Could not create user.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(201, [
            'status' => 'ok',
            'result' => [
                'state' => 'created',
                'user' => $createResult['user'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/admin/users/(\d+)$#', $path, $matches) === 1) {
        $userId = (int) ($matches[1] ?? 0);
        if ($method !== 'PATCH') {
            return $errorResponse(405, 'method_not_allowed', 'Use PATCH for /api/admin/users/{id}.', [
                'allowed_methods' => ['PATCH'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'admin_user_invalid_request_body', 'User update payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $updateResult = videochat_admin_update_user($pdo, $userId, $payload);
        } catch (Throwable) {
            return $errorResponse(500, 'admin_user_update_failed', 'Could not update user.', [
                'reason' => 'internal_error',
            ]);
        }

        $updateReason = (string) ($updateResult['reason'] ?? 'internal_error');
        if (!(bool) ($updateResult['ok'] ?? false)) {
            if ($updateReason === 'validation_failed') {
                return $errorResponse(422, 'admin_user_validation_failed', 'User update payload failed validation.', [
                    'fields' => is_array($updateResult['errors'] ?? null) ? $updateResult['errors'] : [],
                ]);
            }
            if ($updateReason === 'email_conflict') {
                return $errorResponse(409, 'admin_user_conflict', 'A user with that email already exists.', [
                    'fields' => is_array($updateResult['errors'] ?? null) ? $updateResult['errors'] : ['email' => 'already_exists'],
                ]);
            }
            if ($updateReason === 'not_found') {
                return $errorResponse(404, 'admin_user_not_found', 'The requested user does not exist.', [
                    'user_id' => $userId,
                ]);
            }

            return $errorResponse(500, 'admin_user_update_failed', 'Could not update user.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'updated',
                'user' => $updateResult['user'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/admin/users/(\d+)/deactivate$#', $path, $matches) === 1) {
        $userId = (int) ($matches[1] ?? 0);
        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/admin/users/{id}/deactivate.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        try {
            $pdo = $openDatabase();
            $deactivateResult = videochat_admin_deactivate_user($pdo, $userId);
        } catch (Throwable) {
            return $errorResponse(500, 'admin_user_deactivate_failed', 'Could not deactivate user.', [
                'reason' => 'internal_error',
            ]);
        }

        $deactivateReason = (string) ($deactivateResult['reason'] ?? 'internal_error');
        if (!(bool) ($deactivateResult['ok'] ?? false)) {
            if ($deactivateReason === 'not_found') {
                return $errorResponse(404, 'admin_user_not_found', 'The requested user does not exist.', [
                    'user_id' => $userId,
                ]);
            }

            return $errorResponse(500, 'admin_user_deactivate_failed', 'Could not deactivate user.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => $deactivateReason,
                'revoked_sessions' => (int) ($deactivateResult['revoked_sessions'] ?? 0),
                'user' => $deactivateResult['user'] ?? null,
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

    if ($path === '/api/invite-codes') {
        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/invite-codes.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'invite_codes_invalid_request_body', 'Invite-code payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $createResult = videochat_create_invite_code(
                $pdo,
                $authenticatedUserId,
                $authenticatedUserRole,
                $payload
            );
        } catch (Throwable) {
            return $errorResponse(500, 'invite_codes_create_failed', 'Could not create invite code.', [
                'reason' => 'internal_error',
            ]);
        }

        $createReason = (string) ($createResult['reason'] ?? 'internal_error');
        if (!(bool) ($createResult['ok'] ?? false)) {
            if ($createReason === 'validation_failed') {
                return $errorResponse(422, 'invite_codes_validation_failed', 'Invite-code payload failed validation.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : [],
                ]);
            }
            if ($createReason === 'not_found') {
                return $errorResponse(404, 'invite_codes_not_found', 'Invite context does not exist or is not active.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : [],
                ]);
            }
            if ($createReason === 'forbidden') {
                return $errorResponse(403, 'invite_codes_forbidden', 'You are not allowed to issue invite codes for this context.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : [],
                ]);
            }
            if ($createReason === 'conflict') {
                return $errorResponse(409, 'invite_codes_conflict', 'Could not allocate a unique invite code. Please retry.', [
                    'fields' => is_array($createResult['errors'] ?? null) ? $createResult['errors'] : [],
                ]);
            }

            return $errorResponse(500, 'invite_codes_create_failed', 'Could not create invite code.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(201, [
            'status' => 'ok',
            'result' => [
                'state' => 'created',
                'invite_code' => $createResult['invite_code'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/invite-codes/redeem') {
        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        $authenticatedUserRole = (string) (($apiAuthContext['user']['role'] ?? 'user'));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/invite-codes/redeem.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'invite_codes_redeem_invalid_request_body', 'Invite-code redeem payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $redeemResult = videochat_redeem_invite_code(
                $pdo,
                $authenticatedUserId,
                $authenticatedUserRole,
                $payload
            );
        } catch (Throwable) {
            return $errorResponse(500, 'invite_codes_redeem_failed', 'Could not redeem invite code.', [
                'reason' => 'internal_error',
            ]);
        }

        $redeemReason = (string) ($redeemResult['reason'] ?? 'internal_error');
        if (!(bool) ($redeemResult['ok'] ?? false)) {
            if ($redeemReason === 'validation_failed') {
                return $errorResponse(422, 'invite_codes_redeem_validation_failed', 'Invite-code redeem payload failed validation.', [
                    'fields' => is_array($redeemResult['errors'] ?? null) ? $redeemResult['errors'] : [],
                ]);
            }
            if ($redeemReason === 'not_found') {
                return $errorResponse(404, 'invite_codes_redeem_not_found', 'Invite code does not exist.', [
                    'fields' => is_array($redeemResult['errors'] ?? null) ? $redeemResult['errors'] : [],
                ]);
            }
            if ($redeemReason === 'expired') {
                return $errorResponse(410, 'invite_codes_redeem_expired', 'Invite code has expired.', [
                    'fields' => is_array($redeemResult['errors'] ?? null) ? $redeemResult['errors'] : [],
                ]);
            }
            if ($redeemReason === 'exhausted') {
                return $errorResponse(409, 'invite_codes_redeem_exhausted', 'Invite code has reached its redemption limit.', [
                    'fields' => is_array($redeemResult['errors'] ?? null) ? $redeemResult['errors'] : [],
                ]);
            }
            if ($redeemReason === 'conflict') {
                return $errorResponse(409, 'invite_codes_redeem_conflict', 'Invite code resolved to a non-joinable destination.', [
                    'fields' => is_array($redeemResult['errors'] ?? null) ? $redeemResult['errors'] : [],
                ]);
            }
            if ($redeemReason === 'forbidden') {
                return $errorResponse(403, 'invite_codes_redeem_forbidden', 'You are not allowed to redeem this invite code.', [
                    'fields' => is_array($redeemResult['errors'] ?? null) ? $redeemResult['errors'] : [],
                ]);
            }

            return $errorResponse(500, 'invite_codes_redeem_failed', 'Could not redeem invite code.', [
                'reason' => 'internal_error',
            ]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'redeemed',
                'redemption' => $redeemResult['redemption'] ?? null,
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

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})$#', $path, $callMatch) === 1) {
        if ($method !== 'PATCH') {
            return $errorResponse(405, 'method_not_allowed', 'Use PATCH for /api/calls/{id}.', [
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
            return $errorResponse(400, 'calls_update_invalid_request_body', 'Call update payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        $callId = (string) ($callMatch[1] ?? '');
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

    if ($path === '/api/user/avatar') {
        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        if ($method !== 'POST') {
            return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/user/avatar.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'user_avatar_invalid_request_body', 'Avatar upload payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $uploadResult = videochat_store_avatar_for_user(
                $pdo,
                $authenticatedUserId,
                $payload,
                $avatarStorageRoot,
                $avatarMaxBytes
            );
        } catch (Throwable) {
            return $errorResponse(500, 'user_avatar_upload_failed', 'Could not upload avatar.', [
                'reason' => 'internal_error',
            ]);
        }

        if (!(bool) ($uploadResult['ok'] ?? false)) {
            $reason = (string) ($uploadResult['reason'] ?? 'internal_error');
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'user_avatar_validation_failed', 'Avatar upload payload failed validation.', [
                    'fields' => is_array($uploadResult['errors'] ?? null) ? $uploadResult['errors'] : [],
                ]);
            }
            if ($reason === 'not_found') {
                return $errorResponse(404, 'user_not_found', 'Authenticated user could not be resolved.', [
                    'user_id' => $authenticatedUserId,
                ]);
            }

            return $errorResponse(500, 'user_avatar_upload_failed', 'Could not upload avatar.', [
                'reason' => $reason,
            ]);
        }

        return $jsonResponse(201, [
            'status' => 'ok',
            'result' => [
                'state' => 'uploaded',
                'avatar_path' => $uploadResult['avatar_path'] ?? null,
                'content_type' => $uploadResult['content_type'] ?? null,
                'bytes' => (int) ($uploadResult['bytes'] ?? 0),
                'file_name' => $uploadResult['file_name'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/user/avatar-files/([A-Za-z0-9._-]{1,200})$#', $path, $avatarMatch) === 1) {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET for /api/user/avatar-files/{filename}.', [
                'allowed_methods' => ['GET'],
            ]);
        }

        $fileName = (string) ($avatarMatch[1] ?? '');
        $resolvedPath = videochat_avatar_resolve_read_path($avatarStorageRoot, $fileName);
        if (!is_string($resolvedPath) || !is_file($resolvedPath)) {
            return $errorResponse(404, 'user_avatar_not_found', 'Avatar file does not exist.', [
                'file_name' => $fileName,
            ]);
        }

        $binary = @file_get_contents($resolvedPath);
        if (!is_string($binary)) {
            return $errorResponse(500, 'user_avatar_read_failed', 'Could not read avatar file.', [
                'reason' => 'read_failed',
            ]);
        }

        $mime = videochat_avatar_detect_mime_from_binary($binary) ?? 'application/octet-stream';
        return [
            'status' => 200,
            'headers' => [
                'content-type' => $mime,
                'cache-control' => 'private, max-age=60',
            ],
            'body' => $binary,
        ];
    }

    if ($path === '/api/user/settings') {
        $authenticatedUserId = (int) (($apiAuthContext['user']['id'] ?? 0));
        if ($authenticatedUserId <= 0) {
            return $errorResponse(401, 'auth_failed', 'A valid session token is required.', [
                'reason' => 'invalid_user_context',
            ]);
        }

        if ($method === 'GET') {
            try {
                $pdo = $openDatabase();
                $userSettings = videochat_fetch_user_settings($pdo, $authenticatedUserId);
            } catch (Throwable) {
                return $errorResponse(500, 'user_settings_fetch_failed', 'Could not load user settings.', [
                    'reason' => 'internal_error',
                ]);
            }

            if ($userSettings === null) {
                return $errorResponse(404, 'user_not_found', 'Authenticated user could not be resolved.', [
                    'user_id' => $authenticatedUserId,
                ]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'settings' => [
                    'display_name' => (string) ($userSettings['display_name'] ?? ''),
                    'time_format' => (string) ($userSettings['time_format'] ?? '24h'),
                    'theme' => (string) ($userSettings['theme'] ?? 'dark'),
                    'avatar_path' => $userSettings['avatar_path'] ?? null,
                ],
                'user' => $userSettings,
                'time' => gmdate('c'),
            ]);
        }

        if ($method !== 'PATCH') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or PATCH for /api/user/settings.', [
                'allowed_methods' => ['GET', 'PATCH'],
            ]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'user_settings_invalid_request_body', 'User settings payload must be a non-empty JSON object.', [
                'reason' => $decodeError,
            ]);
        }

        try {
            $pdo = $openDatabase();
            $updateResult = videochat_update_user_settings($pdo, $authenticatedUserId, $payload);
        } catch (Throwable) {
            return $errorResponse(500, 'user_settings_update_failed', 'Could not update user settings.', [
                'reason' => 'internal_error',
            ]);
        }

        $updateReason = (string) ($updateResult['reason'] ?? 'internal_error');
        if (!(bool) ($updateResult['ok'] ?? false)) {
            if ($updateReason === 'validation_failed') {
                return $errorResponse(422, 'user_settings_validation_failed', 'User settings payload failed validation.', [
                    'fields' => is_array($updateResult['errors'] ?? null) ? $updateResult['errors'] : [],
                ]);
            }
            if ($updateReason === 'not_found') {
                return $errorResponse(404, 'user_not_found', 'Authenticated user could not be resolved.', [
                    'user_id' => $authenticatedUserId,
                ]);
            }

            return $errorResponse(500, 'user_settings_update_failed', 'Could not update user settings.', [
                'reason' => 'internal_error',
            ]);
        }

        $updatedUser = is_array($updateResult['user'] ?? null) ? $updateResult['user'] : null;
        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'updated',
                'settings' => [
                    'display_name' => (string) ($updatedUser['display_name'] ?? ''),
                    'time_format' => (string) ($updatedUser['time_format'] ?? '24h'),
                    'theme' => (string) ($updatedUser['theme'] ?? 'dark'),
                    'avatar_path' => $updatedUser['avatar_path'] ?? null,
                ],
                'user' => $updatedUser,
            ],
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
            'calls_endpoint' => '/api/calls',
            'call_create_endpoint' => '/api/calls',
            'call_update_endpoint_template' => '/api/calls/{id}',
            'call_cancel_endpoint_template' => '/api/calls/{id}/cancel',
            'invite_code_create_endpoint' => '/api/invite-codes',
            'invite_code_redeem_endpoint' => '/api/invite-codes/redeem',
            'login_endpoint' => '/api/auth/login',
            'session_endpoint' => '/api/auth/session',
            'logout_endpoint' => '/api/auth/logout',
            'admin_probe_endpoint' => '/api/admin/ping',
            'admin_users_endpoint' => '/api/admin/users',
            'admin_user_update_endpoint_template' => '/api/admin/users/{id}',
            'admin_user_deactivate_endpoint_template' => '/api/admin/users/{id}/deactivate',
            'moderation_probe_endpoint' => '/api/moderation/ping',
            'user_probe_endpoint' => '/api/user/ping',
            'user_avatar_upload_endpoint' => '/api/user/avatar',
            'user_avatar_file_endpoint_template' => '/api/user/avatar-files/{filename}',
            'user_settings_endpoint' => '/api/user/settings',
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

        $requestedRoomId = '';
        $queryParams = videochat_request_query_params($request);
        if (is_string($queryParams['room'] ?? null)) {
            $requestedRoomId = (string) $queryParams['room'];
        }

        $initialRoomId = videochat_presence_normalize_room_id($requestedRoomId);
        try {
            $pdo = $openDatabase();
            $resolvedRoom = videochat_fetch_active_room_context($pdo, $initialRoomId);
            if ($resolvedRoom === null) {
                $resolvedRoom = videochat_fetch_active_room_context($pdo, 'lobby');
            }
            if (is_array($resolvedRoom) && is_string($resolvedRoom['id'] ?? null)) {
                $initialRoomId = videochat_presence_normalize_room_id((string) $resolvedRoom['id']);
            }
        } catch (Throwable) {
            $initialRoomId = 'lobby';
        }

        $connectionId = videochat_register_active_websocket(
            $activeWebsocketsBySession,
            $authSessionId,
            $websocket
        );
        $presenceConnection = videochat_presence_connection_descriptor(
            (array) ($websocketAuth['user'] ?? []),
            $authSessionId,
            $connectionId,
            $websocket,
            $initialRoomId
        );
        $presenceJoin = videochat_presence_join_room(
            $presenceState,
            $presenceConnection,
            (string) ($presenceConnection['room_id'] ?? 'lobby')
        );
        $presenceConnection = (array) ($presenceJoin['connection'] ?? $presenceConnection);
        $presenceDetached = false;
        $detachWebsocket = static function () use (
            &$presenceDetached,
            &$activeWebsocketsBySession,
            &$presenceState,
            &$typingState,
            &$presenceConnection,
            $authSessionId,
            $connectionId
        ): void {
            if ($presenceDetached) {
                return;
            }
            $presenceDetached = true;

            videochat_typing_clear_for_connection(
                $typingState,
                $presenceState,
                (array) $presenceConnection,
                'disconnect'
            );
            videochat_unregister_active_websocket($activeWebsocketsBySession, $authSessionId, $connectionId);
            videochat_presence_remove_connection($presenceState, $connectionId);
        };

        if ($session !== null && $streamId > 0 && $authSessionId !== '' && $connectionId !== '') {
            king_server_on_cancel(
                $session,
                $streamId,
                static function () use ($detachWebsocket): void {
                    $detachWebsocket();
                }
            );
        }

        videochat_presence_send_frame(
            $websocket,
            [
                'type' => 'system/welcome',
                'message' => 'video-chat King websocket presence gateway connected',
                'connection_id' => $connectionId,
                'active_room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                'channels' => [
                    'presence' => [
                        'snapshot' => 'room/snapshot',
                        'joined' => 'room/joined',
                        'left' => 'room/left',
                    ],
                    'chat' => [
                        'send' => 'chat/send',
                        'message' => 'chat/message',
                        'ack' => 'chat/ack',
                    ],
                    'typing' => [
                        'start' => 'typing/start',
                        'stop' => 'typing/stop',
                    ],
                ],
                'auth' => [
                    'session' => $websocketAuth['session'] ?? null,
                    'user' => $websocketAuth['user'] ?? null,
                ],
                'time' => gmdate('c'),
            ]
        );

        try {
            while (true) {
                videochat_typing_sweep_expired($typingState, $presenceState);
                $frame = king_client_websocket_receive($websocket, 250);
                if ($frame === false) {
                    $status = function_exists('king_client_websocket_get_status')
                        ? (int) king_client_websocket_get_status($websocket)
                        : 3;
                    if ($status === 3) {
                        break;
                    }

                    continue;
                }

                if (!is_string($frame) || trim($frame) === '') {
                    continue;
                }

                $presenceCommand = videochat_presence_decode_client_frame($frame);
                $commandType = (string) ($presenceCommand['type'] ?? '');
                $commandError = (string) ($presenceCommand['error'] ?? 'invalid_command');

                $chatCommand = null;
                $typingCommand = null;
                if (!(bool) ($presenceCommand['ok'] ?? false) && $commandError === 'unsupported_type') {
                    $chatCommand = videochat_chat_decode_client_frame($frame);
                    if ((bool) ($chatCommand['ok'] ?? false)) {
                        $chatPublish = videochat_chat_publish(
                            $presenceState,
                            $presenceConnection,
                            $chatCommand
                        );
                        if (!(bool) ($chatPublish['ok'] ?? false)) {
                            videochat_presence_send_frame(
                                $websocket,
                                [
                                    'type' => 'system/error',
                                    'code' => 'chat_publish_failed',
                                    'message' => 'Could not publish chat message.',
                                    'details' => [
                                        'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                                    ],
                                    'time' => gmdate('c'),
                                ]
                            );
                            continue;
                        }

                        $message = is_array($chatPublish['event']['message'] ?? null)
                            ? $chatPublish['event']['message']
                            : [];
                        videochat_presence_send_frame(
                            $websocket,
                            [
                                'type' => 'chat/ack',
                                'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                                'message_id' => (string) ($message['id'] ?? ''),
                                'client_message_id' => $message['client_message_id'] ?? null,
                                'server_time' => (string) ($message['server_time'] ?? gmdate('c')),
                                'sent_count' => (int) ($chatPublish['sent_count'] ?? 0),
                                'time' => gmdate('c'),
                            ]
                        );
                        continue;
                    }

                    if ((string) ($chatCommand['error'] ?? '') === 'unsupported_type') {
                        $typingCommand = videochat_typing_decode_client_frame($frame);
                        if ((bool) ($typingCommand['ok'] ?? false)) {
                            $typingResult = videochat_typing_apply_command(
                                $typingState,
                                $presenceState,
                                $presenceConnection,
                                $typingCommand
                            );
                            if (!(bool) ($typingResult['ok'] ?? false)) {
                                videochat_presence_send_frame(
                                    $websocket,
                                    [
                                        'type' => 'system/error',
                                        'code' => 'typing_publish_failed',
                                        'message' => 'Could not publish typing state.',
                                        'details' => [
                                            'room_id' => (string) ($presenceConnection['room_id'] ?? 'lobby'),
                                        ],
                                        'time' => gmdate('c'),
                                    ]
                                );
                            }
                            continue;
                        }

                        $commandType = (string) ($typingCommand['type'] ?? $commandType);
                        $commandError = (string) ($typingCommand['error'] ?? $commandError);
                    } else {
                        $commandType = (string) ($chatCommand['type'] ?? $commandType);
                        $commandError = (string) ($chatCommand['error'] ?? $commandError);
                    }
                }

                if (!(bool) ($presenceCommand['ok'] ?? false)) {
                    videochat_presence_send_frame(
                        $websocket,
                        [
                            'type' => 'system/error',
                            'code' => 'invalid_websocket_command',
                            'message' => 'WebSocket command is invalid.',
                            'details' => [
                                'error' => $commandError,
                                'type' => $commandType,
                            ],
                            'time' => gmdate('c'),
                        ]
                    );
                    continue;
                }

                $commandType = (string) ($presenceCommand['type'] ?? '');
                if ($commandType === 'ping') {
                    videochat_presence_send_frame(
                        $websocket,
                        [
                            'type' => 'system/pong',
                            'time' => gmdate('c'),
                        ]
                    );
                    continue;
                }

                if ($commandType === 'room/snapshot/request') {
                    videochat_presence_send_room_snapshot($presenceState, $presenceConnection, 'requested');
                    continue;
                }

                if ($commandType === 'room/leave') {
                    videochat_typing_clear_for_connection(
                        $typingState,
                        $presenceState,
                        $presenceConnection,
                        'room_leave'
                    );
                    $presenceJoin = videochat_presence_join_room($presenceState, $presenceConnection, 'lobby');
                    $presenceConnection = (array) ($presenceJoin['connection'] ?? $presenceConnection);
                    continue;
                }

                if ($commandType === 'room/join') {
                    $targetRoomId = videochat_presence_normalize_room_id((string) ($presenceCommand['room_id'] ?? ''));
                    try {
                        $pdo = $openDatabase();
                        $targetRoom = videochat_fetch_active_room_context($pdo, $targetRoomId);
                    } catch (Throwable) {
                        $targetRoom = null;
                    }

                    if (!is_array($targetRoom)) {
                        videochat_presence_send_frame(
                            $websocket,
                            [
                                'type' => 'system/error',
                                'code' => 'room_not_found',
                                'message' => 'Requested room is not active.',
                                'details' => [
                                    'room_id' => $targetRoomId,
                                ],
                                'time' => gmdate('c'),
                            ]
                        );
                        continue;
                    }

                    $currentRoomId = videochat_presence_normalize_room_id((string) ($presenceConnection['room_id'] ?? 'lobby'));
                    if ($currentRoomId !== $targetRoomId) {
                        videochat_typing_clear_for_connection(
                            $typingState,
                            $presenceState,
                            $presenceConnection,
                            'room_change'
                        );
                    }
                    $presenceJoin = videochat_presence_join_room($presenceState, $presenceConnection, $targetRoomId);
                    $presenceConnection = (array) ($presenceJoin['connection'] ?? $presenceConnection);
                    continue;
                }
            }
        } finally {
            $detachWebsocket();
        }

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
