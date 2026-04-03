# King Project Assessment

> Stand: 2026-04-03
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
surfaces inside the local tree. They are now concentrated in three narrower
areas:

- deeper CDN/cache/edge behavior under load, invalidation, restart, stale-serve, and observability across the real object-store backends
- broader Smart-DNS distributed-topology validation beyond the current on-wire listener proof, stale-peer rejoin healing after partial durable-state loss, tombstone-aware mother-node re-election churn proof, local query failure/recovery, concurrent-write, live-signal, and split-brain/partial-failure proof
- stronger telemetry exporter ordering/diagnostics and deeper autoscaling multi-node fleet behavior

The active explicitly requested `20`-issue repo-local batch in `ISSUES.md` is
in flight and is currently working through the CDN/object-store and
distributed-topology follow-up items.

## Verified Baseline Snapshot

The currently verified baseline is:

- `./infra/scripts/static-checks.sh`: passing
- `./infra/scripts/check-include-layout.sh`: passing
- `./infra/scripts/audit-runtime-surface.sh`: passing
- `./infra/scripts/build-extension.sh`: passing
- `./infra/scripts/test-extension.sh`: `532/532` passing
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
- `extension/tests`: `532` PHPT files
- public stub parity: `137` functions, `44` classes, `56` declared public methods
- `king_health()['stubbed_api_group_count']`: `0`
- project-owned headers now live under `extension/include` with generated `extension/config.h` as the only root-level exception
- static and runtime-surface audits now enforce that include-tree discipline

## What Is Verified And Real Today

The current tree already proves:

- explicit config and session ownership through `King\Config` and `King\Session`
- real HTTP/1, HTTP/2, and HTTP/3 client request paths, including HTTP/1 keep-alive reuse with verified one-idle-per-origin and sixteen-idle-global pool limits under mixed-load bursts plus honest reopen after peer `Connection: close`, repeated pooled HTTP/2 mixed-load bursts, streaming, bodiless-response handling, cumulative interim-response size-cap enforcement, bounded pending Early Hints storage, explicit mixed-case and repeated HTTP/1 response-header normalization across direct, dispatcher, and OO client surfaces under real traffic, explicit HTTP/1 and HTTP/2 abort/reset failure mapping, explicit HTTP/3 handshake-failure and transport-close mapping, verified HTTP/3 in-flight timeout behavior against real slow-writer and flow-control-stalled slow-reader peers, shared-ring HTTP/3 session-ticket reuse with direct and dispatcher recovery from stale ticket seeds, explicit QUIC session lifecycle proof across direct and dispatcher HTTP/3 one-shot requests with peer-observed Initial, established/open, request-stream open/body/finish plus request-body drain-before-response, response-drain, draining, and closed phases, explicit QUIC idle-timeout and application-close propagation against real peers on those same one-shot request paths, explicit QUIC request-stream reset and peer stop-sending propagation against real peers on those same one-shot request paths, explicit userland CancelToken propagation into active QUIC application-close state against real peers on those same request paths, explicit QUIC/TLS lifecycle proof across fresh handshakes, resumed shared-ticket sessions, and repeated same-port live-listener churn instead of leaving that broader interaction model implicit, peer-observed resumed-session HTTP/3 zero-RTT proof across accepted early-data request/response phases plus server-disabled fallback replay after establishment instead of collapsing all resumed traffic into the ordinary post-handshake request path, explicit HTTP/3 response-stat proof that `quic_packets_sent`, `quic_packets_received`, `quic_packets_lost`, `quic_packets_retransmitted`, `quic_lost_bytes`, and `quic_stream_retransmitted_bytes` stay tied to live runtime counters and peer-observed request/response state instead of drifting into stale bookkeeping fields, explicit temporary established-phase network-blackout recovery where the active HTTP/3 runtime stalls through a real peer-observed interruption, re-wakes on retransmitted progress, and still completes the same request stream with visible QUIC loss and retransmit counters instead of collapsing into timeout or silent retry folklore, injected packet-loss recovery with visible QUIC loss and retransmit counters plus successful multi-request follow-up behavior on the active HTTP/3 runtime, verified buffered `king_http3_request_send_multi()` batches over one QUIC connection with mixed fast and slow concurrent stream progress plus sustained staggered fast-wave fairness while a long slow sibling stream remains active, repeated mixed-load burst soak behavior without poisoned later healthy sessions, and cancel/timeout contracts
- public OO `King\Client\Http3Client` exception mapping across QUIC/TLS handshake failure, peer QUIC `transport_close`, peer protocol `application_close`, QUIC connect timeout, and active `CancelToken` aborts with stable `King\TlsException`, `King\QuicException`, `King\ProtocolException`, `King\TimeoutException`, and `King\RuntimeException` surfaces
- explicit QUIC event-loop wake, idle, and timeout proof against real delayed and silent peers under sustained runtime instead of leaving quiche poll-loop behavior implicit
- explicit QUIC congestion-control survival proof for both supported `cubic` and `bbr` algorithms under sustained lossy constrained links with visible loss and retransmit counters instead of only config-snapshot coverage
- explicit QUIC flow-control exhaustion and recovery proof on sustained HTTP/3 request streams where a real peer advertises a tiny receive window, stalls after exhaustion, later resumes reads, and still completes the response instead of collapsing into a timeout-only contract
- HTTP/2 shared-session fairness under mixed slow and fast concurrent streams
- HTTP/3 one-shot churn isolation across repeated timeout and healthy-recovery cycles
- local server dispatch and listener slices for HTTP/1, HTTP/2, and HTTP/3, plus real one-shot on-wire listener proof for HTTP/1, HTTP/2, and HTTP/3, including bounded HTTP/1 one-shot accept and request-head timeout behavior against stalled clients, stable normalized `uri` and routing `path` materialization from real request targets across the active one-shot listener surfaces, stable normalized live request-header materialization plus allowlisted CORS/preflight response behavior across real HTTP/1, HTTP/2, and HTTP/3 clients on those same one-shot listener surfaces, stable client-visible response normalization for runtime-owned transport headers plus repeated response fields across the active one-shot listener surfaces, real HTTP/1 one-shot `103 Early Hints` emission before the final response plus client-observed pending-hint capture from that same on-wire listener path, real registered cancel-hook invocation on live client aborts across HTTP/1, HTTP/2, and HTTP/3 one-shot traffic, dedicated live HTTP/3 request proof that server-side TLS reload updates the active session snapshot without breaking the response path, repeated close/drain/restart reuse of the same bound ports across HTTP/1, HTTP/2, and HTTP/3 listener cycles, and bounded live admin API proof for auth, mTLS gating, TLS reload, and handled reload failures against real clients instead of only local listener markers
- on-wire WebSocket client handshake/frame/close runtime, stable network-abort mapping for peer disconnect, half-close, and abrupt socket-loss, stable `1002` protocol-close handling for malformed opcode/frame-shape/close-sequence and oversized control-frame peer violations, bounded queued receive pressure via configurable `max_queued_messages` / `max_queued_bytes` with introspectable queue state and policy-close on overflow, honest OO `King\WebSocket\Connection` parity, and a bounded OO `King\WebSocket\Server` surface that binds a real HTTP/1 listener, accepts real websocket upgrades, returns accepted peers as live `Connection` objects, exposes a live accepted-connection registry keyed by opaque `connection_id`, supports targeted and broadcast text/binary sends across those live peers, keeps repeated multi-peer targeted and broadcast scheduling stable under load on one live server object, preserves fair peer progress when a competing accepted connection holds a deeper queued backlog, prunes crash- or abort-killed peers from the live registry on the next fanout instead of poisoning the surviving peers, and turns `stop()` into a real `1001 server-shutdown` close-handshake drain instead of a fake listener-only placeholder
- multi-client WebSocket close/reconnect churn on one server without cross-client starvation or corruption, concurrent slow-consumer backpressure isolation without poisoning unrelated peers, and sustained fairness where one noisy client can hold a deep backlog without starving other concurrent websocket clients
- server-side `king_server_upgrade_to_websocket()` both as an honest local in-process bidirectional frame slice for HTTP/1, HTTP/2, and HTTP/3 local listeners, with dedicated local honesty proof for each of those listener families, explicit request-boundary and same-process worker-reuse cleanup of retained upgrade resources, and as a real on-wire HTTP/1 one-shot upgrade path with frame flow, explicit shutdown/drain coverage, rejection of post-close frame I/O, and clean post-close release of the server-owned socket from the session snapshot
- repeated server/session soak coverage for local upgrade, early hints, admin API, TLS reload, and on-wire websocket close/drain cycles
- IIBIN schema, registry, encode/decode, object hydration, and wire validation
- Semantic DNS register/discover/update routing, bounded local DNS-shaped query handling with fail-closed undersized-response rejection, a real bounded on-wire UDP listener with honest request, timeout, truncation, and recovery behavior, live HTTP health-probe driven route and discovery refresh from service-owned health endpoints, idempotent repeated registration without redundant durable-state churn, coherent parallel service/status persistence through locked refresh-before-persist durable-state transactions, larger-topology local churn coherence, coherent concurrent larger-topology mother-node sync statistics across multiprocess writers in the local persisted-state slice, stale-peer rejoin healing after partial durable-state loss without overwriting newer shared topology entries, tombstone-aware mother-node departure/replacement/rejoin handling under re-election pressure, registry-backed mother-node sync statistics, persisted registration plus mother-node rehydration across restart, object-safe durable-state decode hardening, and private-directory durable state handling
- Smart-DNS public config, lifecycle, active UDP listener, and bounded local query helper now expose the active `service_discovery` / semantic-runtime slice honestly
- router/loadbalancer is now exposed as an explicit config-backed system component with honest policy/discovery-only introspection
- object-store local filesystem persistence plus real `distributed`, `cloud_s3`, `cloud_gcs`, and `cloud_azure` payload transport with explicit `local_fs+distributed+cloud_s3+cloud_gcs+cloud_azure_sidecars` runtime/system contract, `memory_cache -> local_fs` compatibility aliasing, backend-presence-marked `.meta` sidecars with honest multi-backend routing across shared local/cloud roots, explicit `cloud_s3` credential-rejection, endpoint-connect-failure, throttling detection, provider-reported quota-exhaustion detection, and incomplete-write recovery through runtime adapter status/error surfaces plus rehydration, real `cloud_gcs` bearer-token based HTTP transport with verified primary read/write/list/delete behavior plus verified provider-reported quota-exhaustion detection and verified `local_fs` primary backup-copy writes and deletes through the same real GCS path, real `cloud_azure` bearer-token based HTTP transport with verified primary read/write/list/delete behavior plus verified `local_fs` primary backup-copy writes and deletes through the same real Azure Blob path, explicit `cloud_azure` credential-rejection, endpoint-connect-failure, throttling detection, and provider-reported quota-exhaustion detection through the same runtime adapter status/error surfaces, real filesystem-backed `distributed` primary read/write/list/delete behavior under `storage_root_path/.king-distributed/objects`, verified `local_fs` primary backup-copy writes and deletes through that same persisted distributed path, honest replication-status evaluation against actually achieved real copies so partial real-topology shortfalls fail instead of pretending `completed`, plus verified healing of that failed replication status after a later write meets the currently available real-backend topology, honest logical runtime-capacity enforcement across local and real cloud primaries through `max_storage_size_bytes` on committed primary-inventory bytes, visible runtime capacity mode/scope/headroom stats, restart-stable quota rehydration, upload-session capacity rejection before remote over-commit, and restart-safe persisted `begin/append/complete/abort/status` recovery for real `cloud_s3` multipart upload, real `cloud_gcs` resumable upload, and real `cloud_azure` block-list staging across `king_object_store_init()` re-entry and process/request restart, delete-safe local-primary read failover with local payload healing from real `cloud_s3` backup on payload miss, honest cross-backend delete semantics where direct `cloud_s3`, `cloud_gcs`, `cloud_azure`, and `distributed` primaries preserve objects on real delete failure, missing deletes stay `false`, and `local_fs` primary removes the corresponding real backup copy before completing the logical delete while refusing to pretend success when that remote delete fails, verified committed full-backup snapshots through staging-directory manifest swaps so repeated `backup_all` runs do not silently retain deleted objects, verified explicit incremental `backup_all` snapshots against a committed base snapshot with manifest-driven upsert/delete deltas and effective inventory fingerprints, verified fail-closed `restore_all` preflight on manifest and legacy archives so partially corrupted payload/metadata sets do not partially mutate the live store, verified restore/import-time payload and metadata revalidation so direct imports, direct restore aliases, and batch snapshot replay reject corrupted or metadata-tampered archives before they become live across `local_fs`, `cloud_s3`, `cloud_gcs`, and `cloud_azure`, verified manifest-driven `restore_all` replay that restores only the committed snapshot inventory even if stray files exist in the directory, verified patch-style incremental `restore_all` replay on top of a previously restored base snapshot, verified explicit restore-surface shape where `restore_object()` is the only public partial-restore API and `restore_all()` remains committed full-snapshot or incremental-patch replay without rolling/subset batch options, verified quiescent-runtime `restore_all` semantics so active writes or resumable uploads block batch restore and in-flight batch restore blocks new mutations until replay completes, verified per-object mutation-lock-aware backup and restore semantics so export/import fail instead of racing an active write, verified restart rehydration after export/restore and full-snapshot replay across the active persisted-state modes `local_fs`, `memory_cache`, `distributed`, `cloud_s3`, `cloud_gcs`, and `cloud_azure`, verified export/restore migration of objects between the real `local_fs` and `cloud_s3` backends in both directions, verified byte-for-byte payload integrity and content-length preservation for binary objects across that migration path, verified migration-time metadata consistency for preserved semantic fields plus honest backend-presence markers across both directions, plus verified committed `restore_all` metadata migration from real `local_fs` snapshots onto real `cloud_s3`, `cloud_gcs`, and `cloud_azure` targets with preserved durable semantic fields and honest target-presence markers under the shared-root route-change contract, verified public object-store metadata parity across local and real cloud backends including `content_type`, `content_encoding`, backend presence markers, object/cache classification names, consistent list inventory snapshots, and a shared failure taxonomy where ordinary misses stay `false`, invalid ranges and overwrite preconditions become `King\ValidationException`, unavailable local-root backend faults become `King\SystemException`, and transport, credential, throttling, provider-reported quota exhaustion, or local-root backend faults become `King\SystemException`, verified stable public quota and throttling prefixes across CRUD and upload-session leaves with preserved provider detail and HTTP status across `cloud_s3`, `cloud_gcs`, and `cloud_azure`, verified the same normalized quota and throttling contract after upload-session restart rehydration across recovered `append`, `complete`, and `abort` paths, verified RFC-7233-style byte-range reads across local and real cloud backends, verified RFC-7232-style `if_match` / `if_none_match` plus explicit `expected_version` overwrite guards, verified bounded-memory `put_from_stream()` / `get_to_stream()` ingress and egress across local and real cloud backends with no payload-byte leak before full-read integrity validation succeeds, verified explicit chunk-size session semantics where `chunk_size_kb` now drives the bounded-memory stream copy size and the maximum sequential append size exported as `chunk_size_bytes` across real cloud upload sessions, verified per-object exclusive mutation locking across local and real cloud backends so conflicting writes, deletes, and upload-session starts fail instead of racing, verified atomic local payload and `.meta` sidecar commits so readers see the last committed object rather than torn overwrite bytes, verified upload-session lock ownership through abort/complete on the real cloud backends, verified lock-aware local payload rehydration from real cloud backup, verified consistent expiry visibility and cleanup semantics across local and real cloud backends through `expires_at`, `is_expired`, hidden ordinary reads/list entries, and cleanup summaries, verified full-read `integrity_sha256` enforcement on both local and real cloud payloads, and verified persisted `storage_root_path/.king-distributed/coordinator.state` recovery/state semantics alongside the now-real distributed data plane
- CDN cache warmup now stays tied to the active object-store backend truth: `king_cdn_cache_object()` is verified across `local_fs`, `distributed`, `cloud_s3`, `cloud_gcs`, and `cloud_azure`, local and distributed primaries size cache entries through committed metadata sidecars, cloud primaries can rehydrate the same size path through provider `HEAD` when the local sidecar is absent, and those warm paths do not pull the full payload body just to populate CDN state
- full-object `king_object_store_get()` now provides the current honest CDN fill-on-miss path for `smart_cdn` objects across `local_fs`, `distributed`, `cloud_s3`, `cloud_gcs`, and `cloud_azure`: a successful origin/backend read backfills the runtime CDN registry and marks the object served instead of leaving cache-miss fills as config-only folklore
- deterministic QUIC bootstrap through a tracked pinset for the `quiche` repo revision, BoringSSL submodule revision, pinned workspace lockfile, and pinned `wirefilter` git revision with fail-closed static and PHPT verification
- one shared runtime install smoke across staged profiles, packaged release artifacts, and published runtime containers, plus first-class clean-host package install and container smoke matrix entrypoints
- long-duration ASan, UBSan, and leak-oriented soak gates with retained per-iteration logs and archived failure diagnostics under `extension/build/soak/` plus CI artifact upload on soak failure
- MCP request/upload/download parity against a real TCP host/port remote peer with propagated timeout, deadline, cancellation controls, stable OO exception mapping across transport, protocol, timeout, and local backend failures, IPv4 and IPv6 peer targeting coverage, 1 MiB payload roundtrips, canonical collision-free transfer identifiers across newline-shaped and binary-safe tuple components, parallel-transfer backpressure isolation, explicit single-flight reentry guards, rejection of unexpected remote `MISS` request responses without `NULL` payload crashes, same-host partial-failure recovery, persisted remote-state restart recovery coverage, restart-rehydratable local transfer-state fallback with consume-on-successful-download cleanup semantics, explicit `topology_scope=tcp_host_port_peer` introspection, namespaced non-loopback multi-host request/upload/download verification against a host-bound peer, a shared repo-wide multi-host namespace harness that proves a namespaced client can reach a host-bound server over a non-loopback path with cross-host-style peer identity capture, and a reusable named-peer failover harness that proves peer loss, sibling-peer survival, and same-host host/port rejoin with persisted remote-state recovery
- orchestrator persistence, honest `queued -> running -> completed|failed|cancelled` run transitions, explicit `single_attempt` retry plus `caller_managed` idempotency and compensation contracts, explicit reverse-completed-step compensation snapshots for failed or cancelled multi-step runs, explicit per-step error classification in persisted run snapshots so `validation`, `timeout`, `backend`, `remote_transport`, and `cancelled` outcomes remain distinguishable with step-local versus run-level scope, explicit `topology_scope=local_in_process|same_host_file_worker|tcp_host_port_execution_peer` introspection by backend mode, durable per-run distributed observability for queue phase, enqueue time, claim count, claimed PID, recovery count, recovery reason, and remote-attempt timing across persisted run snapshots plus component-level claimed/recovered/remote-attempted counters, deterministic `claimed_recovery_then_fifo_run_id` scheduling, exclusive claimed-file locking across concurrent workers, nofollow and regular-file-only claimed-job opens with nonblocking special-file rejection, recovery of an already-running claimed run exactly once after worker loss, explicit `king_pipeline_orchestrator_resume_run()` continuation of persisted `running` runs after controller restart on the local and `remote_peer` backends, verified continuation after full controller plus remote-peer host loss once the peer returns on the persisted host/port contract, a reusable failover harness that proves controller loss, file-worker loss, and remote-peer return through one repeatable controller/observer/worker process harness, sustained queue fairness under repeated parallel-worker contention, local/file-worker backend boundaries, real TCP host/port `remote_peer` execution with persisted success/failure snapshots, verified multi-worker step claiming and result handling behind one remote execution peer, rejection of remote network object payloads during result decode, cross-process cancellation, and multiprocess controller/observer/worker verification
- telemetry batch queueing, bounded pending span/log capture before flush, bounded retry behavior, explicit `best_effort_bounded_retry` plus `process_local_non_persistent` delivery semantics, process-scoped lazy libcurl lifetime without per-export global teardown, request- and worker-boundary cleanup that drops stale active-span plus pre-flush span/log residue before the next work unit, explicit `restart_replay=not_supported` and `drain_behavior=single_batch_per_flush` component contracts, OTLP metrics/traces/logs export hardening, and real local collector coverage for success plus non-2xx, timeout, response-size-limit, and outage recovery
- telemetry-driven Hetzner autoscaling with controller-owned credentials, persisted recovery state, `register -> ready -> drain -> delete` lifecycle gating, stale pending-node rollback for failed bootstrap, registration, and readiness with explicit provider-delete failure reporting, partial-state inventory reconcile against live provider nodes, preserved rollout bootstrap propagation for freshly provisioned nodes after recovery, and real-load decision snapshots that explain hold, scale-up, cooldown-blocked, and scale-down outcomes through structured live-signal, pressure, and blocker context
- system integration lifecycle coordination, restart-state visibility, and chaos/recovery harness coverage for the local control plane

## What Is Still Not Finished

The repo is still short of a "nothing left to caveat" v1 in these areas:

### Remote Control Plane Depth

- MCP now propagates timeout, deadline, and cancellation controls through the real peer protocol, and host/port TCP peer targeting, stable OO exception mapping across transport, protocol, timeout, and local backend failures, large-payload behavior, parallel-transfer backpressure isolation, single-flight reentry safety, partial-failure recovery, persisted remote-state restart recovery, restart-rehydratable local transfer-state fallback, and a repeatable named-peer failover harness for peer loss/rejoin plus partial-topology breakage are verified. The runtime now exposes that honest `tcp_host_port_peer` scope explicitly; broader distributed failure semantics beyond that named-peer failover harness are still open.
- The orchestrator now has honest `local_in_process`, `same_host_file_worker`, and `tcp_host_port_execution_peer` backend scopes, durable run-level distributed observability for queue, claim, recovery, and remote-attempt history, explicit per-step backend/topology attribution, explicit caller-managed compensation snapshots with reverse-completed-step ordering for failed or cancelled multi-step runs, verified success/failure execution over a real TCP host/port remote peer, verified multi-worker step distribution behind that remote-peer boundary, explicit controller-side continuation after process restart and full host loss/rejoin on the persisted remote-peer route, and one reusable failover harness that exercises controller loss, worker loss, and remote-peer return through the active persisted-state contract.
- Retry and idempotency semantics are now explicit and test-backed for the current file-worker slice, including exact once-only recovery after worker loss during active execution and starvation-free queue progression under parallel-worker contention.

### Transport And Listener Failure Depth

- The current tree proves happy-path wire coverage and several sustained-load slices across HTTP/1, HTTP/2, HTTP/3, WebSocket, and local server/runtime control flows, but failure-depth is still uneven.
- These are no longer architectural unknowns; they are narrower remaining proof leaves on already-real kernels.

### Routing and DNS Scope

- Router/loadbalancer is now honestly fenced to a config-backed control-plane surface; it is not presented as a forwarding dataplane runtime.
- Smart-DNS public config, init, active UDP listener, and bounded local query surfaces are now honest for the current semantic/service-discovery runtime.
- The remaining Smart-DNS work is real distributed topology validation beyond the current on-wire listener, restart-safe persisted-state slice, stale-peer partial-loss healing proof, and mother-node re-election churn proof rather than more local config cleanup, bounded local failure/recovery basics, restart-state basics, or local concurrent-write correctness.

### Observability and Fleet Operations

- Metrics, traces, and logs now share the same bounded export path and are verified against real local collectors for success plus non-2xx, timeout, response-size-limit, and outage-recovery slices.
- Telemetry queueing and pre-flush pending signal capture are now bounded and their current v1 semantics are explicit: best-effort bounded retry, bounded pending span/log capture, queue-size-derived byte budgets, process-local non-persistent queueing, request/worker-boundary stale-state cleanup, live self-metrics for queue/memory growth plus retry pressure, one drain attempt per flush, and no restart replay guarantee. Longer-haul degraded exporter behavior, richer ordering/idempotency guarantees, and stronger diagnostics still need more proof.
- Autoscaling now rolls stale Hetzner pending nodes back safely even when telemetry is missing or degraded, provider-side rollback failures are surfaced explicitly, real load-shape decisions are now explained through structured monitor snapshots instead of only isolated single-signal slices, and partial persisted fleet-state loss can now be repaired against live Hetzner inventory without dropping fresh rollout bootstrap intent. Multi-node rolling restart and broader fleet failover behavior are still open.

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
