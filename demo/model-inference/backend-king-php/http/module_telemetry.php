<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/telemetry/inference_metrics.php';

function model_inference_handle_telemetry_routes(
    string $path,
    string $method,
    callable $jsonResponse,
    callable $errorResponse,
    callable $getInferenceMetrics
): ?array {
    if ($path !== '/api/telemetry/inference/recent') {
        return null;
    }
    if ($method !== 'GET') {
        return $errorResponse(405, 'method_not_allowed', 'GET required.', [
            'path' => $path,
            'method' => $method,
            'allowed' => ['GET'],
        ]);
    }

    /** @var InferenceMetricsRing $ring */
    $ring = $getInferenceMetrics();

    return $jsonResponse(200, [
        'status' => 'ok',
        'items' => $ring->recent(100),
        'count' => $ring->count(),
        'capacity' => $ring->capacity(),
        'time' => gmdate('c'),
    ]);
}
