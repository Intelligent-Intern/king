# MoE Planning

## Goal

Add a King-native userland control-plane surface for distributed MoE training plans, starting with the requested PHP fluent API and contract tests. The first useful milestone is a validated training-run envelope that can be submitted or inspected consistently, even before real GPU/NCCL execution exists in this branch.

The realistic target is distributed training orchestration for agents/workers:
King validates plans, persists state, coordinates workers, streams events, and
hands numeric execution to a backend built for GPU/NCCL work.

The first realistic deployment target is a few GPU-capable servers. Each server
binds a local `king.training.distributed.agent_worker` handler, claims a
rendezvous lease, runs its local GPU backend, writes checkpoint shards through
object store, and reports status over websocket/IIBIN events.

## Proposed Scope

Implement a new userland training package under `demo/userland/training-php/`:

- Global facade classes matching the requested API:
  - `Training`
  - `Optimizer`
  - `Loss`
  - `Placement`
  - `FailurePolicy`
- Namespaced builder classes matching calls such as:
  - `Training\Data::objectStore(...)`
  - `Training\Parallelism::hybrid()`
  - `Training\Schedule::steps(...)`
  - `Training\Checkpointing::objectStore()`
- A `Training\DistributedTrainingBuilder` that collects the plan and validates required sections.
- A `Training\TrainingRun` object returned by `start()`, with nonblocking `watch()` status snapshots.

## Contract Surface

The generated plan should use a stable version marker:

```php
'contract' => 'king.training.distributed.v1'
```

Required plan sections:

- `run_id`
- `model`
- `data`
- `optimizer`
- `loss`
- `parallelism`
- `schedule`
- `checkpointing`
- `placement`
- `failure_policy`
- `execution`

Initial execution state:

```php
'execution' => [
    'mode' => 'control_plane_plan',
    'state' => 'planned',
    'backend' => null,
]
```

This keeps the branch deployable and testable without claiming that a real H100/NCCL scheduler is already wired.

## Validation Rules

Minimum validation for the first pass:

- Run IDs must be nonempty.
- `king://` URIs must use the `king` scheme and contain a nonempty authority/path.
- Dataset format must be nonempty.
- Shuffle seed must be an integer.
- Prefetch window must be positive.
- AdamW learning rate must be positive.
- AdamW betas must be in `[0, 1)`.
- Weight decay must be nonnegative.
- Gradient clipping must be positive.
- Loss ignore index must be an integer.
- Parallelism dimensions must be positive.
- Collectives backend must be nonempty.
- Schedule steps, micro-batch size, global batch tokens, and warmup steps must be valid positive or nonnegative integers as appropriate.
- Checkpoint interval must be positive.
- Elastic rank minimum and maximum must be positive and `min <= max`.

Do not enforce a total-rank product yet. The requested hybrid MoE topology combines data, tensor, expert, and pipeline dimensions, and rank accounting needs a scheduler-aware contract before it is safe to reject plans by multiplication alone.

## Test Plan

Add a PHP contract test that can run without external services:

```sh
php demo/userland/training-php/tests/moe-pretrain-dsl-contract.php
```

Add a PHPT wrapper under `extension/tests/` so CI keeps the behavior:

```sh
php extension/run-tests.php -q extension/tests/743-training-moe-dsl-contract.phpt
```

The test should assert:

- The requested fluent API builds successfully.
- The emitted contract is `king.training.distributed.v1`.
- `iibin.tokens.v1`, shuffle seed `1337`, prefetch window `128`, AdamW settings, cross-entropy ignore index `-100`, NCCL, rendezvous URI, checkpoint target, H100 placement, and failure recovery flags are present.
- `watch()` returns promptly with a planned/accepted status.
- A bad `king://` target or bad elastic rank range raises `InvalidArgumentException`.

## Later Native Wiring

After the control-plane contract is stable, connect it to native King infrastructure in stages:

1. Map `king://` dataset and checkpoint targets onto the existing object-store metadata and streaming wrappers.
2. Add a scheduler/backend adapter that can translate `king.training.distributed.v1` into pipeline-orchestrator jobs.
3. Add a rendezvous adapter for the requested `king://runs/.../rdzv` coordination point.
4. Add NCCL/H100 capability checks as explicit backend admission checks.
5. Add real `watch()` streaming once a backend run ID exists.

6. Define a training backend ABI for fine-tuning/training agents. The ABI
   should accept validated IIBIN plan envelopes, object-store dataset/checkpoint
   object IDs, rendezvous lease information, and worker rank assignment.
7. Add scheduler admission checks for GPU pool, GPU memory, NCCL support, and
   elastic rank bounds before transitioning from `planned` to `accepted`.
8. Add object-store CAS updates for run state and rendezvous leases.

## Non-Goals For First Pass

- No fake training loop.
- No simulated H100 allocation.
- No silent success for unavailable NCCL or GPU backends.
- No compile-time dependency on a specific cluster scheduler.
