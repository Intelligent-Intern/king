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

## Repo-Local Streaming Source Contract

The repository now also carries one real userland source contract under
[`../demo/userland/flow-php/README.md`](../demo/userland/flow-php/README.md) and
[`../demo/userland/flow-php/src/StreamingSource.php`](../demo/userland/flow-php/src/StreamingSource.php).

That code is intentionally repo-local. It is not presented as the final public
Composer package. The important thing for this phase is that the source
boundary is now real and test-backed instead of only described in the tracker.

The current source contract has three shared pieces:

- `SourceCursor`, a serializable progress snapshot that later checkpoint work
  can persist without inventing transport-specific ad hoc arrays
- `SourcePumpResult`, which reports completion, delivered chunks, delivered
  bytes, and the latest cursor
- `pumpBytes()` and `pumpLines()`, which keep byte/blob movement and simple
  record framing in one userland-facing contract

The current adapters are:

- `ObjectStoreByteSource`, which uses `king_object_store_get_to_stream()` plus
  byte-range offsets for direct bounded-memory chunk pulls and
  `resume_strategy=range_offset`
- `HttpByteSource`, which uses `response_stream` plus `King\Response::read()`
  for pull-based backpressure and `resume_strategy=replay_and_skip`
- `McpByteSource`, which uses `king_mcp_download_to_stream()` against a
  writable callback stream so the runtime only advances as the userland chunk
  callback returns, also with `resume_strategy=replay_and_skip`

Those resume strategies are intentionally explicit because they are not all the
same strength. Object-store can resume directly from byte offsets today. HTTP
and MCP are currently restart-aware by replaying from the beginning and
discarding already-consumed bytes until the saved cursor boundary is reached.
That is still a real resumable source contract, but it is honestly weaker than
transport-native range resume.

The current PHPT proof covers:

- object-store record streaming with resumable cursors:
  `600-flow-php-object-store-source-contract.phpt`
- HTTP byte streaming with replay-and-skip resume:
  `601-flow-php-http-source-contract.phpt`
- MCP transfer streaming with replay-and-skip resume:
  `602-flow-php-mcp-source-contract.phpt`

## Repo-Local Streaming Sink Contract

The repository now also carries one real userland sink contract under
[`../demo/userland/flow-php/README.md`](../demo/userland/flow-php/README.md) and
[`../demo/userland/flow-php/src/StreamingSink.php`](../demo/userland/flow-php/src/StreamingSink.php).

Again, that code is intentionally repo-local. The important point for this
phase is that sink behavior is now real and test-backed instead of being left
as a target-shape sentence in the tracker.

The current sink contract has three shared pieces:

- `SinkCursor`, a serializable progress snapshot that later checkpoint work
  can persist without inventing ad hoc per-transport write arrays
- `SinkWriteResult`, which reports current accepted bytes, accepted write
  count, terminal completion, transport commit status, and the latest cursor
- `SinkFailure`, which makes stage, category, retryability, and partial-failure
  state explicit instead of burying that meaning in one transport-specific
  exception string

The current adapters are:

- `ObjectStoreByteSink`, which uses provider-native
  `king_object_store_begin_resumable_upload()` /
  `king_object_store_append_resumable_upload_chunk()` /
  `king_object_store_complete_resumable_upload()` sessions on cloud primary
  backends and falls back to bounded local replay-spool staging plus
  `king_object_store_put_from_stream()` on non-cloud backends
- `HttpByteSink`, which uses `King\Session::sendRequest()` plus
  `King\Stream::send()`, `finish()`, and `receiveResponse()` for live
  request-body streaming with explicit terminal response state and
  `resume_strategy=restart_request`
- `McpByteSink`, which uses a bounded local replay spool plus
  `king_mcp_upload_from_stream()` / `MCP::uploadFromStream()` and
  `resume_strategy=replay_local_spool`

Those write strategies are intentionally not all the same strength.
Object-store can preserve a real upload-session cursor today on real cloud
primaries. HTTP has a live request-body stream, but no public mid-request
resume primitive, so honest recovery means replaying a fresh request. MCP has
large-payload upload, but not remote append/resume, so the honest adapter keeps
explicit local replay state and retries from byte zero instead of pretending
the transport has stronger semantics than it exposes.

The current PHPT proof covers:

- object-store resumable upload progress plus cursor-based resume:
  `603-flow-php-object-store-sink-contract.phpt`
- HTTP request-body streaming plus terminal response state:
  `604-flow-php-http-sink-contract.phpt`
- MCP upload failure, retained replay state, and later retry success:
  `605-flow-php-mcp-sink-contract.phpt`

## Repo-Local Object-Store Dataset Bridge Contract

The repository now also carries one real userland object-store dataset bridge
under [`../demo/userland/flow-php/README.md`](../demo/userland/flow-php/README.md) and
[`../demo/userland/flow-php/src/ObjectStoreDataset.php`](../demo/userland/flow-php/src/ObjectStoreDataset.php).

This piece exists because plain byte sources and sinks are not yet the full
dataset story. An ETL layer still needs one honest handle that can describe a
dataset, open bounded-memory range reads, and write through the same stronger
object-store semantics that King already exposes underneath.

The current dataset bridge has five shared pieces:

- `ObjectStoreDataset`, which keeps one object id plus chunk budget together
  and exposes `describe()`, `source()`, and `sink()` on top of the ordinary
  object-store runtime
- `ObjectStoreDatasetDescriptor`, which preserves content length, `etag`,
  version, integrity, expiry, object type, cache policy, and the raw metadata
  array instead of collapsing a dataset down to only bytes
- `ObjectStoreDatasetTopology`, which keeps local, distributed, and cloud
  backend presence flags, backup state, replication status, and distributed
  peer count explicit
- `ObjectStoreDatasetSource`, which opens bounded-memory object-store reads for
  the whole dataset or a caller-selected byte window with
  `resume_strategy=range_offset`
- `ObjectStoreDatasetWriter`, which keeps the existing `ObjectStoreByteSink`
  behavior available through the dataset handle, including staged local replay
  on non-cloud backends and provider-native resumable multipart upload sessions
  on cloud primaries

The important semantic point is that the bridge keeps stronger runtime
behavior visible instead of hiding it:

- range reads stay byte-windowed and resumable instead of forcing whole-object
  materialization before ETL code can start
- cloud dataset writes still preserve resumable multipart upload-session state
  instead of pretending every dataset write is just one opaque string put
- dataset metadata still carries integrity hashes, expiry, version, cache, and
  topology state so later ETL stages can reason about backup and residency
  honestly across local, distributed, and real cloud backends

The current PHPT proof covers:

- hybrid local-plus-distributed dataset descriptors plus bounded range reads:
  `615-flow-php-object-store-dataset-bridge-local-contract.phpt`
- cloud GCS resumable multipart upload, resumed completion, and streamed
  dataset readback through the bridge:
  `616-flow-php-object-store-dataset-bridge-cloud-contract.phpt`

## Repo-Local Serialization And Schema Bridge Contract

The repository now also carries one real repo-local serialization bridge under
[`../demo/userland/flow-php/README.md`](../demo/userland/flow-php/README.md) and
[`../demo/userland/flow-php/src/SerializationBridge.php`](../demo/userland/flow-php/src/SerializationBridge.php).

This piece exists because a dataset handle by itself still leaves one common
ETL burden unsolved: every job would otherwise have to rebuild the same
JSON/CSV/NDJSON parsing, the same `king_proto_*` or `King\IIBIN` glue, and the
same raw binary payload handoff on top of the object-store or stream layer.

The current serialization bridge has three layers:

- `SerializedRecordReader` and `SerializedRecordWriter`, which sit on top of
  the existing source and sink contracts and keep serialization-specific cursor
  state separate from the underlying transport cursor
- line-delimited codecs such as `NdjsonCodec` and `CsvCodec`, which can decode
  or encode records incrementally while preserving restart through the wrapped
  line cursor
- payload codecs such as `JsonDocumentCodec`, `ProtoSchemaCodec`,
  `IibinSchemaCodec`, and `BinaryObjectCodec`, which keep JSON document, proto,
  IIBIN, and binary-object workflows on the same bridge while being explicit
  that current decode surfaces are whole-payload APIs and therefore resume by
  replaying the payload rather than by mid-message binary continuation

The important semantic point is that framing stays honest instead of hidden:

- NDJSON and CSV are true line-delimited record streams on top of bounded
  source and sink movement
- JSON document workflows are one payload record per object and currently use
  `replay_document`
- IIBIN, Proto, and raw binary object workflows are payload-oriented and
  currently use `replay_payload`, because `king_proto_decode()`,
  `King\IIBIN::decode()`, and raw object consumers all operate on complete
  payload bytes rather than a public incremental message-decoder surface

The current PHPT proof covers:

- NDJSON resume, CSV header-aware write/read, and JSON document replay through
  the bridge:
  `617-flow-php-serialization-bridge-text-contract.phpt`
- Proto, IIBIN, and raw binary object payload round-trips through the bridge:
  `618-flow-php-serialization-bridge-binary-contract.phpt`

## Repo-Local Checkpoint Store Contract

The repository now also carries one real userland checkpoint-store contract
under [`../demo/userland/flow-php/README.md`](../demo/userland/flow-php/README.md) and
[`../demo/userland/flow-php/src/CheckpointStore.php`](../demo/userland/flow-php/src/CheckpointStore.php).

This piece exists because restart-aware source and sink adapters are not enough
by themselves. A pipeline still needs one honest durable place to persist the
latest offsets, cursors, and replay boundary that should survive process loss
and later resume.

The current checkpoint contract has four shared pieces:

- `CheckpointState`, which packages offsets, replay boundary state, source
  cursor state, sink cursor state, and arbitrary progress metadata into one
  serializable value
- `CheckpointRecord`, which adds the real object-store `etag`, `version`,
  metadata snapshot, and committed object identity around one saved state
- `CheckpointCommitResult`, which makes successful commits and version
  conflicts explicit instead of collapsing them into silent last-writer-wins
- `ObjectStoreCheckpointStore`, which persists checkpoints on the ordinary
  King object-store surface using integrity hashes plus `if_none_match`,
  `if_match`, and `expected_version` preconditions

The current repo-local implementation intentionally maps logical
`prefix + checkpoint_id` namespaces onto object-store-safe IDs. That is not a
quirk of the helper layer. It is the honest adapter to the current runtime,
which does not allow raw `/` path separators inside public object ids.

The important semantic point is stronger than the key-shape detail:

- checkpoint state is durable on a real King persistence surface
- full checkpoint reads validate stored integrity on the ordinary object-store
  path
- later writers must present the last committed `etag` and `version` or they
  get an explicit conflict result instead of silently overwriting newer state
- saved `SourceCursor` and `SinkCursor` snapshots can be reconstructed after a
  restart without ETL callers inventing a second persistence system

The current PHPT proof covers:

- local object-store restart, reload, and cursor reconstruction:
  `606-flow-php-checkpoint-store-restart-contract.phpt`
- stale-writer conflict reporting through real object-store preconditions:
  `607-flow-php-checkpoint-store-conflict-contract.phpt`

## Repo-Local Execution Backend Contract

The repository now also carries one real userland execution-backend contract
under [`../demo/userland/flow-php/README.md`](../demo/userland/flow-php/README.md) and
[`../demo/userland/flow-php/src/ExecutionBackend.php`](../demo/userland/flow-php/src/ExecutionBackend.php).

This piece exists because bounded-memory transport adapters and durable
checkpoints still do not answer one operational question: which King execution
boundary owns the run right now, and what does resume or cancel honestly mean
on that boundary.

The current contract keeps that answer explicit instead of flattening it into
one pretend helper method:

- `ExecutionBackendCapabilities` surfaces the active backend, topology scope,
  submission mode, continuation mode, claim mode, cancellation mode, and the
  controller-versus-executor handler duties for that backend
- `ExecutionRunSnapshot` wraps the persisted orchestrator snapshot instead of
  inventing a separate ETL-only run registry
- `OrchestratorExecutionBackend` keeps durable tool registration,
  process-local handler registration, start, resume, claim, inspect, and
  cancel on top of the already-proven orchestrator runtime

The important behavior is backend-specific on purpose:

- `local` runs execute immediately after controller-side tool plus handler
  registration, and restart-aware continuation stays on
  `continueRun($runId)` against the persisted local snapshot
- `file_worker` runs queue through `start()`, but userland-backed queued steps
  still need controller-side handler registration first so the persisted
  `handler_boundary` can honestly name which tool refs a worker must satisfy
- `file_worker` workers re-register the executable handlers locally and use
  `claimNext()` for both fresh queued work and recovered claimed work; a
  queued run cancelled before claim is already terminal, so the later worker
  claim returns `false` instead of pretending there is still a live in-flight
  cancellation window
- `remote_peer` runs execute immediately from the controller side, but the
  peer still owns the executable handlers; restart-aware continuation re-sends
  only the durable boundary plus tool config through `continueRun($runId)`
  without claiming controller PHP callables crossed the network

The current PHPT proof covers:

- local controller restart and persisted resume through the wrapper contract:
  `608-flow-php-execution-backend-local-contract.phpt`
- file-worker queue submission, worker claim, and pre-claim cancellation:
  `609-flow-php-execution-backend-file-worker-contract.phpt`
- remote-peer controller loss, durable boundary replay, and resumed completion:
  `610-flow-php-execution-backend-remote-peer-contract.phpt`

## Repo-Local Control-Plane Contract

The repository now also carries one real userland control-plane helper under
[`../demo/userland/flow-php/README.md`](../demo/userland/flow-php/README.md) and
[`../demo/userland/flow-php/src/ControlPlane.php`](../demo/userland/flow-php/src/ControlPlane.php).

This piece exists because execution capabilities plus checkpoint persistence
still leave one operational gap: a dataflow run needs one explicit place where
`start`, `pause`, `cancel`, `resume`, `inspect`, and replacement recovery live
as durable runtime state instead of as controller-memory branching.

The current contract keeps that state explicit:

- `ObjectStoreFlowControlStore` persists one control record per logical
  dataflow run on the same ordinary King object-store surface already used by
  checkpoints
- `FlowControlPlane` composes `ExecutionBackend`, `CheckpointStore`, and the
  control store into one repo-local userland control surface instead of
  inventing a second hidden run registry
- `CheckpointRecoveryPlan` keeps the recovery boundary honest by saying whether
  replacement starts from checkpoint state, checkpoint progress, or persisted
  initial input merged with that checkpoint material
- `FlowControlSnapshot` returns the stored control record plus the current
  backend snapshot and checkpoint record so callers can inspect both the
  durable intent and the live execution state together

The important behavior is backend-specific on purpose:

- `file_worker` pause and cancel use the stronger persisted queued-run
  cancellation path, so a run paused before worker claim becomes
  `pause_mode=cancelled_before_claim` instead of pretending a live worker still
  owns it
- `local` and `remote_peer` control-plane start persists a control record
  before the blocking immediate-run call returns by using the orchestrator's
  stable sequential `run-N` identity, which keeps `inspect()` honest during an
  already-running controller-owned run
- `resume()` continues the same persisted local or remote run when the
  execution backend honestly supports `continueRun($runId)`, but starts a new
  replacement run when the stored control state says pause/cancel/failure now
  requires recovery instead
- when a checkpoint exists, replacement recovery records the checkpoint ID and
  version inside the new run options; when no checkpoint exists, the control
  plane still has enough persisted state to restart from the original initial
  input instead of failing closed into controller-memory assumptions

The current PHPT proof covers:

- queued file-worker start, inspect, pause, cancel, and checkpoint-aware
  replacement recovery:
  `619-flow-php-control-plane-file-worker-contract.phpt`
- local immediate-run inspectability during controller execution plus
  controller-loss resume through the same control-plane wrapper:
  `620-flow-php-control-plane-local-resume-contract.phpt`

## Repo-Local Failure Taxonomy Contract

The repository now also carries one real userland failure-taxonomy helper
under [`../demo/userland/flow-php/README.md`](../demo/userland/flow-php/README.md) and
[`../demo/userland/flow-php/src/FailureTaxonomy.php`](../demo/userland/flow-php/src/FailureTaxonomy.php).

This piece exists because restart-aware sources, sinks, checkpoints, and
execution backends still leave one important question open for ETL callers:
what failed, and what kind of retry is still honest?

The current contract keeps that answer explicit instead of forcing callers to
reverse-engineer adapter-specific exception strings or provider messages:

- `FlowFailure`, which packages one stable surface-level failure with its
  surface, stage, category, reason, retry disposition, retryability, backend,
  transport, summary, and raw message detail
- `FlowFailureTaxonomy`, which normalizes source, sink, checkpoint, and
  execution outcomes into one repo-local contract instead of four unrelated
  error stories

The current stable categories and retry dispositions include:

- `validation` with `non_retryable` for invalid cursor, contract, or input
  shape failures
- `missing_data` with `wait_for_data` when the requested payload or replay
  material is not present yet
- `transport` with `retry_with_backoff` for retryable network, stream, or
  throttling pressure, and `non_retryable` for protocol or TLS faults
- `quota` with `retry_after_quota_relief` when a backend reports exhausted
  capacity or storage quota
- `resume_conflict` with `reload_checkpoint_and_resume` when checkpoint state
  changed under a stale writer
- `backend` with `retry_after_backend_recovery` when the configured execution
  or storage backend could not accept the work
- `runtime` with `caller_managed_retry` when the runtime hit a non-validation
  execution fault that still belongs to the caller's compensation or retry
  policy
- `timeout`, `missing_handler`, and `cancelled` so ETL callers can preserve
  the stronger control and execution distinctions the orchestrator already
  exposes instead of flattening them into generic runtime failure

The important behavior is cross-surface on purpose:

- source adapters can classify invalid resume cursors, missing payloads, and
  transient transport outages without inventing a separate ETL-only error enum
- sink adapters keep explicit partial-failure state on `SinkFailure`, then map
  quota, throttling, runtime, and backend outcomes into the same stable
  taxonomy
- checkpoint writes preserve stale-writer conflicts as a retryable
  `resume_conflict` instead of falling back to silent overwrite or generic
  runtime failure
- execution snapshots can be read back through the same taxonomy so later ETL
  control logic can distinguish runtime, backend, timeout, missing-handler,
  and cancellation outcomes from persisted orchestrator state

The current PHPT proof covers:

- source validation, missing-data, transport, and sink quota mapping:
  `611-flow-php-failure-taxonomy-source-sink-contract.phpt`
- checkpoint stale-writer conflict plus execution runtime and backend mapping:
  `612-flow-php-failure-taxonomy-checkpoint-execution-contract.phpt`

## Repo-Local Partitioning And Backpressure Contract

The repository now also carries one real userland partitioning and
backpressure helper under
[`../demo/userland/flow-php/README.md`](../demo/userland/flow-php/README.md) and
[`../demo/userland/flow-php/src/Partitioning.php`](../demo/userland/flow-php/src/Partitioning.php).

This piece exists because distributed ETL still needs one honest answer to
three operational questions:

- how work is split into partitions and bounded batches
- where `partition_id` and `batch_id` live on the actual runtime surface
- how the controller decides whether fan-out should keep dispatching or wait

The current contract keeps those answers explicit instead of hiding them in
controller-local loops:

- `PartitionPlan::fromRowsByField()` builds a deterministic plan by sorting
  normalized partition keys, preserving row order inside each partition, and
  then cutting bounded batches by `maxBatchRecords` and `maxBatchBytes`
- `PartitionBatch` carries the stable `partition_id`, `batch_id`, record
  count, estimated bytes, and one `annotateStep()` helper that writes
  partition identity onto the actual orchestrator step definition
- `PartitionAttempt::fromExecutionSnapshot()` reads partition and batch
  identity back from the persisted orchestrator snapshot instead of inventing a
  second ETL-only run registry
- `PartitionBackpressureWindow` gates further dispatch from live active
  attempts, respecting backend submission mode, queue pressure, concurrent
  batch ceilings, and active-partition ceilings
- `PartitionMergeResult` only merges completed batches in explicit
  `partition_then_batch` order, reports pending or failed batches separately,
  and therefore does not pretend distributed fan-in can recreate one hidden
  original interleaving once work has been partitioned

The important runtime rule is that partition and batch identity belongs on the
step boundary, not on a made-up controller side-channel. On the current King
runtime, the durable step snapshot is where `partition_id` and `batch_id`
become visible for distributed ETL accounting, recovery, and later fan-in.

The important backpressure rule is backend-sensitive on purpose:

- `queue_dispatch` backends such as `file_worker` can cap both total active
  batches and still-queued batches before claim
- `run_immediately` backends use the same active-batch and active-partition
  ceilings, but there is no separate queue budget because the backend does not
  persist a queued pre-claim phase
- hot or uneven partitions can continue within the currently admitted active
  partition set, but the contract can still block fan-out into additional
  partitions until the window drains

The current PHPT proof covers:

- deterministic partition IDs, bounded batch cutting, step-identity
  annotation, and honest merge order:
  `613-flow-php-partition-plan-contract.phpt`
- real file-worker queued snapshot gating, queue-budget enforcement,
  active-partition enforcement, and relief after the queue drains:
  `614-flow-php-backpressure-window-contract.phpt`

## Repo-Local End-To-End ETL Proof

The repository now also carries one end-to-end ETL/dataflow proof in
[`../extension/tests/621-flow-php-etl-e2e-local-remote-contract.phpt`](../extension/tests/621-flow-php-etl-e2e-local-remote-contract.phpt).

That proof intentionally composes the repo-local helpers from this chapter
instead of testing them as isolated leaves:

- raw NDJSON input is written through the ordinary object-store sink path with
  integrity material, expiry, and hybrid `local_fs + distributed` topology
- `SerializedRecordReader` plus `ObjectStoreByteSource` perform one partial
  bounded read, persist the resumable `SourceCursor` into
  `ObjectStoreCheckpointStore`, and then resume from that saved boundary
- `PartitionPlan::fromRowsByField()` turns the recovered rows into stable
  `partition_id` plus `batch_id` work units
- `OrchestratorExecutionBackend` executes the transform locally and through a
  real TCP `remote_peer`, while output rows are written back through the same
  NDJSON sink and read again through `ObjectStoreDataset`
- `PartitionAttempt` and `PartitionMergeResult` read the persisted execution
  snapshots back instead of inventing ETL-only shadow state

The current end-to-end proof is intentionally honest about observability
surfaces:

- the `local` path proves exported OTLP metrics and spans by flushing each
  completed batch to a real collector harness
- the `remote_peer` path proves the real handler boundary and execution result
  through persisted run snapshots plus the captured peer-side server events
- the same harness does not currently claim collector-visible OTLP export from
  that remote-peer controller path; it only claims the remote execution,
  checkpoint, manifest, and durable boundary behavior that the test actually
  sees

## What This Chapter Does Not Claim Yet

This chapter is a contract statement, not a claim that every adapter in that
shape already ships today.

It does not claim:

- that King already exposes a finished built-in ETL framework in C
- that the repo already ships a complete public Flow PHP adapter package
- that `King\ObjectStore\RuntimeConfig` and the policy classes above are
  already the exact exported runtime surface today
- that every possible storage, checkpoint, execution, telemetry, and schema
  bridge combination is already implemented end to end
- that target-shape examples elsewhere in the repo are already the exact live
  public PHP surface

The honest claim is narrower and more useful: the repository now states
clearly where ETL semantics belong, which runtime services they build on, and
which stronger King guarantees later adapter work must preserve instead of
flattening away. It also now proves one concrete object-store-backed ETL run
end to end on the local execution path plus the real `remote_peer` boundary.

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
