<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/retrieval/document_store.php';
require_once __DIR__ . '/../domain/retrieval/text_chunker.php';
require_once __DIR__ . '/../domain/retrieval/vector_store.php';

function vector_store_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[vector-store-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    // 1. Function signatures exist.
    $requiredFunctions = [
        'model_inference_vector_generate_id',
        'model_inference_vector_schema_migrate',
        'model_inference_vector_store',
        'model_inference_vector_load',
        'model_inference_vector_list_by_document',
        'model_inference_vector_load_all_for_document',
        'model_inference_vector_load_all',
    ];
    foreach ($requiredFunctions as $fn) {
        vector_store_contract_assert(function_exists($fn), "{$fn} must exist");
    }

    // 2. Vector ID format.
    $vid = model_inference_vector_generate_id();
    vector_store_contract_assert(
        preg_match('/^vec-[a-f0-9]{16}$/', $vid) === 1,
        'vector ID must match vec-{16hex} (got ' . $vid . ')'
    );

    // 3. Schema migration creates vectors table with correct columns.
    $dbPath = sys_get_temp_dir() . '/vector-store-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_document_schema_migrate($pdo);
        model_inference_chunk_schema_migrate($pdo);
        model_inference_vector_schema_migrate($pdo);

        $columns = $pdo->query('PRAGMA table_info(vectors)')->fetchAll();
        $columnNames = array_column($columns, 'name');
        foreach (['vector_id', 'chunk_id', 'document_id', 'embedding_model_id', 'dimensions', 'object_store_key', 'created_at'] as $col) {
            vector_store_contract_assert(
                in_array($col, $columnNames, true),
                "vectors table must have {$col} column"
            );
        }

        // 4. Indexes exist.
        $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='vectors'")->fetchAll();
        $indexNames = array_column($indexes, 'name');
        vector_store_contract_assert(
            in_array('idx_vectors_document_id', $indexNames, true),
            'idx_vectors_document_id index must exist'
        );
        vector_store_contract_assert(
            in_array('idx_vectors_chunk_id', $indexNames, true),
            'idx_vectors_chunk_id index must exist'
        );

        // 5. list_by_document returns empty on clean DB.
        $empty = model_inference_vector_list_by_document($pdo, 'doc-0000000000000000');
        vector_store_contract_assert(
            is_array($empty) && count($empty) === 0,
            'list_by_document must return empty on clean DB'
        );

        // 6. vector_load returns null when object store unavailable.
        if (!function_exists('king_object_store_get')) {
            $loaded = model_inference_vector_load('vec-0000000000000000');
            vector_store_contract_assert($loaded === null, 'vector_load must return null when object store unavailable');
        }

        // 7. vector_store rejects empty vectors.
        $rejected = false;
        try {
            model_inference_vector_store($pdo, 'chk-test0001-0000', 'doc-0000000000000001', 'mdl-test', []);
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        }
        vector_store_contract_assert($rejected, 'vector_store must reject empty vectors');

        // 8. Idempotent migration.
        model_inference_vector_schema_migrate($pdo);
        $columnsAfter = $pdo->query('PRAGMA table_info(vectors)')->fetchAll();
        vector_store_contract_assert(
            count($columnsAfter) === count($columns),
            'repeated migration must not duplicate columns'
        );
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[vector-store-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[vector-store-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
