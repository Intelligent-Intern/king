<?php

declare(strict_types=1);

require_once __DIR__ . '/../http/router.php';
require_once __DIR__ . '/../domain/telemetry/inference_metrics.php';
require_once __DIR__ . '/../domain/telemetry/rag_metrics.php';

function rag_orchestrator_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[rag-orchestrator-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    // 1. Functions exist.
    rag_orchestrator_contract_assert(function_exists('model_inference_rag_execute'), 'model_inference_rag_execute must exist');
    rag_orchestrator_contract_assert(function_exists('model_inference_rag_build_prompt'), 'model_inference_rag_build_prompt must exist');
    rag_orchestrator_contract_assert(function_exists('model_inference_validate_rag_request'), 'model_inference_validate_rag_request must exist');

    // 2. Prompt building: with context.
    $prompt = model_inference_rag_build_prompt('What is PHP?', ['PHP is a language.', 'PHP runs on servers.']);
    rag_orchestrator_contract_assert(str_contains($prompt, 'What is PHP?'), 'prompt must contain the query');
    rag_orchestrator_contract_assert(str_contains($prompt, 'PHP is a language.'), 'prompt must contain context[0]');
    rag_orchestrator_contract_assert(str_contains($prompt, 'PHP runs on servers.'), 'prompt must contain context[1]');
    rag_orchestrator_contract_assert(str_contains($prompt, 'Context:'), 'prompt must contain Context: label');

    // 3. Prompt building: no context.
    $noContext = model_inference_rag_build_prompt('bare question', []);
    rag_orchestrator_contract_assert($noContext === 'bare question', 'no context → raw query returned');

    // 4. Prompt building: custom system.
    $custom = model_inference_rag_build_prompt('q', ['c'], 'Custom system prompt');
    rag_orchestrator_contract_assert(str_contains($custom, 'Custom system prompt'), 'custom system must appear in prompt');

    // 5. Validation: valid minimal request.
    $valid = model_inference_validate_rag_request([
        'query' => 'test query',
        'model_selector' => [
            'chat' => ['model_name' => 'SmolLM2', 'quantization' => 'Q4_K'],
            'embedding' => ['model_name' => 'nomic-embed', 'quantization' => 'Q8_0'],
        ],
    ]);
    rag_orchestrator_contract_assert($valid !== null, 'valid request must pass');
    rag_orchestrator_contract_assert($valid['query'] === 'test query', 'query preserved');
    rag_orchestrator_contract_assert($valid['top_k'] === 5, 'top_k defaults to 5');
    rag_orchestrator_contract_assert($valid['sampling']['temperature'] === 0.7, 'temperature defaults to 0.7');
    rag_orchestrator_contract_assert($valid['sampling']['max_tokens'] === 512, 'max_tokens defaults to 512');

    // 6. Validation: with all options.
    $full = model_inference_validate_rag_request([
        'query' => 'full test',
        'model_selector' => [
            'chat' => ['model_name' => 'a', 'quantization' => 'F16'],
            'embedding' => ['model_name' => 'b', 'quantization' => 'Q4_0'],
        ],
        'document_ids' => ['doc-0000000000000001'],
        'top_k' => 3,
        'min_score' => 0.2,
        'system' => 'Be concise.',
        'sampling' => ['temperature' => 0.3, 'max_tokens' => 256],
    ]);
    rag_orchestrator_contract_assert($full !== null, 'full request must pass');
    rag_orchestrator_contract_assert($full['top_k'] === 3, 'top_k=3 accepted');
    rag_orchestrator_contract_assert($full['system'] === 'Be concise.', 'system preserved');
    rag_orchestrator_contract_assert($full['sampling']['temperature'] === 0.3, 'temperature=0.3 accepted');

    // 7. Validation: rejections.
    $rejections = [
        'missing query' => ['model_selector' => ['chat' => ['model_name' => 'a', 'quantization' => 'Q4_K'], 'embedding' => ['model_name' => 'b', 'quantization' => 'Q8_0']]],
        'missing model_selector' => ['query' => 'test'],
        'missing chat selector' => ['query' => 'test', 'model_selector' => ['embedding' => ['model_name' => 'b', 'quantization' => 'Q8_0']]],
        'missing embedding selector' => ['query' => 'test', 'model_selector' => ['chat' => ['model_name' => 'a', 'quantization' => 'Q4_K']]],
        'invalid chat quant' => ['query' => 'test', 'model_selector' => ['chat' => ['model_name' => 'a', 'quantization' => 'BOGUS'], 'embedding' => ['model_name' => 'b', 'quantization' => 'Q8_0']]],
    ];
    foreach ($rejections as $label => $payload) {
        rag_orchestrator_contract_assert(
            model_inference_validate_rag_request($payload) === null,
            "must reject: {$label}"
        );
    }

    // 8. Dispatcher: POST /api/rag dispatches (trips openDatabase or sessions).
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
    $openDatabase = static function (): PDO { throw new RuntimeException('openDatabase reached — rag module wired.'); };
    $getInferenceSession = static function () { throw new RuntimeException('inference session reached — rag module wired.'); };
    $getInferenceMetrics = static function () { return new InferenceMetricsRing(); };
    $getEmbeddingSession = static function () { throw new RuntimeException('embedding session reached — rag module wired.'); };
    $getRagMetrics = static function () { return new RagMetricsRing(); };

    $tripped = false;
    try {
        model_inference_dispatch_request(
            ['method' => 'POST', 'path' => '/api/rag', 'uri' => '/api/rag', 'headers' => [],
             'body' => '{"query":"test","model_selector":{"chat":{"model_name":"a","quantization":"Q4_K"},"embedding":{"model_name":"b","quantization":"Q8_0"}}}'],
            $jsonResponse, $errorResponse, $methodFromRequest, $pathFromRequest,
            $runtimeEnvelope, $openDatabase, $getInferenceSession, $getInferenceMetrics,
            '/ws', '127.0.0.1', 18090, $getEmbeddingSession, $getRagMetrics
        );
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'openDatabase reached') || str_contains($e->getMessage(), 'session reached')) {
            $tripped = true;
        }
    }
    rag_orchestrator_contract_assert($tripped, 'POST /api/rag must dispatch through retrieve module');

    // 9. GET /api/rag returns 405.
    $getResp = model_inference_dispatch_request(
        ['method' => 'GET', 'path' => '/api/rag', 'uri' => '/api/rag', 'headers' => []],
        $jsonResponse, $errorResponse, $methodFromRequest, $pathFromRequest,
        $runtimeEnvelope, $openDatabase, $getInferenceSession, $getInferenceMetrics,
        '/ws', '127.0.0.1', 18090, $getEmbeddingSession, $getRagMetrics
    );
    rag_orchestrator_contract_assert((int) ($getResp['status'] ?? 0) === 405, 'GET /api/rag must return 405');

    // 10. GET /api/telemetry/rag/recent returns 200.
    $telResp = model_inference_dispatch_request(
        ['method' => 'GET', 'path' => '/api/telemetry/rag/recent', 'uri' => '/api/telemetry/rag/recent', 'headers' => []],
        $jsonResponse, $errorResponse, $methodFromRequest, $pathFromRequest,
        $runtimeEnvelope, $openDatabase, $getInferenceSession, $getInferenceMetrics,
        '/ws', '127.0.0.1', 18090, $getEmbeddingSession, $getRagMetrics
    );
    rag_orchestrator_contract_assert((int) ($telResp['status'] ?? 0) === 200, 'GET /api/telemetry/rag/recent must return 200');
    $telPayload = json_decode((string) ($telResp['body'] ?? ''), true);
    rag_orchestrator_contract_assert(is_array($telPayload) && ($telPayload['status'] ?? '') === 'ok', 'rag telemetry must return ok');
    rag_orchestrator_contract_assert(is_array($telPayload['items'] ?? null), 'rag telemetry must return items array');

    fwrite(STDOUT, "[rag-orchestrator-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[rag-orchestrator-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
