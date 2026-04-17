<?php

declare(strict_types=1);

require_once __DIR__ . '/../http/router.php';
require_once __DIR__ . '/../domain/telemetry/inference_metrics.php';

function embedding_generation_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[embedding-generation-contract] FAIL: {$message}\n");
    exit(1);
}

try {
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
    $methodFromRequest = static function (array $request): string {
        return strtoupper(trim((string) ($request['method'] ?? 'GET')));
    };
    $pathFromRequest = static function (array $request): string {
        return (string) ($request['path'] ?? '/');
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
        throw new RuntimeException('openDatabase should not be reached for embed routing assertions.');
    };
    $getInferenceSession = static function () {
        throw new RuntimeException('inference session should not be reached for embed routing assertions.');
    };
    $getInferenceMetrics = static function () {
        return new InferenceMetricsRing();
    };
    $getEmbeddingSession = static function () {
        throw new RuntimeException('embedding session reached — module is wired.');
    };

    // 1. POST /api/embed dispatches to the embed module (not 404).
    $trippedEmbedding = false;
    try {
        model_inference_dispatch_request(
            ['method' => 'POST', 'path' => '/api/embed', 'uri' => '/api/embed', 'headers' => [], 'body' => '{"texts":["hello"],"model_selector":{"model_name":"test","quantization":"Q8_0"}}'],
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
            18090,
            $getEmbeddingSession
        );
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'embedding session reached')) {
            $trippedEmbedding = true;
        } elseif (str_contains($e->getMessage(), 'openDatabase should not be reached')) {
            $trippedEmbedding = true;
        } else {
            throw $e;
        }
    }
    embedding_generation_contract_assert(
        $trippedEmbedding,
        'POST /api/embed must dispatch through the embed module (not fall through to 404)'
    );

    // 2. GET /api/embed returns 405.
    $getResponse = model_inference_dispatch_request(
        ['method' => 'GET', 'path' => '/api/embed', 'uri' => '/api/embed', 'headers' => []],
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
        18090,
        $getEmbeddingSession
    );
    embedding_generation_contract_assert(
        (int) ($getResponse['status'] ?? 0) === 405,
        'GET /api/embed must return 405'
    );

    // 3. POST /api/embed with empty body returns 400.
    $emptyResponse = model_inference_dispatch_request(
        ['method' => 'POST', 'path' => '/api/embed', 'uri' => '/api/embed', 'headers' => [], 'body' => ''],
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
        18090,
        $getEmbeddingSession
    );
    embedding_generation_contract_assert(
        (int) ($emptyResponse['status'] ?? 0) === 400,
        'POST /api/embed with empty body must return 400'
    );
    $emptyPayload = json_decode((string) ($emptyResponse['body'] ?? ''), true);
    embedding_generation_contract_assert(
        is_array($emptyPayload) && ($emptyPayload['error']['code'] ?? '') === 'invalid_request_envelope',
        'POST /api/embed with empty body must return invalid_request_envelope error code'
    );

    // 4. POST /api/embed with invalid JSON returns 400.
    $invalidResponse = model_inference_dispatch_request(
        ['method' => 'POST', 'path' => '/api/embed', 'uri' => '/api/embed', 'headers' => [], 'body' => '{invalid'],
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
        18090,
        $getEmbeddingSession
    );
    embedding_generation_contract_assert(
        (int) ($invalidResponse['status'] ?? 0) === 400,
        'POST /api/embed with invalid JSON must return 400'
    );

    // 5. Module order includes embed.
    $order = model_inference_dispatch_route_module_order();
    embedding_generation_contract_assert(
        in_array('embed', $order, true),
        'module order must include embed'
    );
    $embedIdx = array_search('embed', $order, true);
    $registryIdx = array_search('registry', $order, true);
    $inferenceIdx = array_search('inference', $order, true);
    embedding_generation_contract_assert(
        $embedIdx > $registryIdx && $embedIdx < $inferenceIdx,
        'embed must be ordered between registry and inference'
    );

    // 6. Without getEmbeddingSession, /api/embed falls through to 404.
    $fallthrough = model_inference_dispatch_request(
        ['method' => 'POST', 'path' => '/api/embed', 'uri' => '/api/embed', 'headers' => [], 'body' => '{"texts":["hello"],"model_selector":{"model_name":"test","quantization":"Q8_0"}}'],
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
    embedding_generation_contract_assert(
        (int) ($fallthrough['status'] ?? 0) === 404,
        'POST /api/embed without getEmbeddingSession must fall through to 404'
    );

    fwrite(STDOUT, "[embedding-generation-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[embedding-generation-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
