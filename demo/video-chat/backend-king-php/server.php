<?php

declare(strict_types=1);

$host = getenv('VIDEOCHAT_KING_HOST') ?: '127.0.0.1';
$port = (int) (getenv('VIDEOCHAT_KING_PORT') ?: '18080');
$wsPath = getenv('VIDEOCHAT_KING_WS_PATH') ?: '/ws';
$dbPath = getenv('VIDEOCHAT_KING_DB_PATH') ?: (__DIR__ . '/.local/video-chat.sqlite');
$appVersion = getenv('VIDEOCHAT_KING_BACKEND_VERSION') ?: '1.0.6-beta';
$appEnv = getenv('VIDEOCHAT_KING_ENV') ?: 'development';
$avatarStorageRoot = getenv('VIDEOCHAT_AVATAR_STORAGE_ROOT') ?: (dirname($dbPath) . '/avatars');
$rawServerMode = strtolower(trim((string) (getenv('VIDEOCHAT_KING_SERVER_MODE') ?: 'all')));
$serverMode = in_array($rawServerMode, ['all', 'http', 'ws'], true) ? $rawServerMode : 'all';
$debugRequests = in_array(
    strtolower(trim((string) (getenv('VIDEOCHAT_DEBUG_REQUESTS') ?: '0'))),
    ['1', 'true', 'yes', 'on'],
    true
);
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

require_once __DIR__ . '/support/database.php';
require_once __DIR__ . '/support/auth.php';
require_once __DIR__ . '/domain/users/avatar_upload.php';
require_once __DIR__ . '/domain/calls/call_directory.php';
require_once __DIR__ . '/domain/calls/call_management.php';
require_once __DIR__ . '/domain/calls/call_access.php';
require_once __DIR__ . '/domain/calls/invite_codes.php';
require_once __DIR__ . '/domain/realtime/realtime_chat.php';
require_once __DIR__ . '/domain/realtime/realtime_lobby.php';
require_once __DIR__ . '/domain/realtime/realtime_presence.php';
require_once __DIR__ . '/domain/realtime/realtime_reaction.php';
require_once __DIR__ . '/domain/realtime/realtime_signaling.php';
require_once __DIR__ . '/domain/realtime/realtime_typing.php';
require_once __DIR__ . '/domain/users/user_directory.php';
require_once __DIR__ . '/domain/users/user_management.php';
require_once __DIR__ . '/domain/users/user_settings.php';
require_once __DIR__ . '/http/router.php';

$avatarMaxBytes = videochat_avatar_max_bytes();

$databaseRuntime = null;
$maxBootstrapAttempts = 40;
for ($attempt = 1; $attempt <= $maxBootstrapAttempts; $attempt += 1) {
    try {
        $databaseRuntime = videochat_bootstrap_sqlite($dbPath);
        break;
    } catch (Throwable $error) {
        $message = $error->getMessage();
        $isSqliteLock = stripos($message, 'database is locked') !== false;
        $isTransientBootstrapRace =
            stripos($message, 'unique constraint failed: users.email') !== false
            || stripos($message, 'bad parameter or other api misuse') !== false
            || stripos($message, 'database schema is locked') !== false;
        if (($isSqliteLock || $isTransientBootstrapRace) && $attempt < $maxBootstrapAttempts) {
            usleep(100_000);
            continue;
        }
        $log('database bootstrap failed: ' . $message);
        exit(1);
    }
}

if (!is_array($databaseRuntime)) {
    $log('database bootstrap failed: no runtime snapshot returned.');
    exit(1);
}

$activeWebsocketsBySession = [];
$presenceState = videochat_presence_state_init();
$lobbyState = videochat_lobby_state_init();
$typingState = videochat_typing_state_init();
$reactionState = videochat_reaction_state_init();

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
    $corsHeaders = [
        'access-control-allow-origin' => '*',
        'access-control-allow-methods' => 'GET,POST,PATCH,DELETE,OPTIONS',
        'access-control-allow-headers' => 'Authorization, Content-Type, X-Session-Id',
        'access-control-max-age' => '600',
    ];

    return [
        'status' => $status,
        'headers' => [
            'content-type' => 'application/json; charset=utf-8',
            ...$corsHeaders,
        ],
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
            'refresh_endpoint' => '/api/auth/refresh',
            'logout_endpoint' => '/api/auth/logout',
            'settings_endpoint' => '/api/user/settings',
            'avatar_upload_endpoint' => '/api/user/avatar',
            'rbac' => [
                'admin_scope_prefix' => '/api/admin/',
                'moderation_scope_prefix' => '/api/moderation/',
                'user_scope_prefix' => '/api/user/',
                'permission_matrix' => videochat_rbac_permission_matrix($wsPath),
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
            'call_access_link_endpoint' => '/api/calls/{call_id}/access-link',
            'call_access_resolve_endpoint' => '/api/call-access/{access_id}',
            'call_access_join_endpoint' => '/api/call-access/{access_id}/join',
            'call_access_session_endpoint' => '/api/call-access/{access_id}/session',
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
$log("server mode: {$serverMode}");
$log('starting King HTTP/1 listener...');
$handler = static function (array $request) use (
    &$activeWebsocketsBySession,
    &$presenceState,
    &$lobbyState,
    &$typingState,
    &$reactionState,
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
    $avatarMaxBytes,
    $log,
    $serverMode,
    $debugRequests
): array {
    $path = $pathFromRequest($request);
    $method = $methodFromRequest($request);
    if ($debugRequests) {
        $log(sprintf('request: %s %s', $method, $path));
    }

    if ($serverMode === 'http' && ($path === $wsPath || $path === '/sfu')) {
        return $errorResponse(404, 'websocket_endpoint_disabled', 'WebSocket endpoint is disabled on this listener.', [
            'path' => $path,
            'listener_mode' => $serverMode,
            'ws_path' => $wsPath,
        ]);
    }

    if ($serverMode === 'ws' && !in_array($path, [$wsPath, '/sfu', '/health'], true)) {
        return $errorResponse(404, 'rest_endpoint_disabled', 'REST endpoint is disabled on this listener.', [
            'path' => $path,
            'listener_mode' => $serverMode,
            'ws_path' => $wsPath,
        ]);
    }

    try {
        return videochat_dispatch_request(
            $request,
            $activeWebsocketsBySession,
            $presenceState,
            $lobbyState,
            $typingState,
            $reactionState,
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
        );
    } catch (Throwable $error) {
        $log(sprintf(
            'unhandled request error on %s %s: %s (%s:%d)',
            $method,
            $path,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine()
        ));

        return $errorResponse(500, 'internal_server_error', 'Request handling failed unexpectedly.', [
            'path' => $path,
            'method' => $method,
        ]);
    }
};


$log('starting King HTTP/1 one-shot listener loop...');
while (true) {
    $ok = king_http1_server_listen_once($host, $port, null, $handler);
    if ($ok === false) {
        $lastError = function_exists('king_get_last_error') ? trim((string) king_get_last_error()) : '';
        if (
            $lastError !== ''
            && stripos($lastError, 'timed out while waiting for the HTTP/1 accept phase') === false
        ) {
            $log('listen_once failure: ' . $lastError);
        }
        usleep(50_000);
    }
}

exit(0);
