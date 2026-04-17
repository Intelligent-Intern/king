<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/profile/hardware_profile.php';

function model_inference_handle_profile_routes(
    string $path,
    string $method,
    callable $jsonResponse,
    callable $errorResponse,
    callable $runtimeEnvelope,
    string $host,
    int $port
): ?array {
    if ($path !== '/api/node/profile') {
        return null;
    }
    if ($method !== 'GET') {
        return $errorResponse(405, 'method_not_allowed', 'GET required.', [
            'path' => $path,
            'method' => $method,
            'allowed' => ['GET'],
        ]);
    }

    $runtime = $runtimeEnvelope();
    $nodeId = (string) (($runtime['node'] ?? [])['node_id'] ?? 'node_unknown');
    $healthUrl = sprintf('http://%s:%d/health', $host === '0.0.0.0' ? '127.0.0.1' : $host, $port);

    $profile = model_inference_hardware_profile($nodeId, $healthUrl, 'ready');

    return $jsonResponse(200, $profile);
}
