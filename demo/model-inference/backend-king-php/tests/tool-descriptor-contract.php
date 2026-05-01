<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/discovery/tool_descriptor_store.php';

function tool_descriptor_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[tool-descriptor-contract] FAIL: {$message}\n");
    exit(1);
}

/** @param array<string, mixed> $payload */
function tool_descriptor_contract_expect_reject(array $payload, string $label): void
{
    $rejected = false;
    try {
        model_inference_validate_tool_descriptor($payload);
    } catch (ToolDescriptorValidationError $e) {
        $rejected = true;
    }
    tool_descriptor_contract_assert($rejected, "must reject: {$label}");
}

try {
    $rulesAsserted = 0;

    $validTarget = ['host' => 'tools.local', 'port' => 9001, 'service' => 'weather', 'method' => 'lookup'];

    // 1. Valid minimal descriptor.
    $minimal = model_inference_validate_tool_descriptor([
        'tool_id' => 'tool-weather-lookup',
        'name' => 'weather lookup',
        'description' => 'returns temperature and forecast for a city',
        'mcp_target' => $validTarget,
    ]);
    tool_descriptor_contract_assert($minimal['tool_id'] === 'tool-weather-lookup', 'tool_id preserved');
    tool_descriptor_contract_assert($minimal['mcp_target']['port'] === 9001, 'port preserved');
    tool_descriptor_contract_assert($minimal['capabilities'] === [], 'capabilities default []');
    tool_descriptor_contract_assert($minimal['tags'] === [], 'tags default []');
    tool_descriptor_contract_assert($minimal['input_schema_ref'] === null, 'input_schema_ref default null');
    $rulesAsserted += 5;

    // 2. Validation rejections.
    tool_descriptor_contract_expect_reject([
        'name' => 'n', 'description' => 'd', 'mcp_target' => $validTarget,
    ], 'missing tool_id');
    tool_descriptor_contract_expect_reject([
        'tool_id' => 'a', 'description' => 'd', 'mcp_target' => $validTarget,
    ], 'missing name');
    tool_descriptor_contract_expect_reject([
        'tool_id' => 'a', 'name' => 'n', 'mcp_target' => $validTarget,
    ], 'missing description');
    tool_descriptor_contract_expect_reject([
        'tool_id' => 'a', 'name' => 'n', 'description' => 'd',
    ], 'missing mcp_target');
    tool_descriptor_contract_expect_reject([
        'tool_id' => 'a', 'name' => 'n', 'description' => 'd',
        'mcp_target' => ['host' => 'h', 'port' => 1, 'service' => 's'],
    ], 'mcp_target missing method');
    tool_descriptor_contract_expect_reject([
        'tool_id' => 'a', 'name' => 'n', 'description' => 'd',
        'mcp_target' => ['host' => 'h', 'port' => 0, 'service' => 's', 'method' => 'm'],
    ], 'port 0 rejected');
    tool_descriptor_contract_expect_reject([
        'tool_id' => 'a', 'name' => 'n', 'description' => 'd',
        'mcp_target' => ['host' => 'h', 'port' => 70000, 'service' => 's', 'method' => 'm'],
    ], 'port > 65535 rejected');
    tool_descriptor_contract_expect_reject([
        'tool_id' => 'a', 'name' => 'n', 'description' => 'd',
        'mcp_target' => ['host' => 'h', 'port' => '80', 'service' => 's', 'method' => 'm'],
    ], 'port string rejected');
    tool_descriptor_contract_expect_reject([
        'tool_id' => 'a', 'name' => 'n', 'description' => 'd', 'mcp_target' => $validTarget,
        'extra' => true,
    ], 'unknown top-level key');
    tool_descriptor_contract_expect_reject([
        'tool_id' => 'a', 'name' => 'n', 'description' => 'd',
        'mcp_target' => ['host' => 'h', 'port' => 1, 'service' => 's', 'method' => 'm', 'extra' => true],
    ], 'unknown mcp_target key');
    tool_descriptor_contract_expect_reject([
        'tool_id' => 'a', 'name' => 'n', 'description' => 'd', 'mcp_target' => $validTarget,
        'input_schema_ref' => str_repeat('x', 257),
    ], 'input_schema_ref too long');
    tool_descriptor_contract_expect_reject([
        'tool_id' => 'a', 'name' => 'n', 'description' => 'd', 'mcp_target' => $validTarget,
        'capabilities' => array_fill(0, 33, 'x'),
    ], 'too many capabilities');
    $rulesAsserted += 12;

    // 3. Non-array payload rejected.
    $rej = false;
    try {
        model_inference_validate_tool_descriptor('not-array');
    } catch (ToolDescriptorValidationError $e) {
        $rej = true;
    }
    tool_descriptor_contract_assert($rej, 'non-array payload rejected');
    $rulesAsserted++;

    // 4. Embedding text contains descriptor fields.
    $text = model_inference_tool_descriptor_embedding_text([
        'tool_id' => 'tool-weather',
        'name' => 'weather lookup',
        'description' => 'returns current temperature',
        'mcp_target' => $validTarget,
        'capabilities' => ['geocoding'],
        'tags' => ['external'],
    ]);
    foreach (['weather lookup', 'current temperature', 'geocoding', 'external'] as $expected) {
        tool_descriptor_contract_assert(str_contains($text, $expected), "embedding text contains '{$expected}'");
        $rulesAsserted++;
    }

    // 5. Tokenizer for tool descriptor.
    $tokens = model_inference_hybrid_tokenize_tool_descriptor([
        'name' => 'weather lookup',
        'description' => 'forecast tool',
        'capabilities' => ['geocoding'],
        'tags' => ['external'],
    ]);
    foreach (['weather', 'lookup', 'forecast', 'tool', 'geocoding', 'external'] as $expected) {
        tool_descriptor_contract_assert(in_array($expected, $tokens, true), "tool tokens contain '{$expected}'");
        $rulesAsserted++;
    }

    // 6. Schema migration + storage plumbing.
    $dbPath = sys_get_temp_dir() . '/tool-descriptor-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_tool_embedding_schema_migrate($pdo);

        $columns = $pdo->query('PRAGMA table_info(tool_embeddings)')->fetchAll();
        $columnNames = array_column($columns, 'name');
        foreach (['tool_id', 'embedding_model_id', 'vector_id', 'dimensions', 'object_store_key', 'descriptor_json', 'updated_at'] as $col) {
            tool_descriptor_contract_assert(
                in_array($col, $columnNames, true),
                "tool_embeddings has {$col}"
            );
            $rulesAsserted++;
        }

        // 7. ID format.
        $tid = model_inference_tool_vector_generate_id();
        tool_descriptor_contract_assert(
            preg_match('/^tvec-[a-f0-9]{16}$/', $tid) === 1,
            'tool vector ID matches tvec-{16hex}'
        );
        $rulesAsserted++;

        // 8. Empty list.
        tool_descriptor_contract_assert(
            model_inference_tool_embedding_list($pdo) === [],
            'empty table -> empty list'
        );
        $rulesAsserted++;

        // 9. load_row returns null for missing tool.
        tool_descriptor_contract_assert(
            model_inference_tool_embedding_load_row($pdo, 'missing') === null,
            'missing tool returns null'
        );
        $rulesAsserted++;

        // 10. Upsert rejects empty embedding_model_id.
        $rej = false;
        try {
            model_inference_tool_embedding_upsert($pdo, [
                'tool_id' => 't1', 'name' => 'n', 'description' => 'd', 'mcp_target' => $validTarget,
            ], '', static fn () => ['vector' => [0.1]]);
        } catch (InvalidArgumentException $e) {
            $rej = true;
        }
        tool_descriptor_contract_assert($rej, 'empty embedding_model_id rejected');
        $rulesAsserted++;

        // 11. Round-trip with object-store when available.
        if (function_exists('king_object_store_put') && function_exists('king_object_store_get')) {
            $result = model_inference_tool_embedding_upsert(
                $pdo,
                ['tool_id' => 't1', 'name' => 'weather', 'description' => 'weather forecast', 'mcp_target' => $validTarget],
                'mdl',
                static fn () => ['vector' => [1.0, 0.0, 0.0, 0.0], 'duration_ms' => 5, 'tokens_used' => 1]
            );
            tool_descriptor_contract_assert($result['tool_id'] === 't1', 'upsert returns tool_id');
            tool_descriptor_contract_assert($result['dimensions'] === 4, 'upsert dimensions');
            tool_descriptor_contract_assert($result['replaced'] === false, 'first insert not replaced');
            $rulesAsserted += 3;

            $loaded = model_inference_tool_embedding_load_all($pdo);
            tool_descriptor_contract_assert(count($loaded) === 1, 'load_all returns 1 row');
            tool_descriptor_contract_assert(count($loaded[0]['vector']) === 4, 'vector dimension preserved');
            tool_descriptor_contract_assert($loaded[0]['descriptor']['mcp_target']['port'] === 9001, 'mcp_target round-trip');
            $rulesAsserted += 3;

            $deleted = model_inference_tool_embedding_delete($pdo, 't1');
            tool_descriptor_contract_assert($deleted === true, 'delete returns true');
            tool_descriptor_contract_assert(model_inference_tool_embedding_load_row($pdo, 't1') === null, 'deleted row gone');
            $rulesAsserted += 2;
        }

        // 12. Contract JSON fixture exists and matches the allowed keys.
        $contractPath = __DIR__ . '/../../contracts/v1/tool-descriptor.contract.json';
        tool_descriptor_contract_assert(is_file($contractPath), 'contract JSON exists');
        $contract = json_decode((string) file_get_contents($contractPath), true);
        tool_descriptor_contract_assert(is_array($contract), 'contract JSON parses');
        tool_descriptor_contract_assert($contract['contract_name'] === 'king-model-inference-tool-descriptor', 'contract_name matches');
        $envelopeKeys = array_keys($contract['request_envelope']);
        sort($envelopeKeys);
        $allowed = model_inference_tool_descriptor_allowed_top_level_keys();
        sort($allowed);
        tool_descriptor_contract_assert($envelopeKeys === $allowed, 'envelope keys match allowed_top_level_keys');
        $rulesAsserted += 4;
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[tool-descriptor-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[tool-descriptor-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
