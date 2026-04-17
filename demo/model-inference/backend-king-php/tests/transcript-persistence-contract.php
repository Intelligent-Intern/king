<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/inference/transcript_store.php';
require_once __DIR__ . '/../http/router.php';
require_once __DIR__ . '/../domain/telemetry/inference_metrics.php';

function transcript_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[transcript-persistence-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    // 1. Function signatures exist.
    transcript_contract_assert(
        function_exists('model_inference_transcript_save'),
        'model_inference_transcript_save must exist'
    );
    transcript_contract_assert(
        function_exists('model_inference_transcript_load'),
        'model_inference_transcript_load must exist'
    );
    transcript_contract_assert(
        function_exists('model_inference_transcript_key'),
        'model_inference_transcript_key must exist'
    );
    transcript_contract_assert(
        function_exists('model_inference_transcript_from_http'),
        'model_inference_transcript_from_http must exist'
    );
    transcript_contract_assert(
        function_exists('model_inference_transcript_from_ws'),
        'model_inference_transcript_from_ws must exist'
    );

    // 2. Key format is flat (no slashes).
    $key = model_inference_transcript_key('req_0123456789abcdef');
    transcript_contract_assert(
        !str_contains($key, '/'),
        'transcript key must be flat (no slashes): got ' . $key
    );
    transcript_contract_assert(
        str_starts_with($key, 'transcript-'),
        'transcript key must start with transcript-: got ' . $key
    );
    transcript_contract_assert(
        str_contains($key, 'req_0123456789abcdef'),
        'transcript key must contain the request_id: got ' . $key
    );
    transcript_contract_assert(
        preg_match('/^transcript-\d{8}-/', $key) === 1,
        'transcript key must include date component YYYYMMDD after prefix: got ' . $key
    );
    $expectedDatePrefix = 'transcript-' . gmdate('Ymd') . '-';
    transcript_contract_assert(
        str_starts_with($key, $expectedDatePrefix),
        'transcript key must use UTC current date prefix ' . $expectedDatePrefix . ': got ' . $key
    );

    $anotherKey = model_inference_transcript_key('req_fedcba9876543210');
    transcript_contract_assert(
        preg_match('/^transcript-(\d{8})-/', $key, $m1) === 1 && preg_match('/^transcript-(\d{8})-/', $anotherKey, $m2) === 1,
        'transcript keys must contain a parsable YYYYMMDD date segment'
    );
    transcript_contract_assert(
        $m1[1] === $m2[1],
        'transcript keys generated at test time must share the same date segment: got ' . $key . ' and ' . $anotherKey
    );
    transcript_contract_assert(
        $key !== $anotherKey,
        'transcript keys for different request IDs must differ'
    );

    // 3. Graceful fallback: save/load return false/null without extension.
    if (!function_exists('king_object_store_put')) {
        $saveResult = model_inference_transcript_save('req_test', ['test' => true]);
        transcript_contract_assert(
            $saveResult === false,
            'save must return false when object store is unavailable'
        );
    }
    if (!function_exists('king_object_store_get')) {
        $loadResult = model_inference_transcript_load('req_test');
        transcript_contract_assert(
            $loadResult === null,
            'load must return null when object store is unavailable'
        );
    }

    // 4. HTTP transcript builder produces correct shape.
    $httpEnvelope = [
        'request_id' => 'req_abc123',
        'session_id' => 'sess-01',
        'model' => [
            'model_id' => 'mdl-0000000000000001',
            'model_name' => 'TestModel',
            'quantization' => 'Q4_K',
        ],
        'completion' => [
            'text' => 'Hello world',
            'tokens_in' => 5,
            'tokens_out' => 2,
            'ttft_ms' => 50,
            'duration_ms' => 100,
            'request_wall_ms' => 120,
            'stop' => ['type' => 'stop', 'word' => '', 'truncated' => false],
        ],
    ];
    $httpRequest = [
        'prompt' => 'Say hello',
        'system' => 'You are helpful.',
        'sampling' => [
            'temperature' => 0.7,
            'top_p' => 0.9,
            'top_k' => 40,
            'max_tokens' => 256,
        ],
    ];
    $httpTranscript = model_inference_transcript_from_http($httpEnvelope, $httpRequest);
    transcript_contract_assert(
        $httpTranscript['request_id'] === 'req_abc123',
        'HTTP transcript must carry request_id'
    );
    transcript_contract_assert(
        $httpTranscript['session_id'] === 'sess-01',
        'HTTP transcript must carry session_id'
    );
    transcript_contract_assert(
        $httpTranscript['transport'] === 'http',
        'HTTP transcript transport must be http'
    );
    transcript_contract_assert(
        $httpTranscript['prompt'] === 'Say hello',
        'HTTP transcript must carry prompt'
    );
    transcript_contract_assert(
        $httpTranscript['system'] === 'You are helpful.',
        'HTTP transcript must carry system'
    );
    transcript_contract_assert(
        is_array($httpTranscript['model']) && $httpTranscript['model']['model_id'] === 'mdl-0000000000000001',
        'HTTP transcript must carry model'
    );
    transcript_contract_assert(
        is_array($httpTranscript['completion']) && ($httpTranscript['completion']['text'] ?? '') === 'Hello world',
        'HTTP transcript must carry completion'
    );
    transcript_contract_assert(
        isset($httpTranscript['recorded_at']),
        'HTTP transcript must carry recorded_at'
    );

    // 5. WS transcript builder produces correct shape.
    $wsStreamSummary = [
        'request_id' => 'req_ws_001',
        'concatenated_text' => 'Hi there',
        'tokens_in' => 3,
        'tokens_out' => 2,
        'ttft_ms' => 30,
        'duration_ms' => 80,
        'stop' => ['type' => 'stop', 'word' => '', 'truncated' => false],
    ];
    $wsRequest = [
        'session_id' => 'sess-ws-01',
        'prompt' => 'Greet me',
        'sampling' => [
            'temperature' => 0.5,
            'top_p' => 1.0,
            'top_k' => 0,
            'max_tokens' => 128,
        ],
    ];
    $wsModel = [
        'model_id' => 'mdl-0000000000000002',
        'model_name' => 'TestModel2',
        'quantization' => 'Q8_0',
    ];
    $wsTranscript = model_inference_transcript_from_ws($wsStreamSummary, $wsRequest, $wsModel);
    transcript_contract_assert(
        $wsTranscript['request_id'] === 'req_ws_001',
        'WS transcript must carry request_id'
    );
    transcript_contract_assert(
        $wsTranscript['transport'] === 'ws',
        'WS transcript transport must be ws'
    );
    transcript_contract_assert(
        is_array($wsTranscript['completion']) && ($wsTranscript['completion']['text'] ?? '') === 'Hi there',
        'WS transcript must carry concatenated completion text'
    );

    // 6. Dispatcher routes GET /api/transcripts/{request_id} to the
    //    inference module (not 404 / not_implemented).
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
            'app' => ['name' => 'king-model-inference-backend', 'version' => 'test', 'environment' => 'test'],
            'runtime' => ['king_version' => 'test', 'transport' => 'test', 'ws_path' => '/ws', 'health' => []],
            'database' => ['status' => 'ready'],
            'node' => ['node_id' => 'node_test', 'role' => 'inference-serving'],
            'time' => gmdate('c'),
        ];
    };
    $openDatabase = static function (): PDO {
        throw new RuntimeException('openDatabase must not be reached');
    };
    $getInferenceSession = static function () {
        throw new RuntimeException('inference session must not be reached');
    };
    $getInferenceMetrics = static function () {
        return new InferenceMetricsRing();
    };

    $response = model_inference_dispatch_request(
        ['method' => 'GET', 'path' => '/api/transcripts/req_0000000000000000', 'uri' => '/api/transcripts/req_0000000000000000', 'headers' => []],
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

    $responseStatus = (int) ($response['status'] ?? 0);
    $responseBody = json_decode((string) ($response['body'] ?? '{}'), true);
    $errorCode = (string) (($responseBody['error'] ?? [])['code'] ?? '');

    // Should be 404 transcript_not_found (not 404 not_implemented).
    transcript_contract_assert(
        $responseStatus === 404 && $errorCode === 'transcript_not_found',
        'GET /api/transcripts/{request_id} must route to transcript handler (expected transcript_not_found, got status=' . $responseStatus . ' code=' . $errorCode . ')'
    );

    fwrite(STDOUT, "[transcript-persistence-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[transcript-persistence-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
