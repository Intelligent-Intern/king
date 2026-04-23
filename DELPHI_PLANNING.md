# Delphi Compute Mesh Plan (King Infra Is Non-Negotiable)

This plan defines how to realize distributed agent compute on **King-native**
infrastructure only: **IIBIN**, **WebSocket**, **SFU**, and **Gossip Mesh**.
No alternative transport/control stack is allowed.

## Non-Negotiable Stack

1. **IIBIN** is the wire contract (`king_proto_*`, `King\\IIBIN`).
2. **WebSocket** is the durable control/session channel
   (`king_client_websocket_*`, `king_server_upgrade_to_websocket()`).
3. **SFU** is the high-fanout realtime plane (existing `/sfu` route and frame flow).
4. **Gossip Mesh** is the decentralized relay/propagation layer.
5. **Pipeline Orchestrator** is the compute execution/runtime boundary
   (`king_pipeline_orchestrator_*`, local/file-worker/remote-peer backends).
6. **Semantic DNS** is discovery/routing policy (`king_semantic_dns_*`).
7. **Object Store** is artifact/staging for large payloads.

## Current Branch Reality (`experiments/1.0.7-delphi`)

1. Present and usable:
   - IIBIN runtime and docs
   - WebSocket client/server runtime
   - Pipeline orchestrator (local, file-worker, remote-peer)
   - Semantic DNS runtime
   - SFU signaling path in `demo/video-chat/backend-king-php/domain/realtime/realtime_sfu_gateway.php`
2. Gap to close first:
   - Gossip Mesh source/header is not fully present on this branch as tracked source.
   - Compute mesh work must start by porting the gossip-mesh primitives from
     `experiments/1.0.7-gossip-mesh` into Delphi.

## Execution Notes (2026-04-22)

1. Orchestrator input now supports graph-shaped submission (`steps` + `id` + `deps`)
   and normalizes it into deterministic topological order before run persistence.
2. This is currently a control-plane scheduling improvement, not fine-grained
   in-process parallel frontier execution. Step-boundary execution semantics remain.
3. M0 scaffolding for recurrent loop + expert fanout is added in userland:
   - IIBIN schema registration helpers
   - one-loop expert fanout DAG template
   - object-store artifact reference contract and encoder helpers
4. Build and targeted contract tests are green for the DAG path and existing
   orchestrator terminal-state visibility path.
5. Build/test snapshot is tracked in `DELPHI_BUILD_STATE.md`.

## Immediate M0 Follow-Through

1. Bind concrete handlers for the scaffolded step IDs:
   - `delphi.prepare_inputs`
   - `delphi.route_tokens_topk`
   - `delphi.dispatch_expert_batch`
   - `delphi.collect_expert_results`
   - `delphi.merge_weighted_outputs`
   - `delphi.next_layer_or_decode`
   - `delphi.emit_final`
2. Land a contract test for one recurrent loop with at least 2 expert fanout branches
   proving deterministic merge and idempotent duplicate-drop behavior.
3. Wire route/expert payloads to real object-store artifact refs (no large inline payloads).
4. Keep branch operations fork-only unless explicitly instructed otherwise:
   - push to `origin` (`sashakolpakov`) only
   - do not push to `upstream`.

## Architecture (King-Native)

### 1) Control Plane: WebSocket + IIBIN

1. Every Delphi node keeps one long-lived King WebSocket session to edge/control.
2. All control messages are IIBIN binary envelopes (not ad hoc JSON).
3. Control topics:
   - node registration and heartbeat
   - task offers/leases/acks
   - failure and retry coordination
   - room/swarm membership changes

### 2) Execution Plane: Pipeline Orchestrator

1. Agent runs are orchestrator runs, not custom hidden callbacks.
2. Task slices become orchestrator steps with explicit run IDs and status.
3. Backend usage:
   - `local`: small immediate tasks
   - `file_worker`: same-host queueing and backpressure
   - `remote_peer`: cross-node execution handoff
4. Resume/cancel/deadline always use orchestrator controls, not bespoke flags.

### 3) Fanout Plane: SFU for Realtime Agent Streams

1. SFU is reused as a compute-stream distribution surface:
   - token stream fanout
   - partial result fanout
   - live agent telemetry fanout
2. For heavy payloads:
   - publish an object-store reference over SFU/control
   - fetch payload out-of-band from object store
3. SFU remains subscriber/publisher aware per room/swarm.

### 4) Mesh Plane: Gossip Mesh for Decentralized Relay

1. Gossip Mesh carries bounded relay for:
   - task announcements
   - partial result propagation
   - node health and availability deltas
2. Use existing mesh semantics (TTL, duplicate suppression, stochastic forwarding).
3. Gossip is not the source of truth for run state; orchestrator snapshots remain source of truth.

### 5) Discovery and Placement: Semantic DNS

1. Nodes register capability tags as service attributes (CPU/GPU/memory/network class).
2. Placement and route selection resolve via Semantic DNS before task lease.
3. Live status updates feed routing/readmission decisions.

## Protocol Design (IIBIN Schemas)

Define a Delphi protocol package on IIBIN with strict versioning:

1. `DelphiMessageKind` enum:
   - `node_announce`, `node_heartbeat`, `task_offer`, `task_lease`,
     `task_chunk`, `task_partial`, `task_final`, `task_fail`,
     `artifact_ref`, `swarm_control`, `swarm_metrics`.
2. `DelphiEnvelope` schema:
   - `version`, `kind`, `trace_id`, `swarm_id`, `sender_node_id`,
     `message_id`, `sent_at_ms`, `payload(bytes)`.
3. `NodeCapabilities` schema:
   - CPU class, memory MB, GPU class, max parallel slots, bandwidth tier.
4. `TaskLease` schema:
   - `task_id`, `chunk_id`, `lease_ms`, `deadline_ms`, `idempotency_key`.
5. `TaskResult` schema:
   - `task_id`, `chunk_id`, `status`, `output_hash`, `artifact_uri`,
     `runtime_ms`, `error_code`, `error_detail`.

Compatibility rule: never reuse field numbers; add-only evolution.

## Work Decomposition for Cheap Nodes

1. Split agent workloads into micro-units:
   - prompt transforms
   - rerank/score slices
   - embedding batches
   - tool-exec subtasks
   - merge/reduce subtasks
2. Keep per-unit memory/time bounded so CPU-only/M1 nodes can contribute.
3. Push model-heavy outputs as references (object store), not giant inline frames.

## Scheduling and Admission

### Capability-Aware Score

`score = capability_fit * 0.40 + queue_headroom * 0.25 + latency_proximity * 0.15 + reliability * 0.20`

1. Reject nodes that miss hard requirements (e.g., GPU-required chunk).
2. Prefer nodes with higher reliability for terminal/critical chunks.
3. Reserve cheap nodes for CPU-friendly chunks by policy.

### Lease Model

1. Every chunk requires explicit lease.
2. Lease expiry triggers re-offer.
3. `idempotency_key` prevents duplicate commit side effects.

## MoE and OP-Tree Distribution (Speed-Critical)

### Short Answer

1. Yes, nodes can own experts, and this should be the default for Delphi.
2. Yes, compute graph partitioning is possible, but do not split at single-op granularity.
3. Fastest practical design is a hybrid:
   - expert-parallel for MoE layers
   - coarse graph partition for dense blocks only when locality is favorable.

### Recommended Execution Model

1. **Primary mode (expert ownership):**
   - each node advertises resident expert IDs and capacity via Semantic DNS + heartbeat
   - router/gating stage computes top-k experts per token group
   - coordinator packs activations per destination expert owner and dispatches remote chunks
   - expert owners execute locally and return partial outputs
   - coordinator performs weighted merge and continues next layer.
2. **Secondary mode (coarse OP-tree partition):**
   - partition only at fused block boundaries (for example: attention block, MLP block, or full layer slice)
   - never dispatch single matmul/add/relu ops across nodes
   - use this mode when a block does not fit target node memory or when expert layout is unavailable.
3. **Fallback mode (local-only):**
   - if network or lease pressure crosses threshold, execute the layer/chunk locally to protect tail latency.

### Why This Is Fastest on King

1. MoE naturally sparsifies compute; only selected experts run.
2. Sending routed activations to expert owners reduces wasted FLOPs versus dense split-by-op.
3. OP-tree micro-splitting causes network serialization overhead that dominates on commodity nodes.
4. Coarse blocks keep data movement bounded and make orchestrator retries tractable.

### King Infra Mapping (Non-Negotiable)

1. **IIBIN**
   - encode `RoutePlan`, `ExpertBatch`, `ExpertResult`, and `LayerMerge` payloads.
   - include tensor metadata (shape, dtype, quantization, checksum, sequence/window id).
2. **WebSocket**
   - control channel for lease, ack, cancel, backpressure, and heartbeat.
   - binary IIBIN envelopes for control-path determinism.
3. **SFU**
   - fast fanout for observers/replicas and live partial stream visibility.
   - not the source of truth for completion; orchestrator state remains authoritative.
4. **Gossip Mesh**
   - quick propagation of capability/health deltas and hot-node pressure signals.
   - bounded TTL and duplicate suppression to avoid storms.
5. **Pipeline Orchestrator**
   - single request = one run; each routed expert batch = step.
   - merge/reduce = explicit terminal steps with idempotency keys.

### Orchestrator DAG Shape

1. `prepare_inputs`
2. `route_tokens_topk`
3. `dispatch_expert_batch::<node/expert>` (parallel fanout)
4. `collect_expert_results`
5. `merge_weighted_outputs`
6. `next_layer_or_decode`
7. `emit_final`

Rules:
1. retries only at step boundaries.
2. lease timeout triggers reassignment or hedged execution.
3. duplicate completions are dropped by `idempotency_key`.

### Placement and Scheduling Rules

1. Keep expert ownership sticky per time window to improve cache locality.
2. Co-locate experts with high co-activation probability when possible.
3. Use adaptive micro-batching:
   - larger batch for stable low-RTT peers
   - smaller batch for weak/high-jitter peers.
4. Use hedged dispatch for stragglers on critical path layers.
5. Enforce per-node in-flight byte and step caps to prevent queue collapse.

### Latency Budget Guardrails

For each dispatch candidate:
1. estimate `remote_total = net_out + queue + compute + net_back + merge`.
2. estimate `local_total = local_queue + local_compute`.
3. dispatch remote only if `remote_total + safety_margin < local_total`.

Safety margin should be dynamic from recent p95 jitter and timeout rate.

### Practical Constraints

1. Expert-per-node works best when expert weights are resident and warm.
2. If experts are too large for many peers, shard by expert group instead of single expert.
3. For very weak nodes, assign CPU-safe preprocessing/postprocessing chunks, not hot MoE path.
4. Keep payloads quantized/compressed where acceptable, with deterministic decode checksums.

## State and Consistency Model

1. **Authoritative state**:
   - orchestrator run snapshot + step status.
2. **Fast-but-eventual state**:
   - gossip membership and quick availability hints.
3. **Artifact state**:
   - object store (payloads, checkpoints, large partial outputs).

Never treat gossip propagation alone as committed run completion.

## Security and Abuse Controls

1. Signed node identity and signed envelope metadata.
2. Per-node quotas:
   - max concurrent chunks
   - max bytes/sec
   - max failed leases before cooldown.
3. Reputation model:
   - lease timeout rate
   - invalid result rate
   - protocol violation rate.
4. Fail closed on schema mismatch / unsigned critical frames / stale leases.

## Implementation Sequence (Code Order)

### Gate A: Foundation Port into Delphi

1. Port gossip mesh runtime artifacts from `experiments/1.0.7-gossip-mesh`:
   - `extension/include/gossip_mesh.h`
   - `extension/src/gossip_mesh/*`
   - any build/config glue required by `extension/config.m4`
2. Keep SFU and WebSocket behavior stable while porting.
3. Add/restore contract tests proving gossip primitives load in Delphi branch.

### Gate B: M0 (Single Machine, Deterministic)

1. Implement IIBIN Delphi protocol definitions.
2. Implement WebSocket control endpoint carrying binary IIBIN envelopes.
3. Wire orchestrator-backed chunk execution over local/file-worker.
4. Add simple in-process mesh simulation using gossip primitives.
5. Deliver deterministic task split/reassemble with 3 local workers.

### Gate C: M1 (LAN Multi-Node)

1. Enable remote-peer orchestrator dispatch plus lease ownership rules.
2. Use Semantic DNS registration/discovery for placement.
3. Add gossip relay for heartbeat and task-offer propagation.
4. Add SFU fanout for realtime partial output streams.

### Gate D: M2 (Mixed Hardware)

1. Capability-aware scheduler tuned for CPU-only + M1 + low GPU mix.
2. Dynamic chunk sizing by node class.
3. Quorum verification for selected critical chunks.
4. Reputation and backpressure enabled by default.

## Test Strategy (King Contract-First)

1. PHPT contracts:
   - IIBIN schema version compatibility
   - lease expiry and idempotent replay
   - gossip duplicate suppression and TTL bound
   - SFU fanout correctness for partial output stream
   - orchestrator resume/cancel over remote-peer boundary
2. Integration tests:
   - single-node deterministic swarm
   - 3-node LAN churn/recovery
   - mixed node classes with forced overload
3. Keep local-vs-remote test profile behavior for practical developer runs.

## Milestones and Acceptance Criteria

### M0 Acceptance

1. One request can become N chunks.
2. Chunks execute on 3 local workers.
3. Aggregated result is deterministic.
4. Orchestrator run history remains truthful under retry/cancel.

### M1 Acceptance

1. 3-node LAN can complete one swarm run with one node failure mid-run.
2. Expired leases are re-assigned automatically.
3. Partial output stream remains visible through SFU.

### M2 Acceptance

1. Scheduler reliably assigns cheap nodes CPU-friendly chunks.
2. No single weak node can stall whole run (backpressure + reassignment).
3. Result correctness remains stable under gossip churn.

## Hard Rules

1. No new non-King transport stack.
2. No raw JSON-only compute protocol once IIBIN schema exists.
3. No hidden execution state outside orchestrator snapshots for run truth.
4. No bypass of Semantic DNS for production placement.
5. No direct large-payload flood over control channel; use object-store references.
