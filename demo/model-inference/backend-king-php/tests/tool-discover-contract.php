<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/discovery/tool_discover.php';

function tool_discover_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[tool-discover-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    foreach (['model_inference_tool_semantic_discover', 'model_inference_tool_hybrid_discover'] as $fn) {
        tool_discover_contract_assert(function_exists($fn), "{$fn} must exist");
        $rulesAsserted++;
    }

    $dbPath = sys_get_temp_dir() . '/tool-discover-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_tool_embedding_schema_migrate($pdo);

        // Empty-table shape assertions.
        $emptySem = model_inference_tool_semantic_discover($pdo, [0.1, 0.2, 0.3, 0.4], 5, 0.0);
        tool_discover_contract_assert($emptySem['result_count'] === 0, 'empty semantic result_count');
        tool_discover_contract_assert($emptySem['candidates_scanned'] === 0, 'empty semantic candidates_scanned');
        tool_discover_contract_assert($emptySem['search_strategy'] === 'brute_force_cosine', 'empty semantic strategy');
        $rulesAsserted += 3;

        $emptyHyb = model_inference_tool_hybrid_discover($pdo, [0.1, 0.2], 'q', 5, 0.0, 0.5);
        tool_discover_contract_assert($emptyHyb['search_strategy'] === 'hybrid_cosine_bm25', 'hybrid strategy');
        tool_discover_contract_assert($emptyHyb['alpha'] === 0.5, 'alpha echoed');
        tool_discover_contract_assert(abs($emptyHyb['bm25_k1'] - 1.2) < 1e-9, 'bm25_k1 = 1.2');
        tool_discover_contract_assert(abs($emptyHyb['bm25_b'] - 0.75) < 1e-9, 'bm25_b = 0.75');
        $rulesAsserted += 4;

        // Validation errors.
        $rej = false;
        try {
            model_inference_tool_semantic_discover($pdo, [0.1], 0, 0.0);
        } catch (InvalidArgumentException $e) {
            $rej = true;
        }
        tool_discover_contract_assert($rej, 'top_k 0 rejected (semantic)');
        $rulesAsserted++;

        $rej = false;
        try {
            model_inference_tool_hybrid_discover($pdo, [0.1], 'q', 5, 0.0, -0.1);
        } catch (InvalidArgumentException $e) {
            $rej = true;
        }
        tool_discover_contract_assert($rej, 'alpha -0.1 rejected (hybrid)');
        $rulesAsserted++;

        if (function_exists('king_object_store_put') && function_exists('king_object_store_get')) {
            $pdo->exec('DELETE FROM tool_embeddings');

            $tools = [
                [
                    'tool_id' => 't-weather',
                    'name' => 'weather lookup',
                    'description' => 'current temperature and forecast for a city',
                    'mcp_target' => ['host' => 'tools.local', 'port' => 9001, 'service' => 'weather', 'method' => 'lookup'],
                    'capabilities' => ['geocoding'],
                ],
                [
                    'tool_id' => 't-stocks',
                    'name' => 'stock quotes',
                    'description' => 'market ticker price lookup',
                    'mcp_target' => ['host' => 'tools.local', 'port' => 9002, 'service' => 'stocks', 'method' => 'quote'],
                ],
                [
                    'tool_id' => 't-calendar',
                    'name' => 'calendar',
                    'description' => 'schedule events',
                    'mcp_target' => ['host' => 'tools.local', 'port' => 9003, 'service' => 'cal', 'method' => 'schedule'],
                ],
            ];
            $vecs = [
                't-weather' => [1.0, 0.0, 0.0, 0.0],
                't-stocks' => [0.0, 1.0, 0.0, 0.0],
                't-calendar' => [0.0, 0.0, 1.0, 0.0],
            ];
            foreach ($tools as $d) {
                model_inference_tool_embedding_upsert($pdo, $d, 'mdl', static fn () => ['vector' => $vecs[$d['tool_id']]]);
            }

            $query = [1.0, 0.0, 0.0, 0.0];

            // Semantic: weather wins cosinely.
            $sem = model_inference_tool_semantic_discover($pdo, $query, 5, 0.0);
            tool_discover_contract_assert($sem['results'][0]['tool_id'] === 't-weather', 'semantic picks t-weather');
            tool_discover_contract_assert($sem['results'][0]['mcp_target']['port'] === 9001, 'semantic preserves mcp_target');
            tool_discover_contract_assert($sem['results'][0]['score'] > $sem['results'][1]['score'], 'semantic scores descending');
            tool_discover_contract_assert($sem['candidates_scanned'] === 3, 'semantic scans 3');
            $rulesAsserted += 4;

            // Hybrid: weather wins on both axes.
            $hyb = model_inference_tool_hybrid_discover($pdo, $query, 'weather forecast', 5, 0.0, 0.5);
            tool_discover_contract_assert($hyb['results'][0]['tool_id'] === 't-weather', 'hybrid picks t-weather');
            tool_discover_contract_assert(is_array($hyb['results'][0]['mcp_target']), 'hybrid preserves mcp_target');
            tool_discover_contract_assert(array_key_exists('semantic_score', $hyb['results'][0]), 'hybrid has semantic_score');
            tool_discover_contract_assert(array_key_exists('keyword_score', $hyb['results'][0]), 'hybrid has keyword_score');
            $rulesAsserted += 4;

            // min_score trims.
            $filtered = model_inference_tool_semantic_discover($pdo, $query, 5, 0.5);
            tool_discover_contract_assert($filtered['result_count'] === 1, 'min_score=0.5 keeps only t-weather');
            $rulesAsserted++;

            // top_k trims.
            $trunc = model_inference_tool_hybrid_discover($pdo, $query, 'weather', 1, 0.0, 0.5);
            tool_discover_contract_assert($trunc['result_count'] === 1, 'top_k=1 truncates');
            $rulesAsserted++;
        }
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[tool-discover-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[tool-discover-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
