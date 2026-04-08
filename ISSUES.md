# King Issues

> This file is the single moving roadmap and execution queue for repo-local
> King v1.
> It carries the currently active executable batch, including leaves already
> closed inside that batch.
> Closed work lives in `PROJECT_ASSESSMENT.md`.
> `EPIC.md` stays the stable charter and release bar.

## Working Rules

- read `CONTRIBUTE` before starting, replenishing, or reshaping any `20`-issue batch
- keep the active batch visible here until it is exhausted; mark closed leaves as `[x]` instead of deleting them mid-batch
- every item must be narrow enough to implement and verify inside this repo
- if a tracker item is still too broad, split it before adding it here
- when a leaf closes, update code, touched comments/docblocks, tests, docs, `PROJECT_ASSESSMENT.md`, and `READYNESS_TRACKER.md` in the same change
- when a leaf closes, also verify the affected runtime with the strongest relevant tests/harnesses available before committing
- when a leaf closes, make exactly one commit for that checkbox; do not batch multiple checkbox closures into one commit
- do not pull new items from `READYNESS_TRACKER.md` into this file unless the user explicitly asks for the next `20`-issue batch or enables continuous batch execution
- when the current batch is exhausted, stop and wait instead of refilling it automatically unless continuous batch execution is explicitly enabled
- complete one checkbox per commit while an active batch is in flight
- do not shrink a meaningful v1 contract just to make tests, CI, or docs easier; if the intended contract matters, build the missing backend work or ask explicitly before reducing scope
- before opening, updating, or marking a PR ready, clear all outstanding GitHub AI findings for this repo at `https://github.com/Intelligent-Intern/king/security/quality/ai-findings`

## Per-Issue Closure Checklist

- update the runtime/backend code needed for the leaf
- update any touched comments, docblocks, headers, and contract wording so code and prose stay aligned
- add or tighten tests that prove the leaf on the strongest honest runtime path available
- update repo docs affected by the leaf
- update `PROJECT_ASSESSMENT.md`
- update `READYNESS_TRACKER.md`
- run the strongest relevant verification available for that leaf before committing
- make exactly one commit for the checkbox
- before any PR refresh or release-candidate handoff, re-check `https://github.com/Intelligent-Intern/king/security/quality/ai-findings` and fix every outstanding finding on the branch

## Batch Mode

- The user is advancing the current batch manually with `w`.
- Close exactly one checkbox, make exactly one commit, and then wait for the next `w`.
- When the current visible batch is exhausted, push `develop/v1.0.1-beta`, open the PR, and wait instead of auto-refilling from `READYNESS_TRACKER.md`.

## Current Next Leaf

- `#9 Define partitioning, fan-out/fan-in, and backpressure semantics for distributed dataflow execution on top of King runtime primitives.`

## Active Executable Items

### Q. Dataflow / ETL / Flow PHP Integration

King should not absorb ETL semantics as a hardwired C-core subsystem just
because the runtime can already transport, store, and orchestrate data. The
expected `Q` end-state is a userland-facing dataflow/ETL layer, such as `Flow
PHP`, running on top of King runtime primitives without losing the stronger
runtime guarantees that King already has around bounded-memory I/O, recovery,
real object-store backends, distributed execution, telemetry, and security.

The expected shape is:
- one reusable runtime/configuration model for secure storage and execution, rather than ad hoc per-pipeline arrays
- explicit adapters for source, sink, checkpoint, execution, telemetry, and schema concerns
- preservation of King object-store semantics such as integrity, expiry, multipart upload, range reads, recovery, and multi-backend topology instead of flattening them away behind a weaker ETL abstraction
- a real end-to-end proof that a dataflow pipeline can run locally and over remote workers while keeping restart recovery, backpressure, and observability intact

`*` Example code below is intentionally target-shape illustration for this
section. It shows the kind of API and runtime model this block is trying to
make real; it is not a claim that the exact userland surface already exists
today.

This block is intentionally mirrored here in full by explicit user request so
the current working queue matches the intended `Flow PHP` implementation area.
Where an item is still too broad for one repo-local change, split it before
closing it.
Keep docs in scope for each leaf so handbook, procedural API, and examples move
with the runtime instead of being deferred to a later cleanup pass.

- [x] `#1 Define the Flow PHP / ETL-on-King contract explicitly as a userland integration layer on top of King runtime services, not as hard-wired C-core pipeline semantics.`
  done when: the repo documents a stable integration boundary that treats King as runtime substrate and `Flow PHP`-style ETL as userland orchestration/dataflow semantics, without silently shrinking existing King runtime guarantees
- [x] `#2 Define a reusable object-store / dataflow runtime configuration model for secure storage topology, encryption, integrity, lifecycle, upload, and replication policy.`
  done when: one shared config object can describe primary plus replica/backups, credential sources, encryption mode, integrity policy, expiry/lifecycle policy, upload policy, and dataflow-facing checkpoint/temp-storage policy without every pipeline restating those concerns ad hoc
- [x] `#3 Implement a streaming source adapter contract on top of King object-store, MCP, HTTP, and other runtime-owned transports.`
  done when: a dataflow source can consume records or blobs from King-backed transports with bounded-memory reads, resume-aware progress, and backpressure instead of requiring whole-object materialization first
- [x] `#4 Implement a streaming sink adapter contract on top of King object-store, MCP, HTTP, and other runtime-owned transports.`
  done when: a dataflow sink can flush output through King-backed transports with bounded-memory writes, multipart/resumable upload where available, and explicit partial-failure handling
- [x] `#5 Implement a checkpoint-store contract for offsets, cursors, resumable progress, and replay boundaries on top of King persistence surfaces.`
  done when: checkpoint state survives restart, can be versioned and resumed honestly, and does not require ETL callers to invent their own persistence layer outside King
- [x] `#6 Implement an execution-backend contract that can run dataflow pipelines over King local, file-worker, and remote-peer orchestrator backends.`
  done when: a dataflow run can target the same verified King execution modes that the orchestrator already exposes, including restart-aware continuation and cancellation semantics
- [x] `#7 Implement a telemetry adapter contract that maps pipeline runs, partitions, batches, retries, and failures into King tracing, metrics, and runtime status.`
  done when: dataflow runs produce first-class King telemetry instead of opaque application logs, and pipeline observability preserves per-run and per-step identity across workers
- [x] `#8 Define stable error and retry taxonomy mapping between ETL/dataflow failures and King validation, runtime, transport, and backend failures.`
  done when: callers can distinguish invalid input, missing data, transient transport failure, backend outage, quota pressure, and retryable checkpoint/resume conditions without reverse-engineering adapter-specific strings
- [ ] `#9 Define partitioning, fan-out/fan-in, and backpressure semantics for distributed dataflow execution on top of King runtime primitives.`
  done when: distributed dataflow can split work predictably, merge it honestly, and keep memory/throughput bounded under slow consumers or uneven partitions
- [ ] `#10 Implement an object-store dataset bridge with bounded-memory streaming, range reads, multipart upload, integrity, expiry, and multi-backend topology semantics preserved.`
  done when: `Flow PHP`-style datasets can read and write through King object-store without discarding the stronger runtime semantics that now exist for local, distributed, and real cloud backends
- [ ] `#11 Implement schema / serialization bridges for JSON, CSV, NDJSON, IIBIN, Proto, and binary object payload workflows.`
  done when: dataflow pipelines can move between structured row formats and King-native binary/runtime formats without re-implementing serialization glue in every job
- [ ] `#12 Implement control-plane surfaces for start, pause, cancel, resume, inspect, and checkpoint-aware recovery of dataflow runs.`
  done when: dataflow runs can be controlled through explicit runtime state instead of hidden process-local control flow, and restart-aware resume can pick up from persisted checkpoints
- [ ] `#13 Validate a real end-to-end ETL/dataflow pipeline on top of King runtime services under local and remote-worker execution.`
  done when: the repo proves one non-trivial pipeline with secure object-store config, checkpointing, streaming source/sink adapters, telemetry, and orchestrated remote execution instead of only disconnected adapter slices

Examples `*`

```php
<?php

use King\ObjectStore\RuntimeConfig;
use King\ObjectStore\Backend\{S3, AzureBlob};
use King\ObjectStore\Encryption\{ServerSide, ClientSide};
use King\ObjectStore\{
    CheckpointPolicy,
    IntegrityPolicy,
    LifecyclePolicy,
    ReplicationPolicy,
    TemporaryStoragePolicy,
    UploadPolicy
};

$store = new RuntimeConfig(
    primary: new S3(
        bucket: 'etl-primary',
        endpoint: 'https://fsn1.your-s3.example',
        credentials: 'env:KING_S3_PRIMARY',
        encryption: new ServerSide('AES256'),
    ),
    replicas: [
        new AzureBlob(
            container: 'etl-replica',
            endpoint: 'https://etl.blob.core.windows.net',
            credentials: 'env:KING_AZURE_REPLICA',
            encryption: new ClientSide('vault:etl-replica-key'),
        ),
    ],
    integrity: new IntegrityPolicy(
        algorithm: 'sha256',
        verifyOnRead: true,
        verifyOnWrite: true,
    ),
    lifecycle: new LifecyclePolicy(
        ttlSeconds: 86400,
        purgeExpired: true,
    ),
    replication: new ReplicationPolicy(
        mode: 'async',
        minCopiesRequired: 2,
    ),
    uploads: new UploadPolicy(
        resumable: true,
        chunkSizeBytes: 8 * 1024 * 1024,
        parallelParts: 4,
    ),
    checkpoints: new CheckpointPolicy(
        objectPrefix: 'checkpoints/orders-import',
        resumeMode: 'latest_committed',
        retainVersions: 5,
    ),
    temporaryStorage: new TemporaryStoragePolicy(
        objectPrefix: 'tmp/orders-import',
        cleanup: 'on_success',
        maxBytes: 20 * 1024 * 1024 * 1024,
    ),
);
```

```php
<?php

use Flow\ETL\Flow;
use Flow\ETL\Adapter\King\KingRuntime;

$king = new KingRuntime(objectStore: $store);

Flow::extract($king->objectStore()->source('raw/orders/*.ndjson'))
    ->withCheckpointStore(
        $king->objectStore()->checkpointStore('checkpoints/orders-import')
    )
    ->map(fn (array $row) => [
        'id' => $row['id'],
        'country' => strtoupper($row['country']),
        'total' => (float) $row['total'],
    ])
    ->load(
        $king->objectStore()->sink('warehouse/orders/{country}/part-{partition}.parquet')
    )
    ->withTelemetry(
        $king->telemetry()->pipeline(
            serviceName: 'orders-etl',
            traceName: 'nightly-orders-import'
        )
    )
    ->run(
        $king->executionBackend(
            mode: 'remote_peer',
            workers: 12,
            maxConcurrency: 8,
            autoscaling: true
        )
    );
```

## Notes

- The active batch is now the full `Flow PHP` / ETL integration block imported from `READYNESS_TRACKER.md` section `Q` by explicit user request.
- The imported block is kept complete here so the next working area is visible in one place; broad items still need splitting before individual implementation/verification passes when necessary.
- Closed leaves inside the visible blocks stay in `ISSUES.md` as `[x]` until the release cut instead of being deleted early.
- The previous userland orchestrator wave is exhausted and its closed work now lives in `PROJECT_ASSESSMENT.md`, `READYNESS_TRACKER.md`, and `main`.
- If a task is not listed here, it is not the current repo-local execution item.
