# Voltron Compute Mesh Plan (King Infra Is Non-Negotiable)

**Purpose**: Voltron is the distributed inference layer that runs a **Qwen clone**
on King's native infrastructure. Weights come from Ollama's GGUF copy of Qwen2.5-coder:3b.
Voltron partitions the model into blocks, the King orchestrator executes them
across a mesh of peers, and `king_gguf_tensor_scan` provides native C tensor ops.
The goal is producing output indistinguishable from Qwen itself, distributed.

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
8. **GGUF in C/Assembly** is the native compute kernel for tensor operations
   (`king_gguf_tensor_scan`): disk-backed GGUF parsing, quantization decode, and
   tensor-vector projection in C/asm. Supports F32/F16/Q8_0/Q4_K/Q6_K. No PHP decode loops.

## Extension API Policy

The King extension public surface (`king_gguf_tensor_scan`) is implemented in C and
disk-backed. Compatibility aliases are maintained only during migration windows and
MUST be removed and replaced as part of code cleanup once callers migrate off them.
Supported tensor types: F32 (0), F16 (1), Q8_0 (8), Q4_K (12), Q6_K (14).

## Current Branch Reality (`experiments/1.0.7-voltron`)

1. Present and usable:
   - IIBIN runtime and docs
   - WebSocket client/server runtime
   - Pipeline orchestrator (local, file-worker, remote-peer) with registered handler API
   - Semantic DNS runtime
   - SFU signaling path
   - Native GGUF tensor scan in C (`king_gguf_tensor_scan`)
   - Voltron tokenizer with BPE encode/decode and byte-level fallback
2. Gap to close first:
   - Gossip Mesh source/header is not fully present on this branch as tracked source.
   - Compute mesh work must start by porting the gossip-mesh primitives from
     `experiments/1.0.7-gossip-mesh` into voltron.

## Execution Notes (2026-04-23)

1. Orchestrator supports graph-shaped submission (`steps` + `id` + `deps`) and normalizes
   into deterministic topological order before run persistence.
2. Registered handler API is used: `king_pipeline_orchestrator_register_handler()` chains
   step output into `current_payload`, which becomes the next step's input and the run's
   final return value. Handler output `['output']` is flattened into the return value.
3. Decode loop terminates via `decode_stop` flag: handler sets `output.decode_stop` from
   `state.stop`, orchestrator flattens it to `result.decode_stop`, runner checks it per iteration.
4. M0 scaffolding is operational: VoltronKernels executes block DAG, VoltronRunner drives
   decode loop, VoltronScheduler assigns peer ownership, VoltronTokenizer handles BPE.
5. Build/test snapshot is tracked in `VOLTRON_BUILD_STATE.md`.

## M0 Status and Immediate Priority

**M0 target: single-machine inference producing output indistinguishable from Qwen2.5-coder:3b**

The Ollama GGUF weights are the ground truth. Voltron must decode them faithfully,
the same way Qwen would. Current state is **partially working**: orchestrator loop
executes, blocks partition, loop terminates, but output is garbage due to correctness bugs.
Fix the bugs first, then validate output quality.

### M0 Correctness Bugs (Must Fix)

1. **Shared embedding (lm_head = token_embd.weight)**: Qwen uses the same weight matrix for
   embedding and lm_head. VoltronKernels samples `hidden_indices` from lm_head rows, which
   corrupts logits. Fix: use full embedding row (no sampling) for lm_head projection.
2. **Tokenizer decode mismatch**: Model vocab uses byte-pair encoding where many tokens
   are not pure byte sequences. `VoltronTokenizer::decodeId` returns empty strings because
   `decodeRawToken` checks `<0x..>` byte patterns but most tokens are BPE merges. Fix:
   detect vocab encoding from token types and implement proper BPE decode.
3. **Format prompt not applied**: `VoltronTokenizer::formatPrompt()` checks `$this->pre !==
   'qwen2'` but model does not set pre-field, so chat template (`<|im_start|>...<|im_end|>`)
   is never injected. Fix: derive pre-field from model architecture metadata or default to
   qwen2 for known model families.

### M0 Performance Notes

1. Native scan (`king_gguf_tensor_scan`) handles F32/Q4_K/Q6_K projection correctly in C.
   Remaining bottleneck is PHP overhead: ~9s/token across 36 blocks, 151936 vocab.
2. No SIMD or threading in extension. Acceptable for M0 demo; not acceptable for production.
3. Future performance paths: batch inference, multi-threaded projection, or CUDA offload.

### M0 Remaining Work (After Correctness Fixes)

1. Verify readable output from voltron.php with 8-16 tokens.
2. Land a smoke contract test that asserts non-garbage output (e.g., contains valid UTF-8,
   contains expected keywords from prompt domain).
3. Enable VOLTRON_KERNEL_WEIGHT_CACHE=1 to cache attention/FFN weights across iterations.

### Gate B: M1 (LAN Multi-Node) - Not Yet Started

1. Enable remote-peer orchestrator dispatch plus lease ownership rules.
2. Use Semantic DNS registration/discovery for placement.
3. Add gossip relay for heartbeat and task-offer propagation.
4. Add SFU fanout for realtime partial output streams.

### Gate C: M2 (Mixed Hardware) - Not Yet Started

1. Capability-aware scheduler tuned for CPU-only + M1 + low GPU mix.
2. Dynamic chunk sizing by node class.
3. Quorum verification for selected critical chunks.
4. Reputation and backpressure enabled by default.

### Gate D: Gossip Mesh Port - Must Precede M1

1. Port gossip mesh runtime from `experiments/1.0.7-gossip-mesh`:
   - `extension/include/gossip_mesh.h`
   - `extension/src/gossip_mesh/*`
   - build/config glue required by `extension/config.m4`
2. Add contract tests proving gossip primitives load in voltron branch.
3. Required before M1 because gossip relay is part of M1's fanout plane.

## Architecture (King-Native)

### 1) Control Plane: WebSocket + IIBIN

1. Every voltron node keeps one long-lived King WebSocket session to edge/control.
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

Define a voltron protocol package on IIBIN with strict versioning:

1. `voltronMessageKind` enum:
   - `node_announce`, `node_heartbeat`, `task_offer`, `task_lease`,
     `task_chunk`, `task_partial`, `task_final`, `task_fail`,
     `artifact_ref`, `swarm_control`, `swarm_metrics`.
2. `voltronEnvelope` schema:
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

1. Yes, nodes can own experts, and this should be the default for voltron.
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

### Gate A: Foundation Port into voltron

1. Port gossip mesh runtime artifacts from `experiments/1.0.7-gossip-mesh`:
   - `extension/include/gossip_mesh.h`
   - `extension/src/gossip_mesh/*`
   - any build/config glue required by `extension/config.m4`
2. Keep SFU and WebSocket behavior stable while porting.
3. Add/restore contract tests proving gossip primitives load in voltron branch.

### Gate B: M0 (Single Machine, Deterministic)

**Status: PARTIALLY WORKING - correctness bugs block readable output**

1. Implement IIBIN voltron protocol definitions.
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
   - native GGUF scan correctness (F32/F16/Q4_K/Q6_K projection accuracy)
   - Voltron output is valid UTF-8 and coherent text (after correctness fix)
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
5. **CRITICAL**: Voltron output is valid UTF-8, coherent text matching prompt domain.
   (Currently broken due to shared embedding + tokenizer decode bugs)

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
6. No PHP decode loops in the extension; tensor scanning is native C and disk-backed.
7. Compatibility aliases MUST be removed as part of code cleanup once callers migrate off them.
8. No shared embedding corruption; lm_head must use full weight rows, not sampled subsets.
9. No garbage output; tokenizer decode must produce valid UTF-8 for BPE vocab tokens.