<?php
declare(strict_types=1);

/**
 * Contract test: Voltron model-agnostic partitioner
 *
 * Tests:
 * 1. ModelConfig::assertValid passes valid configs
 * 2. ModelConfig::assertValid rejects invalid configs
 * 3. ModelPartitioner::partition produces deterministic DAG
 * 4. Block assignments respect memory constraints
 */

require __DIR__ . '/../../voltron/src/ModelConfig.php';
require __DIR__ . '/../../voltron/src/ModelPartitioner.php';

use King\Voltron\ModelConfig;
use King\Voltron\ModelPartitioner;

echo "=== Voltron Model-Agnostic Partitioner Contract Test ===\n\n";

$passed = 0;
$failed = 0;

function test(string $name, callable $fn): void {
    global $passed, $failed;
    try {
        $fn();
        echo "[PASS] $name\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "[FAIL] $name: " . $e->getMessage() . "\n";
        $failed++;
    }
}

function assert_eq(mixed $a, mixed $b, string $msg = ''): void {
    if ($a !== $b) {
        throw new \RuntimeException($msg ?: "Expected $b, got $a");
    }
}

// Test 1: Qwen config is valid
test("ModelConfig::qwen2_5_3b() is valid", function() {
    $config = ModelConfig::qwen2_5_3b();
    ModelConfig::assertValid($config);
    assert_eq($config['name'], 'qwen2.5-coder:3b');
    assert_eq($config['total_params'], 3000000000);
    assert_eq(count($config['block_schema']), 6);
});

// Test 2: Block schema has no cycles
test("Qwen block schema has no cycles", function() {
    $config = ModelConfig::qwen2_5_3b();
    $schema = $config['block_schema'];
    $visited = [];
    $recursionStack = [];
    
    $hasCycle = false;
    
    $adjacency = [];
    $allNodes = [];
    foreach ($schema as $block) {
        $id = $block['id'];
        $allNodes[] = $id;
        foreach ($block['dependencies'] ?? [] as $dep) {
            $adjacency[$dep][] = $id;
        }
    }
    
    $dfs = function(string $nodeId) use (&$dfs, &$adjacency, &$visited, &$recursionStack, &$hasCycle) {
        $visited[$nodeId] = true;
        $recursionStack[$nodeId] = true;
        
        foreach ($adjacency[$nodeId] ?? [] as $dependent) {
            if (!isset($visited[$dependent])) {
                $dfs($dependent);
            } elseif (isset($recursionStack[$dependent])) {
                $hasCycle = true;
            }
        }
        $recursionStack[$nodeId] = false;
    };
    
    foreach ($allNodes as $id) {
        if (!isset($visited[$id])) {
            $dfs($id);
        }
    }
    
    if ($hasCycle) {
        throw new \RuntimeException("Cycle detected");
    }
});

// Test 3: Partition produces DAG with correct block count
test("ModelPartitioner::partition produces DAG", function() {
    $config = ModelConfig::qwen2_5_3b();
    $nodes = [
        'node-a' => ['max_memory_mb' => 4096, 'capabilities' => ['model_inference', 'embedding']],
    ];
    
    $result = ModelPartitioner::partition($config, $nodes);
    
    assert_eq(count($result['steps']), 7, "Should have 6 blocks + 1 emit_final");
    assert_eq(count($result['block_assignments']), 6, "Should assign 6 blocks");
    assert_eq($result['run_config']['model_name'], 'qwen2.5-coder:3b');
});

// Test 4: Partition respects memory constraints
test("ModelPartitioner respects memory constraints", function() {
    $config = ModelConfig::qwen2_5_3b();
    $nodes = [
        'tiny-node' => ['max_memory_mb' => 256, 'capabilities' => ['model_inference']],
    ];
    
    try {
        $result = ModelPartitioner::partition($config, $nodes);
        throw new \RuntimeException("Should have thrown");
    } catch (\InvalidArgumentException $e) {
        if (strpos($e->getMessage(), 'No node available') !== false) {
            return;
        }
        throw $e;
    }
});

// Test 5: Block dependencies are respected
test("Partition respects block dependencies", function() {
    $config = ModelConfig::qwen2_5_3b();
    $nodes = [
        'node-a' => ['max_memory_mb' => 4096, 'capabilities' => ['model_inference']],
    ];
    
    $result = ModelPartitioner::partition($config, $nodes);
    $steps = $result['steps'];
    
    $embedStep = null;
    $chunk1Step = null;
    
    foreach ($steps as $step) {
        if ($step['id'] === 'voltron.execute_block.embed') {
            $embedStep = $step;
        }
        if ($step['id'] === 'voltron.execute_block.layer_chunk_1') {
            $chunk1Step = $step;
        }
    }
    
    assert_eq($embedStep['deps'] ?? [], [], "embed should have no deps");
    assert_eq($chunk1Step['deps'] ?? [], ['voltron.execute_block.embed'], "layer_chunk_1 depends on embed");
});

// Test 6: Qwen chunk ranges cover the real transformer depth from blk.0 .. blk.35
test("Qwen partition covers full transformer range", function() {
    $config = ModelConfig::qwen2_5_3b();
    $schema = $config['block_schema'];

    $chunk1 = null;
    $outputHead = null;
    foreach ($schema as $block) {
        if (($block['id'] ?? null) === 'layer_chunk_1') {
            $chunk1 = $block;
        }
        if (($block['id'] ?? null) === 'output_head') {
            $outputHead = $block;
        }
    }

    assert_eq($chunk1['layers'] ?? null, [0, 8], 'layer_chunk_1 should start at blk.0');
    assert_eq($outputHead['layers'] ?? null, [35, 35], 'output_head should end at blk.35');
});

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit($failed > 0 ? 1 : 0);
