<?php

declare(strict_types=1);

require_once __DIR__ . '/../http/router.php';
require_once __DIR__ . '/../domain/telemetry/inference_metrics.php';

function model_inference_router_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[router-module-order-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    // Current deployed module list. Grows as later leaves land their modules.
    // Intended end-of-sprint order (see demo/model-inference/README.md):
    //   runtime, profile, registry, worker, inference, telemetry, routing, realtime
    $expectedOrder = ['runtime', 'profile', 'registry', 'embed', 'ingest', 'retrieve', 'inference', 'realtime', 'telemetry', 'routing', 'ui'];
    $actualOrder = model_inference_dispatch_route_module_order();

    model_inference_router_contract_assert(
        $actualOrder === $expectedOrder,
        'module order does not match expected deterministic sequence: actual=' . json_encode($actualOrder)
    );
    model_inference_router_contract_assert(
        count(array_unique($actualOrder)) === count($actualOrder),
        'module order contains duplicates'
    );

    // Every declared module must resolve at least one route through the
    // dispatcher. The runtime module owns /api/runtime, so a GET against that
    // path must not fall through to the not_implemented branch.
    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        $payload = [
            'status' => 'error',
            'error' => ['code' => $code, 'message' => $message],
            'time' => gmdate('c'),
        ];
        if ($details !== []) {
            $payload['error']['details'] = $details;
        }
        return $jsonResponse($status, $payload);
    };
    $methodFromRequest = static function (array $request): string {
        $method = strtoupper(trim((string) ($request['method'] ?? 'GET')));
        return $method === '' ? 'GET' : $method;
    };
    $pathFromRequest = static function (array $request): string {
        $path = $request['path'] ?? null;
        if (is_string($path) && $path !== '') {
            return $path;
        }
        return '/';
    };
    $runtimeEnvelope = static function (): array {
        return [
            'service' => 'model-inference-backend-king-php',
            'app' => ['name' => 'king-model-inference-backend', 'version' => 'contract', 'environment' => 'test'],
            'runtime' => ['king_version' => 'test', 'transport' => 'king_http1_server_listen_once', 'ws_path' => '/ws', 'health' => ['build' => 'b', 'module_version' => 'm']],
            'database' => ['status' => 'ready'],
            'node' => ['node_id' => 'node_contract', 'role' => 'inference-serving'],
            'time' => gmdate('c'),
        ];
    };

    $openDatabase = static function (): PDO {
        throw new RuntimeException('openDatabase should not be reached for router module-order assertions.');
    };
    $getInferenceSession = static function () {
        throw new RuntimeException('inference session should not be reached for router module-order assertions.');
    };
    $getInferenceMetrics = static function () {
        throw new RuntimeException('inference metrics should not be reached for router module-order assertions.');
    };

    $runtimeResponse = model_inference_dispatch_request(
        ['method' => 'GET', 'path' => '/api/runtime', 'uri' => '/api/runtime', 'headers' => []],
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
    model_inference_router_contract_assert(
        (int) ($runtimeResponse['status'] ?? 0) === 200,
        'runtime module should serve /api/runtime via the dispatcher'
    );

    // UI module owns GET /ui.
    $uiResponse = model_inference_dispatch_request(
        ['method' => 'GET', 'path' => '/ui', 'uri' => '/ui', 'headers' => []],
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
    model_inference_router_contract_assert(
        (int) ($uiResponse['status'] ?? 0) === 200,
        'ui module should serve /ui with 200 (got ' . $uiResponse['status'] . ')'
    );
    model_inference_router_contract_assert(
        str_contains((string) ($uiResponse['headers']['content-type'] ?? ''), 'text/html'),
        'ui module must return text/html content-type'
    );

    // Telemetry module owns /api/telemetry/inference/recent.
    $telemetryRing = new InferenceMetricsRing();
    $telemetryGetMetrics = static function () use ($telemetryRing) {
        return $telemetryRing;
    };
    $telemetryResponse = model_inference_dispatch_request(
        ['method' => 'GET', 'path' => '/api/telemetry/inference/recent', 'uri' => '/api/telemetry/inference/recent', 'headers' => []],
        $jsonResponse,
        $errorResponse,
        $methodFromRequest,
        $pathFromRequest,
        $runtimeEnvelope,
        $openDatabase,
        $getInferenceSession,
        $telemetryGetMetrics,
        '/ws',
        '127.0.0.1',
        18090
    );
    model_inference_router_contract_assert(
        (int) ($telemetryResponse['status'] ?? 0) === 200,
        'telemetry module should serve /api/telemetry/inference/recent via the dispatcher'
    );

    // Profile module owns /api/node/profile.
    $profileResponse = model_inference_dispatch_request(
        ['method' => 'GET', 'path' => '/api/node/profile', 'uri' => '/api/node/profile', 'headers' => []],
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
    model_inference_router_contract_assert(
        (int) ($profileResponse['status'] ?? 0) === 200,
        'profile module should serve /api/node/profile via the dispatcher'
    );

    // Paths owned by not-yet-shipped modules must return 404 not_implemented.
    // This proves the dispatcher does not pretend to serve routes whose
    // module has not landed yet.
    // Routing module owns /api/route.
    $routingResponse = model_inference_dispatch_request(
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
    model_inference_router_contract_assert(
        (int) ($routingResponse['status'] ?? 0) === 200,
        'routing module should serve /api/route via the dispatcher'
    );

    $targetShapePaths = [
        '/api/worker',            // M-7 (still target-shape in this demo)
    ];
    foreach ($targetShapePaths as $targetPath) {
        $response = model_inference_dispatch_request(
            ['method' => 'GET', 'path' => $targetPath, 'uri' => $targetPath, 'headers' => []],
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
        model_inference_router_contract_assert(
            (int) ($response['status'] ?? 0) === 404,
            "target-shape path {$targetPath} must return 404 until its module lands"
        );
        $payload = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        model_inference_router_contract_assert(
            (($payload['error'] ?? [])['code'] ?? null) === 'not_implemented',
            "target-shape path {$targetPath} must return error code not_implemented"
        );
        model_inference_router_contract_assert(
            is_array((($payload['error'] ?? [])['details'] ?? [])['deployed_modules'] ?? null),
            "target-shape path {$targetPath} must list deployed_modules in error details"
        );
    }

    fwrite(STDOUT, "[router-module-order-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[router-module-order-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
