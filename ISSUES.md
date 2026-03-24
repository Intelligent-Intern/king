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

- [ ] Add fuzz, stress, and edge-case coverage
  build: `pass`
  audit: `pass`
  tests: `269/269`
  static-checks: `pass`
  profiles: `release/debug/asan/ubsan pass`

## Active Fronts

### 1. Semantic DNS and routing

- [x] Keep register/discover/update routing leaves green
- [x] Restore `king_semantic_dns_init()` / `king_semantic_dns_start_server()` runtime and validation parity
- [x] Reconcile Semantic DNS core-server expectations with the active local runtime surface

### 2. Object store and CDN runtime

- [x] Replace local registry behavior with a real object-store backend core
- [x] Restore object-store miss, init-validation, and capacity-boundary regression parity
- [x] Restore durable persistence and metadata rehydration regression parity
- [x] Restore CDN cache/invalidation/TTL/distribution regression parity
- [x] Restore HA, multi-backend, and stress-path regression parity
- [x] Re-run object-store/CDN end-to-end verification to green

### 3. MCP and orchestration

- [x] Port MCP runtime out of the local lifecycle-only slice into `extension/src/mcp/`
- [x] Restore MCP lifecycle/request parity against the current PHPT surface
- [x] Restore MCP upload/download helper parity and validation contracts
- [x] Restore MCP Object Store-backed transfer persistence end-to-end
- [x] Restore pipeline orchestrator runtime/test parity

### 4. Telemetry, autoscaling, and system integration

- [x] Port telemetry runtime beyond snapshots into active span, log, and context handling
- [x] Activate metrics aggregation, flush, and export paths
- [x] Port autoscaling monitoring, decision, and provisioning loops
- [x] Port system integration runtime and component orchestration state
- [x] Add reusable end-to-end harnesses for these subsystems

### 5. Security, performance, CI, and release

- [x] Add security policy enforcement for userland config overrides
- [x] Zeroize secrets and tighten ownership around TLS-adjacent buffers
- [x] Harden public input paths for bounds and type handling
- [x] Reconcile session, HTTP, and exception-hierarchy contract regressions after hardening
- [ ] Add fuzz, stress, and edge-case coverage
- [x] Build benchmark harnesses for session, proto, store, and Semantic DNS paths
- [x] Wire CI to the canonical `build-skeleton`, `test-skeleton`, and `audit-skeleton-surface` scripts
- [x] Add static checks and explicit debug, ASan, UBSan, and release profiles
- [ ] Define reproducible release packaging
- [ ] Reduce remaining stub surface subsystem by subsystem
- [ ] Add final parity, end-to-end, and go-live readiness checks

## How To Use This File

- add new work here only if it is still open
- close items here only after verification
- move durable product statements back to `README.md`
- move strategic decomposition changes back to `EPIC.md`
- move verified-state changes back to `PROJECT_ASSESSMENT.md`
