<?php

declare(strict_types=1);

/**
 * Register this inference node on Semantic-DNS as service type
 * king.inference.v1. Called once after the backend reaches the
 * "ready" state (object store + database + extension loaded).
 *
 * @param array<string, mixed> $profile node-profile envelope (#M-4)
 */
function model_inference_semantic_dns_register(
    string $nodeId,
    string $host,
    int $port,
    array $profile
): bool {
    if (!function_exists('king_semantic_dns_register_service')) {
        return false;
    }

    $gpu = (array) ($profile['gpu'] ?? []);
    $capabilities = (array) ($profile['capabilities'] ?? []);

    return king_semantic_dns_register_service([
        'service_id' => $nodeId,
        'service_name' => 'king-model-inference',
        'service_type' => 'king.inference.v1',
        'hostname' => $host,
        'port' => $port,
        'status' => 'healthy',
        'current_load_percent' => 0,
        'active_connections' => 0,
        'total_requests' => 0,
        'attributes' => [
            'node_id' => $nodeId,
            'gpu_kind' => (string) ($gpu['kind'] ?? 'none'),
            'gpu_present' => (bool) ($gpu['present'] ?? false),
            'vram_total_bytes' => (int) ($gpu['vram_total_bytes'] ?? 0),
            'vram_free_bytes' => (int) ($gpu['vram_free_bytes'] ?? 0),
            'supports_streaming' => (bool) ($capabilities['supports_streaming'] ?? true),
            'supports_quantizations' => (array) ($capabilities['supports_quantizations'] ?? []),
            'supports_embedding' => (bool) ($capabilities['supports_embedding'] ?? false),
            'supports_retrieval' => (bool) ($capabilities['supports_retrieval'] ?? false),
            'supports_rag' => (bool) ($capabilities['supports_rag'] ?? false),
            'embedding_dimensions' => (int) ($capabilities['embedding_dimensions'] ?? 0),
        ],
    ]);
}

/**
 * Update this node's Semantic-DNS status (e.g. on drain or error).
 */
function model_inference_semantic_dns_update_status(
    string $nodeId,
    string $status,
    ?int $activeConnections = null,
    ?int $totalRequests = null,
    ?int $loadPercent = null
): bool {
    if (!function_exists('king_semantic_dns_update_service_status')) {
        return false;
    }

    $metrics = [];
    if ($loadPercent !== null) {
        $metrics['current_load_percent'] = $loadPercent;
    }
    if ($activeConnections !== null) {
        $metrics['active_connections'] = $activeConnections;
    }
    if ($totalRequests !== null) {
        $metrics['total_requests'] = $totalRequests;
    }

    return king_semantic_dns_update_service_status(
        $nodeId,
        $status,
        $metrics !== [] ? $metrics : null
    );
}

/**
 * Deregister on drain: mark the service as unhealthy so the routing
 * layer stops sending traffic before the process exits.
 */
function model_inference_semantic_dns_deregister(string $nodeId): bool
{
    return model_inference_semantic_dns_update_status($nodeId, 'unhealthy', 0);
}

/**
 * Bounded-retry heartbeat after registration. Calls
 * king_semantic_dns_discover_service to confirm this node appears in
 * the topology. Returns true when the node is visible, false after
 * exhausting retries. No sleep — uses usleep between probes.
 */
function model_inference_semantic_dns_heartbeat_after_ready(
    string $nodeId,
    int $maxAttempts = 5,
    int $retryDelayUs = 100_000
): bool {
    if (!function_exists('king_semantic_dns_discover_service')) {
        return false;
    }

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $result = king_semantic_dns_discover_service('king.inference.v1');
            if (!is_array($result)) {
                if ($attempt < $maxAttempts) {
                    usleep($retryDelayUs);
                }
                continue;
            }
            $services = (array) ($result['services'] ?? []);
            foreach ($services as $service) {
                if (!is_array($service)) {
                    continue;
                }
                if (($service['service_id'] ?? null) === $nodeId) {
                    return true;
                }
            }
        } catch (Throwable $ignored) {
        }

        if ($attempt < $maxAttempts) {
            usleep($retryDelayUs);
        }
    }
    return false;
}
