<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/registry/model_registry.php';

function embedding_registry_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[embedding-model-registry-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    // 1. model_type is an allowed model type.
    $allowedTypes = model_inference_registry_allowed_model_types();
    embedding_registry_contract_assert(
        in_array('chat', $allowedTypes, true),
        'chat must be an allowed model type'
    );
    embedding_registry_contract_assert(
        in_array('embedding', $allowedTypes, true),
        'embedding must be an allowed model type'
    );

    // 2. Validation accepts model_type=embedding.
    $validEmbedding = model_inference_registry_validate_metadata([
        'model_name' => 'nomic-embed-text-v1.5',
        'family' => 'nomic-embed',
        'parameter_count' => 137000000,
        'quantization' => 'Q8_0',
        'context_length' => 2048,
        'license' => 'apache-2.0',
        'min_ram_bytes' => 268435456,
        'model_type' => 'embedding',
    ]);
    embedding_registry_contract_assert(
        $validEmbedding['model_type'] === 'embedding',
        'validated metadata must preserve model_type=embedding'
    );

    // 3. Validation defaults model_type to chat.
    $defaultChat = model_inference_registry_validate_metadata([
        'model_name' => 'SmolLM2-135M-Instruct',
        'family' => 'smollm2',
        'parameter_count' => 135000000,
        'quantization' => 'Q4_K',
        'context_length' => 2048,
        'license' => 'apache-2.0',
        'min_ram_bytes' => 268435456,
    ]);
    embedding_registry_contract_assert(
        $defaultChat['model_type'] === 'chat',
        'omitted model_type must default to chat'
    );

    // 4. Validation rejects invalid model_type.
    $rejectedInvalid = false;
    try {
        model_inference_registry_validate_metadata([
            'model_name' => 'test',
            'family' => 'test',
            'parameter_count' => 1,
            'quantization' => 'Q4_K',
            'context_length' => 1,
            'license' => 'mit',
            'min_ram_bytes' => 1,
            'model_type' => 'speech',
        ]);
    } catch (InvalidArgumentException $e) {
        if (str_contains($e->getMessage(), 'model_type')) {
            $rejectedInvalid = true;
        }
    }
    embedding_registry_contract_assert($rejectedInvalid, 'invalid model_type must be rejected');

    // 5. Schema migration adds model_type column.
    $dbPath = sys_get_temp_dir() . '/embedding-registry-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_registry_schema_migrate($pdo);

        $columns = $pdo->query('PRAGMA table_info(models)')->fetchAll();
        $columnNames = array_column($columns, 'name');
        embedding_registry_contract_assert(
            in_array('model_type', $columnNames, true),
            'models table must have model_type column after migration'
        );

        // 6. Row envelope includes model_type.
        $envelope = model_inference_registry_row_to_envelope([
            'model_id' => 'mdl-test',
            'model_name' => 'test-embed',
            'family' => 'test',
            'parameter_count' => 100,
            'quantization' => 'Q8_0',
            'context_length' => 512,
            'model_type' => 'embedding',
            'object_store_key' => 'mdl-test',
            'byte_length' => 1000,
            'sha256_hex' => str_repeat('a', 64),
            'uploaded_at' => gmdate('c'),
            'license' => 'mit',
            'source_url' => null,
            'min_ram_bytes' => 1000,
            'min_vram_bytes' => 0,
            'prefers_gpu' => 0,
            'registered_at' => gmdate('c'),
        ]);
        embedding_registry_contract_assert(
            ($envelope['model_type'] ?? null) === 'embedding',
            'row_to_envelope must include model_type'
        );

        // 7. list_by_type and find_embedding_model functions exist.
        embedding_registry_contract_assert(
            function_exists('model_inference_registry_list_by_type'),
            'model_inference_registry_list_by_type must exist'
        );
        embedding_registry_contract_assert(
            function_exists('model_inference_registry_find_embedding_model'),
            'model_inference_registry_find_embedding_model must exist'
        );

        // 8. Default model_type for rows without the column is chat.
        $envelopeDefault = model_inference_registry_row_to_envelope([
            'model_id' => 'mdl-test2',
            'model_name' => 'test-chat',
            'family' => 'test',
            'parameter_count' => 100,
            'quantization' => 'Q4_K',
            'context_length' => 512,
            'object_store_key' => 'mdl-test2',
            'byte_length' => 1000,
            'sha256_hex' => str_repeat('b', 64),
            'uploaded_at' => gmdate('c'),
            'license' => 'mit',
            'source_url' => null,
            'min_ram_bytes' => 1000,
            'min_vram_bytes' => 0,
            'prefers_gpu' => 0,
            'registered_at' => gmdate('c'),
        ]);
        embedding_registry_contract_assert(
            ($envelopeDefault['model_type'] ?? null) === 'chat',
            'row_to_envelope must default model_type to chat for legacy rows'
        );
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[embedding-model-registry-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[embedding-model-registry-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
