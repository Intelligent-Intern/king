<?php

declare(strict_types=1);

$host = getenv('VIDEOCHAT_KING_HOST') ?: '127.0.0.1';
$port = (int) (getenv('VIDEOCHAT_KING_PORT') ?: '18080');
$wsPath = getenv('VIDEOCHAT_KING_WS_PATH') ?: '/ws';
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

register_shutdown_function(static function () use ($log): void {
    $error = error_get_last();
    if (is_array($error)) {
        $log(sprintf('shutdown with last error: %s (%s:%d)', (string) ($error['message'] ?? 'unknown'), (string) ($error['file'] ?? 'n/a'), (int) ($error['line'] ?? 0)));
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

$runtimeEnvelope = static function () use ($appVersion, $appEnv, $wsPath, $runtimeHealthSummary): array {
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

$log("king_version=" . (function_exists('king_version') ? (string) king_version() : 'n/a'));
$log("http endpoint bound: http://{$host}:{$port}/");
$log("websocket endpoint bound: ws://{$host}:{$port}{$wsPath}");
$log('starting King HTTP/1 listener...');

$handler = static function (array $request) use ($jsonResponse, $pathFromRequest, $runtimeEnvelope, $wsPath): array {
    $path = $pathFromRequest($request);

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

    if ($path === '/' || $path === '/api/bootstrap') {
        return $jsonResponse(200, [
            'service' => 'video-chat-backend-king-php',
            'status' => 'bootstrapped',
            'message' => 'King HTTP and WebSocket scaffold is active.',
            'ws_path' => $wsPath,
            'runtime_endpoint' => '/api/runtime',
            'version_endpoint' => '/api/version',
            'time' => gmdate('c'),
        ]);
    }

    if ($path === $wsPath) {
        $session = $request['session'] ?? null;
        $streamId = (int) ($request['stream_id'] ?? 0);
        if ($session !== null && $streamId > 0) {
            king_server_on_cancel($session, $streamId, static function (): void {
                // Keep cancel registration explicit for long-lived websocket flows.
            });
        }

        $websocket = king_server_upgrade_to_websocket($session, $streamId);
        if ($websocket === false) {
            return $jsonResponse(400, [
                'error' => 'websocket_upgrade_failed',
                'message' => 'Could not upgrade request to websocket.',
            ]);
        }

        king_websocket_send(
            $websocket,
            json_encode([
                'type' => 'system/welcome',
                'message' => 'video-chat King websocket scaffold connected',
                'time' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES)
        );

        return [
            'status' => 101,
            'headers' => [],
            'body' => '',
        ];
    }

    return $jsonResponse(404, [
        'error' => 'not_found',
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
