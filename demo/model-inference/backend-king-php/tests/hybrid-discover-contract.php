<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/discovery/hybrid_discover.php';

function hybrid_discover_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[hybrid-discover-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    foreach ([
        'model_inference_hybrid_discover',
        'model_inference_hybrid_tokenize',
        'model_inference_hybrid_tokenize_descriptor',
        'model_inference_hybrid_bm25_score',
        'model_inference_hybrid_minmax_normalize',
    ] as $fn) {
        hybrid_discover_contract_assert(function_exists($fn), "{$fn} must exist");
        $rulesAsserted++;
    }

    // Tokenizer behavior.
    $tokens = model_inference_hybrid_tokenize('Hello, WORLD! foo_bar  baz.');
    hybrid_discover_contract_assert($tokens === ['hello', 'world', 'foo', 'bar', 'baz'], 'lower + split + drop 1-char');
    $rulesAsserted++;
    hybrid_discover_contract_assert(model_inference_hybrid_tokenize('') === [], 'empty input returns empty');
    $rulesAsserted++;
    hybrid_discover_contract_assert(model_inference_hybrid_tokenize('a 1 ab 12') === ['ab', '12'], 'drops 1-char tokens but keeps 2-char');
    $rulesAsserted++;

    // Descriptor tokenizer includes all four fields.
    $descTokens = model_inference_hybrid_tokenize_descriptor([
        'name' => 'weather service',
        'description' => 'lookup forecast',
        'capabilities' => ['geocoding'],
        'tags' => ['external'],
    ]);
    foreach (['weather', 'service', 'lookup', 'forecast', 'geocoding', 'external'] as $expected) {
        hybrid_discover_contract_assert(in_array($expected, $descTokens, true), "descriptor tokens contain '{$expected}'");
        $rulesAsserted++;
    }

    // Min-max normalization.
    $norm = model_inference_hybrid_minmax_normalize([1.0, 2.0, 3.0]);
    hybrid_discover_contract_assert(abs($norm[0] - 0.0) < 1e-9 && abs($norm[1] - 0.5) < 1e-9 && abs($norm[2] - 1.0) < 1e-9, 'min-max 1,2,3 -> 0,0.5,1');
    $rulesAsserted++;
    $allEqual = model_inference_hybrid_minmax_normalize([0.7, 0.7, 0.7]);
    hybrid_discover_contract_assert($allEqual === [0.0, 0.0, 0.0], 'all-equal collapses to zeros');
    $rulesAsserted++;
    hybrid_discover_contract_assert(model_inference_hybrid_minmax_normalize([]) === [], 'empty input returns empty');
    $rulesAsserted++;

    // BM25 score sanity: identical query and doc -> positive; disjoint -> 0.
    $df = ['hello' => 1, 'world' => 1];
    $score = model_inference_hybrid_bm25_score(['hello', 'world'], ['hello', 'world'], $df, 1, 2.0, 1.2, 0.75);
    hybrid_discover_contract_assert($score > 0.0, 'BM25 overlap score is positive');
    $rulesAsserted++;
    $zero = model_inference_hybrid_bm25_score(['alpha'], ['beta'], ['beta' => 1], 1, 1.0, 1.2, 0.75);
    hybrid_discover_contract_assert($zero === 0.0, 'BM25 disjoint query/doc returns 0');
    $rulesAsserted++;

    // Argument validation.
    $dbPath = sys_get_temp_dir() . '/hybrid-discover-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_service_embedding_schema_migrate($pdo);

        $rejections = [
            ['topK', 0, 0.0, 0.5],
            ['minScore-hi', 5, 1.5, 0.5],
            ['minScore-lo', 5, -0.1, 0.5],
            ['alpha-hi', 5, 0.0, 1.5],
            ['alpha-lo', 5, 0.0, -0.1],
        ];
        foreach ($rejections as $case) {
            [$label, $topK, $minScore, $alpha] = $case;
            $rejected = false;
            try {
                model_inference_hybrid_discover($pdo, [0.1, 0.2], 'q', null, $topK, $minScore, $alpha);
            } catch (InvalidArgumentException $e) {
                $rejected = true;
            }
            hybrid_discover_contract_assert($rejected, "must reject: {$label}");
            $rulesAsserted++;
        }

        // Empty table returns stable-shape empty.
        $empty = model_inference_hybrid_discover($pdo, [0.1, 0.2, 0.3, 0.4], 'weather', null, 5, 0.0, 0.5);
        hybrid_discover_contract_assert($empty['result_count'] === 0, 'empty table -> zero results');
        hybrid_discover_contract_assert($empty['candidates_scanned'] === 0, 'empty table -> zero candidates');
        hybrid_discover_contract_assert($empty['search_strategy'] === 'hybrid_cosine_bm25', 'strategy label');
        hybrid_discover_contract_assert(abs($empty['bm25_k1'] - 1.2) < 1e-9, 'bm25_k1 = 1.2');
        hybrid_discover_contract_assert(abs($empty['bm25_b'] - 0.75) < 1e-9, 'bm25_b = 0.75');
        hybrid_discover_contract_assert($empty['alpha'] === 0.5, 'alpha echoed');
        $rulesAsserted += 6;

        // With object-store available, exercise full ranking.
        if (function_exists('king_object_store_put') && function_exists('king_object_store_get')) {
            require_once __DIR__ . '/../domain/discovery/service_embedding_upsert.php';

            $pdo->exec('DELETE FROM service_embeddings');

            $services = [
                [
                    'descriptor' => [
                        'service_id' => 'svc-weather',
                        'service_type' => 'king.tool.v1',
                        'name' => 'weather lookup',
                        'description' => 'returns current temperature and forecast for a city',
                        'capabilities' => ['geocoding', 'forecast'],
                    ],
                    'vector' => [1.0, 0.0, 0.0, 0.0],
                ],
                [
                    'descriptor' => [
                        'service_id' => 'svc-stocks',
                        'service_type' => 'king.tool.v1',
                        'name' => 'stock quotes',
                        'description' => 'fetches market quotes by ticker symbol',
                        'capabilities' => ['finance'],
                    ],
                    'vector' => [0.0, 1.0, 0.0, 0.0],
                ],
                [
                    'descriptor' => [
                        'service_id' => 'svc-calendar',
                        'service_type' => 'king.tool.v1',
                        'name' => 'calendar',
                        'description' => 'schedule events and meetings',
                        'capabilities' => ['scheduling'],
                    ],
                    'vector' => [0.0, 0.0, 1.0, 0.0],
                ],
            ];
            foreach ($services as $s) {
                model_inference_service_embedding_upsert($pdo, $s['descriptor'], 'mdl', static fn () => ['vector' => $s['vector']]);
            }

            // Query matches "weather forecast" strongly on BM25 (weather/forecast tokens)
            // and vector [1,0,0,0] matches svc-weather cosinely.
            $query = [1.0, 0.0, 0.0, 0.0];
            $queryText = 'weather forecast';

            // alpha = 1.0 -> pure semantic.
            $pureSem = model_inference_hybrid_discover($pdo, $query, $queryText, null, 5, 0.0, 1.0);
            hybrid_discover_contract_assert($pureSem['results'][0]['service_id'] === 'svc-weather', 'pure-semantic still picks weather (vector alignment)');
            $rulesAsserted++;

            // alpha = 0.0 -> pure BM25.
            $pureBm = model_inference_hybrid_discover($pdo, $query, $queryText, null, 5, 0.0, 0.0);
            hybrid_discover_contract_assert($pureBm['results'][0]['service_id'] === 'svc-weather', 'pure-BM25 picks weather (token overlap)');
            $rulesAsserted++;

            // Middle alpha: weather should still win — it's strong in both dimensions.
            $hybrid = model_inference_hybrid_discover($pdo, $query, $queryText, null, 5, 0.0, 0.5);
            hybrid_discover_contract_assert($hybrid['results'][0]['service_id'] === 'svc-weather', 'hybrid picks weather');
            hybrid_discover_contract_assert(array_key_exists('semantic_score', $hybrid['results'][0]), 'result carries semantic_score');
            hybrid_discover_contract_assert(array_key_exists('keyword_score', $hybrid['results'][0]), 'result carries keyword_score');
            hybrid_discover_contract_assert(array_key_exists('semantic_raw', $hybrid['results'][0]), 'result carries semantic_raw');
            hybrid_discover_contract_assert(array_key_exists('keyword_raw', $hybrid['results'][0]), 'result carries keyword_raw');
            $rulesAsserted += 5;

            // Pure-BM25 with a query that has zero token overlap -> all raw BM25 scores are 0
            // (min-max normalization collapses to 0), so every candidate ties at 0.0 and they
            // come back sorted by service_id ascending (deterministic tie-break).
            $noOverlap = model_inference_hybrid_discover($pdo, [0.5, 0.5, 0.0, 0.0], 'zzzzz qqqqq', null, 5, 0.0, 0.0);
            hybrid_discover_contract_assert($noOverlap['result_count'] === 3, 'no-overlap BM25 returns all 3 at score 0');
            hybrid_discover_contract_assert($noOverlap['results'][0]['score'] === 0.0, 'no-overlap top score is 0');
            hybrid_discover_contract_assert(
                $noOverlap['results'][0]['service_id'] === 'svc-calendar',
                'tie-break is ascending service_id'
            );
            $rulesAsserted += 3;

            // min_score filters fused scores.
            $filtered = model_inference_hybrid_discover($pdo, $query, $queryText, null, 5, 0.5, 0.5);
            foreach ($filtered['results'] as $r) {
                hybrid_discover_contract_assert($r['score'] >= 0.5, 'all surviving results >= min_score');
            }
            $rulesAsserted++;

            // top_k truncates.
            $truncated = model_inference_hybrid_discover($pdo, $query, $queryText, null, 1, 0.0, 0.5);
            hybrid_discover_contract_assert($truncated['result_count'] === 1, 'top_k=1 truncates to 1');
            $rulesAsserted++;

            // service_type filter scopes candidates.
            $noMatch = model_inference_hybrid_discover($pdo, $query, $queryText, 'king.nonexistent.v1', 5, 0.0, 0.5);
            hybrid_discover_contract_assert($noMatch['candidates_scanned'] === 0, 'nonexistent service_type scans 0');
            $rulesAsserted++;
        }
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[hybrid-discover-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[hybrid-discover-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
