<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/routing/inference_routing.php';

/**
 * GET /api/route — diagnostic endpoint exposing the current routing
 * resolution for a given model selector. Accepts optional query params:
 *   ?model_name=...&quantization=...&min_free_vram_bytes=...
 *
 * Returns the ordered candidate list with primary + failover breakdown
 * so operators can inspect which nodes would receive traffic.
 */
function model_inference_handle_routing_routes(
    string $path,
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse
): ?array {
    if ($path !== '/api/route') {
        return null;
    }
    if ($method !== 'GET') {
        return $errorResponse(405, 'method_not_allowed', 'GET required.', [
            'path' => $path,
            'method' => $method,
            'allowed' => ['GET'],
        ]);
    }

    $query = model_inference_routing_parse_query($request);

    $criteria = [];
    if (isset($query['model_name']) && $query['model_name'] !== '') {
        $criteria['model_name'] = (string) $query['model_name'];
    }
    if (isset($query['quantization']) && $query['quantization'] !== '') {
        $criteria['quantization'] = (string) $query['quantization'];
    }
    if (isset($query['min_free_vram_bytes'])) {
        $criteria['min_free_vram_bytes'] = (int) $query['min_free_vram_bytes'];
    }

    $resolution = model_inference_route_resolve($criteria);

    return $jsonResponse(200, [
        'status' => 'ok',
        'routing' => $resolution,
        'criteria' => $criteria !== [] ? $criteria : null,
        'time' => gmdate('c'),
    ]);
}

/**
 * @return array<string, string>
 */
function model_inference_routing_parse_query(array $request): array
{
    $uri = (string) ($request['uri'] ?? $request['path'] ?? '');
    $qPos = strpos($uri, '?');
    if ($qPos === false) {
        return [];
    }
    $queryString = substr($uri, $qPos + 1);
    $params = [];
    parse_str($queryString, $params);
    return $params;
}
