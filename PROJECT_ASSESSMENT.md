# King Project Assessment

> Stand: 2026-03-31
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
surfaces inside the local tree. They are now concentrated in five narrower
areas:

- deeper transport and listener failure-path verification across HTTP/2, HTTP/3, and WebSocket
- stronger object-store provider-quota classification and broader cross-backend failure normalization around the now-real core/cloud surface
- orchestrator continuation depth and broader distributed control-plane recovery
- Smart-DNS split-brain, failure/recovery, and broader distributed-topology validation beyond the current concurrent-write, live-signal, and concurrent mother-node churn proof
- stronger telemetry/autoscaling load-bound, cleanup, and recovery guarantees

The long-form completion checklist has now been distilled into the next `20`
repo-local executable leaves. If an open v1 item is not in `ISSUES.md`, it is
not part of the current execution queue yet.

## Verified Baseline Snapshot

The currently verified baseline is:

- `./infra/scripts/static-checks.sh`: passing
- `./infra/scripts/check-include-layout.sh`: passing
- `./infra/scripts/audit-runtime-surface.sh`: passing
- `./infra/scripts/build-extension.sh`: passing
- `./infra/scripts/test-extension.sh`: `445/445` passing
- `./infra/scripts/fuzz-runtime.sh`: passing
- `./infra/scripts/check-stub-parity.sh`: passing
- `./infra/scripts/check-php-support-matrix.sh`: passing
- `./infra/scripts/package-release.sh --verify-reproducible`: passing
- `./infra/scripts/install-package-matrix.sh --archive <release> --php-bins php8.4`: passing
- `./infra/scripts/check-release-upgrade.sh --from-ref HEAD^`: passing
- `./infra/scripts/check-release-downgrade.sh --from-ref HEAD^`: passing
- `./infra/scripts/check-persistence-migration.sh --from-ref HEAD^`: passing
- `./infra/scripts/check-config-compatibility-matrix.sh`: passing
- `./infra/scripts/verify-release-package.sh`: passing
- `./infra/scripts/container-smoke-matrix.sh --php-versions 8.3`: passing
- `./infra/scripts/soak-runtime.sh asan|ubsan|leak --iterations 1`: passing
- `./infra/scripts/go-live-readiness.sh`: passing
- `./infra/scripts/build-profile.sh release|debug|asan|ubsan`: passing
- `./infra/scripts/smoke-profile.sh release|debug|asan|ubsan`: passing
- benchmark smoke and committed CI budget gate: passing

Current tree facts:

- `extension/src`: `177` C files
- `extension/include`: `172` headers
- `extension/tests`: `445` PHPT files
- public stub parity: `135` functions, `43` classes, `48` declared public methods
- `king_health()['stubbed_api_group_count']`: `0`
- project-owned headers now live under `extension/include` with generated `extension/config.h` as the only root-level exception
- static and runtime-surface audits now enforce that include-tree discipline

## What Is Verified And Real Today

The current tree already proves:

- explicit config and session ownership through `King\Config` and `King\Session`
- real HTTP/1, HTTP/2, and HTTP/3 client request paths, including reuse, repeated pooled HTTP/2 mixed-load bursts, streaming, bodiless-response handling, cumulative interim-response size-cap enforcement, bounded pending Early Hints storage, explicit HTTP/1 and HTTP/2 abort/reset failure mapping, explicit HTTP/3 handshake-failure and transport-close mapping, shared-ring HTTP/3 session-ticket reuse with direct and dispatcher recovery from stale ticket seeds, and cancel/timeout contracts
- HTTP/2 shared-session fairness under mixed slow and fast concurrent streams
- HTTP/3 one-shot churn isolation across repeated timeout and healthy-recovery cycles
- local server dispatch and listener slices for HTTP/1, HTTP/2, and HTTP/3, plus real one-shot on-wire listener proof for HTTP/1, HTTP/2, and HTTP/3, including bounded HTTP/1 one-shot accept and request-head timeout behavior against stalled clients
- on-wire WebSocket client handshake/frame/close runtime, stable network-abort mapping for peer disconnect, half-close, and abrupt socket-loss, stable `1002` protocol-close handling for malformed opcode/frame-shape/close-sequence and oversized control-frame peer violations, and honest OO `King\WebSocket\Connection` parity
- multi-client WebSocket close/reconnect churn on one server without cross-client starvation or corruption
- server-side `king_server_upgrade_to_websocket()` both as an honest local marker slice for local listeners and as a real on-wire HTTP/1 one-shot upgrade path with frame flow, handler ownership, and close/drain coverage
- repeated server/session soak coverage for local upgrade, early hints, admin API, TLS reload, and on-wire websocket close/drain cycles
- IIBIN schema, registry, encode/decode, object hydration, and wire validation
- Semantic DNS register/discover/update routing, live HTTP health-probe driven route and discovery refresh from service-owned health endpoints, idempotent repeated registration without redundant durable-state churn, coherent parallel service/status persistence through locked refresh-before-persist durable-state transactions, larger-topology local churn coherence, coherent concurrent larger-topology mother-node sync statistics across multiprocess writers in the local persisted-state slice, registry-backed mother-node sync statistics, persisted registration plus mother-node rehydration across restart, object-safe durable-state decode hardening, and private-directory durable state handling
- Smart-DNS public config and init surfaces are now narrowed to the active `service_discovery` / semantic-runtime knobs
- router/loadbalancer is now exposed as an explicit config-backed system component with honest policy/discovery-only introspection
- object-store local filesystem persistence plus real `distributed`, `cloud_s3`, `cloud_gcs`, and `cloud_azure` payload transport with explicit `local_fs+distributed+cloud_s3+cloud_gcs+cloud_azure_sidecars` runtime/system contract, `memory_cache -> local_fs` compatibility aliasing, backend-presence-marked `.meta` sidecars with honest multi-backend routing across shared local/cloud roots, explicit `cloud_s3` credential-rejection, endpoint-connect-failure, throttling detection, incomplete-write recovery through runtime adapter status/error surfaces plus rehydration, real `cloud_gcs` bearer-token based HTTP transport with verified primary read/write/list/delete behavior plus verified `local_fs` primary backup-copy writes and deletes through the same real GCS path, real `cloud_azure` bearer-token based HTTP transport with verified primary read/write/list/delete behavior plus verified `local_fs` primary backup-copy writes and deletes through the same real Azure Blob path, explicit `cloud_azure` credential-rejection, endpoint-connect-failure, and throttling detection through the same runtime adapter status/error surfaces, real filesystem-backed `distributed` primary read/write/list/delete behavior under `storage_root_path/.king-distributed/objects`, verified `local_fs` primary backup-copy writes and deletes through that same persisted distributed path, honest replication-status evaluation against actually achieved real copies so partial real-topology shortfalls fail instead of pretending `completed`, plus verified healing of that failed replication status after a later write meets the currently available real-backend topology, honest logical runtime-capacity enforcement across local and real cloud primaries through `max_storage_size_bytes` on committed primary-inventory bytes, visible runtime capacity mode/scope/headroom stats, restart-stable quota rehydration, upload-session capacity rejection before remote over-commit, and restart-safe persisted `begin/append/complete/abort/status` recovery for real `cloud_s3` multipart upload, real `cloud_gcs` resumable upload, and real `cloud_azure` block-list staging across `king_object_store_init()` re-entry and process/request restart, delete-safe local-primary read failover with local payload healing from real `cloud_s3` backup on payload miss, honest cross-backend delete semantics where direct `cloud_s3`, `cloud_gcs`, `cloud_azure`, and `distributed` primaries preserve objects on real delete failure, missing deletes stay `false`, and `local_fs` primary removes the corresponding real backup copy before completing the logical delete while refusing to pretend success when that remote delete fails, verified committed full-backup snapshots through staging-directory manifest swaps so repeated `backup_all` runs do not silently retain deleted objects, verified manifest-driven `restore_all` replay that restores only the committed snapshot inventory even if stray files exist in the directory, verified per-object mutation-lock-aware backup and restore semantics so export/import fail instead of racing an active write, verified export/restore migration of objects between the real `local_fs` and `cloud_s3` backends in both directions, verified byte-for-byte payload integrity and content-length preservation for binary objects across that migration path, verified migration-time metadata consistency for preserved semantic fields plus honest backend-presence markers across both directions, verified public object-store metadata parity across local and real cloud backends including `content_type`, `content_encoding`, backend presence markers, object/cache classification names, consistent list inventory snapshots, and a shared failure taxonomy where ordinary misses stay `false`, invalid ranges and overwrite preconditions become `King\ValidationException`, unavailable local-root backend faults become `King\SystemException`, and transport, credential, throttling, or local-root backend faults become `King\SystemException`, verified RFC-7233-style byte-range reads across local and real cloud backends, verified RFC-7232-style `if_match` / `if_none_match` plus explicit `expected_version` overwrite guards, verified bounded-memory `put_from_stream()` / `get_to_stream()` ingress and egress across local and real cloud backends with no payload-byte leak before full-read integrity validation succeeds, verified explicit chunk-size session semantics where `chunk_size_kb` now drives the bounded-memory stream copy size and the maximum sequential append size exported as `chunk_size_bytes` across real cloud upload sessions, verified per-object exclusive mutation locking across local and real cloud backends so conflicting writes, deletes, and upload-session starts fail instead of racing, verified atomic local payload and `.meta` sidecar commits so readers see the last committed object rather than torn overwrite bytes, verified upload-session lock ownership through abort/complete on the real cloud backends, verified lock-aware local payload rehydration from real cloud backup, verified consistent expiry visibility and cleanup semantics across local and real cloud backends through `expires_at`, `is_expired`, hidden ordinary reads/list entries, and cleanup summaries, verified full-read `integrity_sha256` enforcement on both local and real cloud payloads, and verified persisted `storage_root_path/.king-distributed/coordinator.state` recovery/state semantics alongside the now-real distributed data plane, while broader provider-quota classification still stays open
- deterministic QUIC bootstrap through a tracked pinset for the `quiche` repo revision, BoringSSL submodule revision, pinned workspace lockfile, and pinned `wirefilter` git revision with fail-closed static and PHPT verification
- one shared runtime install smoke across staged profiles, packaged release artifacts, and published runtime containers, plus first-class clean-host package install and container smoke matrix entrypoints
- long-duration ASan, UBSan, and leak-oriented soak gates with retained per-iteration logs and archived failure diagnostics under `extension/build/soak/` plus CI artifact upload on soak failure
- MCP request/upload/download parity against a real TCP host/port remote peer with propagated timeout, deadline, cancellation controls, stable OO exception mapping across transport, protocol, timeout, and local backend failures, IPv4 and IPv6 peer targeting coverage, 1 MiB payload roundtrips, canonical collision-free transfer identifiers across newline-shaped and binary-safe tuple components, parallel-transfer backpressure isolation, explicit single-flight reentry guards, rejection of unexpected remote `MISS` request responses without `NULL` payload crashes, same-host partial-failure recovery, persisted remote-state restart recovery coverage, restart-rehydratable local transfer-state fallback with consume-on-successful-download cleanup semantics, and explicit `topology_scope=tcp_host_port_peer` introspection
- orchestrator persistence, honest `queued -> running -> completed|failed|cancelled` run transitions, explicit `single_attempt` retry plus `caller_managed` idempotency contract, explicit `topology_scope=local_in_process|same_host_file_worker|tcp_host_port_execution_peer` introspection by backend mode, deterministic `claimed_recovery_then_fifo_run_id` scheduling, exclusive claimed-file locking across concurrent workers, nofollow and regular-file-only claimed-job opens with nonblocking special-file rejection, recovery of an already-running claimed run exactly once after worker loss, explicit `king_pipeline_orchestrator_resume_run()` continuation of persisted `running` runs after controller restart on the local and `remote_peer` backends, sustained queue fairness under repeated parallel-worker contention, local/file-worker backend boundaries, real TCP host/port `remote_peer` execution with persisted success/failure snapshots, rejection of remote network object payloads during result decode, cross-process cancellation, and multiprocess controller/observer/worker verification
- telemetry batch queueing, bounded pending span/log capture before flush, bounded retry behavior, explicit `best_effort_bounded_retry` plus `process_local_non_persistent` delivery semantics, process-scoped lazy libcurl lifetime without per-export global teardown, explicit `restart_replay=not_supported` and `drain_behavior=single_batch_per_flush` component contracts, OTLP metrics/traces/logs export hardening, and real local collector coverage for success plus non-2xx, timeout, response-size-limit, and outage recovery
- telemetry-driven Hetzner autoscaling with controller-owned credentials, persisted recovery state, `register -> ready -> drain -> delete` lifecycle gating, and stale pending-node rollback for failed bootstrap, registration, and readiness with explicit provider-delete failure reporting
- system integration lifecycle coordination, restart-state visibility, and chaos/recovery harness coverage for the local control plane

## What Is Still Not Finished

The repo is still short of a "nothing left to caveat" v1 in these areas:

### Remote Control Plane Depth

- MCP now propagates timeout, deadline, and cancellation controls through the real peer protocol, and host/port TCP peer targeting, stable OO exception mapping across transport, protocol, timeout, and local backend failures, large-payload behavior, parallel-transfer backpressure isolation, single-flight reentry safety, partial-failure recovery, persisted remote-state restart recovery, plus restart-rehydratable local transfer-state fallback are verified. The runtime now exposes that honest `tcp_host_port_peer` scope explicitly; broader distributed failure semantics and broader multi-host validation depth are still open.
- The orchestrator now has honest `local_in_process`, `same_host_file_worker`, and `tcp_host_port_execution_peer` backend scopes, plus verified success/failure execution over a real TCP host/port remote peer and explicit controller-side continuation after process restart. Remaining gaps are broader distributed multi-worker execution depth, continuation after host restart, observability depth, compensation semantics where publicly claimed, and a broader true multi-host harness.
- Retry and idempotency semantics are now explicit and test-backed for the current file-worker slice, including exact once-only recovery after worker loss during active execution and starvation-free queue progression under parallel-worker contention.

### Transport And Listener Failure Depth

- The current tree proves happy-path wire coverage and several sustained-load slices across HTTP/1, HTTP/2, HTTP/3, WebSocket, and local server/runtime control flows, but failure-depth is still uneven.
- These are no longer architectural unknowns; they are narrower remaining proof leaves on already-real kernels.

### Routing and DNS Scope

- Router/loadbalancer is now honestly fenced to a config-backed control-plane surface; it is not presented as a forwarding dataplane runtime.
- Smart-DNS public config and init surfaces are now honest for the current local semantic/service-discovery runtime.
- The remaining Smart-DNS work is split-brain and partial-failure recovery, real distributed topology validation beyond the local persisted-state slice, and broader failover behavior rather than more local config cleanup, restart-state basics, or local concurrent-write/mother-node churn correctness.

### Observability and Fleet Operations

- Metrics, traces, and logs now share the same bounded export path and are verified against real local collectors for success plus non-2xx, timeout, response-size-limit, and outage-recovery slices.
- Telemetry queueing and pre-flush pending signal capture are now bounded and their current v1 semantics are explicit: best-effort bounded retry, bounded pending span/log capture, process-local non-persistent queueing, one drain attempt per flush, and no restart replay guarantee. Longer-haul degraded exporter behavior, richer ordering/idempotency guarantees, and stronger diagnostics still need more proof.
- Autoscaling now rolls stale Hetzner pending nodes back safely even when telemetry is missing or degraded, and provider-side rollback failures are surfaced explicitly. Multi-node rolling restart, real-load decision depth, and broader fleet failover behavior are still open.

### Build, Compatibility, and Release Confidence

- QUIC and HTTP/3 now bootstrap from a pinned repo-owned dependency path instead of ad hoc local `quiche` resurrection or unlocked cargo retries.
- Clean-host package install and published-container smoke are first-class gates, and the repo-owned CI/workflow matrix now targets host/runtime PHP `8.1`/`8.2`/`8.3`/`8.4`/`8.5` instead of silently dropping older supported lines from those paths.
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
