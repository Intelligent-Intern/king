# KING V1 FINAL READINESS COMPLETION CHECKLIST

Goal:
King is only finished when every exported capability is fully real, deterministically buildable, operationally reliable, failure-tolerant, upgrade-safe, documented, and supportable for the long term.

Status note:
- Checked boxes below mean the current tree already verifies that slice directly in code, tests, or an honest fenced v1 contract.
- Unchecked boxes are still open, intentionally fenced out of the current v1 slice, or broader than the proof that exists in this repository today.
- This file is the long-form closure tracker, not the active execution queue. `ISSUES.md` stays the narrow working backlog.
- Open boxes are not supposed to be "closed" by redefining the product downward. If a stronger shared, remote, persistent, or otherwise meaningful v1 contract already belongs to King, the default action is to implement it correctly rather than quietly shrinking scope.
- `ISSUES.md` only carries the next `20` repo-local executable leaves when an explicit batch is active. Everything else stays here until it is split to that size.
- Recent orchestrator closure: worker-loss recovery, deterministic file-worker claim ordering, concurrent claim locking, sustained fairness under contention, real TCP host/port `remote_peer` execution with persisted success/failure snapshots, distributed observability depth, and controller/worker/remote-peer failover harnesses are now verified; the remaining open boxes below are the broader continuation and larger multi-host slices.
- Recent userland orchestrator contract closure: the public docs and stub surface now explicitly separate durable tool definitions from executable userland handlers, treat closures/object graphs/resources/controller memory as non-durable execution state, pin the public execution contract to per-process handler registration by tool name across local, file-worker, and remote-peer boundaries, and now back the local, file-worker, and remote-peer slices with a real handler API, direct execution plus restart/resume proof, an explicit local/remote handler input-output contract, a durable `handler_boundary`, pre-claim/pre-resume worker readiness validation, and explicit remote fail-closed behavior when a peer lacks the required handler; the remaining open work is the broader failure/control/status slices below.
- Recent handler-identity closure: the exact durable identity for future userland handler execution is now fixed to the tool-name string, with explicit per-process re-registration duties for local controllers, file workers, restarted replacements, and remote peers instead of vague "the system already knows the handler" folklore.
- Recent fail-closed closure: unsupported non-rehydratable handler forms are now documented as explicit fail-closed cases instead of an implied future serialization story.
- Recent handler-registration API closure: the runtime now exposes `king_pipeline_orchestrator_register_handler()` as a real process-local binding surface over previously registered durable tool names, with explicit PHPT proof for restart-time re-registration against recovered tool definitions.
- Recent userland handler docs closure: the handbook workflow guide and procedural API now include explicit restart-duty checklists for local controllers, file workers, and remote peers, with unsupported-form behavior spelled out at each boundary.
- Recent Flow PHP / ETL contract closure: the handbook now explicitly fixes ETL-on-King as a userland integration layer over object-store, orchestration, telemetry, transport, and runtime-configuration services instead of a hard-wired C-core ETL semantics claim, while keeping target-shape examples honest about what is not implemented yet.
- Recent Flow PHP runtime-config closure: the handbook now also defines the target-shape reusable object-store/dataflow runtime config wrapper for topology, credential references, encryption, integrity, lifecycle, upload policy, and checkpoint/temp-storage policy above the existing runtime keys and object-store APIs, without pretending that the exact helper classes are already exported today.
- Recent Flow PHP source closure: the repo now also carries a real userland source contract with serializable cursors and chunk-pump results under `userland/flow-php/src/StreamingSource.php`, plus targeted PHPT proof for object-store range-based streaming, HTTP `response_stream` replay-and-skip resume, and MCP `download_to_stream` replay-and-skip resume through a writable callback-stream boundary.
- Recent Flow PHP sink closure: the repo now also carries a real userland sink contract with serializable cursors, explicit write results, and partial-failure state under `userland/flow-php/src/StreamingSink.php`, plus targeted PHPT proof for cloud object-store upload-session resume, live HTTP request-body streaming, and MCP replay-from-spool retry after an upload failure.
- Recent Flow PHP checkpoint-store closure: the repo now also carries a real userland checkpoint contract with durable offsets, source/sink cursor state, replay boundaries, version metadata, and explicit conflict results under `userland/flow-php/src/CheckpointStore.php`, plus targeted PHPT proof for restart-persistent reload and stale-writer rejection through real object-store version preconditions.
- Recent Flow PHP execution-backend closure: the repo now also carries a real userland execution-backend contract under `userland/flow-php/src/ExecutionBackend.php`, with explicit backend capabilities and persisted run snapshots over local, file-worker, and remote-peer orchestrator modes, plus targeted PHPT proof for local restart continuation, file-worker claim plus pre-claim cancellation, and remote-peer controller-loss replay through the durable handler boundary.
- Recent Flow PHP failure-taxonomy closure: the repo now also carries a real userland failure-taxonomy contract under `userland/flow-php/src/FailureTaxonomy.php`, with stable categories plus retry dispositions across source, sink, checkpoint, and execution surfaces, and targeted PHPT proof for validation, missing-data, transport, quota, resume-conflict, runtime, and backend mapping.
- Recent Flow PHP partitioning/backpressure closure: the repo now also carries a real userland partitioning contract under `userland/flow-php/src/Partitioning.php`, with deterministic partition-plus-batch planning, step-boundary partition identity, honest `partition_then_batch` fan-in, and snapshot-driven queue/active-partition backpressure windows, verified by targeted PHPT proof for plan determinism, merge honesty, and real file-worker queued-gate behavior.
- Recent Flow PHP dataset-bridge closure: the repo now also carries a real userland object-store dataset bridge under `userland/flow-php/src/ObjectStoreDataset.php`, with typed dataset descriptors plus topology state, bounded range-window streaming, and preserved cloud resumable multipart upload or local replay semantics through the same stronger object-store runtime path, verified by targeted PHPT proof for hybrid local-plus-distributed descriptors and cloud GCS resumed upload plus streamed readback.
- Recent Flow PHP serialization-bridge closure: the repo now also carries a real userland serialization and schema bridge under `userland/flow-php/src/SerializationBridge.php`, with line-delimited JSON/CSV/NDJSON handling, payload-oriented JSON document/IIBIN/Proto/raw-binary codecs, and wrapped serialization cursors over the same source/sink contracts, verified by targeted PHPT proof for NDJSON resume, CSV header-aware write/read, JSON document replay, Proto hydration, IIBIN class-map decode, and raw binary-object round-trip.
- Recent Flow PHP control-plane closure: the repo now also carries a real userland control-plane contract under `userland/flow-php/src/ControlPlane.php`, with object-store-backed logical run records over the same execution-backend and checkpoint surfaces, plus targeted PHPT proof for queued file-worker pause/cancel and checkpoint-aware replacement recovery as well as local immediate-run inspectability and controller-loss resume.
- Recent local handler-shape closure: the local userland execution path now passes structured `input`, `tool`, `run`, and `step` context into handlers and enforces an explicit `['output' => <array payload>]` result contract instead of a folklore bare-array return.
- Recent userland no-caveat closure: the userland orchestrator surface no longer carries outstanding caveated claims in project status documents; durable tool definitions and configs are now the only persisted contract across boundaries, while executable handlers remain process-local and are re-registered before execution or resume on every local, file-worker, and remote-peer boundary.
- Recent queued handler-boundary closure: queued file-worker runs now persist an explicit `handler_boundary` snapshot with only durable tool-name references plus step indexes, and targeted PHPT proof now verifies that executable PHP handler callables themselves are not serialized into orchestrator state.
- Recent file-worker readiness closure: userland-backed worker processes now rehydrate `handler_boundary` before claim or claimed-run recovery and skip work they are not ready to execute, with PHPT proof for both queued and recovered claimed runs.
- Recent file-worker handler-execution closure: ready workers now execute boundary-marked userland steps through re-registered handlers, persist the latest payload plus completed-step progress after each completed step, and continue that honest progress after worker loss or replacement instead of replaying already-completed userland-backed work.
- Recent remote-peer handler-execution closure: userland-backed remote runs now persist the same durable `handler_boundary`, send only tool-name references plus durable tool configs across the TCP host/port request, execute through peer-local handlers when the remote process is ready, and fail closed explicitly when the peer lacks a required handler or receives an unsupported topology snapshot.
- Recent userland failure-classification closure: local, file-worker, and remote-peer userland-backed steps now preserve explicit `validation`, `runtime`, `timeout`, `backend`, and `missing_handler` categories at step scope plus honest run-scope `cancelled` control classification instead of collapsing handler failures into generic backend folklore.
- Recent terminal visibility closure: multi-step local userland-backed runs now keep terminal visibility intact through persisted snapshots by exposing `status`, `completed_step_count`, per-step `status`, per-step `compensation_status`, and top-level `compensation` detail for completed and failed outcomes in PHPT coverage.
- Recent userland control-context closure: the public handler contract now carries execution-control fields into active userland handler contexts on local and remote-peer execution paths so `cancel` plus `timeout_budget_ms` and `deadline_budget_ms` are available wherever the contract requires them, with targeted PHPT coverage now asserting presence and type.
- Recent app-worker boundary closure: workflow execution is now explicitly documented and proven as a process-local app-worker boundary, while durable tool definitions/config remain transportable state; a dedicated Smoke PHPT proves that Spark-style remote dispatch does not transport executable callback names across host/process boundaries.
- Recent local-snapshot PHPT closure: a dedicated PHPT (`595-orchestrator-local-userland-persisted-snapshot-contract.phpt`) now proves that a three-step local userland pipeline persists a correct completed run snapshot with accurate step statuses, chained result payload, step-context delivery (run_id, step index, tool name, backend, topology), handler_readiness reflecting no open process-registration boundary, and compensation not required — verifiable from a fresh process with no handler registrations.
- Recent file-worker re-registration PHPT closure: a dedicated PHPT (`596-orchestrator-file-worker-userland-reregistration-contract.phpt`) now proves that a clean worker process can re-register handlers, claim a queued file-worker run, execute all steps through those handlers, and produce a completed snapshot readable by a subsequent fresh process, with correct handler_boundary, handler_readiness, topology, chained result, step-context delivery, and queue cleanup.
- Recent telemetry export closure: metrics, traces, and logs now share the bounded batch/retry path, are verified against real local collectors for success plus non-2xx, timeout, response-size-limit, request-size fail-closed pre-dispatch, outage-recovery slices, and reference-collector OTLP JSON payload validation, now expose an explicit restart contract that stays process-local and non-replaying by default but upgrades to best-effort local durable replay when `king.otel_queue_state_path` is configured, now discard stale active-span plus pre-flush span/log scratch state at the next request or worker boundary instead of leaking it into later work units, now seed the first request-local server span from a valid incoming `traceparent`/`tracestate` pair instead of leaving inbound propagation as metadata-only folklore, now auto-inject the live current span into outgoing HTTP/1, HTTP/2, and HTTP/3 client requests while preserving explicit caller-supplied `traceparent` and `tracestate` boundaries, now preserve caller span lineage across orchestrator process-resume and file-worker boundaries through persisted distributed parent context plus exported `pipeline-orchestrator-boundary` spans, now enforce a queue-size-derived in-process byte budget with live self-metrics for queue growth, drops, retry pressure, and flush CPU cost, now keep retryable export batches in explicit head-of-queue FIFO order so younger batches cannot overtake an older unresolved batch, now keep one stable exporter batch identity across retry plus restart replay so downstream collectors can dedupe the honest at-least-once delivery path, now split mixed metric/span/log flush pressure into bounded FIFO batch chunks instead of collapsing one large local snapshot into an unbounded monolith, now expose a live `last_export_diagnostic` surface that classifies the latest export failure across pre-dispatch, transport, TLS, HTTP, and collector-response failure stages without leaking endpoint secrets, and now enforce endpoint/credential boundaries by rejecting URL-embedded credentials plus query/fragment exporter endpoints while exposing only a public-safe collector origin on request/session/system telemetry metadata; the remaining open boxes below are longer-haul degraded-exporter characterization.
- Recent transport/admin closure: the active tree now verifies the full repo-local HTTP/1, HTTP/2, HTTP/3, QUIC, WebSocket, listener, upgrade, admin-API, CORS/header, fairness, shutdown, and cleanup slices carried in sections `A` and `B`; the remaining transport-adjacent open boxes below are the broader security-review and final-closure gates, not missing runtime proofs in those execution sections.
- Recent QUIC bootstrap closure: the build path now rehydrates a pinned `quiche` commit, pinned BoringSSL submodule commit, tracked workspace lockfile, and pinned `wirefilter` revision without branch-based fallbacks or unlocked cargo retries.
- Recent Smart-DNS closure: the local DNS-shaped query surface now fails closed on undersized response budgets and rehydrates cleanly after restart, the active runtime now proves a bounded real on-wire UDP listener with honest request, timeout, truncation, and recovery behavior, stale peers can now heal partial durable-state loss by merging only missing service and mother-node entries back into the shared topology without overwriting newer overlapping state, and mother-node re-election pressure now carries persisted tombstones so departed leaders are not silently resurrected by stale writers before an explicit rejoin; the broader distributed-topology boxes below remain open.
- Recent autoscaling closure: real load-shape decision explanations, live request-operation signal capture for CPU/memory/active-connections/RPS/response-latency/queue-depth, partial persisted fleet-state recovery against live Hetzner inventory, and preserved fresh-node rollout bootstrap propagation are now verified; the remaining open boxes below are real drain-before-delete, policy-limit, and broader multi-node fleet-behavior slices.
- Recent security closure: HTTP/2 one-shot cumulative body caps, HTTP/3 one-shot full-body completion, MCP persisted transfer-key truncation fixes plus loopback-default peer targeting, bounded object-store metadata-cache growth, CRLF-safe cloud metadata headers, TOCTOU-safe local/distributed object-store reads, snapshot-manifest line caps, snapshot-cleanup symlink hardening, bounded remote orchestrator error metadata, trusted workflow-run source materialization, and loopback-default Semantic DNS live probe allowlists are now verified on the current mainline; the broader full-surface security review remains open below.

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
- [x] Validate HTTP/1 header normalization under real traffic

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
- [x] Validate HTTP/3 early-data / session-ticket behavior
- [x] Validate HTTP/3 retransmit / loss behavior under injected packet loss
- [x] Validate HTTP/3 fairness under sustained load
- [x] Validate HTTP/3 long-duration soak behavior under continuous load

- [x] Validate full QUIC session lifecycle against real peers
- [x] Validate full QUIC stream lifecycle against real peers
- [x] Validate QUIC cancel paths against real transport state
- [x] Validate QUIC poll/event-loop behavior under sustained runtime
- [x] Validate QUIC congestion-control / flow-control behavior
- [x] Validate QUIC zero-RTT / session-resumption paths
- [x] Finalize QUIC error mapping to public exceptions
- [x] Fully validate QUIC/TLS interaction
- [x] Validate QUIC stats fields against real runtime values
- [x] Validate QUIC recovery after network interruption

- [x] Validate WebSocket client handshake fully on-wire
- [x] Validate WebSocket server handshake fully on-wire
- [x] Validate WebSocket text-frame path on-wire
- [x] Validate WebSocket binary-frame path on-wire
- [x] Validate WebSocket ping/pong on-wire
- [x] Validate WebSocket close handshake on-wire
- [x] Validate WebSocket error paths for protocol violations
- [x] Validate WebSocket error paths for network aborts
- [x] Validate long-lived WebSocket connections under continuous load
- [x] Validate WebSocket backpressure under many concurrent connections
- [x] Validate WebSocket fairness under many concurrent connections
- [x] Fully implement honest WebSocket server API behavior
- [x] Back `King\WebSocket\Server` with fully real runtime behavior
- [x] Validate `King\WebSocket\Server` shutdown and drain behavior
- [x] Validate WebSocket upgrade from HTTP/1 on-wire
- [x] Validate WebSocket upgrade from HTTP/2/h3 scenarios where publicly claimed
- [x] Validate WebSocket memory lifecycle across request/worker boundaries

## B. Server Runtime / Listener / Admin / TLS

- [x] Validate HTTP/1 server listener as a real network listener
- [x] Validate HTTP/2 server listener as a real network listener
- [x] Validate HTTP/3 server listener as a real network listener
- [x] Validate server dispatch under real network traffic
- [x] Validate server request normalization against real requests
- [x] Validate server response normalization against real clients
- [x] Validate server-side cancel callbacks under real traffic
- [x] Validate server-side Early Hints on-wire
- [x] Validate server-side WebSocket upgrades on-wire
- [x] Validate server TLS reload under live traffic
- [x] Validate server admin API under real mTLS configuration
- [x] Validate server admin API auth / reload / failure paths
- [x] Validate server CORS / header behavior against real browsers and clients
- [x] Validate server session churn under long-running operation
- [x] Validate server close / drain / restart behavior
- [x] Validate server multi-connection scheduling under load
- [x] Validate server fairness across competing clients
- [x] Validate server resource cleanup under crash / abort scenarios

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
- [x] Validate MCP multi-host operation
- [x] Enforce MCP concurrency and bounded-concurrency guarantees
- [x] Enforce MCP deadline propagation
- [x] Validate MCP upload/download under large payloads
- [x] Validate MCP backpressure under parallel transfers
- [x] Validate MCP recovery after controller / worker restart
- [x] Validate MCP recovery after partial failures

## D. Pipeline Orchestrator

- [x] Define the public userland tool-handler contract for application workflows on top of the pipeline orchestrator
  done when: the docs and public stub surface explicitly distinguish durable tool definitions from executable userland handlers, define the per-process re-registration boundary for any later handler API, and fail closed on unsupported non-durable handler forms instead of pretending executable PHP memory survives restart or remote execution
- [x] Define the exact handler-identity and re-registration contract across local, file-worker, remote-peer, and restart boundaries
  done when: the docs and public stub surface treat the tool-name string as the only durable cross-boundary handler identity and explicitly assign re-registration duties to the exact process that will execute work after queue claim, restart, replacement, or remote-peer return
- [x] Reject unsupported non-rehydratable userland handler forms honestly instead of pretending closures survive restart or host boundaries
  done when: the docs and public stub surface explicitly fence captured closures, resource-backed callables, opaque object-state handlers, and controller-memory-dependent execution forms out of the durable public contract and require fail-closed behavior instead of informal serialization or topology downgrades
- [x] Add a public userland handler-registration API that binds a runtime handler to a registered orchestrator tool name
  done when: the runtime exposes `king_pipeline_orchestrator_register_handler()`, refuses unknown durable tool names, keeps executable bindings process-local and non-persistent, and proves restart-time rebinding against recovered tool definitions in PHPT
- [x] Execute registered userland handlers on the local orchestrator backend with persisted run-state parity
  done when: the local `run()` and `resume_run()` paths execute registered process-local handlers, persist the latest local payload plus completed-step progress after each completed local step, and prove running-snapshot plus controller-restart continuation without rerunning already-completed local steps in PHPT
- [x] Pass step input, tool config, run metadata, and step metadata into local userland handler execution with an explicit result contract
  done when: the local handler invocation context exposes structured `input`, `tool`, `run`, and `step` data, the local runtime enforces an explicit `output` result contract instead of a bare payload return, and PHPT covers both the richer context shape and fail-closed contract enforcement
- [x] Prove local userland tool execution over a persisted run snapshot in PHPT
  done when: a PHPT registers tools and handlers in process-A, runs them synchronously on the local backend, then reads the persisted run snapshot in a fresh process-B and asserts correct status, execution_backend, topology, completed step count, chained result payload, step-context delivery, handler_readiness.requires_process_registration=false, handler_readiness.ready=true, and compensation not required
- [x] Persist the durable handler-reference boundary needed for queued runs without serializing arbitrary PHP callables into state
  done when: queued file-worker runs persist an explicit `handler_boundary` snapshot with only durable tool-name references and step indexes, persisted run snapshots rehydrate that boundary across process restart, and PHPT proves that executable PHP handler callables are not serialized into durable orchestrator state
- [x] Rehydrate and validate handler readiness before file-worker claim or resume instead of failing late inside opaque worker execution
  done when: userland-backed file-worker runs rehydrate their persisted `handler_boundary` before queued claim or claimed-run recovery, workers without the required process-local handler registrations skip those runs instead of failing inside execution, and PHPT proves both queued-skip and claimed-recovery-skip behavior
- [x] Execute registered userland handlers on the file-worker backend after controller and worker restart under the explicit re-registration contract
  done when: a ready file-worker process executes the boundary-marked steps through its re-registered handlers, persists the latest payload plus completed-step progress after each completed step, and PHPT proves worker-loss recovery from that honest file-worker progress without rerunning already-completed userland-backed steps
- [x] Prove file-worker userland tool execution with handler re-registration in a clean separate worker process
  done when: a PHPT dispatches a run on the file-worker queue, verifies callable names are absent from persisted state, a clean worker re-registers handlers and executes the full run via worker_run_next(), and a subsequent reader process confirms the completed snapshot with correct backend, topology, chained result, handler_boundary, handler_readiness, step statuses, and queue cleanup
- [x] Execute registered userland handlers on the remote-peer backend under the explicit peer-local re-registration contract
  done when: remote-peer runs persist a durable `handler_boundary`, `run()` and `resume_run()` send only tool-name references plus durable tool configs across the TCP request, ready peers execute those marked steps through their own handler bindings, unready peers fail closed explicitly, unsupported handler topologies fail closed explicitly with backend classification, and PHPTs prove controller-restart continuation without pretending controller memory crossed the host boundary
- [x] Expose handler-readiness and handler-metadata inspection surfaces
  done when: `king_pipeline_orchestrator_get_run()` exposes `handler_readiness` for each run, orchestrator component info exposes `active_handler_contract` in `configuration`, and PHPT proves queued and claimed file-worker readiness checks correctly distinguish missing local handler registrations
- [x] Represent workflow execution as an app-worker boundary
  done when: documentation and implementation describe and enforce execution as process-local callback invocation on controller/file-worker/remote-peer processes, with durable tool definitions/config only crossing boundary state
- [x] Remove callback-transport assumptions from public workflow documentation
  done when: `11-pipeline-orchestrator-tools`/`pipeline-orchestrator`/procedural docs now consistently distinguish durable tool definitions from local callback execution and align examples with the app-worker boundary
- [x] Document the userland handler contract with restart duties and unsupported forms across handbook and procedural surfaces
  done when: the handbook and procedural API describe the exact per-process binding duties for local, file-worker, and remote-peer userland handlers, including restart/replacement re-registration and explicit unsupported-form fail-closed behavior
- [x] Add smoke-level app-worker boundary proof
  done when: a dedicated PHPT (`593-orchestrator-app-worker-boundary-smoke.phpt`) runs a Spark-style remote dispatch and proves handler callback names are not serialized into durable state or peer transport payloads
- [x] Move orchestrator from local kernel execution to real worker/backend boundaries
- [x] Validate orchestrator execution over a real remote TCP host/port worker peer
- [x] Persist tool registry state
- [x] Rehydrate tool registry state after restart
- [x] Persist pipeline run state
- [x] Rehydrate pipeline run state after restart
- [x] Implement pipeline continuation after process restart
- [x] Implement pipeline continuation after host restart
- [x] Enforce bounded concurrency for pipeline execution
- [x] Enforce per-step deadline handling
- [x] Propagate cancellation across step / worker boundaries
- [x] Propagate execution-control context fields into userland handler invocations
  done when: local and remote-peer userland handler paths include `cancel`, `timeout_budget_ms`, and `deadline_budget_ms` in invocation context where the public handler contract requires control propagation, and PHPT assertions prove the fields are present and typed correctly on successful runs
- [x] Define and implement retry / idempotency semantics per step
- [x] Finalize per-step error classification
- [x] Define and implement rollback / compensation semantics where publicly claimed
- [x] Validate distributed tool execution across multiple workers
- [x] Validate worker failure during active pipeline execution
- [x] Validate queue / scheduler fairness under load
- [x] Finalize exact queued/running/failed/cancelled/completed state transitions
- [x] Fully integrate observability for pipeline execution
- [x] Build end-to-end multi-process harness
- [x] Build end-to-end multi-host harness

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

- [x] Validate CDN cache paths against real object-store backends
- [x] Validate cache fill on miss against real backends
- [x] Validate cache invalidation under load
- [x] Validate cache TTL enforcement under sustained operation
- [x] Validate stale-serve-on-error against real backend failures
- [x] Validate cache consistency after backend update
- [x] Validate cache consistency after delete
- [x] Validate edge-node inventory against real nodes where publicly claimed
- [x] Validate origin timeout / retry behavior
- [x] Validate cache memory limits under load
- [x] Validate large objects in cache under memory pressure
- [x] Validate cache recovery after restart
- [x] Finalize cache metrics and observability

## I. Semantic DNS

- [x] Upgrade Semantic DNS from local lifecycle toggle to real network listener where publicly claimed
- [x] Validate DNS protocol behavior on-wire where publicly claimed
- [x] Validate service registration against real distributed topology
- [x] Validate mother-node synchronization against real topology
- [x] Validate routing decisions against real load / health data
- [x] Validate service discovery under parallel updates
- [x] Validate status updates under concurrent writes
- [x] Implement persistence for registration data
- [x] Implement rehydration of registration data after restart
- [x] Validate consistency after split-brain / partial-failure scenarios where publicly claimed
- [x] Validate topology generation under large service counts
- [x] Validate DNS failure and recovery behavior

## J. Telemetry Core

- [x] Validate span lifecycle fully under sustained runtime
- [x] Validate metric lifecycle fully under sustained runtime
- [x] Validate log lifecycle fully under sustained runtime
- [x] Fully harden request / worker cleanup for telemetry state
- [x] Eliminate all cross-request residue or UAF risk in telemetry state
- [x] Implement trace-context propagation on incoming requests
- [x] Finalize trace-context injection on outgoing requests
- [x] Finalize trace-context extraction from incoming requests
- [x] Preserve span hierarchies correctly across process / worker boundaries
- [x] Finalize telemetry sampling strategy where publicly claimed
- [x] Enforce telemetry memory bounds under load
- [x] Monitor telemetry CPU bounds under load
- [x] Define and enforce telemetry queue limits
- [x] Define and implement telemetry drop policy
- [x] Define and implement telemetry retry policy
- [x] Define and implement telemetry backpressure policy
- [x] Make telemetry failure modes documented and testable
- [x] Finalize telemetry self-metrics

## K. Telemetry Export / OTLP

- [x] Validate OTLP metrics export fully against real collectors
- [x] Validate OTLP traces export fully against real collectors
- [x] Validate OTLP logs export fully against real collectors
- [x] Validate success / failure / retry behavior against real endpoints
- [x] Correctly handle request-size limits before exporter dispatch
- [x] Correctly handle response-size limits against real collectors
- [x] Correctly handle non-2xx responses
- [x] Correctly handle transient network failures
- [x] Correctly handle permanent network failures
- [x] Correctly handle export timeout behavior
- [x] Implement queue replay after collector outage
- [x] Implement queue replay after process restart where required
- [x] Define export ordering correctly
- [x] Define export idempotency correctly
- [x] Finalize batch formation behavior
- [x] Finalize flush semantics
- [x] Finalize delivery semantics
- [x] Validate OTLP JSON payloads against reference collectors
- [x] Provide complete export failure diagnostics
- [x] Finalize export endpoint / credential security boundaries

## L. Autoscaling Core

- [x] Validate autoscaling decision logic under real load patterns
- [x] Validate CPU / memory / RPS / queue / latency signals under real operation
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
- [x] Validate autoscaling recovery after partial fleet-state loss

## M. Provisioning / Provider

- [ ] Finish the Hetzner path as a complete production-grade path
- [x] Validate real release / bootstrap propagation to freshly provisioned nodes
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
- [x] Implement MCP failover harness
- [x] Implement orchestrator failover harness
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

King should not absorb ETL semantics as a hardwired C-core subsystem just
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

The active repo-local execution breakdown for this block now lives in
`ISSUES.md`.

- [x] Define the `Flow PHP` / ETL-on-King contract explicitly as a userland integration layer on top of King runtime services, not as hard-wired C-core pipeline semantics
  done when: the repo documents a stable integration boundary that treats King as runtime substrate and `Flow PHP`-style ETL as userland orchestration/dataflow semantics, without silently shrinking existing King runtime guarantees
- [x] Define a reusable object-store / dataflow runtime configuration model for secure storage topology, encryption, integrity, lifecycle, upload, and replication policy
  done when: one shared config object can describe primary plus replica/backups, credential sources, encryption mode, integrity policy, expiry/lifecycle policy, upload policy, and dataflow-facing checkpoint/temp-storage policy without every pipeline restating those concerns ad hoc
- [x] Implement a streaming source adapter contract on top of King object-store, MCP, HTTP, and other runtime-owned transports
  done when: a dataflow source can consume records or blobs from King-backed transports with bounded-memory reads, resume-aware progress, and backpressure instead of requiring whole-object materialization first
- [x] Implement a streaming sink adapter contract on top of King object-store, MCP, HTTP, and other runtime-owned transports
  done when: a dataflow sink can flush output through King-backed transports with bounded-memory writes, multipart/resumable upload where available, and explicit partial-failure handling
- [x] Implement a checkpoint-store contract for offsets, cursors, resumable progress, and replay boundaries on top of King persistence surfaces
  done when: checkpoint state survives restart, can be versioned and resumed honestly, and does not require ETL callers to invent their own persistence layer outside King
- [x] Implement an execution-backend contract that can run dataflow pipelines over King local, file-worker, and remote-peer orchestrator backends
  done when: a dataflow run can target the same verified King execution modes that the orchestrator already exposes, including restart-aware continuation and cancellation semantics
- [x] Implement a telemetry adapter contract that maps pipeline runs, partitions, batches, retries, and failures into King tracing, metrics, and runtime status
  done when: dataflow runs produce first-class King telemetry instead of opaque application logs, and pipeline observability preserves per-run and per-step identity across workers
- [x] Define stable error and retry taxonomy mapping between ETL/dataflow failures and King validation, runtime, transport, and backend failures
  done when: callers can distinguish invalid input, missing data, transient transport failure, backend outage, quota pressure, and retryable checkpoint/resume conditions without reverse-engineering adapter-specific strings
- [x] Define partitioning, fan-out/fan-in, and backpressure semantics for distributed dataflow execution on top of King runtime primitives
  done when: distributed dataflow can split work predictably, merge it honestly, and keep memory/throughput bounded under slow consumers or uneven partitions
- [x] Implement an object-store dataset bridge with bounded-memory streaming, range reads, multipart upload, integrity, expiry, and multi-backend topology semantics preserved
  done when: `Flow PHP`-style datasets can read and write through King object-store without discarding the stronger runtime semantics that now exist for local, distributed, and real cloud backends
- [x] Implement schema / serialization bridges for JSON, CSV, NDJSON, IIBIN, Proto, and binary object payload workflows
  done when: dataflow pipelines can move between structured row formats and King-native binary/runtime formats without re-implementing serialization glue in every job
- [x] Implement control-plane surfaces for start, pause, cancel, resume, inspect, and checkpoint-aware recovery of dataflow runs
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
- [ ] Align `CONTRIBUTE` with final build / test / release process
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
