<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/discovery/graph_store.php';
require_once __DIR__ . '/../domain/discovery/graph_expand.php';
require_once __DIR__ . '/../domain/discovery/service_embedding_store.php';

function graph_expand_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[graph-expand-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    graph_expand_contract_assert(
        function_exists('model_inference_graph_expand_results'),
        'model_inference_graph_expand_results must exist'
    );
    $rulesAsserted++;

    $dbPath = sys_get_temp_dir() . '/graph-expand-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_graph_schema_migrate($pdo);
        model_inference_service_embedding_schema_migrate($pdo);

        // Empty graph — expansion is a no-op that preserves input.
        $ranked = [
            ['service_id' => 'svc-a', 'score' => 0.9, 'descriptor' => ['name' => 'A']],
            ['service_id' => 'svc-b', 'score' => 0.7, 'descriptor' => ['name' => 'B']],
        ];
        $enriched = model_inference_graph_expand_results($pdo, $ranked, null, 1);
        graph_expand_contract_assert($enriched['results'] === $ranked, 'core results preserved verbatim');
        graph_expand_contract_assert($enriched['expanded'] === [], 'empty graph -> no expansion');
        graph_expand_contract_assert($enriched['neighbor_count'] === 0, 'neighbor_count=0');
        graph_expand_contract_assert($enriched['hops'] === 1, 'hops echoed');
        $rulesAsserted += 4;

        // Seed some edges.
        model_inference_graph_upsert_edge($pdo, 'svc-a', 'svc-x', 'depends_on');
        model_inference_graph_upsert_edge($pdo, 'svc-a', 'svc-y', 'alternative_for');
        model_inference_graph_upsert_edge($pdo, 'svc-x', 'svc-z', 'depends_on');

        // Also register svc-x and svc-z in service_embeddings so we verify
        // descriptor lookup during expansion.
        $pdo->exec("INSERT INTO service_embeddings (service_id, service_type, embedding_model_id, vector_id,
            dimensions, object_store_key, descriptor_json, updated_at) VALUES
            ('svc-x','king.inference.v1','mdl','svec-xxxxxxxxxxxxxxxx',4,'svec-xxxxxxxxxxxxxxxx','{\"name\":\"X\",\"service_type\":\"king.inference.v1\"}','2026-04-20T00:00:00+00:00')");

        $oneHop = model_inference_graph_expand_results($pdo, [['service_id' => 'svc-a']], null, 1);
        graph_expand_contract_assert($oneHop['neighbor_count'] === 2, 'one-hop returns 2 neighbors (x and y)');
        $sids = array_map(fn($n) => $n['service_id'], $oneHop['expanded']);
        sort($sids);
        graph_expand_contract_assert($sids === ['svc-x', 'svc-y'], 'neighbors are svc-x and svc-y');
        foreach ($oneHop['expanded'] as $n) {
            graph_expand_contract_assert($n['source'] === 'graph_expand', 'each neighbor tagged source=graph_expand');
            graph_expand_contract_assert($n['semantic_score'] === null, 'each neighbor semantic_score=null');
            $rulesAsserted += 2;
        }
        $rulesAsserted += 2;

        // Descriptor round-trip: svc-x has a row in service_embeddings, svc-y does not.
        $byId = [];
        foreach ($oneHop['expanded'] as $n) {
            $byId[$n['service_id']] = $n;
        }
        graph_expand_contract_assert($byId['svc-x']['service_type'] === 'king.inference.v1', 'svc-x service_type rehydrated');
        graph_expand_contract_assert(is_array($byId['svc-x']['descriptor']) && ($byId['svc-x']['descriptor']['name'] ?? null) === 'X', 'svc-x descriptor rehydrated');
        graph_expand_contract_assert($byId['svc-y']['service_type'] === null, 'svc-y unknown service_type (no embedding row)');
        graph_expand_contract_assert($byId['svc-y']['descriptor'] === null, 'svc-y descriptor null');
        $rulesAsserted += 4;

        // Filter by edge_type narrows.
        $onlyDepends = model_inference_graph_expand_results($pdo, [['service_id' => 'svc-a']], ['depends_on'], 1);
        graph_expand_contract_assert($onlyDepends['neighbor_count'] === 1, 'filtered to depends_on returns 1 neighbor');
        graph_expand_contract_assert($onlyDepends['expanded'][0]['service_id'] === 'svc-x', 'filtered neighbor is svc-x');
        $rulesAsserted += 2;

        // Two-hop.
        $twoHop = model_inference_graph_expand_results($pdo, [['service_id' => 'svc-a']], ['depends_on'], 2);
        $sids = array_map(fn($n) => $n['service_id'], $twoHop['expanded']);
        sort($sids);
        graph_expand_contract_assert($sids === ['svc-x', 'svc-z'], 'two-hop depends_on returns {x,z}');
        $rulesAsserted++;

        // max_hops=0 is a no-op (contract: core result preserved, no expansion).
        $zero = model_inference_graph_expand_results($pdo, [['service_id' => 'svc-a']], null, 0);
        graph_expand_contract_assert($zero['neighbor_count'] === 0, 'max_hops=0 -> neighbor_count=0');
        graph_expand_contract_assert($zero['results'] === [['service_id' => 'svc-a']], 'core results preserved');
        $rulesAsserted += 2;

        // Seeds (items already in core results) are never duplicated into expanded.
        $redundant = model_inference_graph_expand_results(
            $pdo,
            [['service_id' => 'svc-a'], ['service_id' => 'svc-x']],
            null,
            1
        );
        $sids = array_map(fn($n) => $n['service_id'], $redundant['expanded']);
        graph_expand_contract_assert(!in_array('svc-a', $sids, true), 'seed svc-a not duplicated into expanded');
        graph_expand_contract_assert(!in_array('svc-x', $sids, true), 'seed svc-x not duplicated into expanded');
        $rulesAsserted += 2;

        // Contract boundary assertion (W.9): the core `results` array is
        // bit-identical in shape across the WITH-expand and WITHOUT-expand
        // paths. Only `expanded`/`expanded_count`/`graph_expand` is new.
        $coreBefore = model_inference_graph_expand_results($pdo, $ranked, null, 1)['results'];
        graph_expand_contract_assert($coreBefore === $ranked, 'graph_expand never mutates core results');
        $rulesAsserted++;
    } finally {
        @unlink($dbPath);
    }

    // Contract JSON fixture.
    $contractPath = __DIR__ . '/../../contracts/v1/service-graph.contract.json';
    graph_expand_contract_assert(is_file($contractPath), 'service-graph contract JSON exists');
    $contract = json_decode((string) file_get_contents($contractPath), true);
    graph_expand_contract_assert(is_array($contract), 'contract parses');
    graph_expand_contract_assert($contract['contract_name'] === 'king-model-inference-service-graph', 'contract_name pinned');
    graph_expand_contract_assert(isset($contract['boundary']['core_surface']), 'boundary.core_surface documented');
    graph_expand_contract_assert(isset($contract['boundary']['optional_extension']), 'boundary.optional_extension documented');
    $rulesAsserted += 5;

    fwrite(STDOUT, "[graph-expand-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[graph-expand-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
