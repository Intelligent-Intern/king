<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/telemetry/inference_metrics.php';
require_once __DIR__ . '/../domain/telemetry/rag_metrics.php';
require_once __DIR__ . '/../domain/telemetry/discovery_metrics.php';

function model_inference_handle_telemetry_routes(
    string $path,
    string $method,
    callable $jsonResponse,
    callable $errorResponse,
    callable $getInferenceMetrics,
    ?callable $getRagMetrics = null,
    ?callable $getDiscoveryMetrics = null
): ?array {
    if ($path === '/api/telemetry/inference/recent') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'GET required.', [
                'path' => $path, 'method' => $method, 'allowed' => ['GET'],
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

    if ($path === '/api/telemetry/rag/recent') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'GET required.', [
                'path' => $path, 'method' => $method, 'allowed' => ['GET'],
            ]);
        }
        if ($getRagMetrics === null) {
            return $jsonResponse(200, [
                'status' => 'ok',
                'items' => [],
                'count' => 0,
                'capacity' => 0,
                'time' => gmdate('c'),
            ]);
        }
        /** @var RagMetricsRing $ring */
        $ring = $getRagMetrics();
        return $jsonResponse(200, [
            'status' => 'ok',
            'items' => $ring->recent(100),
            'count' => $ring->count(),
            'capacity' => $ring->capacity(),
            'time' => gmdate('c'),
        ]);
    }

    if ($path === '/api/telemetry/discovery/recent') {
        if ($method !== 'GET') {
            return $errorResponse(405, 'method_not_allowed', 'GET required.', [
                'path' => $path, 'method' => $method, 'allowed' => ['GET'],
            ]);
        }
        if ($getDiscoveryMetrics === null) {
            return $jsonResponse(200, [
                'status' => 'ok',
                'items' => [],
                'count' => 0,
                'capacity' => 0,
                'time' => gmdate('c'),
            ]);
        }
        /** @var DiscoveryMetricsRing $ring */
        $ring = $getDiscoveryMetrics();
        return $jsonResponse(200, [
            'status' => 'ok',
            'items' => $ring->recent(100),
            'count' => $ring->count(),
            'capacity' => $ring->capacity(),
            'time' => gmdate('c'),
        ]);
    }

    return null;
}
