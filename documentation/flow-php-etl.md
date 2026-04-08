# Flow PHP and ETL on King

This chapter defines the boundary between King and a userland dataflow or ETL
library such as Flow PHP.

King is the runtime substrate. A Flow PHP-style layer stays in userland. That
split is intentional. It preserves the stronger runtime guarantees King
already has around storage, execution, transport, telemetry, recovery, and
security instead of pretending the C core should become a hard-wired ETL DSL.

## Start With The Boundary

If a workload needs extract, transform, load, batching, fan-out, fan-in,
checkpointing, or replay, the first question is not "which side owns the
loop?" The first question is "which side owns the semantics?"

King already owns runtime concerns:

- bounded-memory transport and streaming I/O
- durable object-store backends and their integrity, expiry, multipart, range,
  and recovery semantics
- orchestrated execution boundaries for local, file-worker, and remote-peer
  work
- telemetry, runtime status, and operational visibility
- runtime configuration, credentials, encryption policy, and lifecycle policy

A Flow PHP-style ETL layer owns different concerns:

- row, record, and dataset semantics
- transforms, joins, windows, projections, and aggregations
- application-specific schema mapping and domain validation
- job composition and the userland API that makes pipelines pleasant to write

That is the stable contract this repository is documenting. King does not need
to absorb ETL semantics into the C core in order to be the right runtime for
ETL workloads.

## Why The Split Exists

The split keeps two different jobs from being blurred together.

The first job is systems-runtime ownership: move bytes, keep durable state,
recover after restart, coordinate work, emit telemetry, and enforce security
or lifecycle policy. The second job is dataflow meaning: define what a row is,
how transforms compose, how partitions behave, and what a successful ETL run
means for one application.

Keeping those jobs separate avoids two bad outcomes:

- King does not flatten strong runtime guarantees into a weaker "just hand the
  library a file path or array" abstraction.
- A userland ETL layer does not get locked to one hardcoded C-core notion of
  rows, operators, or pipeline shape.

## Runtime Substrate Versus ETL Layer

| Concern | King owns | The ETL layer owns |
| --- | --- | --- |
| Storage durability | Object-store backends, integrity, expiry, multipart upload, range reads, restore, replication, and recovery semantics. | Choosing how a pipeline reads or writes a dataset through those storage surfaces. |
| Execution | Local, file-worker, and remote-peer orchestration boundaries, persisted run state, cancellation, and continuation semantics. | How a pipeline decomposes work into stages, partitions, or userland transforms. |
| Telemetry | Spans, metrics, logs, runtime status, and the stable operational identity of work. | Mapping pipeline runs, batches, partitions, and retries onto those telemetry surfaces. |
| Transport and I/O | HTTP, MCP, binary payload transport, and bounded-memory stream handling. | Source and sink adapters that use those runtime-owned transports. |
| Security and config | Credentials, encryption policy, lifecycle policy, and deployment-scoped runtime config. | Which runtime config a job selects and how a pipeline exposes that selection ergonomically. |
| Recovery | Durable state surfaces and checkpoint-capable persistence substrates. | What the pipeline chooses to checkpoint, replay, or resume at the dataflow level. |

The important rule is that future ETL adapters must preserve the stronger
runtime contract they sit on top of. A dataset bridge should not erase
object-store integrity or lifecycle semantics. A distributed ETL runner should
not quietly reduce execution to inline-only local calls if the runtime already
has honest local, file-worker, and remote-peer backends.

## The Integration Direction

The repository's intended ETL shape is a userland layer that targets King
runtime services through explicit adapter boundaries.

Those boundaries include:

- source and sink adapters over object-store, MCP, HTTP, and other runtime
  transports
- checkpoint persistence on top of King durability surfaces instead of
  pipeline-private temp files
- execution backends that reuse the orchestrator's local, file-worker, and
  remote-peer runtime boundaries
- telemetry adapters that map pipeline identity into first-class King spans,
  metrics, logs, and runtime status
- schema and serialization bridges that move between userland row models and
  King-facing payload formats such as JSON, CSV, NDJSON, IIBIN, Proto, and
  other binary object workflows

For execution specifically, Flow PHP-style transforms that run on workers or
remote peers follow the same rule already documented for the pipeline
orchestrator: durable state stores stable runtime identity and config, while
executable userland handlers remain process-local and must be registered by
the process that will actually execute them.

## One Reusable Runtime Configuration Model

The next boundary after "ETL stays in userland" is "how userland selects the
runtime."

One pipeline should not restate storage topology in one array, upload policy in
another helper, integrity defaults in ad hoc write options, lifecycle policy in
scattered metadata calls, and checkpoint or temp-storage prefixes in random job
code. That repeats one runtime contract in too many places.

The target shape is one reusable userland configuration object that packages
the runtime decisions a dataflow job depends on.

`*` The example below is still target-shape documentation. It makes the
contract concrete, but it is not a claim that the exact classes are already the
live exported PHP surface today.

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

The important design point is not the class name. The important design point is
that source, sink, checkpoint, and temp-storage adapters all receive the same
runtime descriptor instead of each inventing their own partial config story.

## How The Target Shape Maps To The Real Runtime

The current extension already exposes the low-level pieces that this config
model must compose. The reusable ETL runtime object sits above those pieces; it
does not replace or fictionalize them.

| Target-shape field | Current real runtime surfaces it wraps | What that grouping achieves |
| --- | --- | --- |
| `primary`, `replicas`, and credential references | `king_object_store_init()` config such as `primary_backend`, `backup_backend`, `storage_root_path`, `cloud_credentials`, plus runtime `storage.*` topology settings | One job-level description of where durable data lives and which replica or backup routes belong to the same pipeline family. |
| `integrity` | Write options such as `integrity_sha256`, full-read validation, restore-time validation, and import-time validation | One place to state whether the ETL job requires integrity material and when verification is mandatory. |
| `lifecycle` | Object metadata such as `expires_at` and `cache_ttl_sec`, plus object-store cleanup behavior | One default policy for TTL, expiry, and cleanup instead of per-step folklore. |
| `replication` | `replication_factor`, `storage.default_replication_factor`, and `storage.default_redundancy_mode` | One explicit durability policy for the pipeline's outputs and checkpoints. |
| `uploads` | `chunk_size_kb` plus the resumable upload family beginning at `king_object_store_begin_resumable_upload()` | One bounded-memory upload policy for sinks and checkpoint writers. |
| `checkpoints` | Ordinary King object-store prefixes and metadata on top of the same durable store | Checkpoint state stays on the real King persistence surface instead of on ad hoc temp files outside the runtime. |
| `temporaryStorage` | Ordinary King object-store prefixes, lifecycle metadata, and cleanup policy on the same durable store | Temp or staging payloads follow the same security, lifecycle, and storage-topology contract as the rest of the job. |

The same object should also carry credential references rather than raw secret
material where possible. `env:...`, file-path, or vault-style references are
the stronger contract for worker and remote-peer boundaries than copying secret
bytes through arbitrary process-local arrays.

## Rules For Future Adapters

Future Flow PHP-style adapters on King should follow these rules:

- select one runtime config object per logical dataset family or pipeline run
  and pass it to sources, sinks, checkpoint stores, and temp-storage helpers
- treat `storage.*`, `tls.*`, and object-store write or upload options as the
  authoritative low-level runtime surfaces underneath that wrapper
- keep checkpoint and temporary-state policy on King durability surfaces unless
  a weaker out-of-band store is explicitly chosen and documented
- preserve credential references and durable config identity across local,
  file-worker, and remote-peer boundaries instead of smuggling live process
  state through execution backends
- keep the config object declarative; it describes topology and policy, not
  executable callbacks or process-local resources

## What This Chapter Does Not Claim Yet

This chapter is a contract statement, not a claim that every adapter in that
shape already ships today.

It does not claim:

- that King already exposes a finished built-in ETL framework in C
- that the repo already ships a complete public Flow PHP adapter package
- that `King\ObjectStore\RuntimeConfig` and the policy classes above are
  already the exact exported runtime surface today
- that every storage, checkpoint, execution, telemetry, and schema bridge is
  already implemented end to end
- that target-shape examples elsewhere in the repo are already the exact live
  public PHP surface

The honest claim is narrower and more useful: the repository now states
clearly where ETL semantics belong, which runtime services they build on, and
which stronger King guarantees later adapter work must preserve instead of
flattening away.

## How To Read This With The Rest Of The Handbook

Read these chapters together:

- [Object Store and CDN](./object-store-and-cdn.md) for durable payload,
  integrity, expiry, range, restore, and multi-backend storage behavior
- [Pipeline Orchestrator](./pipeline-orchestrator.md) for execution boundaries,
  persistence, recovery, and process-local handler duties
- [Telemetry](./telemetry.md) for spans, metrics, logs, and exported runtime
  identity
- [MCP](./mcp.md) and [HTTP Clients and Streams](./http-clients-and-streams.md)
  for runtime-owned transfer and stream behavior
- [Procedural API Reference](./procedural-api.md) for the exact low-level
  runtime entry points that userland adapters will call
