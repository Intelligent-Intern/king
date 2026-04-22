<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/discovery/semantic_discover.php';

function semantic_discover_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[semantic-discover-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    semantic_discover_contract_assert(
        function_exists('model_inference_semantic_discover'),
        'model_inference_semantic_discover must exist'
    );
    $rulesAsserted++;

    // Without the object-store extension we can't round-trip real vectors;
    // we still exercise validation + empty-set behavior against a live schema.
    $dbPath = sys_get_temp_dir() . '/semantic-discover-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_service_embedding_schema_migrate($pdo);

        // 1. Rejects top_k < 1.
        $rejected = false;
        try {
            model_inference_semantic_discover($pdo, [0.1, 0.2, 0.3], null, 0, 0.0);
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        }
        semantic_discover_contract_assert($rejected, 'top_k < 1 must be rejected');
        $rulesAsserted++;

        // 2. Rejects min_score outside [-1, 1].
        $rejected2 = false;
        try {
            model_inference_semantic_discover($pdo, [0.1], null, 5, 2.0);
        } catch (InvalidArgumentException $e) {
            $rejected2 = true;
        }
        semantic_discover_contract_assert($rejected2, 'min_score > 1 must be rejected');

        $rejected3 = false;
        try {
            model_inference_semantic_discover($pdo, [0.1], null, 5, -1.5);
        } catch (InvalidArgumentException $e) {
            $rejected3 = true;
        }
        semantic_discover_contract_assert($rejected3, 'min_score < -1 must be rejected');
        $rulesAsserted += 2;

        // 3. Empty table returns stable-shape empty result.
        $empty = model_inference_semantic_discover($pdo, [0.1, 0.2, 0.3, 0.4], null, 5, 0.0);
        semantic_discover_contract_assert($empty['result_count'] === 0, 'empty table returns zero results');
        semantic_discover_contract_assert($empty['candidates_scanned'] === 0, 'empty table reports zero candidates');
        semantic_discover_contract_assert($empty['search_strategy'] === 'brute_force_cosine', 'strategy is brute_force_cosine');
        semantic_discover_contract_assert(is_int($empty['search_ms']), 'search_ms is int');
        semantic_discover_contract_assert($empty['results'] === [], 'results is empty array');
        $rulesAsserted += 5;

        // 4. Direct ranking test using in-memory candidates — bypass the object-store
        //    layer by testing model_inference_vector_search's cousin behavior here
        //    through a crafted input. We validate the sort + cutoff semantics by
        //    seeding rows WITHOUT vectors (load_all filters those out) and confirming
        //    we still get an empty result deterministically.
        $pdo->exec("INSERT INTO service_embeddings (service_id, service_type, embedding_model_id, vector_id,
            dimensions, object_store_key, descriptor_json, updated_at) VALUES
            ('svc-a','king.inference.v1','mdl','svec-aaaaaaaaaaaaaaaa',4,'svec-aaaaaaaaaaaaaaaa','{\"name\":\"a\"}','2026-04-20T00:00:00+00:00'),
            ('svc-b','king.tool.v1','mdl','svec-bbbbbbbbbbbbbbbb',4,'svec-bbbbbbbbbbbbbbbb','{\"name\":\"b\"}','2026-04-20T00:00:00+00:00')");

        // Without object-store the vectors cannot be loaded; result must still be empty.
        if (!function_exists('king_object_store_get')) {
            $noVectors = model_inference_semantic_discover($pdo, [0.1, 0.2, 0.3, 0.4], null, 5, 0.0);
            semantic_discover_contract_assert(
                $noVectors['result_count'] === 0,
                'rows without vectors must not surface as results'
            );
            semantic_discover_contract_assert(
                $noVectors['candidates_scanned'] === 0,
                'candidates_scanned reflects only rows with loadable vectors'
            );
            $rulesAsserted += 2;
        }

        // 5. When object-store available, insert a known descriptor + vector and prove the ranking + filter behavior.
        if (function_exists('king_object_store_put') && function_exists('king_object_store_get')) {
            require_once __DIR__ . '/../domain/discovery/service_embedding_upsert.php';

            // Reset table for a clean run.
            $pdo->exec('DELETE FROM service_embeddings');

            $descA = [
                'service_id' => 'svc-a',
                'service_type' => 'king.inference.v1',
                'name' => 'alpha',
                'description' => 'alpha service',
            ];
            $descB = [
                'service_id' => 'svc-b',
                'service_type' => 'king.inference.v1',
                'name' => 'beta',
                'description' => 'beta service',
            ];
            $descC = [
                'service_id' => 'svc-c',
                'service_type' => 'king.tool.v1',
                'name' => 'gamma',
                'description' => 'gamma tool',
            ];

            // Hand-crafted orthogonal-ish vectors: query closer to A than B, C is in a
            // different service_type so must be excluded when scoped.
            $vA = [1.0, 0.0, 0.0, 0.0];
            $vB = [0.0, 1.0, 0.0, 0.0];
            $vC = [1.0, 0.0, 0.0, 0.0];
            $query = [0.9, 0.1, 0.0, 0.0];

            model_inference_service_embedding_upsert($pdo, $descA, 'mdl', static fn () => ['vector' => $vA]);
            model_inference_service_embedding_upsert($pdo, $descB, 'mdl', static fn () => ['vector' => $vB]);
            model_inference_service_embedding_upsert($pdo, $descC, 'mdl', static fn () => ['vector' => $vC]);

            $resultsAll = model_inference_semantic_discover($pdo, $query, null, 5, 0.0);
            semantic_discover_contract_assert($resultsAll['candidates_scanned'] === 3, 'candidates_scanned includes every type when filter null');
            semantic_discover_contract_assert($resultsAll['result_count'] === 3, 'result_count = 3 unfiltered');
            semantic_discover_contract_assert($resultsAll['results'][0]['service_id'] === 'svc-a' || $resultsAll['results'][0]['service_id'] === 'svc-c', 'top result is svc-a or svc-c (both aligned to query)');
            $rulesAsserted += 3;

            $scoped = model_inference_semantic_discover($pdo, $query, 'king.inference.v1', 5, 0.0);
            semantic_discover_contract_assert($scoped['candidates_scanned'] === 2, 'scoped scan sees only inference services');
            semantic_discover_contract_assert($scoped['result_count'] === 2, 'scoped result_count = 2');
            semantic_discover_contract_assert($scoped['results'][0]['service_id'] === 'svc-a', 'svc-a outranks svc-b under query');
            semantic_discover_contract_assert($scoped['results'][1]['service_id'] === 'svc-b', 'svc-b is second');
            semantic_discover_contract_assert($scoped['results'][0]['score'] > $scoped['results'][1]['score'], 'scores descending');
            $rulesAsserted += 5;

            // min_score filters low-scoring rows.
            $filtered = model_inference_semantic_discover($pdo, $query, 'king.inference.v1', 5, 0.5);
            semantic_discover_contract_assert($filtered['result_count'] === 1, 'min_score=0.5 drops svc-b');
            semantic_discover_contract_assert($filtered['results'][0]['service_id'] === 'svc-a', 'only svc-a survives');
            $rulesAsserted += 2;

            // top_k truncates.
            $truncated = model_inference_semantic_discover($pdo, $query, null, 1, 0.0);
            semantic_discover_contract_assert($truncated['result_count'] === 1, 'top_k=1 truncates');
            $rulesAsserted++;

            // Descriptor round-trip.
            semantic_discover_contract_assert(
                is_array($scoped['results'][0]['descriptor']) && ($scoped['results'][0]['descriptor']['name'] ?? null) === 'alpha',
                'descriptor.name round-trips in results'
            );
            $rulesAsserted++;
        }
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[semantic-discover-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[semantic-discover-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
