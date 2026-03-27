# King Project Assessment

> Stand: 2026-03-27
> Scope: verified repo-local v1 state inside this repository
> This file records what is actually verified now.
> `README.md` stays product-level.
> `ISSUES.md` is the single moving roadmap and execution queue.
> `READYNESS_TRACKER.md` is the long-form closure tracker.

## Executive Summary

King currently sits at a green repo-local v1 baseline.
The active extension builds, audits, packages, and passes the full PHPT suite,
and the public stub surface matches the live runtime.

That does not yet mean "final 10/10".
The remaining gaps are no longer about broad runtime parity or placeholder
surfaces inside the local tree. They are now concentrated in four narrower
areas:

- deeper transport and listener failure-path verification across HTTP/1, HTTP/2, HTTP/3, and WebSocket
- MCP transfer durability/error semantics and orchestrator continuation depth
- larger Smart-DNS routing and concurrent-update correctness beyond the current local proof
- stronger telemetry/autoscaling load-bound, cleanup, and recovery guarantees

The long-form completion checklist has now been distilled into the next `20`
repo-local executable leaves. If an open v1 item is not in `ISSUES.md`, it is
not part of the current execution queue yet.

## Verified Baseline Snapshot

The currently verified baseline is:

- `./scripts/static-checks.sh`: passing
- `./scripts/check-include-layout.sh`: passing
- `./scripts/audit-runtime-surface.sh`: passing
- `./scripts/build-extension.sh`: passing
- `./scripts/test-extension.sh`: `351/351` passing
- `./scripts/fuzz-runtime.sh`: passing
- `./scripts/check-stub-parity.sh`: passing
- `./scripts/package-release.sh --verify-reproducible`: passing
- `./scripts/install-package-matrix.sh --archive <release> --php-bins php8.4`: passing
- `./scripts/check-release-upgrade.sh --from-ref HEAD^`: passing
- `./scripts/check-release-downgrade.sh --from-ref HEAD^`: passing
- `./scripts/check-persistence-migration.sh --from-ref HEAD^`: passing
- `./scripts/check-config-compatibility-matrix.sh`: passing
- `./scripts/verify-release-package.sh`: passing
- `./scripts/container-smoke-matrix.sh --php-versions 8.3`: passing
- `./scripts/soak-runtime.sh asan|ubsan|leak --iterations 1`: passing
- `./scripts/go-live-readiness.sh`: passing
- `./scripts/build-profile.sh release|debug|asan|ubsan`: passing
- `./scripts/smoke-profile.sh release|debug|asan|ubsan`: passing
- benchmark smoke and committed CI budget gate: passing

Current tree facts:

- `extension/src`: `177` C files
- `extension/include`: `172` headers
- `extension/tests`: `351` PHPT files
- public stub parity: `125` functions, `43` classes, `48` declared public methods
- `king_health()['stubbed_api_group_count']`: `0`
- project-owned headers now live under `extension/include` with generated `extension/config.h` as the only root-level exception
- static and runtime-surface audits now enforce that include-tree discipline

## What Is Verified And Real Today

The current tree already proves:

- explicit config and session ownership through `King\Config` and `King\Session`
- real HTTP/1, HTTP/2, and HTTP/3 client request paths, including reuse, streaming, and cancel/timeout contracts
- HTTP/2 shared-session fairness under mixed slow and fast concurrent streams
- HTTP/3 one-shot churn isolation across repeated timeout and healthy-recovery cycles
- local server dispatch and listener slices for HTTP/1, HTTP/2, and HTTP/3
- on-wire WebSocket client handshake/frame/close runtime plus honest OO `King\WebSocket\Connection` parity
- multi-client WebSocket close/reconnect churn on one server without cross-client starvation or corruption
- server-side `king_server_upgrade_to_websocket()` both as an honest local marker slice for local listeners and as a real on-wire HTTP/1 one-shot upgrade path with frame flow, handler ownership, and close/drain coverage
- repeated server/session soak coverage for local upgrade, early hints, admin API, TLS reload, and on-wire websocket close/drain cycles
- IIBIN schema, registry, encode/decode, object hydration, and wire validation
- Semantic DNS register/discover/update routing, larger-topology local churn coherence, registry-backed mother-node sync statistics, persisted registration plus mother-node rehydration across restart, and private-directory durable state handling
- Smart-DNS public config and init surfaces are now narrowed to the active `service_discovery` / semantic-runtime knobs
- router/loadbalancer is now exposed as an explicit config-backed system component with honest policy/discovery-only introspection
- object-store local filesystem persistence, explicit `local_fs_only` runtime/system contract, `memory_cache -> local_fs` compatibility aliasing, simulated non-local adapter fencing, `.meta` sidecars, CDN cache/runtime behavior, and confined backup/restore/import/export paths
- deterministic QUIC bootstrap through a tracked pinset for the `quiche` repo revision, BoringSSL submodule revision, pinned workspace lockfile, and pinned `wirefilter` git revision with fail-closed static and PHPT verification
- one shared runtime install smoke across staged profiles, packaged release artifacts, and published runtime containers, plus first-class clean-host package install and container smoke matrix entrypoints
- long-duration ASan, UBSan, and leak-oriented soak gates with retained per-iteration logs and archived failure diagnostics under `extension/build/soak/` plus CI artifact upload on soak failure
- MCP request/upload/download parity against a real TCP host/port remote peer with propagated timeout, deadline, cancellation controls, IPv4 and IPv6 peer targeting coverage, 1 MiB payload roundtrips, parallel-transfer backpressure isolation, explicit single-flight reentry guards, same-host partial-failure recovery, persisted remote-state restart recovery coverage, and explicit `topology_scope=tcp_host_port_peer` introspection
- orchestrator persistence, honest `queued -> running -> completed|failed|cancelled` run transitions, explicit `single_attempt` retry plus `caller_managed` idempotency contract, explicit `topology_scope=local_in_process|same_host_file_worker|tcp_host_port_execution_peer` introspection by backend mode, deterministic `claimed_recovery_then_fifo_run_id` scheduling, exclusive claimed-file locking across concurrent workers, recovery of an already-running claimed run exactly once after worker loss, sustained queue fairness under repeated parallel-worker contention, local/file-worker backend boundaries, real TCP host/port `remote_peer` execution with persisted success/failure snapshots, cross-process cancellation, and multiprocess controller/observer/worker verification
- telemetry batch queueing, bounded retry behavior, explicit `best_effort_bounded_retry` plus `process_local_non_persistent` delivery semantics, explicit `restart_replay=not_supported` and `drain_behavior=single_batch_per_flush` component contracts, OTLP metrics/traces/logs export hardening, and real local collector coverage for success plus non-2xx, timeout, response-size-limit, and outage recovery
- telemetry-driven Hetzner autoscaling with controller-owned credentials, persisted recovery state, `register -> ready -> drain -> delete` lifecycle gating, and stale pending-node rollback for failed bootstrap, registration, and readiness with explicit provider-delete failure reporting
- system integration lifecycle coordination, restart-state visibility, and chaos/recovery harness coverage for the local control plane

## What Is Still Not Finished

The repo is still short of a "nothing left to caveat" v1 in these areas:

### Remote Control Plane Depth

- MCP now propagates timeout, deadline, and cancellation controls through the real peer protocol, and host/port TCP peer targeting, large-payload behavior, parallel-transfer backpressure isolation, single-flight reentry safety, partial-failure recovery, plus persisted remote-state restart recovery are verified. The runtime now exposes that honest `tcp_host_port_peer` scope explicitly; richer distributed failure semantics and broader multi-host validation depth are still open.
- The orchestrator now has honest `local_in_process`, `same_host_file_worker`, and `tcp_host_port_execution_peer` backend scopes, plus verified success/failure execution over a real TCP host/port remote peer. Remaining gaps are broader distributed multi-worker execution depth, continuation after process or host restart, richer error classification, observability depth, compensation semantics where publicly claimed, and a broader true multi-host harness.
- Retry and idempotency semantics are now explicit and test-backed for the current file-worker slice, including exact once-only recovery after worker loss during active execution and starvation-free queue progression under parallel-worker contention.

### Transport And Listener Failure Depth

- The current tree proves happy-path wire coverage and several sustained-load slices across HTTP/1, HTTP/2, HTTP/3, WebSocket, and local server/runtime control flows, but failure-depth is still uneven.
- The next missing proof is concrete and repo-local: bodiless HTTP/1 responses, abort/reset paths, real listener coverage for server HTTP/2 and HTTP/3, handshake/transport-abort cases for HTTP/3, connection reuse/ticket behavior, and more explicit WebSocket violation/abort handling.
- These are no longer architectural unknowns; they are the next missing proof leaves on already-real kernels.

### Routing and DNS Scope

- Router/loadbalancer is now honestly fenced to a config-backed control-plane surface; it is not presented as a forwarding dataplane runtime.
- Smart-DNS public config and init surfaces are now honest for the current local semantic/service-discovery runtime.
- The remaining Smart-DNS work is real distributed topology validation, richer mother-node synchronization beyond the local registry-backed slice, routing verification against real load and health signals, and failover behavior rather than more local config cleanup or restart-state basics.

### Observability and Fleet Operations

- Metrics, traces, and logs now share the same bounded export path and are verified against real local collectors for success plus non-2xx, timeout, response-size-limit, and outage-recovery slices.
- Telemetry queueing is now bounded and its current v1 semantics are explicit: best-effort bounded retry, process-local non-persistent queueing, one drain attempt per flush, and no restart replay guarantee. Longer-haul degraded exporter behavior, richer ordering/idempotency guarantees, and stronger diagnostics still need more proof.
- Autoscaling now rolls stale Hetzner pending nodes back safely even when telemetry is missing or degraded, and provider-side rollback failures are surfaced explicitly. Multi-node rolling restart, real-load decision depth, and broader fleet failover behavior are still open.

### Build, Compatibility, and Release Confidence

- QUIC and HTTP/3 now bootstrap from a pinned repo-owned dependency path instead of ad hoc local `quiche` resurrection or unlocked cargo retries.
- Clean-host package install and published-container smoke are now first-class gates, with CI-driven host PHP `8.3`/`8.4`/`8.5` package verification and published-image builds narrowed to the same supported PHP matrix.
- Upgrade and downgrade compatibility for release artifacts are now explicit script/CI gates that package a previous git ref, verify both archives, and smoke-test both install orders against the same install prefix.
- Release-artifact upgrade, downgrade, representative persisted-state migration, and old/new configuration-state behavior are now all explicit script and CI gates.

## Current Remaining Work Model

The repo no longer treats every imaginable future check as the active queue.

The model is now:

- `EPIC.md`
  stable charter, pillars, and exit criteria
- `ISSUES.md`
  the active executable open items distilled from the larger completion tracker
- `READYNESS_TRACKER.md`
  the broad long-form completion checklist with verified checks and still-open closure gates
- `PROJECT_ASSESSMENT.md`
  verified state and caveats

If a task is broad, vague, or derivative, it does not belong in the active
queue until it is split into a repo-local executable leaf.

## Source Of Truth Boundaries

Use the root documents like this:

- `README.md`
  stable product description
- `EPIC.md`
  stable charter and release bar
- `ISSUES.md`
  single moving roadmap and open execution queue
- `READYNESS_TRACKER.md`
  long-form completion tracker and broad closure reference
- `PROJECT_ASSESSMENT.md`
  verified current state and caveats
- `CONTRIBUTE.md`
  workflow and verification discipline
- `stubs/king.php`
  public PHP signature surface

If a statement is about what is verified right now, it belongs here rather than
in `README.md`.
