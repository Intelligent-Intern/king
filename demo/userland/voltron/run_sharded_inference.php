#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Distributed/LlamaCppShardOrchestrator.php';

echo "=== Distributed Llama.cpp Shard Orchestrator Demo ===\n\n";

$modelPath = $argv[1] ?? '/Users/sasha/qwen2.5-coder-3b-Q4_K.gguf';
$llamaServerPath = '/tmp/llama.cpp/build/bin/llama-server';

if (!file_exists($llamaServerPath)) {
    echo "ERROR: llama-server not found at $llamaServerPath\n";
    echo "Build it with:\n";
    echo "  cd /tmp && git clone --depth 1 https://github.com/ggerganov/llama.cpp.git";
    echo " && cd llama.cpp && mkdir -p build && cd build && cmake .. && make -j4 llama-server\n";
    exit(1);
}

$orchestrator = new King\Voltron\Distributed\LlamaCppShardOrchestrator(
    $modelPath,
    $llamaServerPath,
    6
);

$info = $orchestrator->getShardInfo();
echo "Model: {$info['model_path']}\n";
echo "Total layers: {$info['total_layers']}\n";
echo "Shard count: {$info['shard_count']}\n\n";

echo "Shard configuration:\n";
foreach ($info['shards'] as $shard) {
    echo sprintf(
        "  Shard %d: layers %d-%d (port %d)\n",
        $shard['index'],
        $shard['layer_start'],
        $shard['layer_end'],
        $shard['port']
    );
}

echo "\n[1] Spawning llama-server processes for each shard...\n";
$health = $orchestrator->spawnShardServers();

foreach ($health as $shardIdx => $status) {
    echo "  Shard $shardIdx: {$status['status']}\n";
}

echo "\n[2] Testing direct llama-server on shard 0...\n";
$port = $info['shards'][0]['port'];

$prompt = "The answer to";
$cmd = sprintf(
    'curl -s http://127.0.0.1:%d/infill -d \'{"prompt": "%s", "n_predict": 10, "temp": 0}\'',
    $port,
    $prompt
);

$output = shell_exec($cmd);
echo "  Response: " . substr($output ?? 'empty', 0, 200) . "\n";

echo "\n[3] Health check all shards...\n";
$health = $orchestrator->healthCheck();
foreach ($health as $shardIdx => $status) {
    echo "  Shard $shardIdx: {$status['status']}\n";
}

echo "\n[4] Testing PHP-based forward pass through all shards (embed + layers)...\n";
$start = hrtime(true);

$result = $orchestrator->generate('2+2', 5);

$duration = (hrtime(true) - $start) / 1e6;
echo "  Output: {$result['output']}\n";
echo "  Tokens: " . json_encode($result['tokens']) . "\n";
echo "  Duration: " . round($duration, 0) . "ms\n";

echo "\n[5] Cleanup...\n";
$orchestrator->killShardServers();
echo "  Killed all llama-server processes\n";

echo "\nDone.\n";