# King Issues

> This file is the single moving roadmap and execution queue for repo-local
> King v1.
> It distills `READYNESS_TRACKER.md` down to the next `20` executable leaves
> that are both still open and realistically implementable in this repository.
> Closed work lives in `PROJECT_ASSESSMENT.md`.
> `EPIC.md` stays the stable charter and release bar.

## Working Rules

- read `CONTRIBUTE.md` before starting, replenishing, or reshaping any `20`-issue batch
- keep only open work here
- every item must be narrow enough to implement and verify inside this repo
- if a tracker item is still too broad, split it before adding it here
- when a leaf closes, update code, tests, docs, and `PROJECT_ASSESSMENT.md` in the same change
- do not pull new items from `READYNESS_TRACKER.md` into this file unless the user explicitly asks for the next `20`-issue batch
- when the current batch is exhausted, stop and wait instead of refilling it automatically
- do not shrink a meaningful v1 contract just to make tests, CI, or docs easier; if the intended contract matters, build the missing backend work or ask explicitly before reducing scope

## Current Next Leaf

- [ ] Validate HTTP/3 backpressure under real multi-stream traffic.

## Active Executable Items

### 1. Backup / Restore / Recovery Depth

1. [x] Validate metadata migrations after restore.
   done when: restored objects keep the same honest metadata contract, backend-presence markers, and semantic fields instead of only surviving payload roundtrips.

2. [x] Define rolling-restore / partial-restore semantics where publicly claimed.
   done when: the public backup/restore surface either exposes a real rolling/partial restore contract with verifiable behavior or explicitly narrows the public claim to the committed full/incremental restore shapes that actually exist.

### 2. Transport And Listener Failure Depth

3. [x] Validate HTTP/1 connection reuse limits under load.
   done when: sustained mixed-load traffic proves the runtime caps, recycles, and tears down reused HTTP/1 connections honestly instead of only succeeding on happy-path reuse.

4. [x] Validate HTTP/3 timeout behavior against real slow peers.
   done when: real slow-reader and slow-writer QUIC peers trigger stable timeout behavior rather than only transport-abort and handshake-failure slices.

5. [ ] Validate HTTP/3 backpressure under real multi-stream traffic.
   done when: mixed fast and slow HTTP/3 streams keep progress bounded and fair under real peer pressure instead of only one-shot churn isolation.

6. [ ] Validate HTTP/3 fairness under sustained load.
   done when: repeated concurrent HTTP/3 work proves no starvation or pathological scheduler bias across active streams and sessions.

7. [ ] Validate HTTP/3 long-duration soak behavior under continuous load.
    done when: the runtime survives longer-lived HTTP/3 pressure without transport-state drift, resource leaks, or poisoned follow-up sessions.

8. [ ] Validate WebSocket backpressure under many concurrent connections.
    done when: slow websocket consumers do not let pending writes or queue growth escape the intended bounded runtime behavior.

9. [ ] Validate WebSocket fairness under many concurrent connections.
    done when: many active websocket clients can compete without one noisy or slow peer starving unrelated clients.

10. [ ] Validate server request normalization against real requests.
    done when: the on-wire server paths prove stable request-shape normalization across the active HTTP listener/runtime surfaces instead of only local validation contracts.

11. [ ] Validate server close / drain / restart behavior.
    done when: active listener sessions can shut down, drain, and restart under real traffic without leaks, hangs, or half-closed runtime state.

### 3. Control Plane Distributed Depth

12. [ ] Validate MCP multi-host operation.
    done when: the current real MCP peer contract is proven across actual cross-host topology instead of only same-host TCP host/port peers.

13. [ ] Implement pipeline continuation after host restart.
    done when: orchestrator continuation remains honest after the broader host-level loss case instead of only the current controller-process restart proof.

14. [ ] Finalize per-step error classification for orchestrated execution.
    done when: retry, non-retry, validation, remote transport, and backend failures stay distinguishable through the orchestrator surface without collapsing into generic runtime errors.

15. [ ] Validate distributed tool execution across multiple workers.
    done when: the orchestrator proves stable multi-worker execution, claiming, and result handling beyond the current local/file-worker and single remote-peer depth.

### 4. Smart-DNS Distributed Recovery

16. [ ] Validate consistency after Smart-DNS split-brain / partial-failure scenarios where publicly claimed.
    done when: discovery, routing, and mother-node state converge honestly after conflicting writers, stale peers, or partial topology loss instead of only under the current coherent local slice.

17. [ ] Validate Smart-DNS DNS failure and recovery behavior.
    done when: DNS-facing failure, timeout, and recovery paths are exercised and mapped cleanly instead of leaving the broader networked recovery contract implicit.

## Next-Up Clusters After The Top 20

- object-store provider quota/rate-limit classification and broader backup/import/export recovery depth beyond the current real core/cloud proof
- telemetry cleanup, cross-request residue hardening, load bounds, self-metrics, and richer export diagnostics
- autoscaling decision logic under real load, live drain-before-delete, and automated post-bootstrap registration/readiness
- broader QUIC lifecycle, stats, resumption, and recovery validation beyond the current HTTP/3 client slices
- admin API auth/reload/failure depth, server response normalization, and broader real listener shutdown/drain coverage
- systematic security-review closure, negative-input expansion, and release-gated hardening across public entry, persistence, transport, and provider paths

## Notes

- The active queue is administered in explicit `20`-issue batches, not by automatic replenishment from `READYNESS_TRACKER.md`.
- When the current batch is exhausted, work stops until the next `20`-issue batch is explicitly pulled in.
- The active queue intentionally carries the next `20` open executable leaves, not a historical list of already-closed wins.
- Items still open in `READYNESS_TRACKER.md` but not listed here are either derivative of these leaves, already fenced honestly, or still too broad to be a useful execution item today.
- If an item is not listed here, it is not the current repo-local priority.
