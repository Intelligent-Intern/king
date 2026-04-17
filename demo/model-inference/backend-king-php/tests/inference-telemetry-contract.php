<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/object_store.php';
require_once __DIR__ . '/../domain/registry/model_registry.php';
require_once __DIR__ . '/../domain/inference/inference_session.php';
require_once __DIR__ . '/../domain/inference/inference_stream.php';
require_once __DIR__ . '/../domain/telemetry/inference_metrics.php';
require_once __DIR__ . '/../http/router.php';

function model_inference_telemetry_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[inference-telemetry-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    // -------------------------------------------------------------
    // Section A — ring mechanics (pure, no kernel).
    // -------------------------------------------------------------
    $ring = new InferenceMetricsRing(3);
    model_inference_telemetry_contract_assert($ring->capacity() === 3, 'capacity passthrough');
    model_inference_telemetry_contract_assert($ring->count() === 0, 'fresh ring count is 0');
    model_inference_telemetry_contract_assert($ring->recent() === [], 'fresh ring recent() returns []');

    for ($i = 1; $i <= 5; $i++) {
        $ring->record([
            'request_id' => 'req_' . str_pad((string) $i, 16, '0', STR_PAD_LEFT),
            'session_id' => 'sess-' . $i,
            'transport' => $i % 2 === 0 ? 'ws' : 'http',
            'model_id' => 'mdl-test',
            'model_name' => 'fixture',
            'quantization' => 'Q4_0',
            'node_id' => 'node_ring_contract',
            'tokens_in' => $i,
            'tokens_out' => $i * 2,
            'ttft_ms' => 10 * $i,
            'duration_ms' => 100 * $i,
            'gpu_kind' => 'none',
        ]);
    }
    model_inference_telemetry_contract_assert($ring->count() === 3, 'ring capped at capacity after overflow');

    $entries = $ring->recent();
    model_inference_telemetry_contract_assert(count($entries) === 3, 'recent() returns capacity-bounded list');
    // Newest-first ordering.
    model_inference_telemetry_contract_assert($entries[0]['request_id'] === 'req_0000000000000005', 'recent()[0] is newest');
    model_inference_telemetry_contract_assert($entries[2]['request_id'] === 'req_0000000000000003', 'recent()[last] is oldest surviving');
    // Eviction honesty: entries 1 and 2 must be gone.
    foreach ($entries as $e) {
        model_inference_telemetry_contract_assert($e['request_id'] !== 'req_0000000000000001', 'oldest entry must be evicted');
        model_inference_telemetry_contract_assert($e['request_id'] !== 'req_0000000000000002', 'second-oldest entry must be evicted');
    }

    // tokens_per_second derivation.
    foreach ($entries as $e) {
        $expected = $e['tokens_out'] > 0 && $e['duration_ms'] > 0
            ? round($e['tokens_out'] / ($e['duration_ms'] / 1000.0), 3)
            : 0.0;
        model_inference_telemetry_contract_assert(
            abs(((float) $e['tokens_per_second']) - $expected) < 0.0001,
            "entry.tokens_per_second derivation for request_id={$e['request_id']} (expected {$expected}, got {$e['tokens_per_second']})"
        );
    }

    // recorded_at is server-owned and rfc3339-shaped.
    model_inference_telemetry_contract_assert(
        preg_match('/^\d{4}-\d{2}-\d{2}T/', (string) $entries[0]['recorded_at']) === 1,
        'recorded_at must be rfc3339'
    );

    // Transport normalization: unknown transport defaults to 'http'.
    $ring->record(['request_id' => 'req_x', 'session_id' => 's', 'transport' => 'garbage', 'tokens_out' => 1, 'duration_ms' => 100]);
    model_inference_telemetry_contract_assert($ring->recent(1)[0]['transport'] === 'http', 'unknown transport clamps to http');

    // limit smaller than count.
    for ($i = 0; $i < 3; $i++) {
        $ring->record(['request_id' => 'req_tail' . $i, 'session_id' => 't', 'tokens_out' => 1, 'duration_ms' => 100]);
    }
    model_inference_telemetry_contract_assert(count($ring->recent(2)) === 2, 'recent(2) returns 2 entries');

    // clear().
    $ring->clear();
    model_inference_telemetry_contract_assert($ring->count() === 0, 'clear() drops entries');

    // capacity < 1 rejected.
    $caught = false;
    try {
        new InferenceMetricsRing(0);
    } catch (InvalidArgumentException $ignored) {
        $caught = true;
    }
    model_inference_telemetry_contract_assert($caught, 'capacity < 1 must throw');

    // -------------------------------------------------------------
    // Section B — end-to-end HTTP + WS recording against a real SmolLM2.
    // -------------------------------------------------------------
    model_inference_telemetry_contract_assert(extension_loaded('king'), 'king extension must be loaded');

    $llamaHome = (string) (getenv('LLAMA_CPP_HOME') ?: '/opt/llama-cpp/llama-b8802');
    $ggufFixture = (string) (getenv('MODEL_INFERENCE_GGUF_FIXTURE_PATH') ?: '/workspace/demo/model-inference/backend-king-php/.local/fixtures/SmolLM2-135M-Instruct-Q4_K_S.gguf');
    $tmpRoot = sys_get_temp_dir() . '/king-model-inference-telemetry-' . bin2hex(random_bytes(6));
    $storageRoot = $tmpRoot . '/object-store';
    $ggufCacheRoot = $tmpRoot . '/gguf-cache';
    @mkdir($tmpRoot, 0775, true);
    @mkdir($storageRoot, 0775, true);
    @mkdir($ggufCacheRoot, 0775, true);

    model_inference_object_store_init($storageRoot, 1024 * 1024 * 1024);
    $pdo = model_inference_open_sqlite_pdo($tmpRoot . '/registry.sqlite');
    model_inference_registry_schema_migrate($pdo);
    $fh = fopen($ggufFixture, 'rb');
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
    ], $fh);
    fclose($fh);

    $session = new InferenceSession($llamaHome . '/llama-server', $llamaHome, $ggufCacheRoot);
    $metrics = new InferenceMetricsRing(16);
    $getSession = static function () use ($session) { return $session; };
    $getMetrics = static function () use ($metrics) { return $metrics; };
    $openDb = static function () use ($pdo) { return $pdo; };
    $jsonResponse = static function (int $status, array $payload): array {
        return ['status' => $status, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, ['status' => 'error', 'error' => ['code' => $code, 'message' => $message, 'details' => $details], 'time' => gmdate('c')]);
    };
    $methodFrom = static function (array $r): string { return strtoupper((string) ($r['method'] ?? 'GET')); };
    $pathFrom = static function (array $r): string { return (string) ($r['path'] ?? '/'); };
    $runtimeEnvelope = static function (): array {
        return ['node' => ['node_id' => 'node_telemetry_contract', 'role' => 'inference-serving']];
    };

    // 1. HTTP infer → one entry in the ring.
    $httpBody = [
        'session_id' => 'sess-telemetry-http',
        'model_selector' => ['model_name' => 'SmolLM2-135M-Instruct', 'quantization' => 'Q4_K', 'prefer_local' => true],
        'prompt' => 'Write ok and stop.',
        'sampling' => ['temperature' => 0.0, 'top_p' => 1.0, 'top_k' => 1, 'max_tokens' => 6, 'seed' => 1],
        'stream' => false,
    ];
    $httpResponse = model_inference_dispatch_request(
        ['method' => 'POST', 'path' => '/api/infer', 'uri' => '/api/infer', 'headers' => [], 'body' => json_encode($httpBody)],
        $jsonResponse, $errorResponse, $methodFrom, $pathFrom, $runtimeEnvelope,
        $openDb, $getSession, $getMetrics, '/ws', '127.0.0.1', 18090
    );
    model_inference_telemetry_contract_assert((int) $httpResponse['status'] === 200, 'HTTP infer must 200 (body=' . substr((string) $httpResponse['body'], 0, 200) . ')');
    model_inference_telemetry_contract_assert($metrics->count() === 1, 'ring must carry one entry after first HTTP infer');
    $row = $metrics->recent(1)[0];
    model_inference_telemetry_contract_assert($row['transport'] === 'http', 'first entry transport is http');
    model_inference_telemetry_contract_assert($row['session_id'] === 'sess-telemetry-http', 'first entry session_id passthrough');
    model_inference_telemetry_contract_assert($row['model_name'] === 'SmolLM2-135M-Instruct', 'first entry model_name');
    model_inference_telemetry_contract_assert($row['quantization'] === 'Q4_K', 'first entry quantization');
    model_inference_telemetry_contract_assert($row['node_id'] === 'node_telemetry_contract', 'first entry node_id from profile');
    model_inference_telemetry_contract_assert(preg_match('/^mdl-[a-f0-9]{16}$/', (string) $row['model_id']) === 1, 'first entry model_id shape');
    model_inference_telemetry_contract_assert($row['tokens_out'] >= 1, 'first entry tokens_out >= 1');
    model_inference_telemetry_contract_assert($row['tokens_in'] >= 1, 'first entry tokens_in >= 1');
    model_inference_telemetry_contract_assert($row['gpu_kind'] === 'none', 'first entry gpu_kind from linux-no-GPU profile');

    // 2. GET /api/telemetry/inference/recent returns the ring with the right envelope.
    $recentResponse = model_inference_dispatch_request(
        ['method' => 'GET', 'path' => '/api/telemetry/inference/recent', 'uri' => '/api/telemetry/inference/recent', 'headers' => []],
        $jsonResponse, $errorResponse, $methodFrom, $pathFrom, $runtimeEnvelope,
        $openDb, $getSession, $getMetrics, '/ws', '127.0.0.1', 18090
    );
    model_inference_telemetry_contract_assert((int) $recentResponse['status'] === 200, 'GET /api/telemetry/inference/recent must 200');
    $recent = json_decode((string) $recentResponse['body'], true);
    model_inference_telemetry_contract_assert(is_array($recent), 'recent response must be JSON object');
    model_inference_telemetry_contract_assert(($recent['status'] ?? null) === 'ok', 'recent.status');
    model_inference_telemetry_contract_assert(((int) ($recent['count'] ?? 0)) === 1, 'recent.count = 1');
    model_inference_telemetry_contract_assert(((int) ($recent['capacity'] ?? 0)) === 16, 'recent.capacity matches ring');
    model_inference_telemetry_contract_assert(count((array) ($recent['items'] ?? [])) === 1, 'recent.items has 1 row');
    $item = $recent['items'][0];
    foreach (['request_id', 'session_id', 'transport', 'model_id', 'model_name', 'quantization', 'node_id', 'tokens_in', 'tokens_out', 'ttft_ms', 'duration_ms', 'tokens_per_second', 'vram_total_bytes', 'vram_free_bytes', 'gpu_kind', 'recorded_at'] as $k) {
        model_inference_telemetry_contract_assert(array_key_exists($k, $item), "recent.items[0] must include '{$k}'");
    }

    // 3. WS stream through realtime module → ring gains a SECOND entry with transport=ws.
    //    We exercise model_inference_stream_completion directly + metrics
    //    record through the same helper the WS path uses.
    $worker = $session->workerFor((string) $entry['model_id'], (string) $entry['artifact']['object_store_key'], 1024, 8);
    $validatedWs = [
        'session_id' => 'sess-telemetry-ws',
        'model_selector' => ['model_name' => 'SmolLM2-135M-Instruct', 'quantization' => 'Q4_K', 'prefer_local' => true],
        'prompt' => 'Write ok and stop.',
        'system' => null,
        'sampling' => ['temperature' => 0.0, 'top_p' => 1.0, 'top_k' => 1, 'max_tokens' => 6, 'seed' => 1],
        'stream' => true,
    ];
    $captured = [];
    $summary = model_inference_stream_completion(
        $worker,
        $validatedWs,
        'req_tel_ws_0000001',
        static function (string $bytes) use (&$captured): void { $captured[] = $bytes; },
        6
    );
    $profile = ['node_id' => 'node_telemetry_contract', 'gpu' => ['kind' => 'none', 'vram_total_bytes' => 0, 'vram_free_bytes' => 0]];
    $metrics->record(model_inference_metrics_entry_from_ws($summary, $validatedWs, $entry, $profile));

    model_inference_telemetry_contract_assert($metrics->count() === 2, 'ring must now hold 2 entries (http + ws)');
    $rows = $metrics->recent();
    model_inference_telemetry_contract_assert($rows[0]['transport'] === 'ws', 'newest entry is ws');
    model_inference_telemetry_contract_assert($rows[1]['transport'] === 'http', 'older entry is http');
    model_inference_telemetry_contract_assert($rows[0]['session_id'] === 'sess-telemetry-ws', 'ws entry session_id');
    model_inference_telemetry_contract_assert($rows[0]['model_name'] === 'SmolLM2-135M-Instruct', 'ws entry model_name from registry row');
    model_inference_telemetry_contract_assert($rows[0]['tokens_out'] === $summary['tokens_out'], 'ws entry tokens_out matches stream summary');

    // 4. HTTP/WS path parity: tokens_per_second is derived identically.
    foreach ($rows as $r) {
        $expected = $r['tokens_out'] > 0 && $r['duration_ms'] > 0
            ? round($r['tokens_out'] / ($r['duration_ms'] / 1000.0), 3)
            : 0.0;
        model_inference_telemetry_contract_assert(
            abs(((float) $r['tokens_per_second']) - $expected) < 0.0001,
            "end-to-end entry tokens_per_second derivation (transport={$r['transport']})"
        );
    }

    // 5. Non-GET /api/telemetry/inference/recent rejected.
    $badResponse = model_inference_dispatch_request(
        ['method' => 'POST', 'path' => '/api/telemetry/inference/recent', 'uri' => '/api/telemetry/inference/recent', 'headers' => []],
        $jsonResponse, $errorResponse, $methodFrom, $pathFrom, $runtimeEnvelope,
        $openDb, $getSession, $getMetrics, '/ws', '127.0.0.1', 18090
    );
    model_inference_telemetry_contract_assert((int) $badResponse['status'] === 405, 'POST /api/telemetry/inference/recent must 405');
    $badPayload = json_decode((string) $badResponse['body'], true);
    model_inference_telemetry_contract_assert((($badPayload['error'] ?? [])['code'] ?? null) === 'method_not_allowed', 'non-GET must emit method_not_allowed');

    // Cleanup.
    $session->drainAll();
    foreach (glob($storageRoot . '/*') ?: [] as $p) {
        if (is_dir($p)) { foreach (glob($p . '/*') ?: [] as $i) @unlink($i); @rmdir($p); } else { @unlink($p); }
    }
    foreach (glob($ggufCacheRoot . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($storageRoot);
    @rmdir($ggufCacheRoot);
    @unlink($tmpRoot . '/registry.sqlite');
    @rmdir($tmpRoot);

    fwrite(STDOUT, sprintf(
        "[inference-telemetry-contract] PASS (ring mechanics + cross-transport record; http_tokens_per_s=%.1f ws_tokens_per_s=%.1f)\n",
        (float) $rows[1]['tokens_per_second'],
        (float) $rows[0]['tokens_per_second']
    ));
    exit(0);
} catch (Throwable $error) {
    if (isset($session) && $session instanceof InferenceSession) {
        $session->drainAll();
    }
    fwrite(STDERR, '[inference-telemetry-contract] ERROR: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
}
