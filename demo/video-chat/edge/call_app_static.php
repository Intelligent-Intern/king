<?php

declare(strict_types=1);

function videochat_edge_call_app_normalize_origin(string $origin): string
{
    $trimmed = trim($origin);
    if ($trimmed === '') {
        return '';
    }

    $parts = parse_url($trimmed);
    if (!is_array($parts)) {
        return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return '';
    }
    if (preg_match('/^[a-z0-9.-]+$|^\[[a-f0-9:.]+\]$/i', $host) !== 1) {
        return '';
    }

    $origin = $scheme . '://' . $host;
    $port = isset($parts['port']) ? (int) $parts['port'] : 0;
    if ($port > 0 && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
        $origin .= ':' . $port;
    }

    return $origin;
}

function videochat_edge_call_app_frame_ancestor(string $allowedEmbedderOrigin): string
{
    $normalized = videochat_edge_call_app_normalize_origin($allowedEmbedderOrigin);
    return $normalized !== '' ? $normalized : "'none'";
}

function videochat_edge_call_app_content_security_policy(string $allowedEmbedderOrigin): string
{
    $frameAncestor = videochat_edge_call_app_frame_ancestor($allowedEmbedderOrigin);
    return implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline'",
        "style-src 'self' 'unsafe-inline'",
        "connect-src 'self'",
        "img-src 'self' data: blob:",
        "font-src 'self'",
        "base-uri 'none'",
        "object-src 'none'",
        "frame-src 'none'",
        "form-action 'self'",
        'frame-ancestors ' . $frameAncestor,
    ]);
}

function videochat_edge_serve_call_app_static($client, array $request, string $callAppRoot, callable $writeResponse, callable $contentType, string $assetVersion, string $allowedEmbedderOrigin = ''): void
{
    $allowedEmbedderOrigin = videochat_edge_call_app_normalize_origin($allowedEmbedderOrigin);
    $corsHeaders = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
        'Access-Control-Allow-Headers' => 'Origin, Accept, Content-Type, Range',
        'Access-Control-Max-Age' => '86400',
        'Cross-Origin-Resource-Policy' => 'cross-origin',
    ];

    if (($request['method'] ?? '') === 'OPTIONS') {
        $writeResponse($client, 204, 'No Content', $corsHeaders + ['Content-Type' => 'text/plain; charset=utf-8'], '', true);
        return;
    }

    if (!in_array((string) ($request['method'] ?? ''), ['GET', 'HEAD'], true)) {
        $writeResponse($client, 405, 'Method Not Allowed', $corsHeaders + ['Content-Type' => 'text/plain; charset=utf-8'], "Method Not Allowed\n");
        return;
    }

    $path = rawurldecode((string) ($request['path'] ?? '/'));
    if ($path === '/' || $path === '') {
        $path = '/call-app/whiteboard/public/index.html';
    }
    if (!str_starts_with($path, '/call-app/')) {
        $writeResponse($client, 404, 'Not Found', $corsHeaders + ['Content-Type' => 'text/plain; charset=utf-8'], "Not Found\n", ($request['method'] ?? '') === 'HEAD');
        return;
    }

    $relative = substr($path, strlen('/call-app/'));
    $relativeParts = array_values(array_filter(explode('/', $relative), static fn (string $part): bool => $part !== '' && $part !== '.' && $part !== '..'));
    if ($relativeParts === []) {
        $relativeParts = ['whiteboard', 'public', 'index.html'];
    }

    $rootReal = realpath($callAppRoot);
    $candidate = $rootReal === false ? false : realpath($rootReal . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $relativeParts));
    if ($candidate === false || $rootReal === false || !str_starts_with($candidate, $rootReal . DIRECTORY_SEPARATOR) || !is_file($candidate)) {
        $writeResponse($client, 404, 'Not Found', $corsHeaders + ['Content-Type' => 'text/plain; charset=utf-8'], "Not Found\n", ($request['method'] ?? '') === 'HEAD');
        return;
    }

    $body = (string) @file_get_contents($candidate);
    $isHtmlEntrypoint = basename($candidate) === 'index.html';
    $headers = [
        'Content-Type' => $contentType($candidate),
        'Cache-Control' => $isHtmlEntrypoint
            ? 'no-store'
            : 'public, max-age=31536000, immutable',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'X-Content-Type-Options' => 'nosniff',
    ] + $corsHeaders;
    if ($isHtmlEntrypoint) {
        $headers['Content-Security-Policy'] = videochat_edge_call_app_content_security_policy($allowedEmbedderOrigin);
        if ($allowedEmbedderOrigin !== '') {
            $headers['Allow-CSP-From'] = $allowedEmbedderOrigin;
        }
    }
    if ($assetVersion !== '') {
        $headers['X-KingRT-Asset-Version'] = $assetVersion;
    }

    $writeResponse($client, 200, 'OK', $headers, $body, ($request['method'] ?? '') === 'HEAD');
}
