# Voltron Scaffold

This directory provides the Voltron model-agnostic partitioner:

1. ModelConfig - model block schema definitions (Gemma2B, Gemma7B)
2. ModelPartitioner - partition any model into DAG of blocks
3. VoltronConnector - wire to King infra (orchestrator + semantic DNS)
4. M0Scaffold - legacy Delphi IIBIN schema helpers

## Files

- `src/ModelConfig.php` - model block schemas
- `src/ModelPartitioner.php` - DAG-based partitioning
- `src/VoltronConnector.php` - King infra connector
- `src/M0Scaffold.php` - legacy IIBIN schemas

## Quick Start

```php
<?php
declare(strict_types=1);

require __DIR__ . '/src/VoltronConnector.php';

use King\Voltron\VoltronConnector;

// Create connector for a node
$connector = new VoltronConnector('node-1', 'gemma2B');

// Register node capabilities
$connector->registerNode([
    'max_memory_mb' => 4096,
    'capabilities' => ['model_inference', 'embedding', 'attention'],
]);

// Discover cluster and build pipeline
$nodes = $connector->discoverClusterNodes();
$nodes['node-1'] = ['max_memory_mb' => 4096, 'capabilities' => ['model_inference']];

$pipeline = $connector->buildPipeline($nodes);

// Run via orchestrator
king_pipeline_orchestrator_run(['run_id' => $connector->getRunId()], $pipeline);
```

## Contract Tests

Run: `php contracts/700-voltron-model-partitioner-contract.php`
