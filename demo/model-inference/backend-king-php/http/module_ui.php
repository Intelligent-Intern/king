<?php

declare(strict_types=1);

/**
 * Minimal UI module. Serves the single-file chat client at GET /ui so an
 * operator can open a browser, type a prompt, and watch real SmolLM2
 * tokens stream in over the #M-11 WebSocket path.
 *
 * Scope fence: this module is a static-file server for one file. It does
 * NOT implement a general-purpose /public asset pipeline. A richer UI
 * (asset hashing, compression, SPA routing, etc.) is a future hardening
 * leaf; for now this is an honest one-file chat so the existing wire
 * contract is browsable from the host machine.
 */
function model_inference_handle_ui_routes(
    string $path,
    string $method,
    callable $jsonResponse,
    callable $errorResponse
): ?array {
    // A-7c: /ui/* is the browser-UI namespace. /ui/login is the
    // dedicated login page; /ui (with or without a trailing slash) is
    // the chat app. Keeping both under the same prefix matches the
    // rest of the demo and keeps the root path reserved for API +
    // health surfaces.
    if ($path === '/ui/login' || $path === '/ui/login/') {
        return model_inference_ui_serve_static(
            $method, __DIR__ . '/../public/login.html', $errorResponse
        );
    }
    if ($path !== '/ui' && $path !== '/ui/') {
        return null;
    }
    return model_inference_ui_serve_static(
        $method, __DIR__ . '/../public/chat.html', $errorResponse
    );
}

/**
 * Shared one-shot static-file helper. Keeps /ui and /login on the same
 * simple read-from-disk path so both benefit from the same cache-busting
 * + security headers without spreading a mini asset pipeline across the
 * codebase.
 */
function model_inference_ui_serve_static(string $method, string $file, callable $errorResponse): array
{
    if ($method !== 'GET' && $method !== 'HEAD') {
        return $errorResponse(405, 'method_not_allowed', 'GET required.', [
            'method' => $method, 'allowed' => ['GET', 'HEAD'],
        ]);
    }
    if (!is_file($file)) {
        return $errorResponse(500, 'internal_server_error', 'UI asset is missing.', [
            'field' => basename($file), 'reason' => 'not_found',
        ]);
    }
    $body = @file_get_contents($file);
    if ($body === false) {
        return $errorResponse(500, 'internal_server_error', 'UI asset could not be read.', [
            'field' => basename($file), 'reason' => 'read_failed',
        ]);
    }
    return [
        'status' => 200,
        'headers' => [
            'content-type' => 'text/html; charset=utf-8',
            'cache-control' => 'no-store',
            'x-content-type-options' => 'nosniff',
            'referrer-policy' => 'no-referrer',
        ],
        'body' => $method === 'HEAD' ? '' : $body,
    ];
}
