# Delphi M0 Scaffold

This directory provides a minimal userland scaffold for Delphi M0:

1. IIBIN schema registration for artifact refs and expert-fanout envelopes.
2. A DAG-shaped orchestrator template for one recurrent loop with expert fanout.
3. An object-store artifact contract for tensor payload staging.

## Files

- `src/M0Scaffold.php`: reusable helper class.

## Quick Start

```php
<?php
declare(strict_types=1);

require __DIR__ . '/src/M0Scaffold.php';

use King\Delphi\M0Scaffold;

M0Scaffold::registerIibinSchemas();

$artifactRef = M0Scaffold::storeTensorArtifact(
    'delphi/activations/run-1/loop-0/input.bin',
    random_bytes(4096),
    [
        'shape' => [32, 128],
        'dtype' => 'float16',
        'quantization' => 'fp16',
    ]
);

$pipeline = M0Scaffold::buildOneLoopExpertFanoutPipeline(
    'run-1',
    [
        ['expert_id' => 'expert-0001', 'owner_node_id' => 'node-a'],
        ['expert_id' => 'expert-0002', 'owner_node_id' => 'node-b'],
    ],
    $artifactRef,
    0,
    2
);

// Register Delphi handlers on this process, then run:
// king_pipeline_orchestrator_run(['run_id' => 'run-1'], $pipeline, ['trace_id' => 'delphi-m0']);
```

## Contract Notes

- Control-path payloads should carry the compact `DelphiArtifactRef`, not raw tensors.
- Heavy tensors/checkpoints should move through object-store object IDs and URIs.
- The orchestrator template intentionally uses graph-shaped `steps` with `id`/`deps`.
- The current orchestrator execution remains step-boundary and deterministic; retries remain per step.
