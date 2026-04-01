# KING V1 FINAL COMPLETION CHECKLIST

Goal:
King is only finished when every exported capability is fully real, deterministically buildable, operationally reliable, failure-tolerant, upgrade-safe, documented, and supportable for the long term.

Status note:
- Checked boxes below mean the current tree already verifies that slice directly in code, tests, or an honest fenced v1 contract.
- Unchecked boxes are still open, intentionally fenced out of the current v1 slice, or broader than the proof that exists in this repository today.
- This file is the long-form closure tracker, not the active execution queue. `ISSUES.md` stays the narrow working backlog.
- Open boxes are not supposed to be "closed" by redefining the product downward. If a stronger shared, remote, persistent, or otherwise meaningful v1 contract already belongs to King, the default action is to implement it correctly rather than quietly shrinking scope.
- `ISSUES.md` now carries the next `20` repo-local executable leaves distilled from this tracker. Everything else stays here until it is split to that size.
- Recent orchestrator closure: worker-loss recovery, deterministic file-worker claim ordering, concurrent claim locking, sustained fairness under contention, and real TCP host/port `remote_peer` execution with persisted success/failure snapshots are now verified; the remaining open boxes below are the broader continuation, observability, and multi-host slices.
- Recent telemetry export closure: metrics, traces, and logs now share the bounded batch/retry path, are verified against real local collectors for success plus non-2xx, timeout, response-size-limit, and outage-recovery slices, and now expose an explicit process-local non-persistent non-replay delivery contract across restart; the remaining open boxes below are richer ordering/idempotency guarantees, longer-haul characterization, and stronger diagnostics.
- Recent QUIC bootstrap closure: the build path now rehydrates a pinned `quiche` commit, pinned BoringSSL submodule commit, tracked workspace lockfile, and pinned `wirefilter` revision without branch-based fallbacks or unlocked cargo retries.
- Recent Smart-DNS closure: larger-topology mother-node churn now preserves coherent local persisted-state sync statistics across concurrent multiprocess writers; the broader real-topology and split-brain boxes below remain open.
- Recent security closure: HTTP/2 one-shot cumulative body caps, HTTP/3 one-shot full-body completion, MCP persisted transfer-key truncation fixes plus loopback-default peer targeting, bounded object-store metadata-cache growth, CRLF-safe cloud metadata headers, TOCTOU-safe local/distributed object-store reads, trusted workflow-run source materialization, and loopback-default Semantic DNS live probe allowlists are now verified on the current mainline; the broader full-surface security review remains open below.

## A. Transport / QUIC / HTTP / WebSocket

- [x] Validate full HTTP/1 client behavior on-wire against real servers
- [x] Validate HTTP/1 redirect following against real redirect chains
- [x] Validate HTTP/1 keep-alive reuse against real servers
- [x] Validate HTTP/1 streaming response path against real chunked responses
- [x] Validate HTTP/1 bodiless responses against real servers
- [x] Validate HTTP/1 Content-Length responses against real servers
- [x] Validate HTTP/1 failure paths against real connection aborts
- [x] Validate HTTP/1 timeout behavior against real slow servers
- [x] Validate HTTP/1 connection reuse limits under load
- [ ] Validate HTTP/1 header normalization under real traffic

- [x] Validate full HTTP/2 client behavior on-wire against real h2 servers
- [x] Validate HTTP/2 h2c path against real h2c servers
- [x] Validate HTTP/2 HTTPS/ALPN path against real TLS servers
- [x] Validate HTTP/2 multiplexing against real parallel streams
- [x] Validate HTTP/2 push capture against real push-capable servers
- [x] Validate HTTP/2 session pooling under load
- [x] Validate HTTP/2 failure paths on stream reset
- [x] Validate HTTP/2 failure paths on connection abort
- [x] Validate HTTP/2 backpressure under real multi-stream traffic
- [x] Validate HTTP/2 fairness under sustained load

- [x] Validate full HTTP/3 client behavior on-wire against real h3 servers
- [x] Validate HTTP/3 request/response path against real QUIC connections
- [x] Validate HTTP/3 header and body paths against real h3 endpoints
- [x] Validate HTTP/3 failure paths on transport abort
- [x] Validate HTTP/3 failure paths on handshake failure
- [x] Validate HTTP/3 timeout behavior against real slow peers
- [x] Validate HTTP/3 connection reuse and session ticket paths
- [x] Validate HTTP/3 backpressure under real multi-stream traffic
- [ ] Validate HTTP/3 early-data / session-ticket behavior
- [ ] Validate HTTP/3 retransmit / loss behavior under injected packet loss
- [x] Validate HTTP/3 fairness under sustained load
- [ ] Validate HTTP/3 long-duration soak behavior under continuous load

- [ ] Validate full QUIC session lifecycle against real peers
- [ ] Validate full QUIC stream lifecycle against real peers
- [ ] Validate QUIC cancel paths against real transport state
- [ ] Validate QUIC poll/event-loop behavior under sustained runtime
- [ ] Validate QUIC congestion-control / flow-control behavior
- [ ] Validate QUIC zero-RTT / session-resumption paths
- [ ] Finalize QUIC error mapping to public exceptions
- [ ] Fully validate QUIC/TLS interaction
- [ ] Validate QUIC stats fields against real runtime values
- [ ] Validate QUIC recovery after network interruption

- [x] Validate WebSocket client handshake fully on-wire
- [x] Validate WebSocket server handshake fully on-wire
- [x] Validate WebSocket text-frame path on-wire
- [x] Validate WebSocket binary-frame path on-wire
- [x] Validate WebSocket ping/pong on-wire
- [x] Validate WebSocket close handshake on-wire
- [x] Validate WebSocket error paths for protocol violations
- [x] Validate WebSocket error paths for network aborts
- [x] Validate long-lived WebSocket connections under continuous load
- [ ] Validate WebSocket backpressure under many concurrent connections
- [ ] Validate WebSocket fairness under many concurrent connections
- [ ] Fully implement honest WebSocket server API behavior
- [ ] Back `King\WebSocket\Server` with fully real runtime behavior
- [ ] Validate `King\WebSocket\Server` shutdown and drain behavior
- [x] Validate WebSocket upgrade from HTTP/1 on-wire
- [ ] Validate WebSocket upgrade from HTTP/2/h3 scenarios where publicly claimed
- [ ] Validate WebSocket memory lifecycle across request/worker boundaries

## B. Server Runtime / Listener / Admin / TLS

- [x] Validate HTTP/1 server listener as a real network listener
- [x] Validate HTTP/2 server listener as a real network listener
- [x] Validate HTTP/3 server listener as a real network listener
- [x] Validate server dispatch under real network traffic
- [ ] Validate server request normalization against real requests
- [ ] Validate server response normalization against real clients
- [ ] Validate server-side cancel callbacks under real traffic
- [ ] Validate server-side Early Hints on-wire
- [x] Validate server-side WebSocket upgrades on-wire
- [ ] Validate server TLS reload under live traffic
- [ ] Validate server admin API under real mTLS configuration
- [ ] Validate server admin API auth / reload / failure paths
- [ ] Validate server CORS / header behavior against real browsers and clients
- [x] Validate server session churn under long-running operation
- [ ] Validate server close / drain / restart behavior
- [ ] Validate server multi-connection scheduling under load
- [ ] Validate server fairness across competing clients
- [ ] Validate server resource cleanup under crash / abort scenarios

## C. MCP

- [x] Replace local wrapper MCP connection state with real backend communication
- [x] Validate MCP request path against a real remote server
- [x] Validate MCP upload path against a real remote server
- [x] Validate MCP download path against a real remote server
- [x] Make MCP transfer identifiers permanently safe and collision-free
- [x] Persist MCP transfer state
- [x] Rehydrate MCP transfer state after restart
- [x] Validate MCP request timeouts over real network paths
- [x] Propagate MCP cancellation through real remote execution
- [x] Finalize MCP error mapping for remote protocol failures
- [x] Finalize MCP error mapping for transport failures
- [x] Finalize MCP error mapping for backend failures
- [x] Validate MCP multi-process operation
- [ ] Validate MCP multi-host operation
- [x] Enforce MCP concurrency and bounded-concurrency guarantees
- [x] Enforce MCP deadline propagation
- [x] Validate MCP upload/download under large payloads
- [x] Validate MCP backpressure under parallel transfers
- [x] Validate MCP recovery after controller / worker restart
- [x] Validate MCP recovery after partial failures

## D. Pipeline Orchestrator

- [x] Move orchestrator from local kernel execution to real worker/backend boundaries
- [x] Validate orchestrator execution over a real remote TCP host/port worker peer
- [x] Persist tool registry state
- [x] Rehydrate tool registry state after restart
- [x] Persist pipeline run state
- [x] Rehydrate pipeline run state after restart
- [x] Implement pipeline continuation after process restart
- [ ] Implement pipeline continuation after host restart
- [x] Enforce bounded concurrency for pipeline execution
- [x] Enforce per-step deadline handling
- [x] Propagate cancellation across step / worker boundaries
- [x] Define and implement retry / idempotency semantics per step
- [ ] Finalize per-step error classification
- [ ] Define and implement rollback / compensation semantics where publicly claimed
- [ ] Validate distributed tool execution across multiple workers
- [x] Validate worker failure during active pipeline execution
- [x] Validate queue / scheduler fairness under load
- [x] Finalize exact queued/running/failed/cancelled/completed state transitions
- [ ] Fully integrate observability for pipeline execution
- [x] Build end-to-end multi-process harness
- [ ] Build end-to-end multi-host harness

## E. Object Store Core

- [x] Explicitly and finally specify the object-store backend contract
- [x] Replace all currently simulated object-store backends with real implementations
- [x] Establish uniform failure semantics across all backends
- [x] Establish consistent metadata semantics across all backends
- [x] Establish consistent TTL / expiry semantics across all backends
- [x] Establish consistent chunking semantics across all backends
- [x] Define bounded-memory streaming ingress / egress semantics for large objects
- [x] Define range-read semantics across local and remote backends
- [x] Define provider-native multipart / block / resumable upload semantics where publicly claimed
- [x] Establish consistent delete semantics across all backends
- [x] Establish consistent list / inventory semantics across all backends
- [x] Define consistent overwrite / versioning semantics
- [x] Define consistent concurrency / locking semantics
- [x] Define consistent quota / capacity semantics
- [x] Define per-object integrity validation semantics
- [x] Define per-backend recovery semantics
- [x] Validate object-store initialization across all target backends
- [x] Validate object-store put across all target backends
- [x] Validate object-store get across all target backends
- [x] Validate object-store delete across all target backends
- [x] Validate object-store list across all target backends
- [x] Validate object-store metadata reads across all target backends
- [x] Validate object-store optimize / cleanup paths across all target backends

## F. Object Store Cloud Backends

- [x] Implement real S3 backend
- [x] Implement real GCS backend where publicly claimed
- [x] Implement real Azure Blob backend where publicly claimed
- [x] Finalize local filesystem backend as the reference backend
- [x] Validate multi-backend routing with real backends
- [x] Validate backend failover on primary backend outage
- [x] Validate partial backend failures under replication
- [x] Validate network failures for the real `cloud_s3` backend
- [x] Validate credential failures for the real `cloud_s3` backend
- [x] Validate network failures for future real cloud backends
- [x] Validate credential failures for future real cloud backends
- [x] Validate throttling / rate-limit behavior for the real `cloud_s3` backend
- [x] Validate partial backup-failure recovery for `local_fs` primary plus real `cloud_s3` backup
- [x] Validate `local_fs` primary read fallback to real `cloud_s3` backup on payload miss
- [x] Validate object-store delete semantics across the real `local_fs` and `cloud_s3` backends
- [x] Validate throttling / rate-limit behavior for future real cloud backends
- [x] Validate object migration between backends
- [x] Validate data integrity after backend migration
- [x] Validate metadata consistency after backend migration
- [x] Validate recovery after incomplete writes
- [x] Validate recovery after partial replication

## G. Backup / Restore / Import / Export / Recovery

- [x] Implement complete backup path for payloads
- [x] Implement complete backup path for `.meta` state
- [x] Implement complete restore path for payloads
- [x] Implement complete restore path for `.meta` state
- [x] Implement complete export path for payloads
- [x] Implement complete export path for `.meta` state
- [x] Implement complete import path for payloads
- [x] Implement complete import path for `.meta` state
- [x] Define consistency guarantees for backup snapshots
- [x] Implement incremental backups where publicly claimed
- [x] Handle restore from partially corrupted archives
- [x] Handle restore while the system is running concurrently
- [x] Validate crash recovery after hard process abort
- [x] Validate restart rehydration under all persistence modes
- [x] Enforce integrity checks after restore
- [x] Enforce integrity checks after import
- [x] Validate metadata migrations after restore
- [x] Define rolling-restore / partial-restore semantics where publicly claimed

## H. CDN / Cache / Edge

- [ ] Validate CDN cache paths against real object-store backends
- [ ] Validate cache fill on miss against real backends
- [ ] Validate cache invalidation under load
- [x] Validate cache TTL enforcement under sustained operation
- [ ] Validate stale-serve-on-error against real backend failures
- [ ] Validate cache consistency after backend update
- [x] Validate cache consistency after delete
- [ ] Validate edge-node inventory against real nodes where publicly claimed
- [ ] Validate origin timeout / retry behavior
- [ ] Validate cache memory limits under load
- [ ] Validate large objects in cache under memory pressure
- [ ] Validate cache recovery after restart
- [ ] Finalize cache metrics and observability

## I. Semantic DNS

- [ ] Upgrade Semantic DNS from local lifecycle toggle to real network listener where publicly claimed
- [ ] Validate DNS protocol behavior on-wire where publicly claimed
- [ ] Validate service registration against real distributed topology
- [ ] Validate mother-node synchronization against real topology
- [x] Validate routing decisions against real load / health data
- [x] Validate service discovery under parallel updates
- [x] Validate status updates under concurrent writes
- [x] Implement persistence for registration data
- [x] Implement rehydration of registration data after restart
- [ ] Validate consistency after split-brain / partial-failure scenarios where publicly claimed
- [x] Validate topology generation under large service counts
- [ ] Validate DNS failure and recovery behavior

## J. Telemetry Core

- [ ] Validate span lifecycle fully under sustained runtime
- [ ] Validate metric lifecycle fully under sustained runtime
- [ ] Validate log lifecycle fully under sustained runtime
- [ ] Fully harden request / worker cleanup for telemetry state
- [ ] Eliminate all cross-request residue or UAF risk in telemetry state
- [ ] Implement trace-context propagation on incoming requests
- [ ] Finalize trace-context injection on outgoing requests
- [ ] Finalize trace-context extraction from incoming requests
- [ ] Preserve span hierarchies correctly across process / worker boundaries
- [ ] Finalize telemetry sampling strategy where publicly claimed
- [ ] Enforce telemetry memory bounds under load
- [ ] Monitor telemetry CPU bounds under load
- [x] Define and enforce telemetry queue limits
- [x] Define and implement telemetry drop policy
- [x] Define and implement telemetry retry policy
- [x] Define and implement telemetry backpressure policy
- [x] Make telemetry failure modes documented and testable
- [ ] Finalize telemetry self-metrics

## K. Telemetry Export / OTLP

- [x] Validate OTLP metrics export fully against real collectors
- [x] Validate OTLP traces export fully against real collectors
- [x] Validate OTLP logs export fully against real collectors
- [x] Validate success / failure / retry behavior against real endpoints
- [ ] Correctly handle response-size / request-size limits
- [x] Correctly handle non-2xx responses
- [x] Correctly handle transient network failures
- [ ] Correctly handle permanent network failures
- [x] Correctly handle export timeout behavior
- [x] Implement queue replay after collector outage
- [ ] Implement queue replay after process restart where required
- [ ] Define export ordering and idempotency correctly
- [ ] Finalize batch formation behavior
- [x] Finalize flush semantics
- [x] Finalize delivery semantics
- [ ] Validate OTLP JSON payloads against reference collectors
- [ ] Provide complete export failure diagnostics
- [ ] Finalize export endpoint / credential security boundaries

## L. Autoscaling Core

- [ ] Validate autoscaling decision logic under real load patterns
- [ ] Validate CPU / memory / RPS / queue / latency signals under real operation
- [x] Validate cooldown behavior under rapid load changes
- [x] Validate hysteresis behavior under oscillating load
- [x] Validate pending-node guards under provisioning delays
- [ ] Validate drain-before-delete under real live connections
- [ ] Validate scale-up policy limits under burst load
- [ ] Validate scale-down policy limits under active traffic
- [ ] Finalize autoscaling metrics and decision explanations
- [x] Finalize autoscaling failure behavior when telemetry is missing
- [x] Finalize autoscaling failure behavior when telemetry is degraded
- [x] Finalize autoscaling failure behavior when provider is degraded
- [x] Validate autoscaling recovery after controller restart
- [ ] Validate autoscaling recovery after partial fleet-state loss

## M. Provisioning / Provider

- [ ] Finish the Hetzner path as a complete production-grade path
- [ ] Validate real release / bootstrap propagation to freshly provisioned nodes
- [ ] Make post-bootstrap node registration automated and robust
- [ ] Make post-bootstrap node readiness automated and robust
- [ ] Validate node drain before delete under real traffic
- [ ] Make node delete robust under failures / timeouts
- [x] Fully reconstruct provider state after controller restart
- [ ] Classify and handle provider API failures
- [ ] Classify and handle provider rate limits
- [ ] Classify and handle provider quota limits
- [ ] Securely load and rotate provider credentials where publicly claimed
- [ ] Validate firewalls / placement / labels / networks against real provider APIs
- [x] Implement rollback for failed bootstrap
- [x] Implement rollback for failed registration
- [x] Implement rollback for failed readiness
- [ ] Keep multi-provider support only if all documented providers are real
- [ ] Remove or fully implement all remaining simulated provider paths

## N. System Lifecycle / Readiness / Drain / Failover

- [ ] Define and implement system-wide readiness transitions
- [ ] Define and implement system-wide drain transitions
- [ ] Implement rolling restart across all relevant components
- [ ] Implement ordered component shutdown
- [ ] Implement ordered component startup
- [x] Implement telemetry failover harness
- [x] Implement autoscaling failover harness
- [ ] Implement object-store failover harness
- [ ] Implement MCP failover harness
- [ ] Implement orchestrator failover harness
- [ ] Implement coordinated recovery after component failures
- [ ] Implement coordinated recovery after node failure
- [ ] Validate coordinated recovery after network partition where publicly claimed
- [x] Establish chaos tests for central components
- [x] Integrate chaos tests into CI / release gates where economically acceptable

## O. Build / Bootstrap / Determinism

- [x] Make QUIC backend bootstrap fully deterministic
- [x] Fully pin the `quiche` dependency
- [ ] Fully pin all external build dependencies
- [x] Enable clean-host rehydration in a single reproducible step
- [x] Eliminate branch-based fallbacks from critical bootstrap
- [ ] Eliminate host-specific special cases from release builds
- [ ] Eliminate host-specific special cases from debug / ASan / UBSan builds
- [ ] Eliminate unstable Cargo / Git resolution paths from release builds
- [x] Enforce reproducibility of release artifacts as a hard gate
- [ ] Enforce reproducibility of container builds as a hard gate
- [ ] Freeze toolchain versions completely
- [ ] Fully document dependency provenance
- [ ] Secure supply-chain integrity for release artifacts
- [ ] Enable offline / air-gapped rebuild path where publicly claimed
- [ ] Complete build documentation so no implicit host knowledge is required

## P. CI / Test Gates / Fuzz / Sanitizer / Soak

- [x] Enforce benchmark budgets in CI
- [x] Define and maintain per-case regression budgets
- [x] Run clean-host install matrix in CI
- [x] Run container image install matrix in CI
- [x] Run supported PHP/API combinations in CI
- [x] Check upgrade compatibility in CI
- [x] Check downgrade compatibility in CI
- [x] Check persistence migration paths in CI
- [x] Check old/new configuration-state compatibility in CI
- [x] Establish long-duration ASan soaks
- [x] Establish long-duration UBSan soaks
- [x] Establish long-duration memory / leak soaks
- [ ] Expand fuzzing for high-risk surfaces
- [ ] Expand fuzzing for transport surfaces
- [ ] Expand fuzzing for object-store surfaces
- [ ] Expand fuzzing for MCP / transfer surfaces
- [ ] Expand negative test matrices for malformed input
- [x] Archive failure artifacts for every gate violation
- [ ] Emit automated regression diagnostics
- [ ] Identify and eliminate flaky tests
- [ ] Integrate soak / chaos / recovery gates into release decisions

## Q. Dataflow / ETL / Flow PHP Integration

King should not absorb ETL semantics as a hard-wired C-core subsystem just
because the runtime can already transport, store, and orchestrate data. The
expected `Q` end-state is a userland-facing dataflow/ETL layer, such as `Flow
PHP`, running on top of King runtime primitives without losing the stronger
runtime guarantees that King already has around bounded-memory I/O, recovery,
real object-store backends, distributed execution, telemetry, and security.

The expected shape is:
- one reusable runtime/configuration model for secure storage and execution,
  rather than ad hoc per-pipeline arrays
- explicit adapters for source, sink, checkpoint, execution, telemetry, and
  schema concerns
- preservation of King object-store semantics such as integrity, expiry,
  multipart upload, range reads, recovery, and multi-backend topology instead
  of flattening them away behind a weaker ETL abstraction
- a real end-to-end proof that a dataflow pipeline can run locally and over
  remote workers while keeping restart recovery, backpressure, and observability
  intact

`*` Example code below is intentionally target-shape illustration for this
tracker section. It shows the kind of API and runtime model this block is
trying to make real; it is not a claim that the exact userland surface already
exists today.

- [ ] Define the `Flow PHP` / ETL-on-King contract explicitly as a userland integration layer on top of King runtime services, not as hard-wired C-core pipeline semantics
  done when: the repo documents a stable integration boundary that treats King as runtime substrate and `Flow PHP`-style ETL as userland orchestration/dataflow semantics, without silently shrinking existing King runtime guarantees
- [ ] Define a reusable object-store / dataflow runtime configuration model for secure storage topology, encryption, integrity, lifecycle, upload, and replication policy
  done when: one shared config object can describe primary plus replica/backups, credential sources, encryption mode, integrity policy, expiry/lifecycle policy, upload policy, and dataflow-facing checkpoint/temp-storage policy without every pipeline restating those concerns ad hoc
- [ ] Implement a streaming source adapter contract on top of King object-store, MCP, HTTP, and other runtime-owned transports
  done when: a dataflow source can consume records or blobs from King-backed transports with bounded-memory reads, resume-aware progress, and backpressure instead of requiring whole-object materialization first
- [ ] Implement a streaming sink adapter contract on top of King object-store, MCP, HTTP, and other runtime-owned transports
  done when: a dataflow sink can flush output through King-backed transports with bounded-memory writes, multipart/resumable upload where available, and explicit partial-failure handling
- [ ] Implement a checkpoint-store contract for offsets, cursors, resumable progress, and replay boundaries on top of King persistence surfaces
  done when: checkpoint state survives restart, can be versioned and resumed honestly, and does not require ETL callers to invent their own persistence layer outside King
- [ ] Implement an execution-backend contract that can run dataflow pipelines over King local, file-worker, and remote-peer orchestrator backends
  done when: a dataflow run can target the same verified King execution modes that the orchestrator already exposes, including restart-aware continuation and cancellation semantics
- [ ] Implement a telemetry adapter contract that maps pipeline runs, partitions, batches, retries, and failures into King tracing, metrics, and runtime status
  done when: dataflow runs produce first-class King telemetry instead of opaque application logs, and pipeline observability preserves per-run and per-step identity across workers
- [ ] Define stable error and retry taxonomy mapping between ETL/dataflow failures and King validation, runtime, transport, and backend failures
  done when: callers can distinguish invalid input, missing data, transient transport failure, backend outage, quota pressure, and retryable checkpoint/resume conditions without reverse-engineering adapter-specific strings
- [ ] Define partitioning, fan-out/fan-in, and backpressure semantics for distributed dataflow execution on top of King runtime primitives
  done when: distributed dataflow can split work predictably, merge it honestly, and keep memory/throughput bounded under slow consumers or uneven partitions
- [ ] Implement an object-store dataset bridge with bounded-memory streaming, range reads, multipart upload, integrity, expiry, and multi-backend topology semantics preserved
  done when: `Flow PHP`-style datasets can read and write through King object-store without discarding the stronger runtime semantics that now exist for local, distributed, and real cloud backends
- [ ] Implement schema / serialization bridges for JSON, CSV, NDJSON, IIBIN, Proto, and binary object payload workflows
  done when: dataflow pipelines can move between structured row formats and King-native binary/runtime formats without re-implementing serialization glue in every job
- [ ] Implement control-plane surfaces for start, pause, cancel, resume, inspect, and checkpoint-aware recovery of dataflow runs
  done when: dataflow runs can be controlled through explicit runtime state instead of hidden process-local control flow, and restart-aware resume can pick up from persisted checkpoints
- [ ] Validate a real end-to-end ETL/dataflow pipeline on top of King runtime services under local and remote-worker execution
  done when: the repo proves one non-trivial pipeline with secure object-store config, checkpointing, streaming source/sink adapters, telemetry, and orchestrated remote execution instead of only disconnected adapter slices

Examples `*`

```php
<?php

use King\ObjectStore\RuntimeConfig;
use King\ObjectStore\Backend\{S3, AzureBlob};
use King\ObjectStore\Encryption\{ServerSide, ClientSide};
use King\ObjectStore\{
    IntegrityPolicy,
    LifecyclePolicy,
    ReplicationPolicy,
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

## R. Compatibility / Stability / Long-Term Support

- [ ] Permanently stabilize the public API
- [ ] Permanently stabilize the stub surface
- [ ] Permanently stabilize runtime behavior
- [ ] Permanently stabilize persisted data formats
- [ ] Permanently stabilize wire formats
- [ ] Define and test upgrade paths for persistence
- [ ] Define and test downgrade paths for persistence
- [ ] Define and test release artifact compatibility across versions
- [ ] Define and test behavior under old configuration states
- [ ] Define and test behavior under new configuration states
- [ ] Write down compatibility guarantees
- [ ] Write down breaking-change policy
- [ ] Write down LTS / support policy where product support is claimed

## S. Public Contract / Docs / Truthfulness

- [ ] Align README completely with the real, complete runtime
- [ ] Transition `PROJECT_ASSESSMENT.md` to a state with no remaining caveats
- [ ] Empty `ISSUES.md` completely
- [ ] Synchronize `EPIC.md` with the final end-state
- [ ] Align `CONTRIBUTE.md` with final build / test / release process
- [ ] Keep `stubs/king.php` permanently exact with runtime
- [ ] Leave no public API without real runtime coverage
- [ ] Leave no documentation containing "simulated", "local-first", "incomplete", or equivalent residual states
- [ ] Base all public examples only on finally supported capabilities
- [ ] Fully finalize release documentation
- [ ] Fully finalize operations documentation
- [ ] Fully finalize recovery runbooks
- [ ] Fully finalize security documentation
- [ ] Fully finalize compatibility documentation

## T. Security / Hardening

- [ ] Complete full security review of all public entry points
- [ ] Complete full security review of all persistence paths
- [ ] Complete full security review of all transport paths
- [ ] Complete full security review of all provider / credential paths
- [ ] Complete full security review of the admin API
- [ ] Complete full security review of WebSocket server paths
- [ ] Complete full security review of MCP transfer paths
- [x] Eliminate privileged `workflow_run` checkout of non-trusted refs in release workflows
- [x] Bound HTTP/2 one-shot listener request bodies
- [x] Wait for full HTTP/3 one-shot request completion before handler invocation
- [x] Enforce default loopback-only MCP peer targeting unless a system allowlist permits remote peers
- [x] Keep MCP persisted transfer keys collision-free across the full base64url identifier length
- [x] Bound object-store runtime metadata cache growth
- [x] Reject CRLF-bearing cloud object-store metadata headers before network I/O
- [x] Remove known TOCTOU local and distributed object-store read races
- [x] Restrict Semantic DNS live probes to allowlisted hosts with sanitized request targets
- [ ] Systematically eliminate path traversal, injection, UAF, double-free, leak, and lifetime risks
- [ ] Systematically harden secret / token handling in memory
- [ ] Systematically harden secret / token handling in logs / diagnostics
- [ ] Systematically harden TLS material handling
- [ ] Systematically harden tempfile / archive / packaging paths
- [ ] Cover untrusted-input paths with negative test suites
- [ ] Make security gates a release prerequisite
- [ ] Define disclosure / patch process where product support is claimed

## U. Final Closure Gates

- [ ] No simulated backends remain in the public product state
- [ ] No local-first runtime slices remain in the public product state
- [ ] No partially honest provider / backend claims remain
- [ ] No open build / bootstrap caveats remain
- [ ] No open recovery / failover caveats remain
- [ ] No open WebSocket / realtime caveats remain
- [ ] No open MCP / orchestrator caveats remain
- [ ] No open object-store / persistence caveats remain
- [ ] No open compatibility / upgrade / downgrade caveats remain
- [ ] No open performance / budget / soak caveats remain
- [ ] All CI gates stay green permanently
- [ ] All release gates stay green permanently
- [ ] All recovery gates stay green permanently
- [ ] All install / matrix gates stay green permanently
- [ ] All public claims are exactly aligned with verified runtime behavior
- [ ] V1 can be treated as fully finished without caveat
