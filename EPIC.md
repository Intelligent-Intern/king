# King Program EPIC

This file is the stable charter for King v1. It is not the backlog.

## Purpose

King ships as one coherent native systems runtime for PHP, not as disconnected helper surfaces.

King v1 is the end-state contract for this line. It is not an MVP, demo slice, or temporary release shape.

## Product Boundary

King v1 is real only when the exported surface behaves like one system:

- transport, client, and server paths share lifecycle semantics;
- procedural and OO APIs are parallel surfaces over the same native kernels;
- control-plane subsystems are stateful, policy-driven, and restart-aware;
- routing, load-balancing, DNS, telemetry, storage, and orchestration are backed by real runtime behavior or explicitly outside v1;
- build, packaging, tests, and docs describe the same system.

## Non-Negotiables

- Native runtime first, wrappers second.
- One kernel, multiple API surfaces.
- Explicit ownership, teardown, and failure semantics.
- No capability claim without code and verification.
- No documentation drift between target, verified state, and open work.
- No simulated or local-only behavior presented as fully real.
- No weakening of an intended shared, remote, persistent, or stronger contract without explicit product approval.

## Release Train

Current release train: `1.0.7-beta`.

Everything in `BACKLOG.md` except MarketView/trading future work is intended for the current `1.0.7-beta` line unless explicitly reprioritized.

## Exit Criteria

King can be treated as a finished v1 line only when:

- every exported capability is fully real and verified, or removed from the public surface;
- wire-facing claims are backed by on-wire verification;
- restart, recovery, lifecycle, security, and compatibility semantics are test-backed;
- build and packaging paths are deterministic on clean hosts;
- persisted-state upgrade/downgrade behavior is validated;
- `BACKLOG.md` has no open v1 blockers;
- `SPRINT.md` is empty;
- `READYNESS_TRACKER.md` records the completed proof.

## Document Model

- `README.md`: product overview and install entrypoint.
- `EPIC.md`: stable charter and release exit criteria.
- `BACKLOG.md`: all open work, split into release batches.
- `SPRINT.md`: active branch scope only.
- `READYNESS_TRACKER.md`: completion log only.
- `CONTRIBUTE`: workflow rules.
- `documentation/`: developer and subsystem docs.

## Change Rule

Update this file only for product boundary, non-negotiables, release train, exit criteria, or document model changes.

Put priorities, batches, and decomposed work into `BACKLOG.md` or `SPRINT.md`.
