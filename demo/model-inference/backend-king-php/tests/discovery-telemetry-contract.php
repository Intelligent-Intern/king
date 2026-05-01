<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/telemetry/discovery_metrics.php';
require_once __DIR__ . '/../http/module_telemetry.php';

function discovery_telemetry_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[discovery-telemetry-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    // 1. Class exists.
    discovery_telemetry_contract_assert(class_exists(DiscoveryMetricsRing::class), 'DiscoveryMetricsRing exists');
    $rulesAsserted++;

    // 2. Capacity validation.
    $rej = false;
    try {
        new DiscoveryMetricsRing(0);
    } catch (InvalidArgumentException $e) {
        $rej = true;
    }
    discovery_telemetry_contract_assert($rej, 'capacity < 1 rejected');
    $rulesAsserted++;

    // 3. Empty ring basics.
    $ring = new DiscoveryMetricsRing(3);
    discovery_telemetry_contract_assert($ring->count() === 0, 'empty count=0');
    discovery_telemetry_contract_assert($ring->capacity() === 3, 'capacity preserved');
    discovery_telemetry_contract_assert($ring->recent(10) === [], 'empty recent returns []');
    $rulesAsserted += 3;

    // 4. Record + normalize fields.
    $ring->record([
        'request_id' => 'r1',
        'mode' => 'semantic',
        'embedding_ms' => 12,
        'search_ms' => 3,
        'total_ms' => 15,
        'candidates_scanned' => 7,
        'query_length' => 20,
        'service_type' => 'king.inference.v1',
        'top_k' => 5,
        'min_score' => 0.1,
        'alpha' => 0.5,
    ]);
    discovery_telemetry_contract_assert($ring->count() === 1, 'count=1 after record');
    $recent = $ring->recent(10);
    discovery_telemetry_contract_assert(count($recent) === 1, 'recent returns 1');
    $r = $recent[0];
    foreach ([
        'request_id' => 'r1',
        'mode' => 'semantic',
        'embedding_ms' => 12,
        'search_ms' => 3,
        'total_ms' => 15,
        'candidates_scanned' => 7,
        'query_length' => 20,
        'service_type' => 'king.inference.v1',
        'top_k' => 5,
        'min_score' => 0.1,
        'alpha' => 0.5,
    ] as $k => $v) {
        discovery_telemetry_contract_assert($r[$k] === $v, "field {$k} round-trip");
        $rulesAsserted++;
    }
    discovery_telemetry_contract_assert(is_string($r['recorded_at']) && $r['recorded_at'] !== '', 'recorded_at populated');
    $rulesAsserted += 2;

    // 5. Missing fields get normalized defaults.
    $ring->record(['request_id' => 'r2']);
    $last = $ring->recent(1)[0];
    discovery_telemetry_contract_assert($last['request_id'] === 'r2', 'second entry is r2');
    discovery_telemetry_contract_assert($last['mode'] === '', 'missing mode -> empty string');
    discovery_telemetry_contract_assert($last['embedding_ms'] === 0, 'missing ms -> 0');
    discovery_telemetry_contract_assert($last['min_score'] === 0.0, 'missing min_score -> 0.0');
    $rulesAsserted += 4;

    // 6. Capacity eviction FIFO.
    $ring->record(['request_id' => 'r3']);
    discovery_telemetry_contract_assert($ring->count() === 3, 'ring full at capacity');
    $ring->record(['request_id' => 'r4']);
    discovery_telemetry_contract_assert($ring->count() === 3, 'capacity held at 3 after eviction');
    $ids = array_map(static fn (array $e) => $e['request_id'], $ring->recent(10));
    discovery_telemetry_contract_assert($ids === ['r4', 'r3', 'r2'], 'r1 evicted (oldest), recent returns newest-first');
    $rulesAsserted += 3;

    // 7. Telemetry endpoint returns 200 with empty ring when not configured.
    $jsonResponse = static function (int $status, array $payload): array {
        return ['status' => $status, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($payload, JSON_UNESCAPED_SLASHES)];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, [
            'status' => 'error', 'error' => ['code' => $code, 'message' => $message, 'details' => $details],
            'time' => gmdate('c'),
        ]);
    };
    $getInferenceMetrics = static fn () => null;
    $resp = model_inference_handle_telemetry_routes(
        '/api/telemetry/discovery/recent',
        'GET',
        $jsonResponse,
        $errorResponse,
        $getInferenceMetrics,
        null,
        null
    );
    discovery_telemetry_contract_assert(is_array($resp) && $resp['status'] === 200, 'endpoint returns 200 when unconfigured');
    $decoded = json_decode((string) $resp['body'], true);
    discovery_telemetry_contract_assert(is_array($decoded) && $decoded['count'] === 0, 'unconfigured ring count=0');
    $rulesAsserted += 2;

    // 8. Wired endpoint returns the ring contents.
    $getDiscoveryMetrics = static fn () => $ring;
    $respWired = model_inference_handle_telemetry_routes(
        '/api/telemetry/discovery/recent',
        'GET',
        $jsonResponse,
        $errorResponse,
        $getInferenceMetrics,
        null,
        $getDiscoveryMetrics
    );
    discovery_telemetry_contract_assert(is_array($respWired) && $respWired['status'] === 200, 'wired endpoint 200');
    $decodedWired = json_decode((string) $respWired['body'], true);
    discovery_telemetry_contract_assert(is_array($decodedWired) && $decodedWired['count'] === 3, 'wired count=3');
    discovery_telemetry_contract_assert($decodedWired['capacity'] === 3, 'wired capacity=3');
    discovery_telemetry_contract_assert($decodedWired['items'][0]['request_id'] === 'r4', 'wired items newest-first');
    $rulesAsserted += 4;

    // 9. POST /api/telemetry/discovery/recent -> 405.
    $respPost = model_inference_handle_telemetry_routes(
        '/api/telemetry/discovery/recent',
        'POST',
        $jsonResponse,
        $errorResponse,
        $getInferenceMetrics,
        null,
        $getDiscoveryMetrics
    );
    discovery_telemetry_contract_assert(is_array($respPost) && $respPost['status'] === 405, 'POST -> 405');
    $rulesAsserted++;

    fwrite(STDOUT, "[discovery-telemetry-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[discovery-telemetry-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
