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

- [ ] Validate file-worker queue fairness under sustained contention and parallel workers.

## Active Executable Items

### 1. Control Plane, Routing, and Distributed Execution

1. [ ] Implement real multi-host backend boundaries for MCP and pipeline orchestrator beyond the current same-host topology scope.
   done when: controller, worker, and remote peer behavior is proven across machine boundaries rather than only separate local processes or explicit same-host topology contracts.

2. [ ] Validate file-worker queue fairness under sustained contention and parallel workers.
   done when: queue progression remains fair and starvation-free under repeated concurrent claims rather than only deterministic FIFO and claimed-recovery ordering.

3. [ ] Validate Smart DNS registration, routing, mother-node synchronization, and recovery against larger or distributed topologies.
   done when: service discovery and semantic routing stay coherent under parallel updates, restart, and failover rather than only local happy-path flows.

4. [ ] Finalize the honest v1 object-store backend contract.
   done when: either at least one non-local backend is real and verified, or the public contract is explicitly locked to `local_fs` plus simulated adapters with no stronger claim.

### 2. Observability and Fleet Operations

6. [ ] Validate autoscaling behavior under degraded telemetry and provider conditions, including rollback for failed bootstrap, registration, and readiness.
    done when: controller decisions remain safe and explainable while inputs or provider calls are missing, degraded, or partially failed.

7. [ ] Validate OTLP traces and logs export against real collectors, including non-2xx, timeout, size-limit, and outage-recovery behavior.
    done when: all exported telemetry signal types are verified against honest collectors instead of metrics-only local coverage.

8. [ ] Prove telemetry export semantics under sustained degraded conditions, including replay or explicit non-replay guarantees after restart.
    done when: long-haul exporter outage and recovery do not leave delivery semantics ambiguous.

### 3. Build, Release, and Compatibility Confidence

9. [ ] Turn the QUIC backend bootstrap into a deterministic pinned dependency path.
    done when: `quiche` and related build inputs rehydrate reproducibly on clean hosts without ad hoc branch fallbacks or local resurrection tricks.

10. [ ] Add clean-host and published-container install/smoke matrix coverage across supported PHP and API combinations, then lock upgrade/downgrade release gates behind it.
    done when: fresh-host package installs and published images are verified in CI instead of only local source builds.

11. [ ] Add long-duration ASan, UBSan, and leak-oriented soak gates with archived diagnostics.
    done when: sanitizer and soak regressions produce retained failure artifacts and block release-grade claims automatically.

## Notes

- HTTP/2 shared-session fairness under mixed slow/fast streams, HTTP/3 timeout-vs-recovery churn isolation, and multi-client WebSocket close/reconnect churn on one local server are now verified.
- Router/loadbalancer is now treated as an explicit `config_backed` control-plane component with no stronger forwarding-runtime claim in v1.
- Smart-DNS public config and init surfaces are now narrowed to the active `service_discovery` / semantic-runtime knobs; the remaining DNS work is topology and wire-depth, not more local config cleanup.
- MCP request, upload, and download now talk to a real same-host remote peer with propagated timeout, deadline, and cancellation controls, plus verified 1 MiB payload roundtrips, parallel-transfer backpressure isolation, explicit single-flight reentry guards per connection handle, same-host partial-failure recovery, persisted remote-state restart recovery, and an explicit `topology_scope=same_host_remote_peer` contract in system component info; the remaining MCP gap is real multi-host coverage plus richer distributed failure semantics.
- File-worker orchestration now persists honest `queued -> running -> completed|failed|cancelled` transitions, exposes explicit `single_attempt` retry and `caller_managed` idempotency, surfaces `topology_scope=local_in_process` for the local backend versus `same_host_file_worker` for file-worker mode, advertises `scheduler_policy=claimed_recovery_then_fifo_run_id`, keeps concurrent workers from double-claiming one active run, and now verifies that a run already marked `running` can be recovered exactly once after worker loss; the remaining orchestrator gap is real multi-host execution depth plus sustained fairness under contention.
- Everything else from `READYNESS_TRACKER.md` is either already verified, derivative of these leaves, or still too broad to be the active queue.
- If an item is not listed here, it is not the current repo-local priority.
