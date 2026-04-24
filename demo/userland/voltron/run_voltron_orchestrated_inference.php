#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/demo/userland/voltron/src/VoltronHandlers.php';

echo "=== Voltron DAG Orchestrator + Distributed Llama.cpp ===\n\n";

$modelPath = '/Users/sasha/qwen2.5-coder-3b-Q4_K.gguf';
$llamaServerPath = '/tmp/llama.cpp/build/bin/llama-server';
$shardCount = 6;

if (!extension_loaded('king')) {
    echo "ERROR: King extension not loaded\n";
    exit(1);
}

if (!file_exists($llamaServerPath)) {
    echo "WARNING: llama-server not found. Build it first.\n";
}

King\Voltron\voltron_register_handlers();

$basePort = 9700;

echo "Step 1: Starting llama-server shards...\n";
$pids = [];
for ($i = 0; $i < $shardCount; $i++) {
    $port = $basePort + $i;
    $layersPerShard = (int) (36 / $shardCount);
    $layerStart = $i * $layersPerShard;
    $layerEnd = min(35, $layerStart + $layersPerShard - 1);
    
    $cmd = sprintf(
        '%s -m %s -c 2048 --port %d 2>&1 > /tmp/llama-shard-%d.log &',
        escapeshellarg($llamaServerPath),
        escapeshellarg($modelPath),
        $port,
        $i
    );
    
    exec($cmd);
    $pids[] = $i;
    echo "  Started shard $i on port $port (layers $layerStart-$layerEnd)\n";
}

sleep(3);

echo "\nStep 2: Health checking shards...\n";
$allUp = true;
for ($i = 0; $i < $shardCount; $i++) {
    $port = $basePort + $i;
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
    if ($socket) {
        echo "  Shard $i: UP\n";
        fclose($socket);
    } else {
        echo "  Shard $i: DOWN ($errstr)\n";
        $allUp = false;
    }
}

if (!$allUp) {
    echo "\nNot all shards are up. Continuing anyway...\n";
}

echo "\nStep 3: Defining Voltron DAG pipeline with $shardCount shard steps...\n";

$steps = [];
for ($i = 0; $i < $shardCount; $i++) {
    $layersPerShard = (int) (36 / $shardCount);
    $layerStart = $i * $layersPerShard;
    $layerEnd = min(35, $layerStart + $layersPerShard - 1);
    
    $steps[] = [
        'id' => "shard_$i",
        'tool' => 'voltron.forward_shard',
        'params' => [
            'shard_index' => $i,
            'port' => $basePort + $i,
            'layer_start' => $layerStart,
            'layer_end' => $layerEnd,
        ],
    ];
}

$steps[] = [
    'id' => 'sample',
    'tool' => 'voltron.sample_token',
    'params' => [
        'temperature' => 0.0,
    ],
];

echo "Pipeline steps:\n";
foreach ($steps as $idx => $step) {
    echo "  $idx: {$step['id']} -> {$step['tool']}\n";
}

echo "\nStep 4: Running pipeline with orchestrator...\n";

$runId = 'voltron-distributed-' . time();

$start = hrtime(true);

$result = king_pipeline_orchestrator_run(
    [
        'run_id' => $runId,
        'prompt' => '2+2',
        'voltron_state' => null,
        'decode_iteration' => 0,
    ],
    $steps,
    ['trace_id' => 'voltron-demo']
);

$duration = (hrtime(true) - $start) / 1e6;

echo "\nPipeline result:\n";
echo "  Run ID: {$result['run_id']}\n";
echo "  Duration: " . round($duration, 0) . "ms\n";

if (isset($result['voltron_state']) && is_array($result['voltron_state'])) {
    $state = $result['voltron_state'];
    echo "  Position: " . ($state['position'] ?? '?') . "\n";
    echo "  Last token: " . ($state['last_token_id'] ?? '?') . "\n";
    echo "  Generated: " . bin2hex($state['generated_text'] ?? '') . "\n";
}

if (isset($result['outputs']) && is_array($result['outputs'])) {
    echo "  Outputs:\n";
    foreach ($result['outputs'] as $key => $value) {
        if (is_string($value) && strlen($value) < 100) {
            echo "    $key: $value\n";
        } elseif (is_numeric($value)) {
            echo "    $key: $value\n";
        }
    }
}

echo "\nStep 5: Cleanup...\n";
exec('pkill -f "llama-server" 2>/dev/null');
echo "  Killed llama-server processes\n";

echo "\nDone.\n";