<?php

declare(strict_types=1);

require_once __DIR__ . '/../http/router.php';
require_once __DIR__ . '/../domain/telemetry/inference_metrics.php';

function retrieval_pipeline_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[retrieval-pipeline-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    // 1. Functions exist.
    retrieval_pipeline_contract_assert(
        function_exists('model_inference_retrieval_search'),
        'model_inference_retrieval_search must exist'
    );
    retrieval_pipeline_contract_assert(
        function_exists('model_inference_validate_retrieve_request'),
        'model_inference_validate_retrieve_request must exist'
    );

    // 2. Validation: valid request.
    $valid = model_inference_validate_retrieve_request([
        'query' => 'what is machine learning?',
        'model_selector' => ['model_name' => 'nomic-embed-text-v1.5', 'quantization' => 'Q8_0'],
    ]);
    retrieval_pipeline_contract_assert($valid !== null, 'valid request must pass');
    retrieval_pipeline_contract_assert($valid['query'] === 'what is machine learning?', 'query must be preserved');
    retrieval_pipeline_contract_assert($valid['top_k'] === 5, 'top_k must default to 5');
    retrieval_pipeline_contract_assert($valid['min_score'] === 0.0, 'min_score must default to 0.0');
    retrieval_pipeline_contract_assert($valid['document_ids'] === null, 'document_ids must default to null');

    // 3. Validation: with all options.
    $full = model_inference_validate_retrieve_request([
        'query' => 'test',
        'model_selector' => ['model_name' => 'test', 'quantization' => 'F16'],
        'document_ids' => ['doc-0000000000000001'],
        'top_k' => 10,
        'min_score' => 0.5,
    ]);
    retrieval_pipeline_contract_assert($full !== null, 'full request must pass');
    retrieval_pipeline_contract_assert($full['top_k'] === 10, 'top_k=10 must be accepted');
    retrieval_pipeline_contract_assert(abs($full['min_score'] - 0.5) < 1e-6, 'min_score=0.5 must be accepted');
    retrieval_pipeline_contract_assert(count($full['document_ids']) === 1, 'document_ids must be preserved');

    // 4. Validation: rejections.
    $rejections = [
        'missing query' => ['model_selector' => ['model_name' => 't', 'quantization' => 'Q8_0']],
        'empty query' => ['query' => '', 'model_selector' => ['model_name' => 't', 'quantization' => 'Q8_0']],
        'missing model_selector' => ['query' => 'test'],
        'invalid quantization' => ['query' => 'test', 'model_selector' => ['model_name' => 't', 'quantization' => 'BOGUS']],
        'top_k too low' => ['query' => 'test', 'model_selector' => ['model_name' => 't', 'quantization' => 'Q8_0'], 'top_k' => 0],
        'top_k too high' => ['query' => 'test', 'model_selector' => ['model_name' => 't', 'quantization' => 'Q8_0'], 'top_k' => 101],
        'min_score negative' => ['query' => 'test', 'model_selector' => ['model_name' => 't', 'quantization' => 'Q8_0'], 'min_score' => -0.1],
        'min_score too high' => ['query' => 'test', 'model_selector' => ['model_name' => 't', 'quantization' => 'Q8_0'], 'min_score' => 1.1],
    ];
    foreach ($rejections as $label => $payload) {
        $result = model_inference_validate_retrieve_request($payload);
        retrieval_pipeline_contract_assert($result === null, "must reject: {$label}");
    }

    // 5. Module order includes retrieve.
    $order = model_inference_dispatch_route_module_order();
    retrieval_pipeline_contract_assert(in_array('retrieve', $order, true), 'module order must include retrieve');
    $retrieveIdx = array_search('retrieve', $order, true);
    $ingestIdx = array_search('ingest', $order, true);
    $inferenceIdx = array_search('inference', $order, true);
    retrieval_pipeline_contract_assert(
        $retrieveIdx > $ingestIdx && $retrieveIdx < $inferenceIdx,
        'retrieve must be ordered between ingest and inference'
    );

    // 6. POST /api/retrieve dispatches through the module.
    $jsonResponse = static function (int $status, array $payload): array {
        return ['status' => $status, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, ['status' => 'error', 'error' => ['code' => $code, 'message' => $message, 'details' => $details], 'time' => gmdate('c')]);
    };
    $methodFromRequest = static function (array $r): string { return strtoupper(trim((string) ($r['method'] ?? 'GET'))); };
    $pathFromRequest = static function (array $r): string { return (string) ($r['path'] ?? '/'); };
    $runtimeEnvelope = static function (): array {
        return ['service' => 'test', 'app' => ['name' => 'test', 'version' => 'test', 'environment' => 'test'], 'runtime' => ['king_version' => 'test', 'transport' => 'king_http1_server_listen_once', 'ws_path' => '/ws', 'health' => ['build' => 'b', 'module_version' => 'm']], 'database' => ['status' => 'ready'], 'node' => ['node_id' => 'test', 'role' => 'inference-serving'], 'time' => gmdate('c')];
    };
    $openDatabase = static function (): PDO { throw new RuntimeException('openDatabase reached — retrieve module is wired.'); };
    $getInferenceSession = static function () { throw new RuntimeException('not reached'); };
    $getInferenceMetrics = static function () { return new InferenceMetricsRing(); };
    $getEmbeddingSession = static function () { throw new RuntimeException('embedding session reached — retrieve module is wired.'); };

    // POST with valid body trips openDatabase or embeddingSession (proves wiring).
    $tripped = false;
    try {
        model_inference_dispatch_request(
            ['method' => 'POST', 'path' => '/api/retrieve', 'uri' => '/api/retrieve', 'headers' => [],
             'body' => '{"query":"test","model_selector":{"model_name":"test","quantization":"Q8_0"}}'],
            $jsonResponse, $errorResponse, $methodFromRequest, $pathFromRequest,
            $runtimeEnvelope, $openDatabase, $getInferenceSession, $getInferenceMetrics,
            '/ws', '127.0.0.1', 18090, $getEmbeddingSession
        );
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'openDatabase reached') || str_contains($e->getMessage(), 'embedding session reached')) {
            $tripped = true;
        }
    }
    retrieval_pipeline_contract_assert($tripped, 'POST /api/retrieve must dispatch through retrieve module');

    // GET /api/retrieve returns 405.
    $getResp = model_inference_dispatch_request(
        ['method' => 'GET', 'path' => '/api/retrieve', 'uri' => '/api/retrieve', 'headers' => []],
        $jsonResponse, $errorResponse, $methodFromRequest, $pathFromRequest,
        $runtimeEnvelope, $openDatabase, $getInferenceSession, $getInferenceMetrics,
        '/ws', '127.0.0.1', 18090, $getEmbeddingSession
    );
    retrieval_pipeline_contract_assert((int) ($getResp['status'] ?? 0) === 405, 'GET /api/retrieve must return 405');

    // Empty body returns 400.
    $emptyResp = model_inference_dispatch_request(
        ['method' => 'POST', 'path' => '/api/retrieve', 'uri' => '/api/retrieve', 'headers' => [], 'body' => ''],
        $jsonResponse, $errorResponse, $methodFromRequest, $pathFromRequest,
        $runtimeEnvelope, $openDatabase, $getInferenceSession, $getInferenceMetrics,
        '/ws', '127.0.0.1', 18090, $getEmbeddingSession
    );
    retrieval_pipeline_contract_assert((int) ($emptyResp['status'] ?? 0) === 400, 'empty body must return 400');

    fwrite(STDOUT, "[retrieval-pipeline-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[retrieval-pipeline-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
