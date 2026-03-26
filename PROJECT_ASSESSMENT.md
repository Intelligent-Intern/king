# King Project Assessment

> Stand: 2026-03-26
> Scope: verified v1 runtime reach inside this repository
> This file tracks the moving verified state of King v1.
> `README.md` describes the target system. This file describes the system that is actually here now.

## Executive Summary

King now ships as a repo-local v1 runtime.
Within this repository, that v1 line is treated as the final release line, not
as a beta placeholder.
The repository contains a real, test-backed native implementation across
config, session, client transport, local server slices, IIBIN, local WebSocket
handling, and all major control-plane subsystems (MCP, Telemetry, Autoscaling,
Integration).

The repository now sits at a fully green verified baseline.
As of 2026-03-26, the canonical audit, rebuild, and full PHPT matrix all pass
against the current repository state, and the repo now has a canonical local
benchmark harness for the four core runtime paths that were still uncovered,
explicit local `release`, `debug`, `asan`, and `ubsan` build/smoke paths, and
a seeded local fuzz/stress subset for the highest-risk runtime surfaces, plus a
reproducible release packaging path over the staged canonical release profile.
The final repo-local go-live readiness gate is now in place and verifies public
stub/runtime parity, release-profile smoke, benchmark smoke, reproducible
packaging, and extracted-package readiness over the same current tree. The
legacy public C stub compilation unit is now retired, the public `stubs/king.php`
surface matches the live runtime exactly, and the runtime inventory reports
zero residual stub API groups.
Autoscaling has also moved past the purely simulated provisioning path: the
generic provider contract now ships with a controller-only Hetzner backend that
drives honest HTTP provider calls, persists controller state across restart,
gates admission with explicit `register -> ready -> drain -> delete` node
lifecycle transitions, and keeps non-Hetzner providers explicitly simulated
behind the same interface. The autoscaling controller itself now consumes live
telemetry and system signals for scale decisions, enforces cooldown and
hysteresis, honors capped scale-up policy resolution, and blocks unsafe
follow-up Hetzner scale-ups while nodes are still pending registration,
readiness, or drain.
The local control-plane depth has also advanced again: the pipeline
orchestrator now persists tool-registry state, logging configuration, completed
run history, and in-flight run snapshots across restart, and the recovery path
is verified by dedicated cross-process PHPT coverage.

One important caveat remains in the QUIC/HTTP/3 bootstrap path. The runtime is
real and green, and build tooling now avoids the two common CI breakage modes:
missing host curl headers and stale/non-resolvable `wirefilter` git pins. Fresh
hosts now recover deterministically from local fallback behavior, but the path is
still not fully pinned and reproducibly rehydrated because parts of the dependency
pinning still rely on branch fallback during bootstrap.

## Readiness Model

The limiting factor is no longer runtime parity, public stub coverage, or
repo-local go-live verification. The remaining work is production-depth work
outside the local verified baseline: real external provisioning breadth,
deeper operational backends, remote orchestration depth, and hard
performance/compatibility gates.

| Area | State | Meaning |
|------|-------|---------|
| **Repo-local runtime baseline** | **Verified** | Audit, build, static checks, full PHPT matrix, fuzz subset, profile smokes, benchmark smoke, reproducible packaging, and go-live readiness all pass |
| **Public API and runtime parity** | **Verified** | `stubs/king.php` matches the live extension surface and zero stubbed API groups remain in the runtime inventory |
| **External QUIC backend bootstrap** | **Incomplete** | HTTP/3 and QUIC are green locally, but `quiche` recovery and dependency pinning are still weaker than a fully tracked, deterministic bootstrap path |
| **External autoscaling provisioning** | **Partially production-honest** | Hetzner is real, controller-owned, persisted, and telemetry-driven; other providers intentionally stay simulated behind the generic contract |
| **Distributed control plane** | **Local-first** | MCP and Orchestrator are real in-tree kernels with restart-safe local state, but they are still not deep remote/distributed production backends |
| **Operational backend depth** | **Incomplete** | exporter depth, failover, long-haul soak, and compatibility budgets are still open |

## Verified Baseline

The current repository baseline is anchored to the canonical extension scripts.
The composed final gate is:

```bash
cd extension
./scripts/go-live-readiness.sh
./scripts/build-profile.sh debug
./scripts/smoke-profile.sh debug
./scripts/build-profile.sh asan
./scripts/smoke-profile.sh asan
./scripts/build-profile.sh ubsan
./scripts/smoke-profile.sh ubsan
```

Repository facts from the current tree:

- `extension/src`: 177 C files
- `extension/src_bak`: 177 archived C files
- `extension/include`: 168 headers
- `extension/tests`: 287 PHPT files
- `stubs/`: 1 public PHP stub surface

The currently verified regression baseline is:

- `./scripts/static-checks.sh`: passing
- `./scripts/audit-runtime-surface.sh`: passing
- `./scripts/build-extension.sh`: passing
- extension load smoke: passing
- `./scripts/test-extension.sh`: `287/287` PHPT tests passing
- `./scripts/fuzz-runtime.sh`: passing
- `./scripts/check-stub-parity.sh`: passing (`112` functions, `44` classes, `48` declared public methods)
- `./scripts/smoke-profile.sh release`: passing
- `./scripts/smoke-profile.sh asan`: passing
- benchmark smoke (`session`, `proto`, `object_store`, `semantic_dns`): passing
- `./scripts/package-release.sh --verify-reproducible`: passing
- `./scripts/verify-release-package.sh`: passing
- `./scripts/go-live-readiness.sh`: passing
- `./scripts/build-profile.sh release`: passing
- `./scripts/smoke-profile.sh release`: passing
- `./scripts/build-profile.sh debug`: passing
- `./scripts/smoke-profile.sh debug`: passing
- `./scripts/build-profile.sh asan`: passing
- `./scripts/smoke-profile.sh asan`: passing
- `./scripts/build-profile.sh ubsan`: passing
- `./scripts/smoke-profile.sh ubsan`: passing
- targeted HTTP/3 runtime verification (`190`, `191`, `204`, `232`): passing
- targeted orchestrator persistence verification (`250`, `294`, `307`, `308`): passing
- `king_health()['stubbed_api_group_count']`: `0`
- `.github/workflows/ci.yml`: wired to the canonical audit/build/test path plus the final go-live readiness step
- `./benchmarks/run-canonical.sh`: passing locally

There are currently no open PHPT failures in the canonical suite.

## What Is Real Today

The repo already has active native runtime slices for:

- `King\Config`, `King\Session`, `King\Stream`, `King\Response`, and `King\CancelToken`
- HTTP/1, HTTP/2, and HTTP/3 client request paths
- HTTP/1 streaming receive and response bridging
- HTTP/2 HTTPS/ALPN, multiplexing, and push capture
- local WebSocket connect, frame, ping, close, and OO parity
- local server dispatch, local HTTP/1, HTTP/2, and HTTP/3 listener leaves
- server-side cancel, early hints, websocket upgrade, admin API, TLS reload, CORS, and telemetry-init helpers
- IIBIN schema, enum, encode, decode, object hydration, and wire validation
- native Semantic DNS registry, routing, state persistence, discovery, and mother-node tracking
- native file-system object-store backend core with durable .meta sidecars, local CDN cache, multi-node distribution, and explicit contract/status failure semantics for non-local backends (distributed/S3/GCS/Azure simulated).
- native MCP runtime in `src/mcp/` with stateful session tracking, flattened ID persistence in Object Store, and full request/upload/download parity
- native Pipeline Orchestrator and Tool Registry in `src/pipeline_orchestrator/`, including restart-safe tool registry, logging snapshot persistence, completed run history, and in-flight run rehydration
- native Telemetry runtime with active span lifecycle, metrics aggregation, flush paths, and context propagation
- native Autoscaling engine with monitoring, live telemetry/system-backed decisioning, cooldown/hysteresis enforcement, capped scale-step policy resolution, pluggable provider routing, controller-only Hetzner token loading from `php.ini`, honest Hetzner create/delete HTTP calls, restart-safe controller state persistence, explicit managed-node inventory APIs, and `register -> ready -> drain -> delete` lifecycle control plus pending-node safeguards on the honest Hetzner path while non-Hetzner providers stay simulated behind the same contract
- operator-facing spend/quota budget warning/hard-limit surfaces in `king_autoscaling_get_status`, with warning-only behavior on probe/API degrade and hard-stop enforcement on configured hard limits
- native System Integration core coordinating component lifecycles and health
- security policy enforcement for userland configuration overrides active across all entry points
- a public PHP stub surface that is parity-checked against the live runtime before go-live claims are made

## What Is Not Finished

The repo is still not a full production-grade implementation for:

- multi-node rollout, rollback, and provider-error recovery under sustained fleet pressure beyond the now-verified Hetzner bootstrap/release handoff path
- multi-provider cloud provisioning beyond the Hetzner path; non-Hetzner providers still remain simulated by design
- QUIC/HTTP/3 backend provenance is still weaker than ideal: the runtime depends on an external local `quiche/` tree, and clean-room rehydration is still not yet a fully tracked deterministic bootstrap path for release-grade builds
- object-store cloud adapters remain simulated beyond the local filesystem core; local persisted backend restart rehydration is verified, but distributed/cloud durability guarantees are still open
- release/container profile builds remain sensitive to missing `quiche`/`libcurl` layouts in clean or cross-arch environments until bootstrap normalization is fully deterministic and independent of local host headers
- long-haul telemetry exporter hardening, failover behavior, and queue/backpressure guarantees under degraded conditions
- remote/distributed MCP and orchestrator execution instead of local-first kernels; restart-safe local persistence is verified, but the worker/backend boundary is still missing
- coordinated multi-node rolling-restart, failover, and crash-recovery operational depth
- CI-enforced benchmark budgets, installability matrix, and long-duration sanitizer soak coverage

The biggest architectural caveat is simple:
several areas already have honest local runtime slices, but the backend depth,
transport depth, or operational depth is still incomplete.

## What Still Blocks A Solid 10/10

### 1. Dependency Provenance And Bootstrap

- The repo can run HTTP/3 and QUIC locally, but the `quiche` backend is still an external working-tree dependency rather than a fully pinned and self-rehydrating tracked artifact path.
- Local recovery from an empty or accidentally cleaned workspace is not yet a one-command deterministic bootstrap; restoring `quiche` currently depends on fetching external source again.
- The release-profile build is now resilient enough to recover locally when upstream `Cargo.lock` drift is encountered, but that fallback is convenience, not strong supply-chain control.
- A real `10/10` state here means: pinned backend revision, deterministic bootstrap from the repo workflow itself, and clean-host verification that does not rely on ad hoc dependency resurrection.

### 2. Autoscaling Needs Operational Depth, Not Just Provider Honesty

- Hetzner provisioning is honest and controller-owned, and the bootstrap/release handoff path is verified, but the project still lacks full multi-node fleet rollout and rollback proof.
- The current verification is still fundamentally controller-local; it does not yet prove sustained fleet behavior under real rollout, drain, rollback, or provider-error recovery pressure.
- A real `10/10` state here means: verified release propagation/bootstrap on new nodes and recovery behavior that survives provider/API turbulence without manual babysitting.

### 3. Object Store And Persistence Are Strong Locally But Not Finished Externally

- The local filesystem object-store core is real and well tested, but cloud adapters remain simulated in the current tree.
- Local filesystem backup/restore, import/export, and `.meta` sidecar persistence are now implemented and verified in PHPT (including restart path coverage for primary local backend rehydration). Restart rehydration guarantees for cloud-distributed profiles remain open.
- That leaves a gap between "local runtime is correct" and "operators can trust the state layer across restart, migration, and backend substitution".

### 4. MCP And Orchestrator Are Still Local-First

- MCP request/upload/download behavior is real inside the repo-local runtime, but remote/distributed execution depth is still missing.
- The orchestrator has a real native kernel and now survives restart with persisted tool registry, logging state, completed runs, and running snapshots, yet worker boundaries, bounded concurrency, deadline handling, and cross-process cancellation propagation are still open.
- There is still no multi-process or multi-host verification harness proving that these control-plane slices behave correctly once execution leaves the local process.

### 5. Telemetry And System Operations Still Need Real Export And Recovery Semantics

- Telemetry aggregation, queueing, and export contracts are real, but long-haul delivery guarantees, failure handling, and degraded-mode behavior are not yet production-deep.
- System integration currently verifies local lifecycle composition, not rolling restart, coordinated drain, failover, or chaos recovery across nodes.
- The missing part is not shape or API parity; it is operational truth under degraded conditions.

### 6. Realtime And Server Depth Need More On-Wire And Long-Lived Verification

- WebSocket and server flows are strong locally, but the stack still lacks enough on-wire verification for server-side realtime behavior.
- Long-lived soak coverage for upgrade flows, TLS reload, admin API, session churn, and multi-connection fairness remains thin relative to a true production-grade claim.
- HTTP/2, HTTP/3, and WebSocket backpressure/fairness under sustained churn are still not closed out as hard guarantees.

### 7. Release, Compatibility, And Confidence Gates Are Not Maxed Out Yet

- The repo has reproducible packaging and profile smokes, but not a real clean-host install/smoke matrix across supported PHP/API combinations.
- Benchmark harnesses exist, but CI-enforced regression budgets are still missing.
- Upgrade/downgrade compatibility for release artifacts and persisted state is still not proven as a first-class gate.
- Long-duration ASan/UBSan soak coverage and archived diagnostics on failure remain open.
- Release profile/tooling bootstrap still depends on host/repo path normalization for external `quiche/` and `curl` layouts, which can stall container matrix builds on clean or unusual hosts.

## Current Assessment

### Strong

- audit and rebuild discipline around the active runtime surface
- explicit ownership-oriented config and session runtime
- HTTP client protocol breadth inside the current runtime scope
- local server control and dispatch slices
- IIBIN runtime ownership and codec maturity
- native Semantic DNS register/discover/update control-plane slices
- native Telemetry, Autoscaling, and System Integration coordination
- security-gated userland configuration surface

### Medium

- local WebSocket runtime
- object-store and CDN backend/runtime reach
- MCP and pipeline orchestrator runtime reach
- OO/procedural parity over shared native kernels

### Weak or Still Open

- multi-provider external backend depth and exporter depth around autoscaling
- remote/distributed orchestration depth
- operational recovery, failover, and exporter depth
- hard performance, compatibility, and soak gates

## Source Of Truth Boundaries

Use the root documents like this:

- `README.md`
  Permanent target-system description
- `EPIC.md`
  Strategic delivery decomposition
- `ISSUES.md`
  Active open execution queue
- `CONTRIBUTE.md`
  Workflow and contribution rules
- `stubs/king.php`
  Public PHP signature surface

If a statement is volatile, verified, or tied to the current implementation
reach, it belongs here instead of in `README.md`.
