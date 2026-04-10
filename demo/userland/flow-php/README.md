# Repo-Local Flow PHP Userland Helpers

This directory holds repo-local PHP helpers for the active Flow PHP / ETL
integration batch.

It is not a published Composer package and it is not presented as the final
public package layout. The point is to keep real userland adapter code in the
repository while the contract is still being proven.

The current source, sink, dataset-bridge, serialization/schema bridge,
checkpoint, execution-backend, control-plane, failure-taxonomy, and
partitioning/backpressure contracts live in
`demo/userland/flow-php/src/StreamingSource.php`,
`demo/userland/flow-php/src/StreamingSink.php`, and
`demo/userland/flow-php/src/ObjectStoreDataset.php`, and
`demo/userland/flow-php/src/SerializationBridge.php`, and
`demo/userland/flow-php/src/CheckpointStore.php`, and
`demo/userland/flow-php/src/ExecutionBackend.php`, and
`demo/userland/flow-php/src/ControlPlane.php`, and
`demo/userland/flow-php/src/FailureTaxonomy.php`, and
`demo/userland/flow-php/src/Partitioning.php`.

Current helpers:

- `King\Flow\ObjectStoreByteSource`
- `King\Flow\HttpByteSource`
- `King\Flow\McpByteSource`
- `King\Flow\SourceCursor`
- `King\Flow\SourcePumpResult`
- `King\Flow\ObjectStoreByteSink`
- `King\Flow\HttpByteSink`
- `King\Flow\McpByteSink`
- `King\Flow\SinkCursor`
- `King\Flow\SinkWriteResult`
- `King\Flow\SinkFailure`
- `King\Flow\ObjectStoreDataset`
- `King\Flow\ObjectStoreDatasetDescriptor`
- `King\Flow\ObjectStoreDatasetTopology`
- `King\Flow\ObjectStoreDatasetSource`
- `King\Flow\ObjectStoreDatasetWriter`
- `King\Flow\SerializedRecordReader`
- `King\Flow\SerializedRecordWriter`
- `King\Flow\SerializedRecordPumpResult`
- `King\Flow\SerializedRecordWriteResult`
- `King\Flow\JsonDocumentCodec`
- `King\Flow\NdjsonCodec`
- `King\Flow\CsvCodec`
- `King\Flow\ProtoSchemaCodec`
- `King\Flow\IibinSchemaCodec`
- `King\Flow\BinaryObjectCodec`
- `King\Flow\BinaryObjectPayload`
- `King\Flow\ObjectStoreCheckpointStore`
- `King\Flow\CheckpointState`
- `King\Flow\CheckpointRecord`
- `King\Flow\CheckpointCommitResult`
- `King\Flow\ExecutionBackendCapabilities`
- `King\Flow\ExecutionRunSnapshot`
- `King\Flow\PredictiveRunIdExecutionBackend`
- `King\Flow\OrchestratorExecutionBackend`
- `King\Flow\FlowControlStore`
- `King\Flow\FlowControlRecord`
- `King\Flow\FlowControlCommitResult`
- `King\Flow\ObjectStoreFlowControlStore`
- `King\Flow\CheckpointRecoveryPlan`
- `King\Flow\FlowControlSnapshot`
- `King\Flow\FlowControlPlane`
- `King\Flow\FlowFailure`
- `King\Flow\FlowFailureTaxonomy`
- `King\Flow\PartitionBatch`
- `King\Flow\PartitionPlan`
- `King\Flow\PartitionAttempt`
- `King\Flow\PartitionBackpressureWindow`
- `King\Flow\PartitionDispatchDecision`
- `King\Flow\PartitionMergeResult`

The contract is intentionally small:

- pump bounded byte chunks without whole-payload materialization
- surface a serializable cursor after each delivered chunk
- allow restart by replay-and-skip or direct range-offset resume, depending on
  transport
- layer line-oriented record consumption on top through `pumpLines()`
- flush bounded byte writes without inventing whole-payload string staging as
  the public contract
- keep partial-failure state explicit through serializable sink cursors and
  failure results instead of transport-specific folklore
- surface dataset descriptors that keep integrity, expiry, version, and
  multi-backend topology visible instead of reducing object-store datasets to
  anonymous file-like handles
- expose bounded-memory range-window dataset reads and preserve cloud multipart
  upload-session resume through the dataset bridge instead of forcing ETL
  callers to flatten those runtime semantics back into whole-object strings
- keep schema and serialization glue in one userland bridge so JSON, CSV,
  NDJSON, IIBIN, Proto, and raw binary payload workflows can reuse the same
  dataset and source/sink boundaries instead of reimplementing per-job decode
  and encode plumbing
- make framing honest: line-delimited text formats stream record-by-record,
  while JSON documents plus IIBIN/Proto/binary payload codecs currently replay
  the full payload because the underlying runtime decode surfaces are
  whole-payload string APIs
- persist offsets, source cursors, sink cursors, and replay boundaries on real
  King durability surfaces with explicit version-conflict reporting
- expose backend capabilities instead of pretending `local`, `file_worker`,
  and `remote_peer` all share one hidden execution path
- expose telemetry-adapter, distributed-observability, and step snapshots
  directly from `ExecutionRunSnapshot` so partition and batch identity can be
  read back from the persisted orchestrator surface instead of shadow state
- preserve the durable tool-name boundary separately from process-local handler
  registration duties across controller, worker, and peer processes
- map restart-aware continuation honestly: `continueRun()` for persisted
  `local` or `remote_peer` runs, `claimNext()` for queued or recovered
  `file_worker` runs
- treat pre-claim file-worker cancellation as already-terminal queue state
  rather than pretending the worker still owns a live in-flight cancel path
- persist an explicit control-plane record on the ordinary object-store path so
  `start`, `pause`, `cancel`, `resume`, `inspect`, and checkpoint-aware
  recovery are restart-visible runtime state instead of controller-memory
  folklore
- keep immediate `local` and `remote_peer` control records inspectable during a
  live run by persisting the predicted sequential orchestrator `run-N` identity
  before the blocking start call returns
- map queued file-worker pause and cancel onto the stronger persisted
  `cancelRun()` contract, but keep running local or remote pause/cancel
  requests honest about their intent-only boundary unless a checkpoint-driven
  replacement run is started
- allow resume to continue the same persisted local or remote run when that
  stronger contract exists, or start a new run from persisted initial data or
  checkpoint progress when the durable control record says replacement is the
  honest recovery path
- map source, sink, checkpoint, and execution failures onto one stable
  category-plus-retry taxonomy instead of forcing ETL callers to parse
  transport-specific exception strings
- split dataflow work into deterministic partition and batch plans with
  bounded per-batch records and bytes, annotate the actual orchestrator step
  boundary with `partition_id` plus `batch_id`, merge completed results only
  in honest `partition_then_batch` order, and gate new fan-out through live
  queue plus active-partition backpressure windows

The current repo-local end-to-end proof is
`extension/tests/621-flow-php-etl-e2e-local-remote-contract.phpt`. It composes
the source, sink, checkpoint, partitioning, dataset, serialization, and
execution-backend helpers into one object-store-backed NDJSON pipeline, proves
local OTLP export on the controller-owned path, and separately proves real
`remote_peer` execution through the captured durable handler boundary.
