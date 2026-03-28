<?php
declare(strict_types=1);

$host = getenv('KING_DEMO_HOST') ?: '0.0.0.0';
$port = (int) (getenv('KING_DEMO_PORT') ?: '8080');
$staticRoot = getenv('KING_DEMO_STATIC_ROOT') ?: __DIR__ . '/dist';

function king_demo_log(string $message): void
{
    fwrite(STDOUT, '[' . gmdate('c') . '] ' . $message . PHP_EOL);
    fflush(STDOUT);
}

function king_demo_mime_type(string $path): string
{
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'ico' => 'image/x-icon',
        'map' => 'application/json; charset=utf-8',
        default => 'text/html; charset=utf-8',
    };
}

function king_demo_resolve_static_path(string $root, string $requestUri): string
{
    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
    $path = rawurldecode($path);
    if ($path === '/' || $path === '') {
        return $root . '/index.html';
    }

    $candidate = realpath($root . '/' . ltrim($path, '/'));
    $rootReal = realpath($root);
    if (
        $candidate === false
        || $rootReal === false
        || !str_starts_with($candidate, $rootReal . DIRECTORY_SEPARATOR)
        || !is_file($candidate)
    ) {
        return $root . '/index.html';
    }

    return $candidate;
}

function king_demo_serve_http(array $request, string $staticRoot): array
{
    $path = parse_url((string) ($request['uri'] ?? '/'), PHP_URL_PATH) ?: '/';

    if ($path === '/health') {
        return [
            'status' => 200,
            'headers' => [
                'content-type' => 'application/json; charset=utf-8',
                'cache-control' => 'no-store',
            ],
            'body' => json_encode([
                'ok' => true,
                'service' => 'king-demo-server',
                'pid' => getmypid(),
                'php_version' => PHP_VERSION,
                'time' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES),
        ];
    }

    $filePath = king_demo_resolve_static_path($staticRoot, (string) ($request['uri'] ?? '/'));
    return [
        'status' => 200,
        'headers' => [
            'content-type' => king_demo_mime_type($filePath),
            'cache-control' => str_ends_with($filePath, '/index.html')
                ? 'no-store'
                : 'public, max-age=300',
        ],
        'body' => file_get_contents($filePath) ?: '',
    ];
}

function king_demo_handle_websocket($websocket): void
{
    while (true) {
        $payload = king_client_websocket_receive($websocket, 1000);
        if ($payload === false) {
            return;
        }

        if ($payload === '') {
            continue;
        }

        if (!king_websocket_send($websocket, $payload, true)) {
            return;
        }
    }
}

if (!is_dir($staticRoot)) {
    fwrite(STDERR, "Missing demo static root: {$staticRoot}\n");
    exit(1);
}

king_demo_log("King demo server listening on {$host}:{$port}");

while (true) {
    $listenOk = king_http1_server_listen_once(
        $host,
        $port,
        [
            'tcp_connect_timeout_ms' => 5000,
        ],
        static function (array $request) use ($staticRoot): array {
            $uri = (string) ($request['uri'] ?? '/');
            $path = parse_url($uri, PHP_URL_PATH) ?: '/';

            if ($path === '/ws') {
                $websocket = king_server_upgrade_to_websocket(
                    $request['session'],
                    (int) $request['stream_id']
                );

                if (!is_resource($websocket)) {
                    return [
                        'status' => 400,
                        'headers' => ['content-type' => 'text/plain; charset=utf-8'],
                        'body' => "websocket upgrade failed\n",
                    ];
                }

                king_demo_handle_websocket($websocket);
                @king_client_websocket_close($websocket, 1000, 'demo-server-done');

                return [
                    'status' => 204,
                    'headers' => [],
                    'body' => '',
                ];
            }

            return king_demo_serve_http($request, $staticRoot);
        }
    );

    if ($listenOk !== true) {
        $error = function_exists('king_get_last_error') ? king_get_last_error() : 'unknown error';
        king_demo_log('listen_once failed: ' . $error);
        usleep(200000);
    }
}
