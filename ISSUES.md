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

- [ ] Port CDN cache and distribution logic into the active runtime
  `extension/src/object_store/`

## Active Fronts

### 1. Semantic DNS and routing

All checked!

### 2. Object store and CDN runtime

- [x] Replace local registry behavior with a real object-store backend core
- [x] Implement concrete store backends and capacity boundaries
- [x] Add durable persistence paths
- [ ] Port CDN cache and distribution logic into the active runtime
- [ ] Add edge-state, TTL, invalidation, and distribution behavior
- [ ] Add backend and end-to-end verification

### 3. MCP and orchestration

- [ ] Port MCP runtime out of the local lifecycle-only slice into `extension/src/mcp/`
- [ ] Bind MCP request transport to real session and QUIC-backed paths
- [ ] Replace local upload and download storage with real backends
- [ ] Add MCP transport and mock-service end-to-end tests
- [ ] Activate the pipeline orchestrator core and tool registry
- [ ] Add focused orchestrator tests

### 4. Telemetry, autoscaling, and system integration

- [ ] Port telemetry runtime beyond snapshots into active span, log, and context handling
- [ ] Activate metrics aggregation, flush, and export paths
- [ ] Port autoscaling monitoring, decision, and provisioning loops
- [ ] Port system integration runtime and component orchestration state
- [ ] Add reusable end-to-end harnesses for these subsystems

### 5. Security, performance, CI, and release

- [ ] Zeroize secrets and tighten ownership around TLS-adjacent buffers
- [ ] Harden public input paths for bounds and type handling
- [ ] Add fuzz, stress, and edge-case coverage
- [ ] Build benchmark harnesses for session, proto, store, and Semantic DNS paths
- [ ] Wire CI to the canonical `build-skeleton`, `test-skeleton`, and `audit-skeleton-surface` scripts
- [ ] Add static checks and explicit debug, ASan, UBSan, and release profiles
- [ ] Define reproducible release packaging
- [ ] Reduce remaining stub surface subsystem by subsystem
- [ ] Add final parity, end-to-end, and go-live readiness checks

## How To Use This File

- add new work here only if it is still open
- close items here only after verification
- move durable product statements back to `README.md`
- move strategic decomposition changes back to `EPIC.md`
- move verified-state changes back to `PROJECT_ASSESSMENT.md`
