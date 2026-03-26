# King Project Assessment

> Stand: 2026-03-26
> Scope: verified repo-local v1 state inside this repository
> This file records what is actually verified now.
> `README.md` stays product-level.
> `ISSUES.md` is the single moving roadmap and execution queue.
> `READYNESS_TRACKER.md` is the long-form closure tracker.

## Executive Summary

King currently sits at a green repo-local v1 baseline.
The active extension builds, audits, packages, and passes the full PHPT suite,
and the public stub surface matches the live runtime.

That does not yet mean "final 10/10".
The remaining gaps are no longer about broad runtime parity or placeholder
surfaces inside the local tree.
They are concentrated in six areas:

- higher-scale MCP and deeper distributed orchestration
- larger Smart-DNS topology and distributed routing verification beyond the local slice
- honest non-local object-store backend scope versus a locked `local_fs` v1 contract
- long-haul telemetry, exporter, and fleet recovery depth
- deterministic QUIC bootstrap and clean-host installation confidence
- compatibility, sanitizer soak, and release-grade upgrade guarantees

The long-form completion checklist has been distilled into a smaller active
queue. If an open v1 item is not in `ISSUES.md`, it is not part of the current
repo-local execution plan.

## Verified Baseline Snapshot

The currently verified baseline is:

- `./scripts/static-checks.sh`: passing
- `./scripts/check-include-layout.sh`: passing
- `./scripts/audit-runtime-surface.sh`: passing
- `./scripts/build-extension.sh`: passing
- `./scripts/test-extension.sh`: `327/327` passing
- `./scripts/fuzz-runtime.sh`: passing
- `./scripts/check-stub-parity.sh`: passing
- `./scripts/package-release.sh --verify-reproducible`: passing
- `./scripts/verify-release-package.sh`: passing
- `./scripts/go-live-readiness.sh`: passing
- `./scripts/build-profile.sh release|debug|asan|ubsan`: passing
- `./scripts/smoke-profile.sh release|debug|asan|ubsan`: passing
- benchmark smoke and committed CI budget gate: passing

Current tree facts:

- `extension/src`: `177` C files
- `extension/include`: `172` headers
- `extension/tests`: `327` PHPT files
- public stub parity: `125` functions, `43` classes, `48` declared public methods
- `king_health()['stubbed_api_group_count']`: `0`
- project-owned headers now live under `extension/include` with generated `extension/config.h` as the only root-level exception
- static and runtime-surface audits now enforce that include-tree discipline

## What Is Verified And Real Today

The current tree already proves:

- explicit config and session ownership through `King\Config` and `King\Session`
- real HTTP/1, HTTP/2, and HTTP/3 client request paths, including reuse, streaming, and cancel/timeout contracts
- HTTP/2 shared-session fairness under mixed slow and fast concurrent streams
- HTTP/3 one-shot churn isolation across repeated timeout and healthy-recovery cycles
- local server dispatch and listener slices for HTTP/1, HTTP/2, and HTTP/3
- on-wire WebSocket client handshake/frame/close runtime plus honest OO `King\WebSocket\Connection` parity
- multi-client WebSocket close/reconnect churn on one server without cross-client starvation or corruption
- server-side `king_server_upgrade_to_websocket()` both as an honest local marker slice for local listeners and as a real on-wire HTTP/1 one-shot upgrade path with frame flow, handler ownership, and close/drain coverage
- repeated server/session soak coverage for local upgrade, early hints, admin API, TLS reload, and on-wire websocket close/drain cycles
- IIBIN schema, registry, encode/decode, object hydration, and wire validation
- Semantic DNS register/discover/update routing plus private-directory durable state handling
- Smart-DNS public config and init surfaces are now narrowed to the active `service_discovery` / semantic-runtime knobs
- router/loadbalancer is now exposed as an explicit config-backed system component with honest policy/discovery-only introspection
- object-store local filesystem persistence, `.meta` sidecars, CDN cache/runtime behavior, and confined backup/restore/import/export paths
- MCP request/upload/download parity against a real remote peer with propagated timeout, deadline, cancellation controls, 1 MiB payload roundtrips, parallel-transfer backpressure isolation, explicit single-flight reentry guards, same-host partial-failure recovery, persisted remote-state restart recovery coverage, and explicit `topology_scope=same_host_remote_peer` introspection
- orchestrator persistence, honest `queued -> running -> completed|failed|cancelled` run transitions, explicit `single_attempt` retry plus `caller_managed` idempotency contract, explicit `topology_scope=local_in_process|same_host_file_worker` introspection by backend mode, local/file-worker backend boundary, cross-process cancellation, and multiprocess controller/observer/worker verification
- telemetry batch queueing, bounded retry behavior, OTLP metrics export hardening, and local exporter failover/recovery coverage
- telemetry-driven Hetzner autoscaling with controller-owned credentials, persisted recovery state, and `register -> ready -> drain -> delete` lifecycle gating
- system integration lifecycle coordination, restart-state visibility, and chaos/recovery harness coverage for the local control plane

## What Is Still Not Finished

The repo is still short of a "nothing left to caveat" v1 in these areas:

### Remote Control Plane Depth

- MCP now propagates timeout, deadline, and cancellation controls through the real peer protocol, and same-host large-payload, parallel-transfer backpressure isolation, single-flight reentry safety, partial-failure recovery, plus persisted remote-state restart recovery are verified. The runtime now also exposes that same-host scope explicitly; real multi-host recovery depth and richer distributed failure semantics are still open.
- The orchestrator has a real cross-process file-worker boundary and now exposes its current topology scope explicitly by backend mode, but it still does not have a verified multi-host boundary.
- Retry and idempotency semantics are now explicit and test-backed for the current file-worker slice, but broader remote/distributed execution guarantees are still thinner than a final release bar.

### Routing and DNS Scope

- Router/loadbalancer is now honestly fenced to a config-backed control-plane surface; it is not presented as a forwarding dataplane runtime.
- Smart-DNS public config and init surfaces are now honest for the current local semantic/service-discovery runtime.
- The remaining Smart-DNS work is larger-topology, mother-node, and failover verification rather than more local config cleanup.

### Object Store Scope

- The local filesystem backend is honest and verified.
- Non-local object-store backends are still simulated.
- The remaining work is either to implement at least one honest non-local backend or to freeze the v1 public contract around `local_fs` without ambiguity.

### Observability and Fleet Operations

- Metrics export is ahead of traces/logs export in verification depth.
- Telemetry queueing is now bounded, but long-haul degraded exporter behavior still needs more proof.
- Autoscaling and system recovery are chaos-tested locally, but multi-node rolling restart and failover depth are still open.

### Build, Compatibility, and Release Confidence

- QUIC and HTTP/3 are green locally, but `quiche` bootstrap is still not pinned and deterministic enough for a "done forever" claim.
- Clean-host install and container smoke matrices are still missing as first-class gates.
- Upgrade/downgrade compatibility for release artifacts and persisted state is still not proven.
- Long-duration ASan/UBSan/leak soaks with archived diagnostics are still open.

## Current Remaining Work Model

The repo no longer treats every imaginable future check as the active queue.

The model is now:

- `EPIC.md`
  stable charter, pillars, and exit criteria
- `ISSUES.md`
  the active executable open items distilled from the larger completion tracker
- `READYNESS_TRACKER.md`
  the broad long-form completion checklist with verified checks and still-open closure gates
- `PROJECT_ASSESSMENT.md`
  verified state and caveats

If a task is broad, vague, or derivative, it does not belong in the active
queue until it is split into a repo-local executable leaf.

## Source Of Truth Boundaries

Use the root documents like this:

- `README.md`
  stable product description
- `EPIC.md`
  stable charter and release bar
- `ISSUES.md`
  single moving roadmap and open execution queue
- `READYNESS_TRACKER.md`
  long-form completion tracker and broad closure reference
- `PROJECT_ASSESSMENT.md`
  verified current state and caveats
- `CONTRIBUTE.md`
  workflow and verification discipline
- `stubs/king.php`
  public PHP signature surface

If a statement is about what is verified right now, it belongs here rather than
in `README.md`.
