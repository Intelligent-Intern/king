<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/discovery/mcp_pick.php';

function mcp_pick_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[mcp-pick-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    mcp_pick_contract_assert(function_exists('model_inference_mcp_pick'), 'model_inference_mcp_pick exists');
    mcp_pick_contract_assert(class_exists(McpPickNoMatchException::class), 'McpPickNoMatchException exists');
    $rulesAsserted += 2;

    $dbPath = sys_get_temp_dir() . '/mcp-pick-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_tool_embedding_schema_migrate($pdo);

        // Invalid mode rejected.
        $rej = false;
        try {
            model_inference_mcp_pick($pdo, [0.1], 'q', 'keyword');
        } catch (InvalidArgumentException $e) {
            $rej = true;
        }
        mcp_pick_contract_assert($rej, 'mode=keyword rejected (pick only supports semantic|hybrid)');
        $rulesAsserted++;

        // Empty registry -> McpPickNoMatchException.
        $rej = false;
        $caught = null;
        try {
            model_inference_mcp_pick($pdo, [0.1, 0.2, 0.3, 0.4], 'anything', 'semantic', 0.0);
        } catch (McpPickNoMatchException $e) {
            $rej = true;
            $caught = $e;
        }
        mcp_pick_contract_assert($rej, 'empty registry -> McpPickNoMatchException');
        mcp_pick_contract_assert($caught !== null && $caught->candidatesScanned === 0, 'exception carries candidatesScanned=0');
        $rulesAsserted += 2;

        if (function_exists('king_object_store_put') && function_exists('king_object_store_get')) {
            // Seed a single tool and confirm we pick it.
            $weatherTool = [
                'tool_id' => 't-weather',
                'name' => 'weather lookup',
                'description' => 'returns current temperature and forecast for a city',
                'mcp_target' => ['host' => 'tools.local', 'port' => 9001, 'service' => 'weather', 'method' => 'lookup'],
                'capabilities' => ['forecast', 'geocoding'],
            ];
            model_inference_tool_embedding_upsert($pdo, $weatherTool, 'mdl', static fn () => ['vector' => [1.0, 0.0, 0.0, 0.0]]);

            $pick = model_inference_mcp_pick($pdo, [1.0, 0.0, 0.0, 0.0], 'weather forecast lookup', 'semantic', 0.0);
            mcp_pick_contract_assert($pick['tool_id'] === 't-weather', 'pick returns t-weather');
            mcp_pick_contract_assert($pick['mcp_target']['host'] === 'tools.local', 'pick preserves host');
            mcp_pick_contract_assert($pick['mcp_target']['port'] === 9001, 'pick preserves port');
            mcp_pick_contract_assert($pick['mcp_target']['service'] === 'weather', 'pick preserves service');
            mcp_pick_contract_assert($pick['mcp_target']['method'] === 'lookup', 'pick preserves method');
            mcp_pick_contract_assert($pick['mode'] === 'semantic', 'mode echoed');
            mcp_pick_contract_assert($pick['candidates_scanned'] === 1, 'candidates_scanned=1');
            mcp_pick_contract_assert(is_array($pick['descriptor']) && $pick['descriptor']['tool_id'] === 't-weather', 'descriptor in result');
            $rulesAsserted += 8;

            // Hybrid mode with matching text.
            $pickH = model_inference_mcp_pick($pdo, [1.0, 0.0, 0.0, 0.0], 'weather forecast lookup', 'hybrid', 0.0, 0.5);
            mcp_pick_contract_assert($pickH['tool_id'] === 't-weather', 'hybrid pick returns weather');
            mcp_pick_contract_assert($pickH['mode'] === 'hybrid', 'hybrid mode echoed');
            $rulesAsserted += 2;

            // min_score above the best score -> fail closed.
            $rej = false;
            $observed = 0;
            try {
                // Query orthogonal to svec -> cosine ~0, below 0.9
                model_inference_mcp_pick($pdo, [0.0, 1.0, 0.0, 0.0], 'q', 'semantic', 0.9);
            } catch (McpPickNoMatchException $e) {
                $rej = true;
                $observed = $e->candidatesScanned;
            }
            mcp_pick_contract_assert($rej, 'high min_score -> McpPickNoMatchException');
            mcp_pick_contract_assert($observed === 1, 'exception carries candidatesScanned=1');
            $rulesAsserted += 2;
        }
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[mcp-pick-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[mcp-pick-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
