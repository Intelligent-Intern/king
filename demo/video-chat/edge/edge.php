<?php

declare(strict_types=1);

require_once __DIR__ . '/call_app_static.php';

$httpHost = getenv('VIDEOCHAT_EDGE_HOST') ?: '0.0.0.0';
$httpPort = (int) (getenv('VIDEOCHAT_EDGE_HTTP_PORT') ?: '8080');
$httpsPort = (int) (getenv('VIDEOCHAT_EDGE_HTTPS_PORT') ?: '8443');
$domain = strtolower(trim((string) (getenv('VIDEOCHAT_EDGE_DOMAIN') ?: getenv('VIDEOCHAT_V1_PUBLIC_HOST') ?: 'localhost')));
$rootDomain = strtolower(trim((string) (getenv('VIDEOCHAT_EDGE_ROOT_DOMAIN') ?: getenv('VIDEOCHAT_DEPLOY_DOMAIN') ?: $domain)));
$apiDomain = strtolower(trim((string) (getenv('VIDEOCHAT_EDGE_API_DOMAIN') ?: 'api.' . $rootDomain)));
$wsDomain = strtolower(trim((string) (getenv('VIDEOCHAT_EDGE_WS_DOMAIN') ?: 'ws.' . $rootDomain)));
$sfuDomain = strtolower(trim((string) (getenv('VIDEOCHAT_EDGE_SFU_DOMAIN') ?: 'sfu.' . $rootDomain)));
$turnDomain = strtolower(trim((string) (getenv('VIDEOCHAT_EDGE_TURN_DOMAIN') ?: 'turn.' . $rootDomain)));
$cdnDomain = strtolower(trim((string) (getenv('VIDEOCHAT_EDGE_CDN_DOMAIN') ?: 'cdn.' . $rootDomain)));
$callAppDomain = strtolower(trim((string) (getenv('VIDEOCHAT_EDGE_CALL_APP_DOMAIN') ?: getenv('VIDEOCHAT_DEPLOY_CALL_APP_DOMAIN') ?: 'whiteboard.' . $rootDomain)));
$cdnAliasInput = trim((string) (getenv('VIDEOCHAT_EDGE_CDN_ALIASES') ?: ''));
$externalDomainInput = trim((string) getenv('VIDEOCHAT_EDGE_EXTERNAL_DOMAINS'));
$externalDomains = [];
foreach (preg_split('/\s*,\s*/', $externalDomainInput) ?: [] as $externalDomain) {
    $externalDomain = strtolower(trim((string) $externalDomain));
    if ($externalDomain !== '') {
        $externalDomains[] = $externalDomain;
    }
}
$externalDomains = array_values(array_unique($externalDomains));
$cdnDomains = [$cdnDomain];
foreach (preg_split('/\s*,\s*/', $cdnAliasInput) ?: [] as $alias) {
    $alias = strtolower(trim((string) $alias));
    if ($alias !== '') {
        $cdnDomains[] = $alias;
    }
}
$cdnDomains = array_values(array_unique($cdnDomains));
$reservedRootSubdomains = array_values(array_unique(array_filter([
    'app',
    'api',
    'ws',
    'sfu',
    'cdn',
    'turn',
    'registry',
    'www',
])));
$certFile = getenv('VIDEOCHAT_EDGE_CERT_FILE') ?: '/run/certs/live/fullchain.pem';
$keyFile = getenv('VIDEOCHAT_EDGE_KEY_FILE') ?: '/run/certs/live/privkey.pem';
$staticRoot = rtrim((string) (getenv('VIDEOCHAT_EDGE_STATIC_ROOT') ?: '/app/frontend-dist'), '/');
$callAppRoot = rtrim((string) (getenv('VIDEOCHAT_EDGE_CALL_APP_ROOT') ?: '/app/call-app'), '/');
$apiUpstream = getenv('VIDEOCHAT_EDGE_API_UPSTREAM') ?: 'videochat-backend-v1:18080';
$wsUpstream = getenv('VIDEOCHAT_EDGE_WS_UPSTREAM') ?: 'videochat-backend-ws-v1:18080';
$sfuUpstream = getenv('VIDEOCHAT_EDGE_SFU_UPSTREAM') ?: 'videochat-backend-sfu-v1:18080';
$externalUpstream = trim((string) getenv('VIDEOCHAT_EDGE_EXTERNAL_UPSTREAM'));
$socialPreviewImagePath = getenv('VIDEOCHAT_EDGE_SOCIAL_PREVIEW_IMAGE') ?: '/assets/orgas/kingrt/social/invitation-preview.png';
$maxHeaderBytes = (int) (getenv('VIDEOCHAT_EDGE_MAX_HEADER_BYTES') ?: '65536');
$connectTimeout = (float) (getenv('VIDEOCHAT_EDGE_CONNECT_TIMEOUT_SECONDS') ?: '5');
$httpIdleTimeout = (int) (getenv('VIDEOCHAT_EDGE_HTTP_IDLE_TIMEOUT_SECONDS') ?: '60');
$wsIdleTimeout = (int) (getenv('VIDEOCHAT_EDGE_WS_IDLE_TIMEOUT_SECONDS') ?: '86400');
$writeStallTimeout = max(1.0, (float) (getenv('VIDEOCHAT_EDGE_WRITE_STALL_TIMEOUT_SECONDS') ?: '10'));
$zeroWriteSleepMicros = max(1000, (int) (getenv('VIDEOCHAT_EDGE_ZERO_WRITE_SLEEP_MICROS') ?: '10000'));
$readStallTimeout = max(1.0, (float) (getenv('VIDEOCHAT_EDGE_READ_STALL_TIMEOUT_SECONDS') ?: (string) $writeStallTimeout));
$maxChildren = (int) (getenv('VIDEOCHAT_EDGE_MAX_CHILDREN') ?: '512');
$assetVersion = trim((string) (getenv('VIDEOCHAT_ASSET_VERSION') ?: ''));
$backgroundUploadMaxBodyBytes = max(1024 * 1024, (int) (getenv('VIDEOCHAT_EDGE_BACKGROUND_UPLOAD_MAX_BODY_BYTES') ?: '16777216'));
$backgroundUploadBodyTimeout = max(10, (int) (getenv('VIDEOCHAT_EDGE_BACKGROUND_UPLOAD_BODY_TIMEOUT_SECONDS') ?: '300'));

if ($httpPort < 1 || $httpPort > 65535 || $httpsPort < 1 || $httpsPort > 65535) {
    fwrite(STDERR, "[videochat-edge] invalid listener port configuration\n");
    exit(1);
}
if (!is_file($certFile) || !is_readable($certFile)) {
    fwrite(STDERR, "[videochat-edge] certificate is not readable: {$certFile}\n");
    exit(1);
}
if (!is_file($keyFile) || !is_readable($keyFile)) {
    fwrite(STDERR, "[videochat-edge] private key is not readable: {$keyFile}\n");
    exit(1);
}
if (!is_dir($staticRoot) || !is_readable($staticRoot . '/index.html')) {
    fwrite(STDERR, "[videochat-edge] frontend dist is not readable: {$staticRoot}\n");
    exit(1);
}

$log = static function (string $message): void {
    fwrite(STDERR, '[videochat-edge] ' . $message . "\n");
};

$context = stream_context_create([
    'ssl' => [
        'local_cert' => $certFile,
        'local_pk' => $keyFile,
        'disable_compression' => true,
        'honor_cipher_order' => true,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => false,
        'SNI_enabled' => true,
    ],
    'socket' => [
        'so_reuseport' => false,
        'backlog' => 256,
    ],
]);

$httpServer = @stream_socket_server(
    "tcp://{$httpHost}:{$httpPort}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);
if (!is_resource($httpServer)) {
    fwrite(STDERR, "[videochat-edge] failed to bind HTTP listener {$httpHost}:{$httpPort}: {$errstr} ({$errno})\n");
    exit(1);
}

$httpsServer = @stream_socket_server(
    "tcp://{$httpHost}:{$httpsPort}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);
if (!is_resource($httpsServer)) {
    fclose($httpServer);
    fwrite(STDERR, "[videochat-edge] failed to bind HTTPS listener {$httpHost}:{$httpsPort}: {$errstr} ({$errno})\n");
    exit(1);
}

stream_set_blocking($httpServer, false);
stream_set_blocking($httpsServer, false);

$children = [];
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGCHLD, static function () use (&$children): void {
        while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
            unset($children[$pid]);
        }
    });
}

$normalizeHost = static function (string $host): string {
    $host = strtolower(trim($host));
    if ($host === '') {
        return '';
    }
    if (str_starts_with($host, '[')) {
        $end = strpos($host, ']');
        return $end === false ? $host : substr($host, 0, $end + 1);
    }
    $colon = strpos($host, ':');
    return $colon === false ? $host : substr($host, 0, $colon);
};

$parseUpstream = static function (string $upstream): array {
    $parts = explode(':', $upstream, 2);
    return [$parts[0] ?: '127.0.0.1', isset($parts[1]) ? (int) $parts[1] : 80];
};

$callAppKeyForHost = static function (string $host) use ($rootDomain, $callAppDomain, $reservedRootSubdomains): string {
    $host = strtolower(trim($host));
    if ($host === '') {
        return '';
    }
    if ($host === $callAppDomain) {
        $parts = explode('.', $host);
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', $parts[0] ?? '') === 1 ? (string) $parts[0] : '';
    }
    if ($rootDomain === '' || !str_ends_with($host, '.' . $rootDomain)) {
        return '';
    }

    $label = substr($host, 0, -1 * (strlen($rootDomain) + 1));
    if ($label === '' || str_contains($label, '.')) {
        return '';
    }
    if (in_array($label, $reservedRootSubdomains, true)) {
        return '';
    }
    return preg_match('/^[a-z0-9][a-z0-9-]*$/', $label) === 1 ? $label : '';
};

$readRequestHead = static function ($client) use ($maxHeaderBytes, $readStallTimeout, $zeroWriteSleepMicros): array {
    $head = '';
    $deadline = microtime(true) + 10.0;
    $lastReadProgress = microtime(true);
    stream_set_blocking($client, false);

    while (microtime(true) < $deadline) {
        $read = [$client];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 1, 0);
        if ($ready === false) {
            return ['head' => null, 'bytes_read' => strlen($head), 'reason' => 'select_failed'];
        }
        if ($ready === 0) {
            continue;
        }

        $chunk = @fread($client, 8192);
        if ($chunk === false) {
            return ['head' => null, 'bytes_read' => strlen($head), 'reason' => 'read_failed'];
        }
        if ($chunk === '') {
            if (feof($client)) {
                return ['head' => null, 'bytes_read' => strlen($head), 'reason' => 'client_closed'];
            }
            if ((microtime(true) - $lastReadProgress) >= $readStallTimeout) {
                return ['head' => null, 'bytes_read' => strlen($head), 'reason' => 'read_stalled'];
            }
            usleep($zeroWriteSleepMicros);
            continue;
        }

        $lastReadProgress = microtime(true);
        $head .= $chunk;
        if (strlen($head) > $maxHeaderBytes) {
            return ['head' => null, 'bytes_read' => strlen($head), 'reason' => 'header_too_large'];
        }
        if (strpos($head, "\r\n\r\n") !== false) {
            return ['head' => $head, 'bytes_read' => strlen($head), 'reason' => 'complete'];
        }
    }

    return ['head' => null, 'bytes_read' => strlen($head), 'reason' => 'deadline'];
};

$parseRequest = static function (string $head) use ($normalizeHost): array {
    [$headerBlock] = explode("\r\n\r\n", $head, 2);
    $lines = preg_split('/\r\n/', $headerBlock) ?: [];
    $requestLine = array_shift($lines) ?: '';
    $parts = preg_split('/\s+/', trim($requestLine), 3) ?: [];
    $headers = [];

    foreach ($lines as $line) {
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $colon)));
        $value = trim(substr($line, $colon + 1));
        if ($name !== '') {
            $headers[$name] = $value;
        }
    }

    $target = $parts[1] ?? '/';
    $path = '/';
    $queryPos = strpos($target, '?');
    $rawPath = $queryPos === false ? $target : substr($target, 0, $queryPos);
    if (is_string($rawPath) && $rawPath !== '') {
        $path = str_starts_with($rawPath, '/') ? $rawPath : '/' . $rawPath;
    }

    return [
        'method' => strtoupper((string) ($parts[0] ?? 'GET')),
        'target' => $target,
        'path' => $path,
        'host' => $normalizeHost((string) ($headers['host'] ?? '')),
        'headers' => $headers,
        'upgrade' => strtolower((string) ($headers['upgrade'] ?? '')),
    ];
};

$writeChunk = static function ($stream, string $buffer): array {
    if (!is_resource($stream) || feof($stream)) {
        return ['ok' => false, 'written' => 0];
    }

    $writeWarning = null;
    set_error_handler(static function (int $severity, string $message) use (&$writeWarning): bool {
        $writeWarning = $message;
        return true;
    });
    try {
        $written = fwrite($stream, $buffer);
    } finally {
        restore_error_handler();
    }

    $fatalWriteWarning = false;
    if ($writeWarning !== null) {
        $message = strtolower($writeWarning);
        $fatalWriteWarning = str_contains($message, 'broken pipe')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'errno=32')
            || str_contains($message, 'errno 32')
            || str_contains($message, 'errno=104')
            || str_contains($message, 'errno 104');
    }

    if ($fatalWriteWarning || $written === false || $written < 0) {
        return ['ok' => false, 'written' => 0];
    }

    return ['ok' => true, 'written' => $written];
};

$writeAll = static function ($stream, string $buffer) use ($writeChunk): bool {
    $offset = 0;
    $length = strlen($buffer);

    while ($offset < $length) {
        $chunk = substr($buffer, $offset, 65536);
        $result = $writeChunk($stream, $chunk);
        if (!$result['ok']) {
            return false;
        }
        $written = (int) $result['written'];
        if ($written === 0) {
            $read = null;
            $write = [$stream];
            $except = null;
            $ready = @stream_select($read, $write, $except, 10, 0);
            if ($ready !== 1) {
                return false;
            }
            continue;
        }
        $offset += $written;
    }

    return true;
};

$writeResponse = static function ($client, int $status, string $reason, array $headers, string $body, bool $headOnly = false) use ($writeAll): void {
    $headers['Content-Length'] = (string) strlen($body);
    $headers['Connection'] = 'close';
    $response = "HTTP/1.1 {$status} {$reason}\r\n";
    foreach ($headers as $name => $value) {
        $response .= $name . ': ' . $value . "\r\n";
    }
    $response .= "\r\n";
    if (!$headOnly) {
        $response .= $body;
    }
    $writeAll($client, $response);
};

$proxyCorsHeaders = static function (): array {
    return [
        'Content-Type' => 'text/plain; charset=utf-8',
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET,POST,PATCH,DELETE,OPTIONS',
        'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-Session-Id, X-Upload-Trace-Id, X-Upload-Batch-Index, X-Upload-Batch-Count',
        'Access-Control-Max-Age' => '600',
        'Vary' => 'Origin',
    ];
};

$contentType = static function (string $path): string {
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'html' => 'text/html; charset=utf-8',
        'js', 'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json', 'map' => 'application/json; charset=utf-8',
        'wasm' => 'application/wasm',
        'data', 'tflite', 'binarypb' => 'application/octet-stream',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'txt' => 'text/plain; charset=utf-8',
        default => 'application/octet-stream',
    };
};

$escapeHtml = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$absoluteHttpsUrl = static function (string $host, string $target) use ($domain): string {
    $host = trim($host) !== '' ? $host : $domain;
    $target = trim($target) !== '' ? $target : '/';
    if ($target[0] !== '/') {
        $target = '/' . $target;
    }
    return 'https://' . $host . $target;
};

$injectSocialPreview = static function (string $body, array $request) use ($domain, $cdnDomain, $socialPreviewImagePath, $escapeHtml, $absoluteHttpsUrl): string {
    $target = (string) ($request['target'] ?: ($request['path'] ?? '/'));
    $path = (string) ($request['path'] ?? '/');
    $host = (string) ($request['host'] ?: $domain);
    $assetHost = $cdnDomain !== '' ? $cdnDomain : $host;
    $pageUrl = $absoluteHttpsUrl($host, $target);
    $imageUrl = $absoluteHttpsUrl($assetHost, $socialPreviewImagePath);
    $isInvite = str_starts_with($path, '/join/');
    $title = $isInvite ? "You're invited to a KINGRT video call" : 'KINGRT Video Chat';
    $description = $isInvite
        ? 'Join the video call on KINGRT.'
        : 'Run your own calls with KINGRT open-source video collaboration.';

    $tags = [
        '<meta name="description" content="' . $escapeHtml($description) . '" />',
        '<link rel="canonical" href="' . $escapeHtml($pageUrl) . '" />',
        '<meta property="og:type" content="website" />',
        '<meta property="og:site_name" content="KINGRT" />',
        '<meta property="og:title" content="' . $escapeHtml($title) . '" />',
        '<meta property="og:description" content="' . $escapeHtml($description) . '" />',
        '<meta property="og:url" content="' . $escapeHtml($pageUrl) . '" />',
        '<meta property="og:image" content="' . $escapeHtml($imageUrl) . '" />',
        '<meta property="og:image:secure_url" content="' . $escapeHtml($imageUrl) . '" />',
        '<meta property="og:image:type" content="image/png" />',
        '<meta property="og:image:width" content="1076" />',
        '<meta property="og:image:height" content="562" />',
        '<meta property="og:image:alt" content="' . $escapeHtml($title) . '" />',
        '<meta name="twitter:card" content="summary_large_image" />',
        '<meta name="twitter:title" content="' . $escapeHtml($title) . '" />',
        '<meta name="twitter:description" content="' . $escapeHtml($description) . '" />',
        '<meta name="twitter:image" content="' . $escapeHtml($imageUrl) . '" />',
    ];
    $meta = implode("\n    ", $tags);

    if (str_contains($body, '<!-- kingrt-social-preview -->')) {
        return str_replace('<!-- kingrt-social-preview -->', $meta, $body);
    }

    return str_replace('</head>', "    {$meta}\n  </head>", $body);
};

$serveStatic = static function ($client, array $request) use ($staticRoot, $writeResponse, $contentType, $cdnDomains, $assetVersion, $injectSocialPreview): void {
    $path = rawurldecode((string) $request['path']);
    $isCdnAsset = in_array($request['host'], $cdnDomains, true) || str_starts_with($path, '/cdn/');
    $isCallAppAsset = str_starts_with($path, '/call-app/');
    $corsHeaders = $isCdnAsset
        ? [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Access-Control-Allow-Headers' => 'Origin, Accept, Content-Type, Range',
            'Access-Control-Max-Age' => '86400',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]
        : [];

    if ($request['method'] === 'OPTIONS') {
        $writeResponse($client, 204, 'No Content', $corsHeaders + ['Content-Type' => 'text/plain; charset=utf-8'], '', true);
        return;
    }

    if (!in_array($request['method'], ['GET', 'HEAD'], true)) {
        $writeResponse($client, 405, 'Method Not Allowed', $corsHeaders + ['Content-Type' => 'text/plain; charset=utf-8'], "Method Not Allowed\n");
        return;
    }

    if ($path === '/' || $path === '') {
        $path = '/index.html';
    }

    $candidate = realpath($staticRoot . '/' . ltrim($path, '/'));
    $rootReal = realpath($staticRoot);
    if ($candidate === false || $rootReal === false || !str_starts_with($candidate, $rootReal . DIRECTORY_SEPARATOR) || !is_file($candidate)) {
        if (str_starts_with($path, '/assets/') || preg_match('/\.[A-Za-z0-9]{1,12}$/', $path) === 1) {
            $writeResponse($client, 404, 'Not Found', $corsHeaders + ['Content-Type' => 'text/plain; charset=utf-8'], "Not Found\n", $request['method'] === 'HEAD');
            return;
        }
        $candidate = $staticRoot . '/index.html';
    }

    $body = (string) @file_get_contents($candidate);
    if (basename($candidate) === 'index.html') {
        $body = $injectSocialPreview($body, $request);
    }
    $headers = [
        'Content-Type' => $contentType($candidate),
        'Cache-Control' => basename($candidate) === 'index.html'
            ? 'no-store'
            : 'public, max-age=31536000, immutable',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'X-Content-Type-Options' => 'nosniff',
    ] + $corsHeaders;
    if ($assetVersion !== '') {
        $headers['X-KingRT-Asset-Version'] = $assetVersion;
    }
    if ($isCallAppAsset) {
        $headers['Content-Security-Policy'] = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data: blob:; font-src 'self'; frame-ancestors 'self'";
        $headers['Cross-Origin-Resource-Policy'] = 'same-origin';
    }
    $writeResponse($client, 200, 'OK', $headers, $body, $request['method'] === 'HEAD');
};

$isBackgroundUploadRequest = static function (array $request): bool {
    return (string) ($request['method'] ?? '') === 'POST'
        && (string) ($request['path'] ?? '') === '/api/admin/workspace-administration/background-images';
};

$uploadTraceIdFromRequest = static function (array $request): string {
    $headers = is_array($request['headers'] ?? null) ? (array) $request['headers'] : [];
    $candidate = trim((string) ($headers['x-upload-trace-id'] ?? ''));
    if ($candidate !== '' && preg_match('/^[A-Za-z0-9._-]{1,80}$/', $candidate) === 1) {
        return $candidate;
    }

    try {
        return 'edge_bgup_' . bin2hex(random_bytes(8));
    } catch (Throwable) {
        return 'edge_bgup_' . substr(hash('sha256', uniqid('background-upload', true) . microtime(true)), 0, 16);
    }
};

$edgeUploadLog = static function (string $traceId, string $stage, array $fields = []) use ($log): void {
    $safe = [];
    foreach ($fields as $key => $value) {
        $name = is_string($key) ? $key : (string) $key;
        if (is_array($value)) {
            $safe[$name] = array_slice($value, 0, 12, true);
            continue;
        }
        if (is_string($value)) {
            $safe[$name] = strlen($value) > 240 ? substr($value, 0, 240) . '...' : $value;
            continue;
        }
        if (is_scalar($value) || $value === null) {
            $safe[$name] = $value;
        }
    }
    $log('[background-upload] ' . json_encode([
        'trace_id' => $traceId,
        'stage' => $stage,
        'time' => gmdate('c'),
        'details' => $safe,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
};

$injectForwardHeaders = static function (string $head, array $request): string {
    $parts = explode("\r\n\r\n", $head, 2);
    $headerBlock = $parts[0] ?? '';
    $body = $parts[1] ?? '';
    $lines = preg_split('/\r\n/', $headerBlock) ?: [];
    $requestLine = array_shift($lines) ?: '';
    $filtered = [];
    $contentLengthSeen = false;

    foreach ($lines as $line) {
        $name = strtolower(trim(strtok($line, ':') ?: ''));
        if (in_array($name, ['connection', 'proxy-connection', 'x-forwarded-proto', 'x-forwarded-host'], true)) {
            continue;
        }
        if ($name === 'content-length') {
            if ($contentLengthSeen) {
                continue;
            }
            $contentLengthSeen = true;
        }
        $filtered[] = $line;
    }

    $filtered[] = $request['upgrade'] === 'websocket'
        ? 'Connection: Upgrade'
        : 'Connection: close';
    $filtered[] = 'X-Forwarded-Proto: https';
    $host = $request['headers']['host'] ?? '';
    if ($host !== '') {
        $filtered[] = "X-Forwarded-Host: {$host}";
    }

    return $requestLine . "\r\n" . implode("\r\n", $filtered) . "\r\n\r\n" . $body;
};

$proxy = static function ($client, string $head, array $request, string $upstream) use ($parseUpstream, $connectTimeout, $httpIdleTimeout, $wsIdleTimeout, $writeStallTimeout, $readStallTimeout, $zeroWriteSleepMicros, $writeChunk, $writeResponse, $proxyCorsHeaders, $injectForwardHeaders, $isBackgroundUploadRequest, $uploadTraceIdFromRequest, $edgeUploadLog, $backgroundUploadMaxBodyBytes, $backgroundUploadBodyTimeout): void {
    $isBackgroundUpload = $isBackgroundUploadRequest($request);
    $uploadTraceId = $isBackgroundUpload ? $uploadTraceIdFromRequest($request) : '';
    $headParts = explode("\r\n\r\n", $head, 2);
    $initialBodyBytes = strlen((string) ($headParts[1] ?? ''));
    $clientBodyBytesSeen = $initialBodyBytes;
    $upstreamBytesWritten = 0;
    $upstreamResponseStatus = '';
    $upstreamResponseHead = '';
    $proxyStartedAt = microtime(true);
    [$upstreamHost, $upstreamPort] = $parseUpstream($upstream);
    if ($isBackgroundUpload) {
        $contentLength = (int) trim((string) ($request['headers']['content-length'] ?? '0'));
        $edgeUploadLog($uploadTraceId, 'proxy_connect_started', [
            'upstream' => $upstream,
            'content_length' => (string) (($request['headers']['content-length'] ?? '')),
            'transfer_encoding' => (string) (($request['headers']['transfer-encoding'] ?? '')),
            'head_bytes' => strlen($head),
            'initial_body_bytes' => $initialBodyBytes,
        ]);
        if ($contentLength <= 0 || $contentLength > $backgroundUploadMaxBodyBytes) {
            $edgeUploadLog($uploadTraceId, 'proxy_rejected_upload_body_size', [
                'content_length' => $contentLength,
                'max_body_bytes' => $backgroundUploadMaxBodyBytes,
            ]);
            $payload = json_encode([
                'status' => 'error',
                'error' => [
                    'code' => 'workspace_background_upload_failed',
                    'message' => 'Background image upload request body is too large.',
                    'details' => [
                        'reason' => $contentLength <= 0 ? 'invalid_content_length' : 'request_body_too_large',
                        'trace_id' => $uploadTraceId,
                        'body_bytes' => $contentLength,
                        'max_body_bytes' => $backgroundUploadMaxBodyBytes,
                    ],
                ],
                'time' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers = $proxyCorsHeaders();
            $headers['Content-Type'] = 'application/json; charset=utf-8';
            $headers['X-Upload-Trace-Id'] = $uploadTraceId;
            $writeResponse($client, $contentLength <= 0 ? 411 : 413, $contentLength <= 0 ? 'Length Required' : 'Payload Too Large', $headers, is_string($payload) ? $payload : '{}');
            return;
        }

        $body = (string) ($headParts[1] ?? '');
        if (strlen($body) > $contentLength) {
            $body = substr($body, 0, $contentLength);
        }
        $remainingBodyBytes = max(0, $contentLength - strlen($body));
        $edgeUploadLog($uploadTraceId, 'proxy_body_read_started', [
            'content_length' => $contentLength,
            'initial_body_bytes' => strlen($body),
            'remaining_body_bytes' => $remainingBodyBytes,
            'max_body_bytes' => $backgroundUploadMaxBodyBytes,
        ]);
        $bodyReadDeadline = microtime(true) + $backgroundUploadBodyTimeout;
        $lastBodyReadProgress = microtime(true);
        while ($remainingBodyBytes > 0 && microtime(true) < $bodyReadDeadline) {
            $read = [$client];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 1, 0);
            if ($ready === false) {
                break;
            }
            if ($ready === 0) {
                if ((microtime(true) - $lastBodyReadProgress) >= $readStallTimeout) {
                    break;
                }
                continue;
            }
            $chunk = @fread($client, min(65536, $remainingBodyBytes));
            if ($chunk === false || $chunk === '') {
                if (feof($client) || (microtime(true) - $lastBodyReadProgress) >= $readStallTimeout) {
                    break;
                }
                usleep($zeroWriteSleepMicros);
                continue;
            }
            $body .= $chunk;
            $clientBodyBytesSeen = strlen($body);
            $remainingBodyBytes = max(0, $contentLength - strlen($body));
            $lastBodyReadProgress = microtime(true);
        }
        if (strlen($body) !== $contentLength) {
            $edgeUploadLog($uploadTraceId, 'proxy_body_read_failed', [
                'content_length' => $contentLength,
                'client_body_bytes_seen' => strlen($body),
                'remaining_body_bytes' => max(0, $contentLength - strlen($body)),
                'duration_ms' => (int) round((microtime(true) - $proxyStartedAt) * 1000),
            ]);
            $payload = json_encode([
                'status' => 'error',
                'error' => [
                    'code' => 'workspace_background_upload_failed',
                    'message' => 'Background image upload body could not be read completely.',
                    'details' => [
                        'reason' => 'request_body_incomplete',
                        'trace_id' => $uploadTraceId,
                        'body_bytes' => strlen($body),
                        'content_length' => $contentLength,
                    ],
                ],
                'time' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers = $proxyCorsHeaders();
            $headers['Content-Type'] = 'application/json; charset=utf-8';
            $headers['X-Upload-Trace-Id'] = $uploadTraceId;
            $writeResponse($client, 408, 'Request Timeout', $headers, is_string($payload) ? $payload : '{}');
            return;
        }
        $edgeUploadLog($uploadTraceId, 'proxy_body_read_complete', [
            'content_length' => $contentLength,
            'client_body_bytes_seen' => strlen($body),
            'duration_ms' => (int) round((microtime(true) - $proxyStartedAt) * 1000),
        ]);
    }
    $upstreamStream = @stream_socket_client(
        "tcp://{$upstreamHost}:{$upstreamPort}",
        $errno,
        $errstr,
        $connectTimeout,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($upstreamStream)) {
        if ($isBackgroundUpload) {
            $edgeUploadLog($uploadTraceId, 'proxy_connect_failed', [
                'upstream' => $upstream,
                'errno' => $errno,
                'error' => $errstr,
            ]);
        }
        $writeResponse($client, 502, 'Bad Gateway', $proxyCorsHeaders(), "Bad Gateway\n");
        return;
    }

    stream_set_blocking($client, false);
    stream_set_blocking($upstreamStream, false);

    $toUpstream = $isBackgroundUpload
        ? $injectForwardHeaders((string) ($headParts[0] ?? '') . "\r\n\r\n", $request) . $body
        : $injectForwardHeaders($head, $request);
    $toClient = '';
    $clientOpen = true;
    $upstreamOpen = true;
    $isWebSocket = $request['upgrade'] === 'websocket';
    $webSocketHandshakeAccepted = !$isWebSocket;
    $idleTimeout = $isWebSocket ? $wsIdleTimeout : ($isBackgroundUpload ? $backgroundUploadBodyTimeout : $httpIdleTimeout);
    $lastActivity = microtime(true);
    $lastClientReadProgress = $lastActivity;
    $lastUpstreamReadProgress = $lastActivity;
    $lastClientWriteProgress = $lastActivity;
    $lastUpstreamWriteProgress = $lastActivity;
    $backgroundUploadRequestWrittenLogged = false;
    $closeWebSocketTunnel = static function () use (&$clientOpen, &$upstreamOpen, &$toClient, &$toUpstream): void {
        // WebSocket tunnels cannot stay half-open: otherwise browser requests
        // remain pending while the closed upstream socket sits in CLOSE_WAIT.
        $clientOpen = false;
        $upstreamOpen = false;
        $toClient = '';
        $toUpstream = '';
    };
    $closeWebSocketUpstream = static function () use (&$upstreamOpen, &$toUpstream): void {
        // The upstream may close immediately after rejecting a websocket
        // handshake. Keep any buffered HTTP rejection queued for the client.
        $upstreamOpen = false;
        $toUpstream = '';
    };

    while ($clientOpen || $upstreamOpen || $toUpstream !== '' || $toClient !== '') {
        if ((microtime(true) - $lastActivity) > $idleTimeout) {
            break;
        }
        // Upstream may reject a websocket handshake with HTTP bytes, then close.
        // Keep the client side alive until that buffered response is flushed.
        if ($isWebSocket && !$upstreamOpen && $toClient === '') {
            $clientOpen = false;
        }
        if ($isWebSocket && !$clientOpen && $toUpstream === '') {
            $upstreamOpen = false;
        }
        if (!$isWebSocket && !$upstreamOpen && $toClient === '' && $toUpstream === '') {
            break;
        }
        if (!$clientOpen) {
            $toClient = '';
        }
        if (!$upstreamOpen) {
            $toUpstream = '';
        }
        if (!$clientOpen && $toClient === '') {
            break;
        }
        if (!$clientOpen && !$upstreamOpen) {
            break;
        }

        $read = [];
        $canReadClient = $clientOpen && strlen($toUpstream) < 1048576;
        if ($isWebSocket && !$webSocketHandshakeAccepted) {
            $canReadClient = false;
        }
        if ($canReadClient) {
            $read[] = $client;
        }
        if ($upstreamOpen && strlen($toClient) < 1048576) {
            $read[] = $upstreamStream;
        }

        $write = [];
        if ($upstreamOpen && $toUpstream !== '') {
            $write[] = $upstreamStream;
        }
        if ($clientOpen && $toClient !== '') {
            $write[] = $client;
        }

        $except = null;
        if ($read === [] && $write === []) {
            break;
        }

        $ready = @stream_select($read, $write, $except, 1, 0);
        if ($ready === false) {
            break;
        }
        if ($ready === 0) {
            continue;
        }

        $madeProgress = false;
        $needsBackoff = false;
        foreach ($read as $stream) {
            $chunk = @fread($stream, 16384);
            if ($chunk === false) {
                if ($isWebSocket) {
                    if ($stream === $upstreamStream) {
                        $closeWebSocketUpstream();
                        $madeProgress = true;
                        continue;
                    }
                    $closeWebSocketTunnel();
                    continue;
                }
                if ($stream === $client) {
                    $clientOpen = false;
                } else {
                    $upstreamOpen = false;
                }
                continue;
            }
            if ($chunk === '') {
                if (feof($stream)) {
                    if ($isWebSocket) {
                        if ($stream === $upstreamStream) {
                            $closeWebSocketUpstream();
                            $madeProgress = true;
                            continue;
                        }
                        $closeWebSocketTunnel();
                        $madeProgress = true;
                        continue;
                    }
                    if ($stream === $client) {
                        $clientOpen = false;
                    } else {
                        $upstreamOpen = false;
                    }
                    $madeProgress = true;
                    continue;
                }
                if ($isWebSocket) {
                    // TLS/nonblocking WebSocket streams can report readability
                    // without yielding bytes. Keep the tunnel alive and let the
                    // websocket idle timeout decide, otherwise live SFU streams
                    // are cut while the browser is still connected.
                    $needsBackoff = true;
                    continue;
                }
                if ($stream === $client) {
                    if ((microtime(true) - $lastClientReadProgress) >= $readStallTimeout) {
                        $clientOpen = false;
                        $madeProgress = true;
                    } else {
                        $needsBackoff = true;
                    }
                } else {
                    if ((microtime(true) - $lastUpstreamReadProgress) >= $readStallTimeout) {
                        $upstreamOpen = false;
                        $madeProgress = true;
                    } else {
                        $needsBackoff = true;
                    }
                }
                continue;
            }
            $lastActivity = microtime(true);
            if ($stream === $client) {
                $lastClientReadProgress = $lastActivity;
                if ($toUpstream === '') {
                    $lastUpstreamWriteProgress = $lastActivity;
                }
                $toUpstream .= $chunk;
                if ($isBackgroundUpload) {
                    $clientBodyBytesSeen += strlen($chunk);
                }
            } else {
                $lastUpstreamReadProgress = $lastActivity;
                if ($toClient === '') {
                    $lastClientWriteProgress = $lastActivity;
                }
                if ($isWebSocket && !$webSocketHandshakeAccepted) {
                    $handshakeProbe = $toClient . $chunk;
                    $statusLineEnd = strpos($handshakeProbe, "\r\n");
                    if ($statusLineEnd !== false) {
                        $statusLine = substr($handshakeProbe, 0, $statusLineEnd);
                        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+101\b/i', $statusLine) === 1) {
                            $webSocketHandshakeAccepted = true;
                        }
                    }
                }
                $toClient .= $chunk;
                if ($isBackgroundUpload && $upstreamResponseStatus === '') {
                    $upstreamResponseHead .= $chunk;
                    if (strpos($upstreamResponseHead, "\r\n") !== false) {
                        $statusLine = strtok($upstreamResponseHead, "\r\n") ?: '';
                        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})\b/', $statusLine, $statusMatch) === 1) {
                            $upstreamResponseStatus = (string) ($statusMatch[1] ?? '');
                            $edgeUploadLog($uploadTraceId, 'proxy_upstream_response_started', [
                                'status' => $upstreamResponseStatus,
                                'client_body_bytes_seen' => $clientBodyBytesSeen,
                                'upstream_bytes_written' => $upstreamBytesWritten,
                            ]);
                        }
                    }
                    if (strlen($upstreamResponseHead) > 8192) {
                        $upstreamResponseHead = substr($upstreamResponseHead, 0, 8192);
                    }
                }
            }
            $madeProgress = true;
        }

        foreach ($write as $stream) {
            if ($stream === $upstreamStream && $toUpstream !== '') {
                $writeBuffer = $isBackgroundUpload ? substr($toUpstream, 0, 65536) : $toUpstream;
                $result = $writeChunk($upstreamStream, $writeBuffer);
                if (!$result['ok']) {
                    if ($isBackgroundUpload) {
                        $edgeUploadLog($uploadTraceId, 'proxy_upstream_write_failed', [
                            'client_body_bytes_seen' => $clientBodyBytesSeen,
                            'upstream_bytes_written' => $upstreamBytesWritten,
                            'queued_to_upstream_bytes' => strlen($toUpstream),
                        ]);
                    }
                    if ($isWebSocket) {
                        $closeWebSocketTunnel();
                        continue;
                    }
                    $upstreamOpen = false;
                    $toUpstream = '';
                    continue;
                }
                $written = (int) $result['written'];
                if ($written === 0) {
                    if ((microtime(true) - $lastUpstreamWriteProgress) >= $writeStallTimeout) {
                        if ($isWebSocket) {
                            $closeWebSocketTunnel();
                            continue;
                        }
                        $upstreamOpen = false;
                        $toUpstream = '';
                    } else {
                        usleep($zeroWriteSleepMicros);
                    }
                    continue;
                }
                $toUpstream = substr($toUpstream, $written);
                if ($isBackgroundUpload) {
                    $upstreamBytesWritten += $written;
                    if (!$backgroundUploadRequestWrittenLogged && $toUpstream === '') {
                        $backgroundUploadRequestWrittenLogged = true;
                        $edgeUploadLog($uploadTraceId, 'proxy_upstream_request_written', [
                            'client_body_bytes_seen' => $clientBodyBytesSeen,
                            'upstream_bytes_written' => $upstreamBytesWritten,
                        ]);
                    }
                }
                $lastActivity = microtime(true);
                $lastUpstreamWriteProgress = $lastActivity;
                $madeProgress = true;
            }
            if ($stream === $client && $toClient !== '') {
                $result = $writeChunk($client, $toClient);
                if (!$result['ok']) {
                    if ($isBackgroundUpload) {
                        $edgeUploadLog($uploadTraceId, 'proxy_client_write_failed', [
                            'client_body_bytes_seen' => $clientBodyBytesSeen,
                            'upstream_bytes_written' => $upstreamBytesWritten,
                            'upstream_response_status' => $upstreamResponseStatus,
                        ]);
                    }
                    if ($isWebSocket) {
                        $closeWebSocketTunnel();
                        continue;
                    }
                    $clientOpen = false;
                    $toClient = '';
                    continue;
                }
                $written = (int) $result['written'];
                if ($written === 0) {
                    if ((microtime(true) - $lastClientWriteProgress) >= $writeStallTimeout) {
                        if ($isWebSocket) {
                            $closeWebSocketTunnel();
                            continue;
                        }
                        $clientOpen = false;
                        $toClient = '';
                    } else {
                        usleep($zeroWriteSleepMicros);
                    }
                    continue;
                }
                $toClient = substr($toClient, $written);
                $lastActivity = microtime(true);
                $lastClientWriteProgress = $lastActivity;
                $madeProgress = true;
            }
        }

        if (!$madeProgress && $needsBackoff) {
            usleep($zeroWriteSleepMicros);
        }
    }

    if ($isBackgroundUpload) {
        $edgeUploadLog($uploadTraceId, 'proxy_finished', [
            'duration_ms' => (int) round((microtime(true) - $proxyStartedAt) * 1000),
            'client_body_bytes_seen' => $clientBodyBytesSeen,
            'upstream_bytes_written' => $upstreamBytesWritten,
            'upstream_response_status' => $upstreamResponseStatus,
            'client_open' => $clientOpen,
            'upstream_open' => $upstreamOpen,
        ]);
    }
    @fclose($upstreamStream);
};

$route = static function (array $request) use ($domain, $apiDomain, $wsDomain, $sfuDomain, $turnDomain, $cdnDomains, $externalDomains, $apiUpstream, $wsUpstream, $sfuUpstream, $externalUpstream, $callAppKeyForHost): ?string {
    $host = $request['host'];
    $path = $request['path'];
    if ($externalUpstream !== '' && in_array($host, $externalDomains, true)) {
        return $externalUpstream;
    }
    if (in_array($host, $cdnDomains, true)) {
        return 'static';
    }
    if ($callAppKeyForHost($host) !== '' || str_starts_with($path, '/call-app/')) {
        return 'call_app_static';
    }
    if ($path === '/ws' || $host === $wsDomain) {
        return $wsUpstream;
    }
    if ($path === '/sfu' || $host === $sfuDomain) {
        return $sfuUpstream;
    }
    if ($host === $apiDomain || $path === '/api' || str_starts_with($path, '/api/') || $path === '/health') {
        return $apiUpstream;
    }
    if ($host === $turnDomain) {
        return null;
    }
    if ($host === $domain || $host === '') {
        return 'static';
    }
    return 'static';
};

$handleClient = static function ($client, bool $tls) use ($domain, $callAppRoot, $assetVersion, $readRequestHead, $parseRequest, $writeResponse, $contentType, $route, $serveStatic, $proxy, $proxyCorsHeaders, $isBackgroundUploadRequest, $uploadTraceIdFromRequest, $edgeUploadLog, $callAppKeyForHost): void {
    stream_set_timeout($client, 10);
    if ($tls) {
        $crypto = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
        if ($crypto !== true) {
            @fclose($client);
            return;
        }
    }

    $requestHead = $readRequestHead($client);
    $head = $requestHead['head'] ?? null;
    if ($head === null) {
        if ((int) ($requestHead['bytes_read'] ?? 0) <= 0) {
            @fclose($client);
            return;
        }
        $writeResponse($client, 400, 'Bad Request', ['Content-Type' => 'text/plain; charset=utf-8'], "Bad Request\n");
        @fclose($client);
        return;
    }

    $request = $parseRequest($head);
    if (!$tls) {
        $host = (string) ($request['headers']['host'] ?? $domain);
        $host = preg_replace('/:\d+$/', '', $host) ?: $domain;
        $target = (string) ($request['target'] ?: '/');
        $location = 'https://' . $host . $target;
        $writeResponse($client, 301, 'Moved Permanently', [
            'Location' => $location,
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ], "Redirecting to {$location}\n", $request['method'] === 'HEAD');
        @fclose($client);
        return;
    }

    $upstream = $route($request);
    if ($isBackgroundUploadRequest($request)) {
        $traceId = $uploadTraceIdFromRequest($request);
        $headers = is_array($request['headers'] ?? null) ? (array) $request['headers'] : [];
        $contentLength = trim((string) ($headers['content-length'] ?? ''));
        $transferEncoding = strtolower(trim((string) ($headers['transfer-encoding'] ?? '')));
        if ($transferEncoding !== '' || $contentLength === '' || preg_match('/^\d+$/', $contentLength) !== 1) {
            $edgeUploadLog($traceId, 'proxy_rejected_invalid_upload_length', [
                'content_length' => $contentLength,
                'transfer_encoding' => $transferEncoding,
                'path' => (string) ($request['path'] ?? ''),
            ]);
            $payload = json_encode([
                'status' => 'error',
                'error' => [
                    'code' => 'workspace_background_upload_failed',
                    'message' => 'Background image uploads require a valid Content-Length.',
                    'details' => [
                        'reason' => $transferEncoding !== '' ? 'chunked_upload_not_supported' : 'invalid_content_length',
                        'trace_id' => $traceId,
                    ],
                ],
                'time' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers = $proxyCorsHeaders();
            $headers['Content-Type'] = 'application/json; charset=utf-8';
            $headers['X-Upload-Trace-Id'] = $traceId;
            $writeResponse($client, $transferEncoding !== '' ? 400 : 411, $transferEncoding !== '' ? 'Bad Request' : 'Length Required', $headers, is_string($payload) ? $payload : '{}');
            @fclose($client);
            return;
        }
    }
    if ($upstream === 'static') {
        $serveStatic($client, $request);
        @fclose($client);
        return;
    }
    if ($upstream === 'call_app_static') {
        $callAppKey = $callAppKeyForHost((string) ($request['host'] ?? ''));
        if ($callAppKey !== '' && !str_starts_with((string) ($request['path'] ?? ''), '/call-app/')) {
            $path = (string) ($request['path'] ?? '/');
            if ($path === '/' || $path === '') {
                $path = '/public/index.html';
            }
            $request['path'] = '/call-app/' . rawurlencode($callAppKey) . '/' . ltrim($path, '/');
        }
        videochat_edge_serve_call_app_static($client, $request, $callAppRoot, $writeResponse, $contentType, $assetVersion, 'https://' . $domain);
        @fclose($client);
        return;
    }
    if ($upstream === null) {
        $writeResponse($client, 404, 'Not Found', ['Content-Type' => 'text/plain; charset=utf-8'], "Not Found\n", $request['method'] === 'HEAD');
        @fclose($client);
        return;
    }

    $proxy($client, $head, $request, $upstream);
    @fclose($client);
};

$log("listening on {$httpHost}:{$httpPort} redirect and {$httpHost}:{$httpsPort} tls for {$domain}");

while (true) {
    $read = [$httpServer, $httpsServer];
    $write = null;
    $except = null;
    $ready = @stream_select($read, $write, $except, 1, 0);
    if ($ready === false) {
        usleep(100000);
        continue;
    }
    if ($ready === 0) {
        if (function_exists('pcntl_waitpid')) {
            while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                unset($children[$pid]);
            }
        }
        continue;
    }

    foreach ($read as $server) {
        while (count($children) >= $maxChildren && function_exists('pcntl_waitpid')) {
            $pid = pcntl_waitpid(-1, $status);
            if ($pid > 0) {
                unset($children[$pid]);
            } else {
                break;
            }
        }

        $client = @stream_socket_accept($server, 0);
        if (!is_resource($client)) {
            continue;
        }
        $tls = $server === $httpsServer;

        if (function_exists('pcntl_fork')) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $log('fork failed; handling connection in master');
                $handleClient($client, $tls);
                continue;
            }
            if ($pid === 0) {
                @fclose($httpServer);
                @fclose($httpsServer);
                $handleClient($client, $tls);
                exit(0);
            }
            $children[$pid] = true;
            @fclose($client);
            continue;
        }

        $handleClient($client, $tls);
    }
}
