<?php

declare(strict_types=1);

function model_inference_handle_runtime_routes(
    string $path,
    string $method,
    callable $jsonResponse,
    callable $runtimeEnvelope,
    string $wsPath
): ?array {
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
            'service' => 'model-inference-backend-king-php',
            'status' => 'bootstrapped',
            'message' => 'King HTTP/WS scaffold is active. Inference endpoints land across leaves #M-4..#M-17.',
            'ws_path' => $wsPath,
            'runtime_endpoint' => '/api/runtime',
            'version_endpoint' => '/api/version',
            'time' => gmdate('c'),
        ]);
    }

    return null;
}
