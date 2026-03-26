# King Issues

> This file is the single moving roadmap and execution queue for repo-local
> King v1.
> It distills the long-form completion checklist down to the 20 highest-signal
> items that are both still open and realistically executable in this
> repository.
> Closed work lives in `PROJECT_ASSESSMENT.md`.
> `EPIC.md` now stays short and only carries the stable program charter and exit
> criteria.

## Working Rules

- keep only open work here
- every item must be narrow enough to implement and verify inside this repo
- if a checklist item is too broad, split it before adding it here
- when a leaf closes, update code, tests, docs, and `PROJECT_ASSESSMENT.md` in the same change
- if a capability stays simulated, local-only, or intentionally unsupported, keep the public docs honest

## Current Next Leaf

- [ ] Replace local-only WebSocket handshake/runtime assumptions with on-wire client and server verification.

## Top 20 Executable Items

### 1. Realtime and Server Wire Truth

1. [ ] Replace local-only WebSocket handshake/runtime assumptions with on-wire client and server verification.
   done when: dedicated harnesses verify client handshake, server upgrade, frame flow, ping/pong, and close semantics over real sockets.

2. [ ] Add on-wire WebSocket upgrade coverage from HTTP/1 plus honest server-side close and drain behavior.
   done when: upgrade, handler ownership, shutdown, and drain semantics are exercised against a real listener instead of only local shims.

3. [ ] Add long-lived server/session soak coverage for upgrade, early-hints, TLS reload, admin API, and close/drain flows.
   done when: sustained listener tests prove lifecycle stability over time instead of only short request-response passes.

4. [ ] Verify multi-connection backpressure and fairness semantics under HTTP/2, HTTP/3, and WebSocket churn.
   done when: competing clients cannot starve each other and queue growth stays bounded under sustained load.

### 2. MCP and Orchestrator Depth

5. [ ] Move MCP beyond local-first helper state into a real remote backend path.
   done when: request, upload, and download leave the current purely local boundary and talk to an actual counterpart.

6. [ ] Verify MCP request, upload, and download against a real remote peer with timeout, deadline, and cancellation propagation.
   done when: targeted end-to-end harnesses cover success, timeout, cancellation, and remote failure behavior.

7. [ ] Validate MCP large-payload transfer, bounded concurrency, backpressure, and restart/partial-failure recovery.
   done when: stressed transfer paths stay bounded and recover cleanly after interruption.

8. [ ] Finalize orchestrator retry, idempotency, and exact queued/running/failed/cancelled/completed state transitions.
   done when: pipeline reruns, worker retries, and failure classification are explicit, test-backed, and stable.

### 3. Distributed Execution and Fleet Behavior

9. [ ] Extend orchestrator and MCP verification from same-host multiprocess coverage to multi-host topology coverage.
   done when: controller, worker, and remote peer behavior is proven across machine boundaries rather than only separate local processes.

10. [ ] Validate worker failure handling, queue fairness, and scheduler behavior under sustained pipeline load.
    done when: active runs survive worker loss correctly and queue progression remains fair under contention.

11. [ ] Finalize the honest v1 object-store backend contract.
    done when: either at least one non-local backend is real and verified, or the public contract is explicitly locked to `local_fs` plus simulated adapters with no stronger claim.

12. [ ] Add coordinated rolling-restart, readiness, drain, and failover coverage across system components and autoscaling.
    done when: component restart and recovery flows are verified as a whole system behavior, not just as local status flips.

### 4. Observability and Operational Depth

13. [ ] Validate autoscaling behavior under degraded telemetry and provider conditions, including rollback for failed bootstrap, registration, and readiness.
    done when: controller decisions remain safe and explainable while inputs or provider calls are missing, degraded, or partially failed.

14. [ ] Validate OTLP traces and logs export against real collectors, including non-2xx, timeout, and outage-recovery behavior.
    done when: all exported telemetry signal types are verified against honest collectors instead of metrics-only local coverage.

15. [ ] Finalize telemetry drop, retry, and backpressure policy plus self-metrics and operator-facing failure diagnostics.
    done when: queue saturation, exporter failure, and dropped-data behavior are explicit, surfaced, and test-backed.

16. [ ] Prove telemetry export semantics under sustained degraded conditions, including process-restart recovery if the exported contract requires it.
    done when: long-haul exporter outage and recovery do not leave delivery semantics ambiguous.

### 5. Build, Release, and Compatibility Confidence

17. [ ] Turn the QUIC backend bootstrap into a deterministic pinned dependency path.
    done when: `quiche` and related build inputs rehydrate reproducibly on clean hosts without ad hoc branch fallbacks or local resurrection tricks.

18. [ ] Add clean-host and published-container install/smoke matrix coverage across supported PHP and API combinations.
    done when: fresh-host package installs and published images are verified in CI instead of only local source builds.

19. [ ] Verify upgrade and downgrade compatibility for release artifacts and persisted runtime state.
    done when: object-store, orchestrator, semantic-dns, and release package state survive version transitions with explicit guarantees.

20. [ ] Add long-duration ASan, UBSan, and leak-oriented soak gates with archived diagnostics.
    done when: sanitizer and soak regressions produce retained failure artifacts and block release-grade claims automatically.

## Notes

- Everything else from the long-form completion checklist is either already verified, derivative of these leaves, or still too broad to be the active queue.
- If an item is not listed here, it is not the current repo-local priority.
