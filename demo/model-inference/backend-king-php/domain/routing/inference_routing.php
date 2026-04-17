<?php

declare(strict_types=1);

/**
 * Inference-aware routing helper wrapping Semantic-DNS discovery +
 * optimal-route selection with model-specific criteria.
 *
 * Reuses the ordered-candidates-with-failover discipline from
 * McpServiceResolution (demo/userland/flow-php/src/McpServiceDiscovery.php)
 * but without importing the class — the shape is reproduced as plain
 * arrays so the demo stays self-contained.
 *
 * Criteria shape accepted by the routing kernel:
 *   { model_name: string, quantization: string, min_free_vram_bytes: int }
 *
 * The kernel matches criteria against service attributes registered
 * by M-13's semantic_dns_register call.
 */

/**
 * Resolve an ordered list of inference-capable nodes for a given
 * model selector. Returns the McpServiceResolution-shaped result:
 *   { role, primary, failover[], candidates[], resolved_at }
 *
 * @param array{model_name: string, quantization: string, min_free_vram_bytes?: int} $criteria
 * @return array{role: string, primary: array|null, failover: list<array>, candidates: list<array>, resolved_at: string, source: string}
 */
function model_inference_route_resolve(array $criteria): array
{
    $result = [
        'role' => 'inference',
        'primary' => null,
        'failover' => [],
        'candidates' => [],
        'resolved_at' => gmdate('c'),
        'source' => 'semantic_dns',
    ];

    if (!function_exists('king_semantic_dns_discover_service')) {
        $result['source'] = 'unavailable';
        return $result;
    }

    $dnsCriteria = [];
    if (isset($criteria['model_name']) && $criteria['model_name'] !== '') {
        $dnsCriteria['model_name'] = $criteria['model_name'];
    }
    if (isset($criteria['quantization']) && $criteria['quantization'] !== '') {
        $dnsCriteria['quantization'] = $criteria['quantization'];
    }
    if (isset($criteria['min_free_vram_bytes']) && (int) $criteria['min_free_vram_bytes'] > 0) {
        $dnsCriteria['min_free_vram_bytes'] = (int) $criteria['min_free_vram_bytes'];
    }

    $discovery = king_semantic_dns_discover_service(
        'king.inference.v1',
        $dnsCriteria !== [] ? $dnsCriteria : null
    );

    $services = is_array($discovery['services'] ?? null) ? $discovery['services'] : [];
    if ($services === []) {
        return $result;
    }

    $candidates = model_inference_route_rank_candidates($services);
    $result['candidates'] = $candidates;

    if ($candidates !== []) {
        $result['primary'] = $candidates[0];
        $result['failover'] = array_slice($candidates, 1);
    }

    if (function_exists('king_semantic_dns_get_optimal_route')) {
        $optimalRoute = king_semantic_dns_get_optimal_route(
            'king-model-inference',
            $dnsCriteria !== [] ? $dnsCriteria : null
        );
        if (is_array($optimalRoute) && !isset($optimalRoute['error']) && isset($optimalRoute['service_id'])) {
            $preferredId = (string) $optimalRoute['service_id'];
            $candidates = model_inference_route_prefer_candidate($candidates, $preferredId);
            $result['candidates'] = $candidates;
            $result['primary'] = $candidates[0] ?? null;
            $result['failover'] = array_slice($candidates, 1);
        }
    }

    return $result;
}

/**
 * Attempt failover: given the current resolution and a failed service_id,
 * return the next candidate or null when exhausted.
 *
 * @param array{candidates: list<array>} $resolution
 * @return array|null the next candidate node, or null
 */
function model_inference_route_failover(array $resolution, string $failedServiceId): ?array
{
    $candidates = (array) ($resolution['candidates'] ?? []);
    $found = false;
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $id = (string) ($candidate['service_id'] ?? '');
        if ($id === $failedServiceId) {
            $found = true;
            continue;
        }
        if ($found) {
            return $candidate;
        }
    }
    return null;
}

/**
 * Rank discovered services: healthy first, then by load, then by
 * service_id for determinism. Same discipline as McpServiceDiscovery.
 *
 * @param list<array> $services
 * @return list<array>
 */
function model_inference_route_rank_candidates(array $services): array
{
    $nodes = [];
    foreach ($services as $service) {
        if (!is_array($service)) {
            continue;
        }
        $nodes[] = model_inference_route_normalize_node($service);
    }

    usort($nodes, static function (array $a, array $b): int {
        $statusOrder = static function (string $status): int {
            return match (strtolower($status)) {
                'healthy' => 0,
                'degraded' => 1,
                'draining' => 2,
                default => 3,
            };
        };

        $cmp = $statusOrder($a['status']) <=> $statusOrder($b['status']);
        if ($cmp !== 0) {
            return $cmp;
        }

        $cmp = ($a['current_load_percent'] ?? 0) <=> ($b['current_load_percent'] ?? 0);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp($a['service_id'], $b['service_id']);
    });

    return $nodes;
}

/**
 * Normalize a raw DNS service record into the routing node shape.
 *
 * @return array<string, mixed>
 */
function model_inference_route_normalize_node(array $service): array
{
    $attrs = is_array($service['attributes'] ?? null) ? $service['attributes'] : [];
    return [
        'service_id' => (string) ($service['service_id'] ?? ''),
        'service_name' => (string) ($service['service_name'] ?? ''),
        'service_type' => (string) ($service['service_type'] ?? ''),
        'hostname' => (string) ($service['hostname'] ?? ''),
        'port' => (int) ($service['port'] ?? 0),
        'status' => (string) ($service['status'] ?? 'unknown'),
        'current_load_percent' => (int) ($attrs['current_load_percent'] ?? $service['current_load_percent'] ?? 0),
        'active_connections' => (int) ($attrs['active_connections'] ?? $service['active_connections'] ?? 0),
        'gpu_kind' => (string) ($attrs['gpu_kind'] ?? 'none'),
        'vram_total_bytes' => (int) ($attrs['vram_total_bytes'] ?? 0),
        'vram_free_bytes' => (int) ($attrs['vram_free_bytes'] ?? 0),
        'node_id' => (string) ($attrs['node_id'] ?? $service['service_id'] ?? ''),
    ];
}

/**
 * Promote a preferred candidate (from king_semantic_dns_get_optimal_route)
 * to the front of the ordered list.
 *
 * @param list<array> $candidates
 * @return list<array>
 */
function model_inference_route_prefer_candidate(array $candidates, string $preferredServiceId): array
{
    $preferred = null;
    $rest = [];
    foreach ($candidates as $c) {
        if (($c['service_id'] ?? '') === $preferredServiceId && $preferred === null) {
            $preferred = $c;
        } else {
            $rest[] = $c;
        }
    }
    if ($preferred !== null) {
        return [$preferred, ...$rest];
    }
    return $candidates;
}
