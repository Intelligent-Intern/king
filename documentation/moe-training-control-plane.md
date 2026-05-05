# MoE Training Control Plane

This document describes the first realistic King infrastructure slice for
distributed MoE fine-tuning and pretraining.

The intended role of King is the distributed control plane:

- validate training plans before submission
- store data, run envelopes, rendezvous state, and checkpoints through
  `king_object_store_*`
- move compact control/status messages through IIBIN binary frames
- stream those frames over King WebSocket connections
- distribute worker steps through the pipeline orchestrator
- expose deterministic run state for operators and automation

The intended role of a training backend is numeric execution: GPU placement,
tensor kernels, NCCL collectives, optimizer stepping, expert routing, and model
state mutation. King should coordinate that backend; it should not pretend PHP
itself is the tensor runtime.

## API Shape

The userland front door is a fluent PHP DSL:

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
    ->loss(Loss::crossEntropy()->ignoreIndex(-100))
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

The current implementation lives in
`demo/userland/training-php/src/TrainingControlPlane.php`.

## Realistic Architecture

The realistic deployment shape is:

```text
PHP DSL
  -> validated king.training.distributed.v1 plan
  -> IIBIN run envelope
  -> object-store persisted run record
  -> orchestrator submission for agent/worker control steps
  -> websocket/IIBIN watch and worker event streams
  -> native training backend executes GPU work
```

This is realistic because King already has the required substrate:

- `king_proto_define_schema()`, `king_proto_encode()`,
  `king_proto_encode_batch()`, `king_proto_decode()`, and batch decode for
  IIBIN contracts.
- `king_client_websocket_connect()`, `king_client_websocket_send()`,
  `king_client_websocket_receive()`, `king_websocket_send()`, and the OO
  WebSocket server/connection surface for durable control channels.
- `king_object_store_put()`, stream reads/writes, metadata, CAS preconditions,
  and resumable uploads for run records, datasets, checkpoints, and manifests.
- `king_pipeline_orchestrator_dispatch()`,
  `king_pipeline_orchestrator_worker_run_next()`,
  `king_pipeline_orchestrator_resume_run()`, and
  `king_pipeline_orchestrator_get_run()` for distributed worker control.

It is not realistic to train a large MoE model directly inside this PHP
control-plane layer. The DSL must compile to a contract consumed by native or
external training workers.

## GPU Server Topology

The practical near-term deployment target is a small fleet of GPU-capable
servers coordinated by King:

```text
controller
  -> persists run plan in object_store
  -> creates rendezvous lease prefix
  -> dispatches worker-control steps through pipeline_orchestrator
  -> streams run events over websocket/IIBIN

gpu-server-a
  -> binds king.training.distributed.agent_worker locally
  -> claims rank/worker lease
  -> runs local GPU training backend
  -> writes checkpoint shards/manifests to object_store
  -> emits websocket/IIBIN status events

gpu-server-b
  -> same worker contract

gpu-server-c
  -> same worker contract
```

Each GPU server owns local execution. King coordinates rank assignment,
heartbeats, checkpoints, failure state, and operator visibility. This is a good
fit for fine-tuning multiple agents or training a modest distributed MoE run
across a controlled server set.

For this topology, `elasticRanks(min, max)` should be treated as scheduler
admission intent, not as proof that those ranks already exist. The backend
adapter must admit the run only after enough GPU workers have joined and passed
capability checks.

## Generated Contract

`start()` emits:

```php
[
    'contract' => 'king.training.distributed.v1',
    'execution' => [
        'mode' => 'control_plane_plan',
        'state' => 'planned',
        'backend' => null,
    ],
]
```

The `planned` state is intentional. It means the plan is validated and ready
for backend admission, not that H100/NCCL work has already started.

The plan also records:

- dataset logical URI and native object-store object ID
- IIBIN dataset format and batch limit
- websocket/IIBIN coordination transport
- object-store checkpoint target and native checkpoint object prefix
- durable run object URI and native object ID
- pipeline-orchestrator tool and worker APIs
- rendezvous object prefix
- explicit runtime requirements

## IIBIN Contracts

Training control messages must be versioned IIBIN schemas. The current
schemas are:

- `KingTrainingRunEnvelopeV1`
- `KingTrainingRunEventV1`

Runtime schemas use `tag`, not `field_number`, because the native IIBIN schema
validator requires positive `tag` metadata.

IIBIN batches are bounded by the native limit of `65536` records. Token,
event, and status flows must chunk before that limit instead of treating batch
encode/decode as an unbounded stream.

The implementation gracefully falls back to an unencoded payload when the King
extension is not loaded, so local PHP contracts can run without native modules.
Native deployments should encode envelopes and events with `king_proto_encode`
or `King\IIBIN::encode`.

## Object Store Contracts

Public training addresses use logical `king://` URIs. Native object-store APIs
must not receive those raw URIs.

Native object IDs are flat, capped, and reject path separators. The training
control plane maps every logical URI to a native-safe object ID with
`Training\ObjectStoreUriMapper`:

```text
king://runs/moe-pretrain-run-2026-05-04/plan.iibin
  -> training-run!runs!moe-pretrain-run-2026-05-04!plan.iibin
```

Long or unsafe IDs fall back to:

```text
training-<kind>-sha256-<hash>
```

Run plan creation uses object-store create semantics with `if_none_match => '*'`
when the native extension is available. Future updates should use ETag and
version preconditions, mirroring the Flow control-plane/checkpoint pattern.

## WebSocket Contracts

WebSocket is the control/status transport. IIBIN is the binary frame format.

The initial `watch()` method returns a nonblocking planned-event snapshot. A
live scheduler-backed implementation should stream events such as:

- `training.run.accepted`
- `worker.joined`
- `rank.ready`
- `checkpoint.committed`
- `failure.quarantined`
- `run.completed`

Worker and operator channels should use King WebSocket admission/readiness
gates. During shutdown, drain, or startup states, peers must not silently bypass
system readiness.

## Orchestrator Contracts

The training plan names the orchestrator tool:

```text
king.training.distributed.agent_worker
```

The orchestrator is a control distribution layer, not a tensor executor. It can
dispatch agent/worker control steps, persist run snapshots, resume interrupted
work, and coordinate process-local handler rebinding.

Handlers are process-local. Remote workers and file workers must bind their own
tool handlers before claiming training control work. The plan records this as:

```text
handler_boundary = process_local_rebind_required
```

## Current Limitations

- No GPU scheduler adapter is implemented yet.
- No NCCL runtime admission check is implemented yet.
- No real tensor checkpoint writer is implemented yet.
- `watch()` is currently a nonblocking planned snapshot, not a live stream.
- Rendezvous is represented as an object-store object prefix, not yet a lease
  protocol.
- The training backend ABI is not yet defined.

## Next Implementation Steps

1. Add a `TrainingRunStore` that persists run envelopes and updates status with
   object-store CAS.
2. Add a rendezvous lease adapter with per-rank claims and heartbeats under
   mapped object IDs.
3. Add an orchestrator adapter that dispatches `king.training.distributed.agent_worker`
   control steps and reads status through `king_pipeline_orchestrator_get_run()`.
4. Add IIBIN event batch encode/decode contracts over WebSocket.
5. Define the per-server training worker ABI for GPU/NCCL execution.
6. Add capability probes for GPU count, memory, NCCL availability, backend
   version, model shard compatibility, and checkpoint shard ownership.
