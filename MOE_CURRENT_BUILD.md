# MoE Current Build

Last updated: 2026-05-05T08:11:16Z

Branch: `experiments/moe-tuning-tracking`

Baseline commit: `c0cf1bfb`

## Current State

This branch currently tracks `origin/experiments/moe-tuning-tracking` and was clean when this document was created.

There is now an initial userland training control-plane DSL under `demo/userland/training-php/`. It validates the requested fluent API and emits a `king.training.distributed.v1` run plan. This is a control-plane contract, not a GPU training executor.

Current related infrastructure is adjacent rather than a complete training backend:

- `demo/userland/flow-php/src/` contains userland control-plane, object-store dataset, execution backend, checkpoint, and failure taxonomy patterns.
- `extension/src/pipeline_orchestrator/` and related PHPT tests cover native orchestration contracts.
- `extension/src/object_store/` and userland Flow object-store wrappers provide existing object-store semantics that a training data/checkpoint layer should align with.
- `stubs/king.php` exposes native King extension APIs, but does not define the requested training API surface.

Additional implementation notes:

- IIBIN run/event schemas are defined as `KingTrainingRunEnvelopeV1` and `KingTrainingRunEventV1` when the native extension is available.
- Runtime IIBIN schema definitions use `tag`, not `field_number`.
- Logical `king://` URIs are mapped to native-safe flat object IDs before object-store writes.
- WebSocket/IIBIN watch transport and pipeline-orchestrator worker APIs are represented in the emitted plan.
- `watch()` remains nonblocking and returns a planned-event snapshot until a real scheduler backend is connected.

## Requested API Shape

The target userland surface should make this style possible:

```php
$run = Training::distributed('moe-pretrain-run-2026-05-04')
    ->model($moe)
    ->data(
        Training\Data::objectStore('king://datasets/tokens/v9')
            ->format('iibin.tokens.v1')
            ->shuffle(seed: 1337)
            ->prefetch(window: 128)
    )
    ->optimizer(
        Optimizer::adamw()
            ->lr(3e-4)
            ->betas(0.9, 0.95)
            ->weightDecay(0.1)
            ->gradientClipping(1.0)
    )
    ->loss(
        Loss::crossEntropy()
            ->ignoreIndex(-100)
    )
    ->parallelism(
        Training\Parallelism::hybrid()
            ->dataParallel(128)
            ->tensorParallel(4)
            ->expertParallel(64)
            ->pipelineParallel(2)
            ->collectives('nccl')
            ->rendezvous('king://runs/moe-pretrain-run-2026-05-04/rdzv')
    )
    ->schedule(
        Training\Schedule::steps(1_000_000)
            ->microBatchSize(2)
            ->globalBatchTokens(8_000_000)
            ->warmupSteps(2000)
            ->cosineDecay()
    )
    ->checkpointing(
        Training\Checkpointing::objectStore()
            ->target('king://moe-prod/checkpoints/pretrain')
            ->everySteps(1000)
            ->async()
            ->resumeAutomatically()
    )
    ->placement(
        Placement::distributed()
            ->nodePool('gpu-h100')
            ->requireGpuMemory('>=80GB')
            ->spreadAcrossRacks()
            ->coLocateHotExperts()
    )
    ->failurePolicy(
        FailurePolicy::training()
            ->elasticRanks(min: 512, max: 2048)
            ->reconstructMissingExpertFromCheckpoint()
            ->quarantineBadWorkers()
            ->resumeFromLastConsistentCheckpoint()
    )
    ->start();

$run->watch();
```

## Build Constraints

- The first implementation should be honest control-plane infrastructure, not a fake GPU trainer.
- `start()` must produce a validated King training run envelope that can be contracted and evolved toward native scheduling.
- `watch()` must not hang in local or CI tests; it should provide a deterministic status/event snapshot until a real scheduler backend is connected.
- Unsupported runtime execution must be explicit in the generated state instead of silently pretending that H100/NCCL execution happened.
- The DSL must validate obvious bad plans up front: empty run IDs, invalid `king://` URIs, nonpositive batch/step values, invalid optimizer ranges, bad checkpoint cadence, and invalid elastic rank bounds.

## Test Expectations

The branch should gain a contracted test that:

- Builds the requested MoE pretraining example using the actual DSL classes.
- Verifies the emitted plan contains data, optimizer, loss, parallelism, schedule, checkpointing, placement, and failure policy fields.
- Verifies King object-store URIs and rendezvous/checkpoint targets are preserved.
- Verifies `watch()` is nonblocking and reports the run as a planned or accepted control-plane run.
- Verifies at least one invalid configuration fails with a clear exception.

The active local test is:

```sh
php demo/userland/training-php/tests/moe-pretrain-dsl-contract.php
```

The CI PHPT wrapper is:

```sh
php extension/run-tests.php -q extension/tests/743-training-moe-dsl-contract.phpt
```

