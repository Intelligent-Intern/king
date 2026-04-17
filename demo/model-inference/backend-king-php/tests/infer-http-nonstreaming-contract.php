<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/object_store.php';
require_once __DIR__ . '/../domain/registry/model_registry.php';
require_once __DIR__ . '/../domain/inference/inference_session.php';
require_once __DIR__ . '/../domain/telemetry/inference_metrics.php';
require_once __DIR__ . '/../http/router.php';

function model_inference_infer_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[infer-http-nonstreaming-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    model_inference_infer_contract_assert(extension_loaded('king'), 'king extension must be loaded');

    $llamaHome = (string) (getenv('LLAMA_CPP_HOME') ?: '/opt/llama-cpp/llama-b8802');
    $ggufFixture = (string) (getenv('MODEL_INFERENCE_GGUF_FIXTURE_PATH') ?: (__DIR__ . '/../.local/fixtures/SmolLM2-135M-Instruct-Q4_K_S.gguf'));
    model_inference_infer_contract_assert(is_executable($llamaHome . '/llama-server'), "llama-server not executable at {$llamaHome}/llama-server");
    model_inference_infer_contract_assert(is_file($ggufFixture), "GGUF fixture missing at {$ggufFixture}");

    $tmpRoot = sys_get_temp_dir() . '/king-model-inference-infer-' . bin2hex(random_bytes(6));
    $storageRoot = $tmpRoot . '/object-store';
    $ggufCacheRoot = $tmpRoot . '/gguf-cache';
    $dbPath = $tmpRoot . '/registry.sqlite';
    @mkdir($tmpRoot, 0775, true);
    @mkdir($storageRoot, 0775, true);
    @mkdir($ggufCacheRoot, 0775, true);

    model_inference_object_store_init($storageRoot, 1024 * 1024 * 1024);
    $pdo = model_inference_open_sqlite_pdo($dbPath);
    model_inference_registry_schema_migrate($pdo);

    // Seed the SmolLM2 fixture through the same domain API the HTTP route
    // uses — proves an end-to-end flow against a real registry row.
    $stream = fopen($ggufFixture, 'rb');
    $entry = model_inference_registry_create_from_stream($pdo, [
        'model_name' => 'SmolLM2-135M-Instruct',
        'family' => 'smollm2',
        'quantization' => 'Q4_K',
        'parameter_count' => 135000000,
        'context_length' => 2048,
        'license' => 'apache-2.0',
        'min_ram_bytes' => 268435456,
        'min_vram_bytes' => 0,
        'prefers_gpu' => false,
        'source_url' => null,
    ], $stream);
    fclose($stream);
    model_inference_infer_contract_assert(preg_match('/^mdl-[a-f0-9]{16}$/', (string) $entry['model_id']) === 1, 'seed model_id shape');

    // Wire dispatcher dependencies.
    $jsonResponse = static function (int $status, array $payload): array {
        return ['status' => $status, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, ['status' => 'error', 'error' => ['code' => $code, 'message' => $message, 'details' => $details], 'time' => gmdate('c')]);
    };
    $methodFromRequest = static function (array $request): string {
        return strtoupper(trim((string) ($request['method'] ?? 'GET')));
    };
    $pathFromRequest = static function (array $request): string {
        return (string) ($request['path'] ?? '/');
    };
    $runtimeEnvelope = static function (): array {
        return ['node' => ['node_id' => 'node_infer_contract', 'role' => 'inference-serving']];
    };
    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };

    $session = new InferenceSession($llamaHome . '/llama-server', $llamaHome, $ggufCacheRoot);
    $getInferenceSession = static function () use ($session): InferenceSession {
        return $session;
    };
    $metrics = new InferenceMetricsRing(8);
    $getInferenceMetrics = static function () use ($metrics): InferenceMetricsRing {
        return $metrics;
    };

    $dispatch = static function (string $method, string $path, array $body) use (
        $jsonResponse,
        $errorResponse,
        $methodFromRequest,
        $pathFromRequest,
        $runtimeEnvelope,
        $openDatabase,
        $getInferenceSession,
        $getInferenceMetrics
    ): array {
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return model_inference_dispatch_request(
            [
                'method' => $method,
                'path' => $path,
                'uri' => $path,
                'headers' => ['content-type' => 'application/json'],
                'body' => $encoded,
            ],
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
    };

    // 1. Happy path: real prompt, real completion.
    $payload = [
        'session_id' => 'sess-infer-contract',
        'model_selector' => ['model_name' => 'SmolLM2-135M-Instruct', 'quantization' => 'Q4_K', 'prefer_local' => true],
        'prompt' => 'Write the single word OK and stop.',
        'sampling' => ['temperature' => 0.2, 'top_p' => 0.95, 'top_k' => 40, 'max_tokens' => 16, 'seed' => 7],
        'stream' => false,
    ];
    $response = $dispatch('POST', '/api/infer', $payload);
    model_inference_infer_contract_assert(
        (int) ($response['status'] ?? 0) === 200,
        'POST /api/infer must return 200; got ' . $response['status'] . ' body=' . substr((string) ($response['body'] ?? ''), 0, 400)
    );
    $envelope = json_decode((string) ($response['body'] ?? ''), true);
    model_inference_infer_contract_assert(is_array($envelope), 'response body must be JSON object');
    model_inference_infer_contract_assert(($envelope['status'] ?? null) === 'ok', 'envelope.status must be ok');
    model_inference_infer_contract_assert(preg_match('/^req_[a-f0-9]{16}$/', (string) ($envelope['request_id'] ?? '')) === 1, 'request_id shape');
    model_inference_infer_contract_assert(($envelope['session_id'] ?? null) === 'sess-infer-contract', 'session_id passthrough');
    model_inference_infer_contract_assert(($envelope['model']['model_id'] ?? null) === (string) $entry['model_id'], 'model_id matches registry row');
    $completion = $envelope['completion'] ?? null;
    model_inference_infer_contract_assert(is_array($completion), 'completion block must be present');
    model_inference_infer_contract_assert(is_string($completion['text'] ?? null) && $completion['text'] !== '', 'completion.text must be non-empty (got: ' . var_export($completion['text'] ?? null, true) . ')');
    model_inference_infer_contract_assert(((int) ($completion['tokens_out'] ?? 0)) >= 1, 'completion.tokens_out must be >= 1; got ' . (int) ($completion['tokens_out'] ?? 0));
    model_inference_infer_contract_assert(((int) ($completion['tokens_in'] ?? 0)) >= 1, 'completion.tokens_in must be >= 1');
    model_inference_infer_contract_assert(((int) ($completion['duration_ms'] ?? -1)) >= 0, 'completion.duration_ms must be >= 0');
    model_inference_infer_contract_assert(((int) ($completion['ttft_ms'] ?? -1)) >= 0, 'completion.ttft_ms must be >= 0');
    model_inference_infer_contract_assert(((int) ($completion['request_wall_ms'] ?? 0)) >= 1, 'completion.request_wall_ms must be >= 1');
    model_inference_infer_contract_assert(is_array($completion['stop'] ?? null), 'completion.stop block required');
    model_inference_infer_contract_assert(is_array($envelope['worker'] ?? null), 'worker diagnostics required');
    model_inference_infer_contract_assert(((int) ($envelope['worker']['port'] ?? 0)) >= 1, 'worker.port must be a positive int');

    // 2. Second call reuses the cached worker for the same model_id (same port).
    $firstPort = (int) $envelope['worker']['port'];
    $firstPid = (int) $envelope['worker']['pid'];
    $response2 = $dispatch('POST', '/api/infer', $payload);
    model_inference_infer_contract_assert(
        (int) ($response2['status'] ?? 0) === 200,
        'second POST must also return 200'
    );
    $envelope2 = json_decode((string) ($response2['body'] ?? ''), true);
    model_inference_infer_contract_assert(((int) ($envelope2['worker']['port'] ?? 0)) === $firstPort, 'worker cache reuse: port stays the same across back-to-back requests');
    model_inference_infer_contract_assert(((int) ($envelope2['worker']['pid'] ?? 0)) === $firstPid, 'worker cache reuse: pid stays the same across back-to-back requests');

    // 3. HTTP rejects stream=true (transport cross-check from #M-8).
    $bad = $payload;
    $bad['stream'] = true;
    $response3 = $dispatch('POST', '/api/infer', $bad);
    model_inference_infer_contract_assert((int) ($response3['status'] ?? 0) === 400, 'stream=true must return 400');
    $err3 = json_decode((string) ($response3['body'] ?? ''), true);
    model_inference_infer_contract_assert((($err3['error'] ?? [])['code'] ?? null) === 'invalid_request_envelope', 'stream=true must emit invalid_request_envelope');
    model_inference_infer_contract_assert((($err3['error']['details'] ?? [])['field'] ?? null) === 'stream', 'error details.field must be stream');

    // 4. Missing selector model returns 404 model_not_found.
    $bad = $payload;
    $bad['model_selector']['model_name'] = 'does-not-exist';
    $response4 = $dispatch('POST', '/api/infer', $bad);
    model_inference_infer_contract_assert((int) ($response4['status'] ?? 0) === 404, 'unknown model must return 404');
    $err4 = json_decode((string) ($response4['body'] ?? ''), true);
    model_inference_infer_contract_assert((($err4['error'] ?? [])['code'] ?? null) === 'model_not_found', 'unknown model must emit model_not_found');

    // 5. Empty body.
    $emptyResponse = model_inference_dispatch_request(
        ['method' => 'POST', 'path' => '/api/infer', 'uri' => '/api/infer', 'headers' => [], 'body' => ''],
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
    model_inference_infer_contract_assert((int) ($emptyResponse['status'] ?? 0) === 400, 'empty body must 400');
    $emptyPayload = json_decode((string) ($emptyResponse['body'] ?? ''), true);
    model_inference_infer_contract_assert((($emptyPayload['error'] ?? [])['code'] ?? null) === 'invalid_request_envelope', 'empty body must emit invalid_request_envelope');

    // 6. Malformed JSON body.
    $malformedResponse = model_inference_dispatch_request(
        ['method' => 'POST', 'path' => '/api/infer', 'uri' => '/api/infer', 'headers' => [], 'body' => '{not json'],
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
    model_inference_infer_contract_assert((int) ($malformedResponse['status'] ?? 0) === 400, 'malformed json must 400');
    $malformedPayload = json_decode((string) ($malformedResponse['body'] ?? ''), true);
    model_inference_infer_contract_assert((($malformedPayload['error'] ?? [])['code'] ?? null) === 'invalid_request_envelope', 'malformed json must emit invalid_request_envelope');

    // 7. Non-POST method on /api/infer.
    $getResponse = $dispatch('GET', '/api/infer', []);
    model_inference_infer_contract_assert((int) ($getResponse['status'] ?? 0) === 405, 'GET /api/infer must 405');
    $getPayload = json_decode((string) ($getResponse['body'] ?? ''), true);
    model_inference_infer_contract_assert((($getPayload['error'] ?? [])['code'] ?? null) === 'method_not_allowed', 'GET /api/infer must emit method_not_allowed');

    // 8. Drain the session; next infer spawns a fresh worker on a new port.
    $session->drainAll();
    $response5 = $dispatch('POST', '/api/infer', $payload);
    model_inference_infer_contract_assert((int) ($response5['status'] ?? 0) === 200, 'post-drain infer must succeed');
    $envelope5 = json_decode((string) ($response5['body'] ?? ''), true);
    model_inference_infer_contract_assert(((int) ($envelope5['worker']['pid'] ?? 0)) !== $firstPid, 'post-drain infer must spawn a NEW pid (got same pid as before)');

    // Cleanup.
    $session->drainAll();
    foreach (glob($storageRoot . '/*') ?: [] as $entryPath) {
        if (is_dir($entryPath)) {
            foreach (glob($entryPath . '/*') ?: [] as $inner) {
                @unlink($inner);
            }
            @rmdir($entryPath);
        } else {
            @unlink($entryPath);
        }
    }
    foreach (glob($ggufCacheRoot . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($storageRoot);
    @rmdir($ggufCacheRoot);
    @unlink($dbPath);
    @rmdir($tmpRoot);

    fwrite(STDOUT, sprintf(
        "[infer-http-nonstreaming-contract] PASS (real SmolLM2 round-trip: tokens_in=%d tokens_out=%d ttft_ms=%d duration_ms=%d; worker cache reused)\n",
        (int) ($completion['tokens_in'] ?? 0),
        (int) ($completion['tokens_out'] ?? 0),
        (int) ($completion['ttft_ms'] ?? 0),
        (int) ($completion['duration_ms'] ?? 0)
    ));
    exit(0);
} catch (Throwable $error) {
    if (isset($session) && $session instanceof InferenceSession) {
        $session->drainAll();
    }
    fwrite(STDERR, '[infer-http-nonstreaming-contract] ERROR: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
}
