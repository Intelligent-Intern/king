<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/telemetry/rag_metrics.php';

function rag_telemetry_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[rag-telemetry-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    // 1. RagMetricsRing class exists and is final.
    rag_telemetry_contract_assert(class_exists('RagMetricsRing'), 'RagMetricsRing class must exist');
    $ref = new ReflectionClass('RagMetricsRing');
    rag_telemetry_contract_assert($ref->isFinal(), 'RagMetricsRing must be final');
    $rulesAsserted += 2;

    // 2. Required methods.
    foreach (['record', 'recent', 'count', 'capacity'] as $method) {
        rag_telemetry_contract_assert($ref->hasMethod($method), "RagMetricsRing must have {$method} method");
        $rulesAsserted++;
    }

    // 3. Default capacity.
    $ring = new RagMetricsRing();
    rag_telemetry_contract_assert($ring->capacity() === 100, 'default capacity must be 100');
    rag_telemetry_contract_assert($ring->count() === 0, 'fresh ring count must be 0');
    $rulesAsserted += 2;

    // 4. Record + recent round-trip.
    $ring->record([
        'request_id' => 'rag_test',
        'query_length' => 42,
        'embedding_ms' => 50,
        'retrieval_ms' => 10,
        'inference_ms' => 200,
        'total_ms' => 260,
        'chunks_used' => 3,
        'vectors_scanned' => 20,
        'tokens_in' => 100,
        'tokens_out' => 50,
        'chat_model' => 'SmolLM2/Q4_K',
        'embedding_model' => 'nomic/Q8_0',
    ]);
    rag_telemetry_contract_assert($ring->count() === 1, 'count must be 1 after one record');
    $recent = $ring->recent(10);
    rag_telemetry_contract_assert(count($recent) === 1, 'recent must return 1 entry');
    $entry = $recent[0];
    rag_telemetry_contract_assert($entry['request_id'] === 'rag_test', 'request_id preserved');
    rag_telemetry_contract_assert($entry['embedding_ms'] === 50, 'embedding_ms preserved');
    rag_telemetry_contract_assert($entry['retrieval_ms'] === 10, 'retrieval_ms preserved');
    rag_telemetry_contract_assert($entry['inference_ms'] === 200, 'inference_ms preserved');
    rag_telemetry_contract_assert($entry['total_ms'] === 260, 'total_ms preserved');
    rag_telemetry_contract_assert($entry['chunks_used'] === 3, 'chunks_used preserved');
    rag_telemetry_contract_assert($entry['vectors_scanned'] === 20, 'vectors_scanned preserved');
    rag_telemetry_contract_assert($entry['tokens_in'] === 100, 'tokens_in preserved');
    rag_telemetry_contract_assert($entry['tokens_out'] === 50, 'tokens_out preserved');
    rag_telemetry_contract_assert(isset($entry['recorded_at']), 'recorded_at must be set');
    $rulesAsserted += 11;

    // 5. FIFO eviction.
    $small = new RagMetricsRing(3);
    for ($i = 0; $i < 5; $i++) {
        $small->record(['request_id' => "rag_{$i}"]);
    }
    rag_telemetry_contract_assert($small->count() === 3, 'ring must cap at capacity (3)');
    $recent3 = $small->recent(10);
    rag_telemetry_contract_assert($recent3[0]['request_id'] === 'rag_4', 'newest must be rag_4');
    rag_telemetry_contract_assert($recent3[2]['request_id'] === 'rag_2', 'oldest must be rag_2 (rag_0 and rag_1 evicted)');
    $rulesAsserted += 3;

    // 6. Capacity < 1 rejected.
    $rejected = false;
    try {
        new RagMetricsRing(0);
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    rag_telemetry_contract_assert($rejected, 'capacity < 1 must be rejected');
    $rulesAsserted++;

    // 7. recent(0) returns empty.
    rag_telemetry_contract_assert($ring->recent(0) === [], 'recent(0) must return empty');
    $rulesAsserted++;

    fwrite(STDOUT, "[rag-telemetry-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[rag-telemetry-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
