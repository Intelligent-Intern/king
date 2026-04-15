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
    if ($path !== '/ui' && $path !== '/ui/') {
        return null;
    }
    if ($method !== 'GET' && $method !== 'HEAD') {
        return $errorResponse(405, 'method_not_allowed', 'GET required.', [
            'path' => $path,
            'method' => $method,
            'allowed' => ['GET', 'HEAD'],
        ]);
    }

    $file = __DIR__ . '/../public/chat.html';
    if (!is_file($file)) {
        return $errorResponse(500, 'internal_server_error', 'Chat UI asset is missing.', [
            'field' => 'public/chat.html',
            'reason' => 'not_found',
        ]);
    }

    $body = @file_get_contents($file);
    if ($body === false) {
        return $errorResponse(500, 'internal_server_error', 'Chat UI asset could not be read.', [
            'field' => 'public/chat.html',
            'reason' => 'read_failed',
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
