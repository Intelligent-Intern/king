# King Issues

> This document is the active repo-local execution queue.
> It tracks what is still open in this repository after the current verified
> baseline in `PROJECT_ASSESSMENT.md`.
> `README.md` stays stable. `EPIC.md` stays strategic. This file is allowed to move.

## Working Rules

- only mark a leaf done when code, tests, and repo-local docs agree
- prefer small verifiable leaves over broad vague work items
- do not claim capability because archived code exists under `extension/src_bak/`
- keep procedural and OO surfaces tied to the same native kernels
- update `PROJECT_ASSESSMENT.md` when the verified reach materially changes

## Current Next Leaf

- [x] Add operator-facing spend and quota warnings for the honest Hetzner autoscaling path
  why this blocks `10/10`: live telemetry-driven decisions, cooldown/hysteresis, and Hetzner lifecycle guards are now verified, but operators still lack first-class visibility into approaching spend/quota limits before the controller provisions more nodes
  done when: the Hetzner path exposes stable warning signals for configured spend/quota thresholds, surfaces them through status/introspection, degrades safely when provider budget APIs are unavailable, and is verified under warning/no-warning/error scenarios without making spend APIs the only hard-stop mechanism
  completed: 2026-03-25

- [ ] Verify PHP 8.5 transport bootstrap in full matrix after curl/wirefilter hardening
  why this blocks `10/10`: the local fix must be proven in container CI for PHP 8.5 as well; current evidence is from local bootstrap + smoke on current host PHP (8.4).
  done when: GitHub matrix for 8.1, 8.2, 8.3, 8.4, and 8.5 runs `./scripts/build-profile.sh release` and smoke on clean architecture-labeled workers without `curl/curl.h` or wirefilter revision failures.

- [x] Verify end-to-end release/bootstrap rollout onto freshly provisioned Hetzner nodes
  why this blocks `10/10`: the provisioning lifecycle is now honest, but there is still no verified end-to-end control-plane test proving the controller can propagate releases and bootstrap onto newly created workers under fleet conditions.
  done when: at least one release propagation scenario proves code/asset rollout, registration, readiness, and stable behavior across worker joins after an autoscaling-driven create path.
  completed: 2026-03-25

- [ ] Replace simulated object-store cloud adapters with explicit backend contracts and stable failure semantics
  why this blocks `10/10`: object-store cloud adapters are still simulated, which leaves production migration and provider-specific failure modes unverified.
  done when: explicit backend contracts and stable failure semantics are in place for all adapters plus explicit adapter status/error propagation in object-store operations.

- [x] Harden MCP transfer identifiers to prevent object-store path traversal
  why this blocks `10/10`: MCP transfer helpers could write and read traversal-contaminated identifiers directly into object-store object IDs, enabling filesystem path escape with the local backend.
  done when: `king_mcp_validate_transfer_args()` rejects path separator characters in `service`, `method`, and transfer identifiers before storage/read paths are materialized; regression coverage includes traversal cases and regression test `236-mcp-upload-download-validation.phpt` passes.
  completed: 2026-03-25

- [x] Harden multi-architecture build-profile bootstrap for missing `quiche` and `libcurl` header layouts
  why this blocks `10/10`: clean-host matrix builds can still fail when `quiche` or `libcurl` header/layout assumptions diverge across CI workers.
  done when: bootstrap normalization is deterministic across clean and cross-architecture checkouts and container matrix builds succeed without host-local assumptions.
  completed: 2026-03-25

## Active Fronts

### 1. Real external backends

- [x] Keep a generic autoscaling provider contract with Hetzner as the first and only honest backend implementation
- [x] Add controller-only Hetzner credentials and config loading from `php.ini`, without replicating the cloud API token onto scaled nodes
- [x] Serialize Hetzner network, labels, placement-group, firewall, and bootstrap metadata into honest create-server payloads
- [x] Persist Hetzner scale actions, instance identity, and recovery state so controller restarts do not orphan live nodes
- [x] Document clearly that non-Hetzner providers may exist behind the same interface but are currently simulated; "production-honest in-tree today means Hetzner only"
- [x] Complete Hetzner node admission and retirement with register, readiness, and drain instead of treating provider success as immediate service readiness
- [x] Verify end-to-end release/bootstrap rollout onto freshly provisioned Hetzner nodes
  why this blocks `10/10`: the provisioning lifecycle is now honest, but there is still no verified end-to-end control-plane test proving the controller can propagate releases and bootstrap onto newly created workers under fleet conditions.
  done when: at least one release propagation scenario proves code/asset rollout, registration, readiness, and stable behavior across worker joins after an autoscaling-driven create path.
  completed: 2026-03-25
- [ ] Replace simulated object-store cloud adapters with explicit backend contracts and stable failure semantics
- [ ] Add backup/restore and import/export paths for object-store payloads plus `.meta` state
- [ ] Add crash-recovery and restart rehydration verification for persisted backends

### 2. Distributed MCP and orchestrator depth

- [ ] Move orchestrator execution beyond the purely local runtime path and define a real worker/backend boundary
- [ ] Persist tool-registry and pipeline-run state across restart and recovery
- [ ] Add bounded concurrency, deadline, and cancellation propagation across MCP request/upload/download and orchestrator execution
- [ ] Add a multi-process end-to-end harness for remote MCP/orchestrator topology instead of single-process local-only verification

### 3. Observability, autoscaling, and lifecycle operations

- [ ] Add a real telemetry export queue and exporter semantics instead of local-only flush counters
- [x] Drive autoscaling decisions from live telemetry/system metrics with hysteresis, cooldown, saturation coverage, and Hetzner-specific scale-step guards
- [x] Add operator-facing spend and quota warnings for the Hetzner path; do not make provider spend APIs the sole hard-stop mechanism
- [ ] Add rolling restart, drain, and readiness transitions to system integration instead of immediate local lifecycle flips
- [ ] Add failover/chaos harnesses for telemetry, autoscaling, and coordinated system recovery

### 4. Realtime and server runtime depth

- [ ] Replace local-only WebSocket handshake/runtime assumptions with on-wire client and server verification
- [ ] Give `King\WebSocket\Server` an honest public runtime surface or retire it from the exported API
- [ ] Add long-lived server/session soak coverage for upgrade, early-hints, TLS reload, admin API, and close/drain flows
- [ ] Verify multi-connection backpressure and fairness semantics under HTTP/2, HTTP/3, and WebSocket churn

### 5. Performance, compatibility, and release confidence

- [ ] Put benchmark baselines under CI with explicit per-case regression budgets
- [x] Harden multi-architecture build-profile bootstrap for missing `quiche` and `libcurl` header layouts
  why this blocks `10/10`: release image and CI builds can still fail on clean hosts when `quiche`/`libcurl` are missing or live in architecture-specific include paths.
  done when: `./scripts/build-profile.sh` recovers/reuses external transport/layouts deterministically and container matrix builds succeed from a clean checkout.
  completed: 2026-03-25
- [ ] Turn the QUIC backend bootstrap into a deterministic pinned dependency path instead of relying on a locally resurrected external `quiche/` checkout
- [ ] Add package install/smoke matrix coverage for clean hosts, published container images, and supported PHP/API combinations
- [ ] Verify upgrade/downgrade compatibility for release artifacts and persisted object-store metadata/state
- [ ] Add long-duration ASan/UBSan soak gates with archived diagnostics on failure

## Verified Baseline Already Closed

- [x] Canonical build, audit, test, fuzz, package, package-verify, and go-live-readiness gates
  build: `pass`
  audit: `pass`
  tests: `279/279`
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
- [x] CI transport bootstrap is stabilized for curl/wirefilter failures in fresh hosts
  coverage: `./scripts/build-profile.sh` now validates curl headers for system builds, normalizes the qlog-dancer `wirefilter` dependency pin to a resolvable branch fallback, and avoids stale cargo git cache fragments before cargo metadata/build.

## How To Use This File

- add new work here only if it is still open
- close items here only after verification
- move durable product statements back to `README.md`
- move strategic decomposition changes back to `EPIC.md`
- move verified-state changes back to `PROJECT_ASSESSMENT.md`
