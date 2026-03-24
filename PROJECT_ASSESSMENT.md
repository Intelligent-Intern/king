# King Project Assessment

> Stand: 2026-03-21
> Scope: current verified implementation reach inside this repository
> This file is the moving current-state document.
> `README.md` describes the target system. This file describes the system that is actually here now.

## Executive Summary

King is no longer a pure stub shell.
The repository contains a real, test-backed skeleton runtime with active native
kernels across config, session, client transport, local server slices, IIBIN,
local WebSocket handling, and selected control-plane helpers.

That said, the project is still a skeleton platform, not a finished production
runtime across all major subsystems.
The strongest areas today are build discipline, PHPT coverage, config/session
foundations, the active HTTP client slices, the local server slices, and the
IIBIN backend.
The weakest areas are still backend depth and end-to-end behavior for Semantic
DNS, object-store/CDN, MCP transport, telemetry, autoscaling, system
integration, CI/release, and final operational hardening.

## Verified Baseline

The current repository baseline is anchored to the canonical extension scripts:

```bash
cd extension
./scripts/audit-skeleton-surface.sh
./scripts/build-skeleton.sh
./scripts/test-skeleton.sh
```

Repository facts from the current tree:

- `extension/src`: 169 C files
- `extension/src_bak`: 177 archived C files
- `extension/include`: 167 headers
- `extension/tests`: 260 PHPT files
- `stubs/`: 1 public PHP stub surface

The currently tracked green regression baseline is:

- `260/260` PHPT tests passing

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
- native MCP runtime in src/mcp/ with stateful QUIC session tracking, simulated request transport, and stream-upload/download parity surfaces
- local MCP lifecycle and stream-upload/download parity surfaces (archived/delegated)
## What Is Not Finished

The repo is not yet a full production-grade implementation for:

- CDN edge/runtime distribution behavior
- real MCP transport and backend-backed upload/download paths
- telemetry write runtime, metric export, autoscaling engine, and system integration runtime
- benchmark harnesses, CI hardening, release packaging, and full go-live readiness

The biggest architectural caveat is simple:
several areas already have honest local runtime slices, but the backend depth,
transport depth, or operational depth is still incomplete.

## Current Assessment

### Strong

- build and test discipline around the active skeleton surface
- explicit ownership-oriented config and session runtime
- HTTP client protocol breadth inside the current skeleton scope
- local server control and dispatch slices
- IIBIN runtime ownership and codec maturity
- native Semantic DNS control-plane, routing, and policy-based discovery
- native object-store `local_fs` backend core, backend-routing dispatch, capacity enforcement, replication stub, and durable `.meta` sidecar persistence with stats rehydration
- CDN in-memory cache registry with TTL expiry, in-place re-cache, invalidation, and live edge-node exposure from runtime config

### Medium

- local WebSocket runtime
- local CDN cache behavior and lifecycle hooks
- OO/procedural parity over shared native kernels

### Weak or Still Open

- transport-backed MCP runtime
- operational subsystems beyond snapshots and local helpers
- release engineering, benchmark coverage, and end-to-end readiness

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
