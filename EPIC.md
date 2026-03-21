# King Program EPIC

> This document is the strategic delivery map for King.
> It sits between the permanent product description in `README.md` and the
> granular execution backlog in `../docs/issues.md`.
> It is not a changelog, not a test report, and not a line-by-line migration log.

## Purpose

King is being built as a native systems platform for PHP:

- transport-aware client and server runtime
- QUIC, HTTP/1, HTTP/2, HTTP/3, TLS, streaming, cancellation, and upgrades
- Semantic DNS and service-routing control plane
- IIBIN binary serialization
- MCP and orchestration primitives
- object-store and CDN capabilities
- telemetry, admin, autoscaling, and operational control surfaces

The purpose of this EPIC is to define the major delivery tracks required to turn
that target system into a production-grade implementation.

## Document Boundaries

Use the documents in this way:

- `README.md`
  Permanent target-system description. This should stay stable.
- `EPIC.md`
  Strategic delivery decomposition and ordering.
- `../docs/issues.md`
  Active execution queue with verifiable leaves.
- `../docs/project_assessment.md`
  Verified implementation state and current reach.

If a statement is about what King is supposed to become, it belongs in
`README.md`. If it is about how the program is decomposed and delivered, it
belongs here. If it is about what is open right now, it belongs in
`../docs/issues.md`.

## North Star

King is done when it behaves like one coherent native runtime rather than a
collection of partially wired helper surfaces.

That means:

- the transport stack is real, reusable, and protocol-correct
- client and server paths share explicit session, stream, config, and lifecycle semantics
- OO and procedural APIs are parallel surfaces over the same kernels
- the control plane is transport-aware, stateful, and policy-driven
- data-plane subsystems are backed by real runtime implementations, not local placeholders
- telemetry, admin, and operational controls are production-usable
- the build, docs, test matrix, and error contracts describe reality

## Delivery Rules

Every epic follows the same quality bar:

- native runtime first, wrappers second
- one kernel, multiple API surfaces
- explicit ownership and teardown
- deterministic policy and validation
- no claimed capability without build and test proof
- docs move with the runtime, not ahead of it

A major area is only complete when:

- the relevant native path is active in the build
- the exposed API surface reaches that path directly
- error contracts are explicit and stable
- targeted tests exist
- full build and suite verification stay green

## Epic Map

### Epic 1: Truth, Hygiene, and Build Discipline

**Goal**
Keep the repository honest so strategic work is measured against the real build,
real docs, and real test surface.

**Includes**

- separating target-system docs from current-state docs
- keeping build inputs, generated artifacts, and archived sources distinct
- surfacing active runtime versus stubbed surface clearly
- preventing documentation drift

**Done when**

- the build surface is explicit
- generated noise is controlled
- status docs stop overstating or understating reality

### Epic 2: Runtime Foundation

**Goal**
Establish the irreducible native base: config snapshots, session state, TLS
state, ticket lifecycle, shutdown semantics, and transport ownership.

**Includes**

- `King\Config` as real composed runtime state
- `King\Session` lifecycle and native state ownership
- TLS defaults, material loading, and session-ticket handling
- transport bootstrap and core socket/runtime primitives

**Done when**

- config, session, and TLS are not placeholders
- lifecycle rules are explicit and shared by all upper layers

### Epic 3: Client Transport and Protocol Runtime

**Goal**
Deliver a real client stack across HTTP/1, HTTP/2, HTTP/3, streaming, reuse,
timeouts, cancellation, and upgrade-oriented flows.

**Includes**

- direct and dispatched request paths
- receive and streaming semantics
- protocol-specific reuse and multiplexing
- redirect, retry, early hints, push, and cancel behavior
- WebSocket and related realtime client paths

**Done when**

- protocol clients are real kernels, not adapter shells
- transport-backed behavior is consistent across direct, dispatcher, and OO paths

### Epic 4: Server Runtime and Control Surface

**Goal**
Deliver a real server-side runtime around listener dispatch, request/session
state, upgrades, control hooks, admin, TLS reload, and operational helpers.

**Includes**

- listener and dispatcher runtime
- server session semantics
- HTTP/1, HTTP/2, HTTP/3 listener paths
- cancel, early hints, websocket upgrade
- admin and TLS control surfaces
- telemetry and CORS server helpers

**Done when**

- server paths are not just config normalization or local snapshots
- listeners, session state, and control hooks represent real runtime ownership

### Epic 5: Public API Parity

**Goal**
Make the exposed PHP API reflect one runtime model rather than separate
procedural and OO islands.

**Includes**

- object handlers and wrappers
- procedural and OO parity over shared kernels
- arginfo, signatures, and reflection correctness
- typed exception hierarchy and stable validation/runtime/system boundaries

**Done when**

- OO and procedural surfaces are two views over the same state machines
- signature and exception contracts are trustworthy

### Epic 6: IIBIN and Binary Data Plane

**Goal**
Ship IIBIN as a real schema-driven binary runtime, not a partial proto toy.

**Includes**

- schema and enum registry
- compiler-backed schema metadata
- encode and decode runtime
- object hydration
- wire-compatibility behavior

**Done when**

- schema, codec, and object-hydration behavior are backend-owned
- the procedural and `King\IIBIN` surfaces share the same runtime

### Epic 7: Semantic DNS and Routing Control Plane

**Goal**
Turn local registry/read-model behavior into a real discovery and routing
subsystem.

**Includes**

- init and server-state lifecycle
- mother-node/control-node coordination
- routing and scoring policy
- durable or replicated state behavior
- network-backed and end-to-end validation

**Done when**

- Semantic DNS is not just local registration plus lookup snapshots
- discovery and route selection are driven by real state and policy

### Epic 8: Object Store and CDN Runtime

**Goal**
Turn local store/cache behavior into backend-backed storage and distribution
primitives.

**Includes**

- object-store backend abstraction
- local and remote persistence
- CDN cache and edge-state logic
- TTL, invalidation, and distribution behavior
- end-to-end storage and cache verification

**Done when**

- storage is durable or backend-backed
- CDN behavior is more than local registry bookkeeping

### Epic 9: MCP and Orchestration Runtime

**Goal**
Deliver MCP and orchestration as actual transport-aware runtime subsystems.

**Includes**

- MCP request transport
- stream upload and download backends
- protocol correctness and service interaction
- pipeline orchestration and tool registry runtime

**Done when**

- MCP is not limited to local lifecycle and transfer placeholders
- orchestrator execution is runtime-backed and testable

### Epic 10: Telemetry, Autoscaling, and System Control

**Goal**
Move operational surfaces from snapshot-style status APIs to real active
subsystems.

**Includes**

- telemetry spans, metrics, and export
- autoscaling loop and provisioning behavior
- system-level control and component management
- observability and operator-facing runtime hooks

**Done when**

- operational APIs reflect live behavior rather than static summaries

### Epic 11: Product Hardening

**Goal**
Raise the whole system from “broad skeleton with real slices” to a production
platform with hard guarantees.

**Includes**

- end-to-end and cross-protocol tests
- real backend integration coverage
- performance validation
- security and policy hardening
- failure-mode and lifecycle stress verification
- packaging and build reproducibility

**Done when**

- subsystem-local correctness is matched by whole-system correctness
- performance, safety, and operational expectations are backed by proof

## Ordering Constraints

The epics are not independent. The real dependency chain is:

1. Truth and build hygiene
2. Config, session, TLS, and lifecycle foundation
3. Client and server transport/runtime kernels
4. API parity over shared kernels
5. Data-plane and control-plane subsystems
6. Operational subsystems
7. Product hardening across the whole stack

In practice:

- no serious API-parity work should outrun kernel ownership
- no control-plane claim should outrun runtime state
- no product claim should outrun E2E and failure testing

## Release-Level Exit Criteria

King can be treated as a production-grade 1.0 system only when all of the
following are true:

- the primary transport and protocol stack is real and reusable
- client and server behavior share coherent lifecycle semantics
- the public API surface is parity-correct and reflection-clean
- Semantic DNS, storage/CDN, MCP, and telemetry are backend-backed
- performance and operational claims are validated, not aspirational
- documentation cleanly separates target system, execution queue, and verified state

## Execution Notes

Granular work decomposition, checkboxes, and current leaves belong in
`../docs/issues.md`. That file is expected to move frequently.

This EPIC should change only when:

- the program decomposition changes
- epic ordering changes
- the definition of “done” changes
- the product boundary changes

Everything else is execution detail.
