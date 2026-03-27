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

- [ ] Turn the QUIC backend bootstrap into a deterministic pinned dependency path.

## Active Executable Items

### 1. Build, Release, and Compatibility Confidence

1. [ ] Turn the QUIC backend bootstrap into a deterministic pinned dependency path.
   done when: `quiche` and related build inputs rehydrate reproducibly on clean hosts without ad hoc branch fallbacks or local resurrection tricks.

2. [ ] Add clean-host and published-container install/smoke matrix coverage across supported PHP and API combinations, then lock upgrade/downgrade release gates behind it.
   done when: fresh-host package installs and published images are verified in CI instead of only local source builds.

3. [ ] Add long-duration ASan, UBSan, and leak-oriented soak gates with archived diagnostics.
   done when: sanitizer and soak regressions produce retained failure artifacts and block release-grade claims automatically.

## Notes

- HTTP/2 shared-session fairness under mixed slow/fast streams, HTTP/3 timeout-vs-recovery churn isolation, and multi-client WebSocket close/reconnect churn on one local server are now verified.
- Router/loadbalancer is now treated as an explicit `config_backed` control-plane component with no stronger forwarding-runtime claim in v1.
- Smart-DNS public config and init surfaces are now narrowed to the active `service_discovery` / semantic-runtime knobs, and Semantic-DNS now persists and rehydrates registered services plus mother-node topology across restart while also verifying coherent discovery, routing, and mother-node sync statistics under larger local topology churn. The remaining DNS work is real distributed topology depth, richer mother-node synchronization beyond the local registry-backed slice, failover behavior, and wire-level scope, not more local config cleanup or restart-state ambiguity.
- Object-store v1 is now explicitly frozen to the honest `local_fs` contract. `memory_cache` is only a compatibility alias to the same local backend, and `distributed` plus cloud adapters remain explicitly simulated/unavailable instead of implying a stronger non-local storage claim.
- MCP request, upload, and download now talk to a real TCP host/port remote peer with propagated timeout, deadline, and cancellation controls, plus verified IPv4 and IPv6 peer targeting, 1 MiB payload roundtrips, parallel-transfer backpressure isolation, explicit single-flight reentry guards per connection handle, same-host partial-failure recovery, persisted remote-state restart recovery, and an explicit `topology_scope=tcp_host_port_peer` contract in system component info; the remaining MCP gaps are richer distributed failure semantics and broader multi-host validation depth, not a false same-host-only transport claim.
- Pipeline orchestration now has three honest backend scopes: `local_in_process`, `same_host_file_worker`, and `tcp_host_port_execution_peer`. The new `remote_peer` backend executes runs over a real TCP host/port worker boundary, persists local run snapshots, and records both successful and failed remote execution outcomes. Remaining orchestrator work is deeper distributed execution semantics, restart continuation, richer error classification, observability depth, and compensation/rollback where publicly claimed.
- OTLP metrics, traces, and logs now share the same bounded batch/retry path and are validated against real local collectors for success plus non-2xx, timeout, response-size-limit, and outage-recovery slices. Telemetry now also exposes an explicit `best_effort_bounded_retry` contract with a process-local non-persistent queue, single-batch-per-flush drain behavior, and no restart replay guarantee. The remaining telemetry work is stronger ordering/idempotency guarantees, richer diagnostics, and longer-haul degraded characterization rather than collector coverage or restart-semantics ambiguity.
- Hetzner autoscaling now rolls stale `provisioned` and `registered` nodes back before the next monitor decision when bootstrap, registration, or readiness stall past `idle_node_timeout_sec`, and it reports provider-side delete failures explicitly instead of silently wedging pending capacity under degraded telemetry or provider conditions.
- Everything else from `READYNESS_TRACKER.md` is either already verified, derivative of these leaves, or still too broad to be the active queue.
- If an item is not listed here, it is not the current repo-local priority.
