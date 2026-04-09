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
- when a leaf closes, update code, touched comments/docblocks, and tests in the same change; handbook docs and `READYNESS_TRACKER.md` may be deferred only when the current batch explicitly says so by user request
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
- update repo docs affected by the leaf, unless the current batch explicitly defers handbook closeout to the end
- update `PROJECT_ASSESSMENT.md`
- update `READYNESS_TRACKER.md`, unless the current batch explicitly defers tracker closeout to the end
- run the strongest relevant verification available for that leaf before committing
- make exactly one commit for the checkbox
- before any PR refresh or release-candidate handoff, re-check `https://github.com/Intelligent-Intern/king/security/quality/ai-findings` and fix every outstanding finding on the branch

## Batch Mode

- The user is advancing the current batch manually with `w`.
- Close exactly one checkbox, make exactly one commit, and then wait for the next `w`.
- For the current visible batch, defer repo docs and `READYNESS_TRACKER.md` updates until every visible checkbox is closed, then do the closeout sweep once before pushing `develop/v1.0.3-beta` and opening the PR.
- After the PR is open, each further `w` means wait instead of auto-refilling from `READYNESS_TRACKER.md`.

## Current Next Leaf

- batch `S` is active on `develop/v1.0.3-beta`.

## Active Executable Items

### S. Distribution Proof, Multi-Node Operations, and V1 Hardening

The next batch focuses on closing the biggest remaining public-reliability gaps before the next beta gate: on-wire distribution, system recovery, provider behavior, security-hardening, and release determinism.

- [ ] `#1 Implement verifiable distributed WebSocket fanout and upgrade-forwarding so HTTP listeners can route live sessions across nodes under sustained load.`
  done when: the repo proves on-wire websocket fanout and node-to-node upgrade forwarding for real multi-node traffic, including routing fairness and backpressure behavior.
- [ ] `#3 Prove sustained QUIC/HTTP/3 runtime stability under stress and partial failure.`
  done when: long-duration soak tests cover stream/session lifecycle, congestion and flow control, zero-RTT/resumption, interruption recovery, and deterministic error mapping.
- [ ] `#4 Finish HTTP/1, HTTP/2, and HTTP/3 listener verification for real on-wire request/response and session behavior.`
  done when: server listeners survive heavy traffic with normalization, cleanup, Early Hints, TLS reload, mTLS admin paths, fairness, and restart-safe drain behavior.
- [ ] `#5 Promote Semantic DNS from local-only mode to real network listener behavior.`
  done when: discovery, registration, and gossip-like topology behavior run over real sockets with persistence, rehydration, and partial split-brain recovery.
- [x] `#6 Validate routing decisions against real health and load signals instead of static or local-only heuristics.`
  done when: router policy uses measured load/health deltas and produces explainable routing decisions with bounded stale-state impact.
- [x] `#7 Enforce system-wide readiness and drain state transitions across the entire runtime fabric.`
  done when: all runtime entrypoints observe ordered state transitions for start, ready, drain, stop, and fail conditions, including controlled admission behavior during drain.
- [ ] `#8 Implement coordinated recovery across node and component failures with explicit state replay policy.`
  done when: recovery plans are proven for at least one node failure and one component failure path, including bounded divergence recovery windows.
- [ ] `#9 Validate autoscaling in load-representative traffic and recovery conditions.`
  done when: autoscaling decisions are explained from observed CPU/memory/RPS/queue/latency signals and recover correctly after controller restart or partial state loss.
- [x] `#10 Implement drain-before-delete behavior with active-connections preservation and safe teardown.`
  done when: node deletion under live traffic preserves in-flight work and only closes at a controlled boundary with bounded loss.
- [x] `#11 Harden Hetzner provision/deletion path as production-grade.`
  done when: bootstrap, registration, readiness propagation, delete/retry behavior, and failure modes are proven end-to-end in real Hetzner API conditions.
- [ ] `#12 Reconstruct provider fleet state after controller restart without losing pending decisions.`
  done when: controller recovery rehydrates live provider state and safely resumes pending actions with deterministic conflict handling.
- [ ] `#13 Finalize OTLP export behavior under real collectors and failure modes.`
  done when: success/failure/rate-limit/retry/timeouts/request-size/response-size are all represented with deterministic ordering and bounded replay policy.
- [ ] `#14 Close telemetry lifecycle gaps around memory bounds, residue prevention, and context propagation.`
  done when: telemetry state cannot leak across requests/workers, queue policies prevent unbounded growth, and propagation stays intact under resumptions.
- [ ] `#15 Implement end-to-end backup and restore flows for snapshots and metadata with integrity checks.`
  done when: restore from full/incremental payloads is validated and idempotent under partial corruption and schema-migration pressure.
- [ ] `#16 Prove restart-rehydration consistency across all persistence modes in one matrix.`
  done when: local restart and crash recovery preserve contracts for store, runtime state, and in-flight recovery semantics under all supported persistence modes.
- [ ] `#17 Harden real S3 path with multi-backend fallback and failure-aware recovery.`
  done when: cloud-backed object operations survive credential/rate-limit/network faults and keep replica/failover semantics coherent.
- [ ] `#18 Strengthen cache/CDN behavior under real traffic and pressure.`
  done when: cache fill/invalidation/TTL/recovery semantics are verified under load, stale-object handling, and memory pressure.
- [ ] `#19 Execute a full hardening sweep across public entry points, persistence, transport, credentials, and untrusted inputs.`
  done when: path traversal, injection, UAF, leak/double-free, and secret-handling risks are systematically closed with regression tests for negative inputs.

## Notes

- The active batch is now the `S` distributed runtime and v1 hardening block.
- The `Q` and `R` blocks are fully completed and recorded in `PROJECT_ASSESSMENT.md`.
- If a task is not listed here, it is not the current repo-local execution item.
