# King Program EPIC

> This file is intentionally short.
> It is the stable charter for King v1, not the moving roadmap.
> All concrete remaining work, including strategic ordering, now lives in
> `ISSUES.md`.

## Purpose

King is meant to ship as one coherent native systems runtime for PHP rather
than a bag of partially wired helper surfaces.

`EPIC.md` now exists only to hold the parts of that goal that should stay
stable while execution moves:

- the product boundary
- the non-negotiable engineering rules
- the release-level exit criteria

If a statement is a live priority, a current leaf, or a decomposed task, it no
longer belongs here.

## Product Boundary

King v1 is only real when the exported surface behaves like one system:

- transport, client, and server paths share coherent lifecycle semantics
- procedural and OO APIs are parallel surfaces over the same native kernels
- control-plane subsystems are stateful, policy-driven, and restart-aware
- routing, load-balancing, and DNS surfaces are either backed by real kernels or explicitly excluded from the v1 contract
- operational surfaces describe real live behavior rather than static snapshots
- build, packaging, tests, and docs describe the same system

## Non-Negotiables

- native runtime first, wrappers second
- one kernel, multiple API surfaces
- explicit ownership, teardown, and failure semantics
- no capability claim without build and test proof
- no permanent doc drift between target, verified state, and open work
- no simulated or local-only behavior presented as fully real

## Strategic Pillars

### 1. Runtime Truth

Config, session, transport, server, and data-plane behavior must be backed by
real kernels with explicit lifecycle and error contracts.

### 2. Wire Truth

Anything claimed as a transport or listener capability must be verified on-wire
against real peers, not only through local runtime shims.

### 3. Durable Control Plane

MCP, orchestration, storage, routing, load-balancing, and DNS must survive restart, failure, and
process boundaries honestly. Local-only convenience is not enough.

### 4. Operational Truth

Telemetry, autoscaling, admin, readiness, drain, and recovery behavior must be
observable, bounded, and failure-aware under degraded conditions.

### 5. Release Truth

Build, bootstrap, packaging, compatibility, and sanitizer/soak coverage must be
deterministic enough that release confidence does not depend on one lucky local
machine.

## Exit Criteria

King can only be treated as a truly finished v1 line when all of the following
are true:

- every exported capability is either fully real and verified or removed from the public surface
- wire-facing claims are backed by on-wire verification
- restart, recovery, and lifecycle semantics are explicit and test-backed
- build and packaging paths are deterministic on clean hosts
- compatibility and persisted-state guarantees are written down and validated
- `PROJECT_ASSESSMENT.md` has no material caveats left for the shipped surface
- `ISSUES.md` no longer carries open v1 blockers

## Document Model

Use the root documents like this:

- `README.md`
  stable product description
- `EPIC.md`
  stable charter and exit criteria
- `ISSUES.md`
  single moving roadmap and execution queue
- `PROJECT_ASSESSMENT.md`
  verified implementation state and current caveats
- `CONTRIBUTE.md`
  workflow and change discipline

## When To Change This File

Only update `EPIC.md` when one of these changes:

- the product boundary
- the non-negotiable engineering rules
- the release-level exit criteria
- the document model itself

If the change is a priority shift, a new task, a split leaf, or a current
blocker, it belongs in `ISSUES.md` instead.
