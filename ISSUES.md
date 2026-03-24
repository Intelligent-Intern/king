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

- [ ] Add operator-facing spend and quota warnings for the honest Hetzner autoscaling path
  why this blocks `10/10`: live telemetry-driven decisions, cooldown/hysteresis, and Hetzner lifecycle guards are now verified, but operators still lack first-class visibility into approaching spend/quota limits before the controller provisions more nodes
  done when: the Hetzner path exposes stable warning signals for configured spend/quota thresholds, surfaces them through status/introspection, degrades safely when provider budget APIs are unavailable, and is verified under warning/no-warning/error scenarios without making spend APIs the only hard-stop mechanism

## Active Fronts

### 1. Real external backends

- [x] Keep a generic autoscaling provider contract with Hetzner as the first and only honest backend implementation
- [x] Add controller-only Hetzner credentials and config loading from `php.ini`, without replicating the cloud API token onto scaled nodes
- [x] Serialize Hetzner network, labels, placement-group, firewall, and bootstrap metadata into honest create-server payloads
- [x] Persist Hetzner scale actions, instance identity, and recovery state so controller restarts do not orphan live nodes
- [x] Document clearly that non-Hetzner providers may exist behind the same interface but are currently simulated; "production-honest in-tree today means Hetzner only"
- [x] Complete Hetzner node admission and retirement with register, readiness, and drain instead of treating provider success as immediate service readiness
- [ ] Verify end-to-end release/bootstrap rollout onto freshly provisioned Hetzner nodes instead of stopping at provider payload and controller lifecycle honesty
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
- [ ] Add operator-facing spend and quota warnings for the Hetzner path; do not make provider spend APIs the sole hard-stop mechanism
- [ ] Add rolling restart, drain, and readiness transitions to system integration instead of immediate local lifecycle flips
- [ ] Add failover/chaos harnesses for telemetry, autoscaling, and coordinated system recovery

### 4. Realtime and server runtime depth

- [ ] Replace local-only WebSocket handshake/runtime assumptions with on-wire client and server verification
- [ ] Give `King\WebSocket\Server` an honest public runtime surface or retire it from the exported API
- [ ] Add long-lived server/session soak coverage for upgrade, early-hints, TLS reload, admin API, and close/drain flows
- [ ] Verify multi-connection backpressure and fairness semantics under HTTP/2, HTTP/3, and WebSocket churn

### 5. Performance, compatibility, and release confidence

- [ ] Put benchmark baselines under CI with explicit per-case regression budgets
- [ ] Turn the QUIC backend bootstrap into a deterministic pinned dependency path instead of relying on a locally resurrected external `quiche/` checkout
- [ ] Add package install/smoke matrix coverage for clean hosts and supported PHP/API combinations
- [ ] Verify upgrade/downgrade compatibility for release artifacts and persisted object-store metadata/state
- [ ] Add long-duration ASan/UBSan soak gates with archived diagnostics on failure

## Verified Baseline Already Closed

- [x] Canonical build, audit, test, fuzz, package, package-verify, and go-live-readiness gates
  build: `pass`
  audit: `pass`
  tests: `278/278`
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

## How To Use This File

- add new work here only if it is still open
- close items here only after verification
- move durable product statements back to `README.md`
- move strategic decomposition changes back to `EPIC.md`
- move verified-state changes back to `PROJECT_ASSESSMENT.md`
