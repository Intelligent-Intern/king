# King Project Assessment

> Stand: 2026-03-24
> Scope: current verified implementation reach inside this repository
> This file is the moving current-state document.
> `README.md` describes the target system. This file describes the system that is actually here now.

## Executive Summary

King is no longer a pure stub shell.
The repository contains a real, test-backed skeleton runtime with active native
kernels across config, session, client transport, local server slices, IIBIN,
local WebSocket handling, and all major control-plane subsystems (MCP, Telemetry,
Autoscaling, Integration).

The repository now sits at a fully green verified baseline.
As of 2026-03-24, the canonical audit, rebuild, and full PHPT matrix all pass
against the current repository state, and the repo now has a canonical local
benchmark harness for the four core runtime paths that were still uncovered.

## Readiness Score: 8.5/10

The system has clearly transitioned from a stub shell into a coordinated
runtime. The limiting factor is no longer runtime parity; it is the remaining
production-readiness work around deeper CI hardening, release packaging,
sanitizer/profile coverage, and reducing the still-intentional skeleton surface.

| Subsystem | Score | Status |
|-----------|-------|--------|
| **Build & Infrastructure** | 9/10 | Audit, rebuild, full regression pass, and canonical CI wiring present |
| **Config & Session** | 9/10 | Native ownership active; full PHPT parity green |
| **HTTP Client Slices** | 10/10 | H1, H2, and H3 parity |
| **IIBIN & Codecs** | 10/10 | Fully native, object hydration |
| **Semantic DNS** | 9/10 | Register/discover/update/init/start-server parity green |
| **Object Store & CDN** | 9/10 | Runtime, persistence, HA, multi-backend, and stress parity green |
| **MCP & Orchestrator** | 8/10 | Runtime/request/transfer parity green; still skeleton-oriented underneath |
| **Telemetry & Autoscale** | 8/10 | Active monitoring and metrics loops verified in current targeted coverage |
| **System Integration** | 8/10 | Core lifecycle harness active and green in the full matrix |
| **Security & Hardening** | 8/10 | Policy gated, zeroing active, and hardened contracts verified green |
| **Performance/Bench** | 7/10 | Canonical local harnesses and baseline-compare flow present; not CI-gated yet |

## Verified Baseline

The current repository baseline is anchored to the canonical extension scripts:

```bash
cd extension
./scripts/audit-skeleton-surface.sh
./scripts/build-skeleton.sh
./scripts/test-skeleton.sh
```

Repository facts from the current tree:

- `extension/src`: 178 C files
- `extension/src_bak`: 177 archived C files
- `extension/include`: 168 headers
- `extension/tests`: 269 PHPT files
- `stubs/`: 1 public PHP stub surface

The currently verified regression baseline is:

- `./scripts/audit-skeleton-surface.sh`: passing
- `./scripts/build-skeleton.sh`: passing
- extension load smoke: passing
- `./scripts/test-skeleton.sh`: `269/269` PHPT tests passing
- `.github/workflows/ci.yml`: wired to the canonical audit/build/test path
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
- native file-system object-store backend core with durable .meta sidecars, local CDN cache, multi-node distribution, Cloud HA hooks (S3/Backup), and multi-backend routing (S3/Memcached simulated)
- native MCP runtime in `src/mcp/` with stateful session tracking, flattened ID persistence in Object Store, and full request/upload/download parity
- native Pipeline Orchestrator and Tool Registry in `src/pipeline_orchestrator/`
- native Telemetry runtime with active span lifecycle, metrics aggregation, flush paths, and context propagation
- native Autoscaling engine with monitoring, decision, and provisioning loops
- native System Integration core coordinating component lifecycles and health
- security policy enforcement for userland configuration overrides active across all entry points

## What Is Not Finished

The repo is still not a full production-grade implementation for:

- real hardware-backed cloud provisioning (currently simulated)
- CI/profile hardening, release packaging, and full go-live readiness

The biggest architectural caveat is simple:
several areas already have honest local runtime slices, but the backend depth,
transport depth, or operational depth is still incomplete.

## Current Assessment

### Strong

- audit and rebuild discipline around the active skeleton surface
- explicit ownership-oriented config and session runtime
- HTTP client protocol breadth inside the current skeleton scope
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

- richer CI/profile coverage, remaining fuzz/stress depth, release engineering,
  and final end-to-end readiness

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
