<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/discovery/graph_store.php';

function graph_store_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[graph-store-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    foreach ([
        'model_inference_graph_schema_migrate',
        'model_inference_graph_upsert_edge',
        'model_inference_graph_delete_edge',
        'model_inference_graph_list_outgoing',
        'model_inference_graph_traverse_outgoing',
        'model_inference_graph_count',
    ] as $fn) {
        graph_store_contract_assert(function_exists($fn), "{$fn} must exist");
        $rulesAsserted++;
    }

    $dbPath = sys_get_temp_dir() . '/graph-store-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_graph_schema_migrate($pdo);

        $cols = array_column($pdo->query('PRAGMA table_info(service_edges)')->fetchAll(), 'name');
        foreach (['edge_id', 'from_service_id', 'to_service_id', 'edge_type', 'attributes_json', 'created_at'] as $c) {
            graph_store_contract_assert(in_array($c, $cols, true), "service_edges column {$c} exists");
            $rulesAsserted++;
        }

        $indexes = array_column($pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='service_edges'")->fetchAll(), 'name');
        graph_store_contract_assert(in_array('idx_service_edges_unique', $indexes, true), 'unique index exists');
        graph_store_contract_assert(in_array('idx_service_edges_from', $indexes, true), 'from index exists');
        graph_store_contract_assert(in_array('idx_service_edges_to', $indexes, true), 'to index exists');
        graph_store_contract_assert(in_array('idx_service_edges_type', $indexes, true), 'type index exists');
        $rulesAsserted += 4;

        graph_store_contract_assert(model_inference_graph_count($pdo) === 0, 'empty graph count=0');
        graph_store_contract_assert(model_inference_graph_list_outgoing($pdo, 'none') === [], 'missing node has no outgoing');
        $rulesAsserted += 2;

        // Validation rejections.
        foreach ([
            ['', 'b', 'depends_on'],
            ['a', '', 'depends_on'],
            ['a', 'b', ''],
            [str_repeat('x', 129), 'b', 'depends_on'],
            ['a', str_repeat('x', 129), 'depends_on'],
            ['a', 'b', str_repeat('x', 65)],
            ['a', 'b', 'Invalid-Type'],     // uppercase rejected
            ['a', 'b', '1bad'],             // leading digit rejected
        ] as $case) {
            $rej = false;
            try {
                model_inference_graph_upsert_edge($pdo, $case[0], $case[1], $case[2]);
            } catch (InvalidArgumentException $e) {
                $rej = true;
            }
            graph_store_contract_assert($rej, 'reject invalid upsert: ' . implode('|', array_map(fn($v) => (string) $v, $case)));
            $rulesAsserted++;
        }

        // Valid upsert.
        $id = model_inference_graph_upsert_edge($pdo, 'svc-a', 'svc-b', 'depends_on', ['weight' => 0.5]);
        graph_store_contract_assert($id > 0, 'upsert returns positive id');
        $rulesAsserted++;

        // Upsert with same (from, to, type) updates attributes without creating a new row.
        $id2 = model_inference_graph_upsert_edge($pdo, 'svc-a', 'svc-b', 'depends_on', ['weight' => 0.9]);
        graph_store_contract_assert($id === $id2, 'duplicate upsert returns same edge_id');
        graph_store_contract_assert(model_inference_graph_count($pdo) === 1, 'count stays at 1 after re-upsert');
        $rulesAsserted += 2;

        $edges = model_inference_graph_list_outgoing($pdo, 'svc-a');
        graph_store_contract_assert(count($edges) === 1, 'list_outgoing returns 1 edge');
        graph_store_contract_assert($edges[0]['attributes']['weight'] === 0.9, 'attributes updated by upsert');
        graph_store_contract_assert($edges[0]['from_service_id'] === 'svc-a', 'from preserved');
        graph_store_contract_assert($edges[0]['to_service_id'] === 'svc-b', 'to preserved');
        graph_store_contract_assert($edges[0]['edge_type'] === 'depends_on', 'edge_type preserved');
        $rulesAsserted += 5;

        // Build a small graph:
        //   svc-a ─depends_on→ svc-b ─depends_on→ svc-c
        //   svc-a ─alternative_for→ svc-d
        model_inference_graph_upsert_edge($pdo, 'svc-b', 'svc-c', 'depends_on');
        model_inference_graph_upsert_edge($pdo, 'svc-a', 'svc-d', 'alternative_for');
        graph_store_contract_assert(model_inference_graph_count($pdo) === 3, 'graph has 3 edges');
        $rulesAsserted++;

        // One-hop traversal from svc-a — should reach b and d.
        $oneHop = model_inference_graph_traverse_outgoing($pdo, ['svc-a'], null, 1);
        sort($oneHop);
        graph_store_contract_assert($oneHop === ['svc-b', 'svc-d'], 'one-hop traversal unfiltered returns {b,d}');
        $rulesAsserted++;

        // One-hop filtered by depends_on — only b.
        $filtered = model_inference_graph_traverse_outgoing($pdo, ['svc-a'], ['depends_on'], 1);
        graph_store_contract_assert($filtered === ['svc-b'], 'filtered one-hop returns only {b}');
        $rulesAsserted++;

        // Two-hop from svc-a with depends_on — b then c.
        $twoHop = model_inference_graph_traverse_outgoing($pdo, ['svc-a'], ['depends_on'], 2);
        sort($twoHop);
        graph_store_contract_assert($twoHop === ['svc-b', 'svc-c'], 'two-hop depends_on returns {b,c}');
        $rulesAsserted++;

        // Seed includes node already visited — seeds are excluded from result.
        $excludeSeed = model_inference_graph_traverse_outgoing($pdo, ['svc-a', 'svc-b'], null, 1);
        sort($excludeSeed);
        graph_store_contract_assert($excludeSeed === ['svc-c', 'svc-d'], 'seeds excluded from traversal result');
        $rulesAsserted++;

        // max_hops = 0 returns empty.
        graph_store_contract_assert(model_inference_graph_traverse_outgoing($pdo, ['svc-a'], null, 0) === [], 'max_hops=0 returns []');
        $rulesAsserted++;

        // max_hops > 3 rejected.
        $rej = false;
        try {
            model_inference_graph_traverse_outgoing($pdo, ['svc-a'], null, 4);
        } catch (InvalidArgumentException $e) {
            $rej = true;
        }
        graph_store_contract_assert($rej, 'max_hops > 3 rejected');
        $rulesAsserted++;

        // Empty seed list returns empty.
        graph_store_contract_assert(model_inference_graph_traverse_outgoing($pdo, [], null, 2) === [], 'empty seed returns []');
        $rulesAsserted++;

        // Delete edge.
        graph_store_contract_assert(
            model_inference_graph_delete_edge($pdo, 'svc-a', 'svc-d', 'alternative_for') === true,
            'delete returns true on existing edge'
        );
        graph_store_contract_assert(
            model_inference_graph_delete_edge($pdo, 'svc-a', 'svc-d', 'alternative_for') === false,
            'delete returns false on missing edge'
        );
        graph_store_contract_assert(model_inference_graph_count($pdo) === 2, 'count=2 after delete');
        $rulesAsserted += 3;

        // Idempotent migration.
        model_inference_graph_schema_migrate($pdo);
        graph_store_contract_assert(model_inference_graph_count($pdo) === 2, 'remigrate preserves data');
        $rulesAsserted++;
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[graph-store-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[graph-store-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
