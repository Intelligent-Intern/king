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
 * 5. VoltronConnector builds valid pipeline
 */

require __DIR__ . '/../../voltron/src/ModelConfig.php';
require __DIR__ . '/../../voltron/src/ModelPartitioner.php';
require __DIR__ . '/../../voltron/src/VoltronConnector.php';

use King\Voltron\ModelConfig;
use King\Voltron\ModelPartitioner;
use King\Voltron\VoltronConnector;

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

// Test 1: Gemma2B config is valid
test("ModelConfig::gemma2B() is valid", function() {
    $config = ModelConfig::gemma2B();
    ModelConfig::assertValid($config);
    assert_eq($config['name'], 'gemma2B');
    assert_eq($config['total_params'], 2000000000);
    assert_eq(count($config['block_schema']), 10);
});

// Test 2: Gemma7B config is valid
test("ModelConfig::gemma7B() is valid", function() {
    $config = ModelConfig::gemma7B();
    ModelConfig::assertValid($config);
    assert_eq($config['name'], 'gemma7B');
    assert_eq($config['total_params'], 7000000000);
    assert_eq(count($config['block_schema']), 10);
});

// Test 3: Block schema has no cycles
test("Gemma2B block schema has no cycles", function() {
    $config = ModelConfig::gemma2B();
    $schema = $config['block_schema'];
    $visited = [];
    $recursionStack = [];
    
    $hasCycle = false;
    
    // Build adjacency: from dependency TO dependent
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

// Test 4: Partition produces DAG with correct block count
test("ModelPartitioner::partition produces DAG", function() {
    $config = ModelConfig::gemma2B();
    $nodes = [
        'node-a' => ['max_memory_mb' => 2048, 'capabilities' => ['model_inference', 'embedding']],
        'node-b' => ['max_memory_mb' => 4096, 'capabilities' => ['model_inference', 'attention', 'ffn']],
        'node-c' => ['max_memory_mb' => 4096, 'capabilities' => ['model_inference', 'output_head']],
    ];
    
    $result = ModelPartitioner::partition($config, $nodes);
    
    assert_eq(count($result['steps']), 11, "Should have 10 blocks + 1 emit_final");
    assert_eq(count($result['block_assignments']), 10, "Should assign 10 blocks");
    assert_eq($result['run_config']['model_name'], 'gemma2B');
    assert_eq($result['run_config']['total_blocks'], 10);
});

// Test 5: Partition respects memory constraints
test("ModelPartitioner respects memory constraints", function() {
    $config = ModelConfig::gemma2B();
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

// Test 6: Block dependencies are respected
test("Partition respects block dependencies", function() {
    $config = ModelConfig::gemma2B();
    $nodes = [
        'node-a' => ['max_memory_mb' => 4096, 'capabilities' => ['model_inference']],
    ];
    
    $result = ModelPartitioner::partition($config, $nodes);
    $steps = $result['steps'];
    
    $embedStep = null;
    $attention1Step = null;
    
    foreach ($steps as $step) {
        if ($step['id'] === 'voltron.execute_block.embed') {
            $embedStep = $step;
        }
        if ($step['id'] === 'voltron.execute_block.attention_1') {
            $attention1Step = $step;
        }
    }
    
    assert_eq($embedStep['deps'] ?? [], [], "embed should have no deps");
    assert_eq($attention1Step['deps'] ?? [], ['voltron.execute_block.embed'], "attention_1 depends on embed");
});

// Test 7: VoltronConnector can build pipeline
test("VoltronConnector builds valid pipeline", function() {
    $nodes = [
        'node-a' => ['max_memory_mb' => 2048, 'capabilities' => ['model_inference', 'embedding']],
        'node-b' => ['max_memory_mb' => 4096, 'capabilities' => ['model_inference', 'attention', 'ffn']],
    ];
    
    $connector = new VoltronConnector('test-node', 'gemma2B');
    $pipeline = $connector->buildPipeline($nodes);
    
    assert_eq(count($pipeline['steps']), 11);
    assert_eq($pipeline['run_config']['model_name'], 'gemma2B');
    assert_eq($pipeline['run_config']['total_blocks'], 10);
});

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit($failed > 0 ? 1 : 0);