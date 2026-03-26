# King Issues

> This document is the active repo-local execution queue.
> It tracks what is still open in this repository after the current verified
> baseline in `PROJECT_ASSESSMENT.md`.
> `README.md` stays stable. `EPIC.md` stays strategic. This file is allowed to move.
> v1 here means the final repo-local release line, not a beta placeholder.

## Working Rules

- only mark a leaf done when code, tests, and repo-local docs agree
- prefer small verifiable leaves over broad vague work items
- do not claim capability because archived code exists under `extension/src_bak/`
- keep procedural and OO surfaces tied to the same native kernels
- update `PROJECT_ASSESSMENT.md` when the verified reach materially changes

## Current Next Leaf

- [ ] Replace local-only WebSocket handshake/runtime assumptions with on-wire client and server verification
  why this blocks `8/10`: the local control plane and recovery paths are now chaos-tested, but the realtime/server story still leans on local-only assumptions instead of exercised wire behavior.
  done when: dedicated on-wire harnesses verify handshake, frame flow, close semantics, and server-side upgrade behavior over real sockets rather than only local runtime shims.

## Active Fronts

### 1. Real external backends

- [x] Keep a generic autoscaling provider contract with Hetzner as the first and only honest backend implementation
- [x] Add controller-only Hetzner credentials and config loading from `php.ini`, without replicating the cloud API token onto scaled nodes
- [x] Serialize Hetzner network, labels, placement-group, firewall, and bootstrap metadata into honest create-server payloads
- [x] Persist Hetzner scale actions, instance identity, and recovery state so controller restarts do not orphan live nodes
- [x] Document clearly that non-Hetzner providers may exist behind the same interface but are currently simulated; "production-honest in-tree today means Hetzner only"
- [x] Complete Hetzner node admission and retirement with register, readiness, and drain instead of treating provider success as immediate service readiness
- [x] Verify end-to-end release/bootstrap rollout onto freshly provisioned Hetzner nodes
  completed: 2026-03-25
- [x] Harden MCP transfer identifiers to prevent object-store path traversal
  completed: 2026-03-25
- [x] Replace simulated object-store cloud adapters with explicit backend contracts and stable failure semantics
  completed: 2026-03-25
- [x] Add backup/restore and import/export paths for object-store payloads plus `.meta` state
  completed: 2026-03-25
- [x] Constrain object-store backup/restore directories to the active storage root and reject external import/export paths
  completed: 2026-03-26
- [x] Add crash-recovery and restart rehydration verification for persisted backends
  completed: 2026-03-25

### 2. Distributed MCP and orchestrator depth

- [x] Move orchestrator execution beyond the purely local runtime path and define a real worker/backend boundary
  completed: 2026-03-26
- [x] Persist tool-registry and pipeline-run state across restart and recovery
  completed: 2026-03-26
- [x] Enforce timeout/deadline/cancel budgets across MCP request/upload/download helpers
  completed: 2026-03-26
- [x] Enforce timeout/deadline/max_concurrency controls across orchestrator run/dispatch and worker-side recovery, with honest rejection of unsupported live `CancelToken` propagation on `file_worker`
  completed: 2026-03-26
- [x] Add a real cross-process cancellation channel for claimed file-worker orchestrator runs
  completed: 2026-03-26
- [x] Add a multi-process end-to-end harness for remote MCP/orchestrator topology instead of single-process local-only verification
  completed: 2026-03-26
- [x] Harden orchestrator cancel-option ownership so persisted option sanitizing cannot dangle CancelToken references or mutate caller arrays
  completed: 2026-03-26
- [x] Harden file-worker queue persistence so worker queue paths require a private real directory and queued/cancel files cannot follow symlinks
  completed: 2026-03-26
- [x] Harden orchestrator state snapshot persistence so `orchestrator_state_path` stays system-owned and state load/save refuses symlinked paths
  completed: 2026-03-26

### 3. Observability, autoscaling, and lifecycle operations

- [x] Add a real telemetry export queue and exporter semantics instead of local-only flush counters
  completed: 2026-03-25
- [x] Harden telemetry export retry queue semantics so repeated export failures cannot create cyclic batches or shutdown-time memory corruption
  completed: 2026-03-26
- [x] Drive autoscaling decisions from live telemetry/system metrics with hysteresis, cooldown, saturation coverage, and Hetzner-specific scale-step guards
- [x] Add operator-facing spend and quota warnings for the Hetzner path; do not make provider spend APIs the sole hard-stop mechanism
  completed: 2026-03-25
- [x] Add rolling restart, drain, and readiness transitions to system integration instead of immediate local lifecycle flips
  completed: 2026-03-25
- [x] Add failover/chaos harnesses for telemetry, autoscaling, and coordinated system recovery
  completed: 2026-03-26

### 4. Realtime and server runtime depth

- [ ] Replace local-only WebSocket handshake/runtime assumptions with on-wire client and server verification
- [x] Give `King\WebSocket\Server` an honest public runtime surface or retire it from the exported API
  completed: 2026-03-26
- [ ] Add long-lived server/session soak coverage for upgrade, early-hints, TLS reload, admin API, and close/drain flows
- [ ] Verify multi-connection backpressure and fairness semantics under HTTP/2, HTTP/3, and WebSocket churn

### 5. Performance, compatibility, and release confidence

- [x] Put benchmark baselines under CI with explicit per-case regression budgets
  completed: 2026-03-26
- [x] Harden multi-architecture build-profile bootstrap for missing `quiche` and `libcurl` header layouts
  completed: 2026-03-25
- [x] Drop ARMv7 from the Docker publish matrix until Quiche wirefilter bootstrap is stable there
  completed: 2026-03-25
  note: `.github/workflows/docker.yml` now builds php-runtime images for `linux/amd64` and `linux/arm64` across all active PHP versions.
- [ ] Turn the QUIC backend bootstrap into a deterministic pinned dependency path instead of relying on a locally resurrected external `quiche/` checkout
- [ ] Add package install/smoke matrix coverage for clean hosts, published container images, and supported PHP/API combinations
- [ ] Verify upgrade/downgrade compatibility for release artifacts and persisted object-store metadata/state
- [ ] Add long-duration ASan/UBSan soak gates with archived diagnostics on failure

## Verified Baseline Already Closed

- [x] Canonical build, audit, test, fuzz, package, package-verify, and go-live-readiness gates
  build: `pass`
  audit: `pass`
  tests: `304/304`
  static-checks: `pass`
  profiles: `release/debug/asan/ubsan pass`
  fuzz: `pass`
  stub-parity: `pass`
  release-smoke: `pass`
  benchmark-smoke: `pass`
  package: `pass`
  package-verify: `pass`
  go-live-readiness: `pass`
  stubbed-api-groups: `0`
- [x] Autoscaling provider contract now has an honest Hetzner backend and verified controller recovery
  targeted PHPTs: `012`, `280`, `295`, `296`, `297`
  coverage: real HTTP provider calls against a local mock Hetzner API, controller-only token path, explicit `register -> ready -> drain -> delete` lifecycle gating, persisted state reload, and readonly worker-mode behavior without a cloud token
- [x] Autoscaling controller decisions now consume live telemetry/system signals with cooldown, hysteresis, and Hetzner step guards
  targeted PHPTs: `012`, `013`, `280`, `298`, `299`
  coverage: live telemetry-backed CPU/queue/RPS/latency signals, system-memory fallback, cooldown enforcement across same-second ticks, capped scale-up policy resolution, pending-node guards on the Hetzner path, and drain-before-delete automatic scale-down behavior
- [x] Docker runtime and demo image build paths now point at real repo assets and build locally
  workflow: `.github/workflows/docker.yml`
  runtime image: `infra/php-runtime.Dockerfile`
  demo image: `infra/demo-server/Dockerfile`
  local verification: `docker build --build-arg PHP_VERSION=8.3 -f infra/php-runtime.Dockerfile .` and `docker build -f infra/demo-server/Dockerfile .`
  coverage: the GHCR workflow no longer references nonexistent `infra/php8.x/` paths, the runtime image bootstraps required build dependencies including `quiche` and `uuid`, and the demo image now has a complete Vite entry surface that builds into a runnable nginx-served artifact
- [x] Object-store backup/restore and import/export now stay confined to the active storage root
  targeted PHPTs: `302`, `305`, `313`
  coverage: export/import reject directories outside `storage_root_path`, positive in-root bundle backup/restore remains green, and batch backup/restore now tolerates in-root bundle directories without treating them as broken object entries.
- [x] CI transport bootstrap is stabilized for curl/wirefilter failures in fresh hosts
  coverage: `./scripts/build-profile.sh` now validates curl headers for system builds, normalizes the qlog-dancer `wirefilter` dependency pin to a resolvable branch fallback, and avoids stale cargo git cache fragments before cargo metadata/build.
- [x] Orchestrator registry and pipeline-run snapshots now survive restart and recovery
  targeted PHPTs: `250`, `294`, `307`, `308`
  coverage: persistent tool registry, logging snapshot persistence, completed-run history recovery, running-snapshot rehydration, restart-safe `king_system_get_component_info('pipeline_orchestrator')`, and continued pipeline execution after a recovered warm start.
- [x] Orchestrator execution now crosses a real file-worker backend boundary
  targeted PHPTs: `250`, `307`, `308`, `309`
  coverage: config-selectable `local` versus `file_worker` execution backend, persisted run dispatch, cross-process queue claim and execution, worker-side run snapshot readback, local-path refusal when the file-worker backend is active, and live `queued_run_count` introspection in component info.
- [x] MCP and orchestrator runtime controls now enforce deadline/timeout ceilings and bounded local concurrency
  targeted PHPTs: `157`, `234`, `235`, `236`, `309`, `310`, `311`
  coverage: MCP request/upload/download `timeout_ms`/`deadline_ms`/`cancel` handling, OO and procedural parity, orchestrator `timeout_ms`/`overall_timeout_ms`/`deadline_ms`/`max_concurrency`, persisted worker-side control recovery, and explicit refusal to pretend that live `CancelToken` propagation already works across the `file_worker` boundary.
- [x] MCP upload helpers now prove repeated stream-upload teardown does not accumulate per-call payload ownership
  targeted PHPTs: `234`, `235`, `317`
  coverage: procedural and OO MCP upload paths both release caller-owned payload buffers after persistence, repeated connection teardown stays bounded under a low `memory_limit`, and the transfer registry no longer duplicates persisted transfer keys as zval string payloads.
- [x] HTTP/1 chunked parsing now rejects oversized chunk-size lines before CRLF accounting can overflow
  targeted PHPTs: `158`, `159`, `169`, `318`
  coverage: direct HTTP/1 requests, dispatcher requests, and response_stream mode now reject SIZE_MAX-style chunk-size lines as oversized during parse, so chunk payload bounds checks and terminator indexing never execute on wrapped `chunk_size + 2` arithmetic.
- [x] File-worker orchestrator runs now honor persisted cross-process cancellation after claim and during stale-claim recovery
  targeted PHPTs: `309`, `311`, `314`
  coverage: controller-side `king_pipeline_orchestrator_cancel_run()` requests persist into claimed runs, workers convert live and recovered claimed jobs into durable `cancelled` snapshots instead of fatal-only exits, and stale `claimed-*.job` recovery now stays cancellable across restart-safe queue handoff.
- [x] Orchestrator exec controls now own CancelToken lifetime across persisted-option sanitizing
  targeted PHPTs: `311`, `322`
  coverage: local orchestrator runs now copy `options['cancel']` into owned exec-control state before sanitizing persisted options, the sanitized snapshot separates its array storage before deleting `cancel`, caller option arrays stay unchanged, and the same `CancelToken` remains usable for a later run instead of becoming a dangling pointer.
- [x] File-worker queue persistence now rejects unsafe queue directories and symlinked job/cancel targets
  targeted PHPTs: `309`, `311`, `314`, `315`, `323`
  coverage: file-worker queues now require a real non-group/world-writable directory, queued job creation uses exclusive no-follow opens, cancel markers use no-follow writes, worker claim scans ignore non-regular queue entries, and a preplanted `queued-run-1.job` symlink no longer overwrites its target.
- [x] Orchestrator state snapshots now stay on system-owned paths and use symlink-safe load/save handling
  targeted PHPTs: `307`, `308`, `324`
  coverage: `orchestrator.state_path` is no longer accepted through `King\Config` userland overrides, persisted snapshots now use private `mkstemp` staging instead of predictable temp names, state load refuses symlinked paths, and a symlinked state target no longer gets overwritten during tool-registry persistence.
- [x] Semantic DNS durable state now stays in a private runtime directory and rejects oversized topology snapshots
  targeted PHPTs: `253`, `303`, `325`
  coverage: semantic-dns state now persists under a private `0700` runtime directory instead of a shared `/tmp` file, save uses private `mkstemp` staging, load refuses insecure state-directory setups, oversized persisted `mother_node_count` values are rejected before allocation, and malicious snapshots no longer crash startup.
- [x] Multiprocess control-plane topology is now verified across independent controller observer and worker processes
  targeted PHPTs: `307`, `309`, `314`, `315`
  coverage: fresh controller processes persist tool state and queued runs, fresh observer processes rehydrate and inspect live/cancelled/completed snapshots, and fresh workers complete both live-claim and stale-claim recovery paths without falling back to single-process assumptions.
- [x] Canonical benchmark budgets now gate CI and final go-live readiness
  workflow: `.github/workflows/ci.yml`
  budget file: `benchmarks/budgets/canonical-ci.json`
  local verification: `./benchmarks/run-canonical.sh --iterations=5000 --warmup=500 --budget-file=benchmarks/budgets/canonical-ci.json` and `./scripts/go-live-readiness.sh --skip-baseline --benchmark-iterations 5000 --benchmark-warmup 500 --benchmark-budget-file benchmarks/budgets/canonical-ci.json`
  coverage: explicit per-case `max_ns_per_iteration` ceilings for `session`, `proto`, `object_store`, and `semantic_dns`, plus the same budget gate wired into the canonical CI go-live path.
- [x] Telemetry failed-export retries now keep the queue acyclic and shutdown-safe
  targeted PHPTs: `031`, `260`, `316`
  coverage: dequeued telemetry batches now detach stale `next` pointers before requeue, repeated failed flushes keep retry order intact instead of creating cyclic lists, and shutdown cleanup remains safe after multiple exporter failures with queued batches still pending.
- [x] Telemetry OTLP metrics export now handles packed metric batches and enum metric types without crashing
  targeted PHPTs: `031`, `260`, `316`, `319`
  coverage: `king_telemetry_init()` can seed a local OTLP endpoint for export-path verification, the exporter now reads metric names from batch payloads instead of assuming string hash keys, enum-backed metric `type` longs are normalized safely, and counter plus gauge metrics export successfully through a local mock OTLP collector.
- [x] Failover and chaos recovery are now verified for telemetry export, autoscaling controller state, and system lifecycle transitions
  targeted PHPTs: `320`, `321`
  coverage: telemetry keeps failed batches queued across exporter outage and drains the same batch after endpoint recovery, system components expose `shutting_down -> initializing -> running` transitions with request gating during recovery, and the Hetzner controller rehydrates persisted managed-node state after restart instead of losing admission progress.
- [x] The exported WebSocket OO surface now only exposes the honest `Connection` runtime
  targeted PHPTs: `143`, `227`, `228`, `312`
  coverage: the empty `King\WebSocket\Server` placeholder is retired from the stub and runtime registration, class-registration parity remains green, and the OO WebSocket surface now matches the actual implemented connection runtime instead of advertising a no-op server shell.

## How To Use This File

- add new work here only if it is still open
- close items here only after verification
- move durable product statements back to `README.md`
- move strategic decomposition changes back to `EPIC.md`
- move verified-state changes back to `PROJECT_ASSESSMENT.md`
