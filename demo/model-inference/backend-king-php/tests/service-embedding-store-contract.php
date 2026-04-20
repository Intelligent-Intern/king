<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/discovery/service_embedding_store.php';

function service_embedding_store_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[service-embedding-store-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    // 1. Function signatures exist.
    $requiredFunctions = [
        'model_inference_service_vector_generate_id',
        'model_inference_service_embedding_schema_migrate',
        'model_inference_service_embedding_store',
        'model_inference_service_embedding_load_row',
        'model_inference_service_vector_load',
        'model_inference_service_embedding_list',
        'model_inference_service_embedding_load_all',
        'model_inference_service_embedding_delete',
    ];
    foreach ($requiredFunctions as $fn) {
        service_embedding_store_contract_assert(function_exists($fn), "{$fn} must exist");
        $rulesAsserted++;
    }

    // 2. svec ID format.
    $sid = model_inference_service_vector_generate_id();
    service_embedding_store_contract_assert(
        preg_match('/^svec-[a-f0-9]{16}$/', $sid) === 1,
        'service vector ID must match svec-{16hex} (got ' . $sid . ')'
    );
    $rulesAsserted++;

    // 3. Two consecutive calls produce different IDs (entropy).
    $sid2 = model_inference_service_vector_generate_id();
    service_embedding_store_contract_assert($sid !== $sid2, 'generated service vector IDs must differ');
    $rulesAsserted++;

    // 4. Schema migration creates the service_embeddings table with correct columns.
    $dbPath = sys_get_temp_dir() . '/service-embedding-store-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_service_embedding_schema_migrate($pdo);

        $columns = $pdo->query('PRAGMA table_info(service_embeddings)')->fetchAll();
        $columnNames = array_column($columns, 'name');
        foreach ([
            'service_id', 'service_type', 'embedding_model_id', 'vector_id',
            'dimensions', 'object_store_key', 'descriptor_json', 'updated_at'
        ] as $col) {
            service_embedding_store_contract_assert(
                in_array($col, $columnNames, true),
                "service_embeddings table must have {$col} column"
            );
            $rulesAsserted++;
        }

        // 5. service_id is PRIMARY KEY.
        $pkCol = null;
        foreach ($columns as $c) {
            if ((int) $c['pk'] === 1) {
                $pkCol = $c['name'];
                break;
            }
        }
        service_embedding_store_contract_assert($pkCol === 'service_id', 'service_id must be PRIMARY KEY');
        $rulesAsserted++;

        // 6. Index on service_type exists.
        $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='service_embeddings'")->fetchAll();
        $indexNames = array_column($indexes, 'name');
        service_embedding_store_contract_assert(
            in_array('idx_service_embeddings_service_type', $indexNames, true),
            'idx_service_embeddings_service_type index must exist'
        );
        $rulesAsserted++;

        // 7. Idempotent migration.
        model_inference_service_embedding_schema_migrate($pdo);
        $columnsAfter = $pdo->query('PRAGMA table_info(service_embeddings)')->fetchAll();
        service_embedding_store_contract_assert(
            count($columnsAfter) === count($columns),
            'repeated migration must not duplicate columns'
        );
        $rulesAsserted++;

        // 8. Empty list on clean table.
        $empty = model_inference_service_embedding_list($pdo);
        service_embedding_store_contract_assert(
            is_array($empty) && count($empty) === 0,
            'list must return empty on clean table'
        );
        $rulesAsserted++;

        // 9. load_row returns null for unknown service_id.
        $missing = model_inference_service_embedding_load_row($pdo, 'svc-missing');
        service_embedding_store_contract_assert($missing === null, 'load_row must return null for unknown service_id');
        $rulesAsserted++;

        // 10. service_vector_load returns null when object-store not loaded.
        if (!function_exists('king_object_store_get')) {
            $loaded = model_inference_service_vector_load('svec-0000000000000000');
            service_embedding_store_contract_assert($loaded === null, 'service_vector_load returns null without object-store');
            $rulesAsserted++;
        }

        // 11. service_embedding_store rejects empty vectors.
        $descriptor = [
            'service_id' => 'svc-node-a',
            'service_type' => 'king.inference.v1',
            'name' => 'node-a',
            'description' => 'primary inference node',
            'capabilities' => [],
            'tags' => [],
        ];
        $rejected = false;
        try {
            model_inference_service_embedding_store($pdo, $descriptor, 'mdl-test', []);
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        }
        service_embedding_store_contract_assert($rejected, 'service_embedding_store must reject empty vector');
        $rulesAsserted++;

        // 12. Fails closed when object-store extension missing.
        if (!function_exists('king_object_store_put')) {
            $rejected2 = false;
            try {
                model_inference_service_embedding_store($pdo, $descriptor, 'mdl-test', [0.1, 0.2, 0.3]);
            } catch (RuntimeException $e) {
                $rejected2 = true;
                service_embedding_store_contract_assert(
                    str_contains($e->getMessage(), 'king_object_store_put not available'),
                    'expected fail-closed error when extension missing'
                );
                $rulesAsserted++;
            }
            service_embedding_store_contract_assert($rejected2, 'store must fail closed without extension');
            $rulesAsserted++;
        }

        // 13. list filtered by service_type only returns matching rows (prefill via raw insert so we don't need object-store).
        $pdo->exec("INSERT INTO service_embeddings (service_id, service_type, embedding_model_id, vector_id,
            dimensions, object_store_key, descriptor_json, updated_at) VALUES
            ('svc-a','king.inference.v1','mdl','svec-aaaaaaaaaaaaaaaa',4,'svec-aaaaaaaaaaaaaaaa','{}','2026-04-20T00:00:00+00:00'),
            ('svc-b','king.tool.v1','mdl','svec-bbbbbbbbbbbbbbbb',4,'svec-bbbbbbbbbbbbbbbb','{}','2026-04-20T00:00:00+00:00'),
            ('svc-c','king.inference.v1','mdl','svec-cccccccccccccccc',4,'svec-cccccccccccccccc','{}','2026-04-20T00:00:00+00:00')");

        $allRows = model_inference_service_embedding_list($pdo);
        service_embedding_store_contract_assert(count($allRows) === 3, 'list returns all rows when service_type null');
        $rulesAsserted++;

        $inference = model_inference_service_embedding_list($pdo, 'king.inference.v1');
        service_embedding_store_contract_assert(count($inference) === 2, 'list filtered to king.inference.v1 returns 2');
        foreach ($inference as $row) {
            service_embedding_store_contract_assert(
                $row['service_type'] === 'king.inference.v1',
                'filtered rows all have matching service_type'
            );
        }
        $rulesAsserted += 3;

        $tools = model_inference_service_embedding_list($pdo, 'king.tool.v1');
        service_embedding_store_contract_assert(count($tools) === 1, 'list filtered to king.tool.v1 returns 1');
        service_embedding_store_contract_assert($tools[0]['service_id'] === 'svc-b', 'tool row is svc-b');
        $rulesAsserted += 2;

        // 14. load_row returns parsed descriptor fields.
        $pdo->exec("UPDATE service_embeddings SET descriptor_json =
            '{\"service_id\":\"svc-a\",\"service_type\":\"king.inference.v1\",\"name\":\"alpha\",\"description\":\"primary inference\",\"capabilities\":[\"chat\"],\"tags\":[\"primary\"]}'
            WHERE service_id='svc-a'");
        $row = model_inference_service_embedding_load_row($pdo, 'svc-a');
        service_embedding_store_contract_assert($row !== null, 'load_row returns non-null');
        service_embedding_store_contract_assert($row['descriptor']['name'] === 'alpha', 'descriptor name round-trip');
        service_embedding_store_contract_assert($row['descriptor']['capabilities'] === ['chat'], 'descriptor capabilities round-trip');
        $rulesAsserted += 3;

        // 15. delete removes row and returns true when present, false when absent.
        $deleted = model_inference_service_embedding_delete($pdo, 'svc-b');
        service_embedding_store_contract_assert($deleted === true, 'delete returns true for existing row');
        $afterDelete = model_inference_service_embedding_load_row($pdo, 'svc-b');
        service_embedding_store_contract_assert($afterDelete === null, 'deleted row is gone');
        $deletedAgain = model_inference_service_embedding_delete($pdo, 'svc-b');
        service_embedding_store_contract_assert($deletedAgain === false, 'delete returns false for missing row');
        $rulesAsserted += 3;
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[service-embedding-store-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[service-embedding-store-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
