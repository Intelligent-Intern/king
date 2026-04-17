<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/routing/inference_routing.php';
require_once __DIR__ . '/../http/router.php';
require_once __DIR__ . '/../domain/telemetry/inference_metrics.php';

function routing_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[inference-routing-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    // 1. Function signatures exist.
    routing_contract_assert(
        function_exists('model_inference_route_resolve'),
        'model_inference_route_resolve must exist'
    );
    routing_contract_assert(
        function_exists('model_inference_route_failover'),
        'model_inference_route_failover must exist'
    );
    routing_contract_assert(
        function_exists('model_inference_route_rank_candidates'),
        'model_inference_route_rank_candidates must exist'
    );
    routing_contract_assert(
        function_exists('model_inference_route_normalize_node'),
        'model_inference_route_normalize_node must exist'
    );
    routing_contract_assert(
        function_exists('model_inference_route_prefer_candidate'),
        'model_inference_route_prefer_candidate must exist'
    );

    // 2. Graceful fallback without extension.
    $result = model_inference_route_resolve([
        'model_name' => 'TestModel',
        'quantization' => 'Q4_K',
    ]);
    routing_contract_assert($result['role'] === 'inference', 'role must be inference');
    if (!function_exists('king_semantic_dns_discover_service')) {
        routing_contract_assert(
            $result['source'] === 'unavailable',
            'source must be unavailable without extension'
        );
        routing_contract_assert(
            $result['primary'] === null,
            'primary must be null without extension'
        );
        routing_contract_assert(
            $result['failover'] === [],
            'failover must be empty without extension'
        );
    }
    routing_contract_assert(
        isset($result['resolved_at']),
        'resolved_at must be present'
    );

    // 3. Ranking logic: healthy < degraded < draining < unknown; then by load.
    $services = [
        [
            'service_id' => 'node_c', 'service_name' => 'king-model-inference',
            'service_type' => 'king.inference.v1', 'hostname' => '10.0.0.3',
            'port' => 18090, 'status' => 'degraded',
            'attributes' => ['current_load_percent' => 20],
        ],
        [
            'service_id' => 'node_a', 'service_name' => 'king-model-inference',
            'service_type' => 'king.inference.v1', 'hostname' => '10.0.0.1',
            'port' => 18090, 'status' => 'healthy',
            'attributes' => ['current_load_percent' => 50],
        ],
        [
            'service_id' => 'node_b', 'service_name' => 'king-model-inference',
            'service_type' => 'king.inference.v1', 'hostname' => '10.0.0.2',
            'port' => 18090, 'status' => 'healthy',
            'attributes' => ['current_load_percent' => 10],
        ],
        [
            'service_id' => 'node_d', 'service_name' => 'king-model-inference',
            'service_type' => 'king.inference.v1', 'hostname' => '10.0.0.4',
            'port' => 18090, 'status' => 'draining',
            'attributes' => ['current_load_percent' => 0],
        ],
    ];
    $ranked = model_inference_route_rank_candidates($services);
    routing_contract_assert(count($ranked) === 4, 'all 4 services must be ranked');
    routing_contract_assert(
        $ranked[0]['service_id'] === 'node_b',
        'node_b (healthy, 10% load) must rank first, got ' . $ranked[0]['service_id']
    );
    routing_contract_assert(
        $ranked[1]['service_id'] === 'node_a',
        'node_a (healthy, 50% load) must rank second, got ' . $ranked[1]['service_id']
    );
    routing_contract_assert(
        $ranked[2]['service_id'] === 'node_c',
        'node_c (degraded) must rank third, got ' . $ranked[2]['service_id']
    );
    routing_contract_assert(
        $ranked[3]['service_id'] === 'node_d',
        'node_d (draining) must rank last, got ' . $ranked[3]['service_id']
    );

    // 4. Prefer-candidate promotes a specific node to front.
    $reordered = model_inference_route_prefer_candidate($ranked, 'node_a');
    routing_contract_assert(
        $reordered[0]['service_id'] === 'node_a',
        'preferred candidate must be promoted to front'
    );
    routing_contract_assert(
        count($reordered) === 4,
        'prefer_candidate must not drop any candidates'
    );

    // 5. Failover returns next candidate after the failed one.
    $resolution = ['candidates' => $ranked];
    $next = model_inference_route_failover($resolution, 'node_b');
    routing_contract_assert(
        $next !== null && $next['service_id'] === 'node_a',
        'failover from node_b must yield node_a'
    );
    $nextAfterA = model_inference_route_failover(
        ['candidates' => array_slice($ranked, 1)],
        'node_a'
    );
    routing_contract_assert(
        $nextAfterA !== null && $nextAfterA['service_id'] === 'node_c',
        'failover from node_a must yield node_c'
    );
    $exhausted = model_inference_route_failover($resolution, 'node_d');
    routing_contract_assert(
        $exhausted === null,
        'failover from last candidate must return null'
    );

    // 6. Node normalization extracts attributes.
    $node = model_inference_route_normalize_node([
        'service_id' => 'node_test',
        'hostname' => '10.0.0.5',
        'port' => 18090,
        'status' => 'healthy',
        'attributes' => [
            'gpu_kind' => 'metal',
            'vram_total_bytes' => 8589934592,
            'vram_free_bytes' => 4294967296,
            'node_id' => 'node_test',
            'current_load_percent' => 25,
        ],
    ]);
    routing_contract_assert($node['gpu_kind'] === 'metal', 'gpu_kind must be extracted from attributes');
    routing_contract_assert($node['vram_total_bytes'] === 8589934592, 'vram_total_bytes must be extracted');
    routing_contract_assert($node['current_load_percent'] === 25, 'current_load_percent must be extracted');

    // 7. GET /api/route resolves through the dispatcher with 200.
    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, [
            'status' => 'error',
            'error' => ['code' => $code, 'message' => $message, 'details' => $details],
            'time' => gmdate('c'),
        ]);
    };
    $methodFromRequest = static function (array $r): string {
        return strtoupper(trim((string) ($r['method'] ?? 'GET')));
    };
    $pathFromRequest = static function (array $r): string {
        return (string) ($r['path'] ?? '/');
    };
    $runtimeEnvelope = static function (): array {
        return [
            'service' => 'model-inference-backend-king-php',
            'app' => ['name' => 'test', 'version' => 'test', 'environment' => 'test'],
            'runtime' => ['king_version' => 'test', 'transport' => 'test', 'ws_path' => '/ws', 'health' => []],
            'database' => ['status' => 'ready'],
            'node' => ['node_id' => 'node_test', 'role' => 'inference-serving'],
            'time' => gmdate('c'),
        ];
    };
    $openDatabase = static function (): PDO {
        throw new RuntimeException('not reached');
    };
    $getInferenceSession = static function () {
        throw new RuntimeException('not reached');
    };
    $getInferenceMetrics = static function () {
        return new InferenceMetricsRing();
    };

    $response = model_inference_dispatch_request(
        ['method' => 'GET', 'path' => '/api/route', 'uri' => '/api/route', 'headers' => []],
        $jsonResponse,
        $errorResponse,
        $methodFromRequest,
        $pathFromRequest,
        $runtimeEnvelope,
        $openDatabase,
        $getInferenceSession,
        $getInferenceMetrics,
        '/ws',
        '127.0.0.1',
        18090
    );
    routing_contract_assert(
        (int) ($response['status'] ?? 0) === 200,
        'GET /api/route must return 200'
    );
    $body = json_decode((string) ($response['body'] ?? '{}'), true);
    routing_contract_assert(
        is_array($body) && ($body['status'] ?? '') === 'ok',
        'GET /api/route body.status must be ok'
    );
    routing_contract_assert(
        is_array($body['routing'] ?? null) && ($body['routing']['role'] ?? '') === 'inference',
        'GET /api/route body.routing.role must be inference'
    );

    fwrite(STDOUT, "[inference-routing-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[inference-routing-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
