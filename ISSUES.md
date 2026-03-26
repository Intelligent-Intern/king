# King Issues

> This file is the single moving roadmap and execution queue for repo-local
> King v1.
> It distills the long-form completion checklist down to the highest-signal
> open items that are both still open and realistically executable in this
> repository.
> It is derived from the current verified tree plus `READYNESS_TRACKER.md`,
> but only keeps leaves that are narrow enough to execute here.
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

- [ ] Verify multi-connection backpressure and fairness semantics under HTTP/2, HTTP/3, and WebSocket churn.

## Active Executable Items

### 1. Realtime and Server Wire Truth

1. [ ] Verify multi-connection backpressure and fairness semantics under HTTP/2, HTTP/3, and WebSocket churn.
   done when: competing clients cannot starve each other and queue growth stays bounded under sustained load.

### 2. Control Plane, Routing, and Distributed Execution

2. [ ] Move MCP beyond local-first helper state into a real remote backend path.
   done when: request, upload, and download leave the current purely local boundary and talk to an actual counterpart.

3. [ ] Verify MCP request, upload, and download against a real remote peer with timeout, deadline, and cancellation propagation.
   done when: targeted end-to-end harnesses cover success, timeout, cancellation, and remote failure behavior.

4. [ ] Validate MCP large-payload transfer, bounded concurrency, backpressure, and restart/partial-failure recovery.
   done when: stressed transfer paths stay bounded and recover cleanly after interruption.

5. [ ] Finalize orchestrator retry, idempotency, and exact queued/running/failed/cancelled/completed state transitions.
   done when: pipeline reruns, worker retries, and failure classification are explicit, test-backed, and stable.

6. [ ] Extend orchestrator and MCP verification from same-host multiprocess coverage to multi-host topology coverage.
   done when: controller, worker, and remote peer behavior is proven across machine boundaries rather than only separate local processes.

7. [ ] Validate worker failure handling, queue fairness, and scheduler behavior under sustained pipeline load.
   done when: active runs survive worker loss correctly and queue progression remains fair under contention.

8. [ ] Validate Smart DNS registration, routing, mother-node synchronization, and recovery against larger or distributed topologies.
   done when: service discovery and semantic routing stay coherent under parallel updates, restart, and failover rather than only local happy-path flows.

9. [ ] Finalize the honest v1 object-store backend contract.
   done when: either at least one non-local backend is real and verified, or the public contract is explicitly locked to `local_fs` plus simulated adapters with no stronger claim.

### 3. Observability and Fleet Operations

10. [ ] Validate autoscaling behavior under degraded telemetry and provider conditions, including rollback for failed bootstrap, registration, and readiness.
    done when: controller decisions remain safe and explainable while inputs or provider calls are missing, degraded, or partially failed.

11. [ ] Validate OTLP traces and logs export against real collectors, including non-2xx, timeout, size-limit, and outage-recovery behavior.
    done when: all exported telemetry signal types are verified against honest collectors instead of metrics-only local coverage.

12. [ ] Prove telemetry export semantics under sustained degraded conditions, including replay or explicit non-replay guarantees after restart.
    done when: long-haul exporter outage and recovery do not leave delivery semantics ambiguous.

### 4. Build, Release, and Compatibility Confidence

13. [ ] Turn the QUIC backend bootstrap into a deterministic pinned dependency path.
    done when: `quiche` and related build inputs rehydrate reproducibly on clean hosts without ad hoc branch fallbacks or local resurrection tricks.

14. [ ] Add clean-host and published-container install/smoke matrix coverage across supported PHP and API combinations, then lock upgrade/downgrade release gates behind it.
    done when: fresh-host package installs and published images are verified in CI instead of only local source builds.

15. [ ] Add long-duration ASan, UBSan, and leak-oriented soak gates with archived diagnostics.
    done when: sanitizer and soak regressions produce retained failure artifacts and block release-grade claims automatically.

## Notes

- WebSocket client connect/send/receive/ping/close, HTTP/1 server-side websocket upgrade, and repeated listener/session soak coverage for upgrade, early hints, admin API, TLS reload, and close/drain are now verified. The remaining realtime work is multi-connection fairness and backpressure under churn.
- Router/loadbalancer is now treated as an explicit `config_backed` control-plane component with no stronger forwarding-runtime claim in v1.
- Smart-DNS public config and init surfaces are now narrowed to the active `service_discovery` / semantic-runtime knobs; the remaining DNS work is topology and wire-depth, not more local config cleanup.
- Everything else from `READYNESS_TRACKER.md` is either already verified, derivative of these leaves, or still too broad to be the active queue.
- If an item is not listed here, it is not the current repo-local priority.
