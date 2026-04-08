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

## What This Chapter Does Not Claim Yet

This chapter is a contract statement, not a claim that every adapter in that
shape already ships today.

It does not claim:

- that King already exposes a finished built-in ETL framework in C
- that the repo already ships a complete public Flow PHP adapter package
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
