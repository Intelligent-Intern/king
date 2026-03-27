# King PHP Extension
**Systems-grade networking and infrastructure primitives for PHP**

<p align="center">
  <img src="crowned-elephant-mascot.jpg" alt="King mascot: the crowned elephant" width="320">
</p>

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![CI](https://github.com/Intelligent-Intern/king/actions/workflows/ci.yml/badge.svg)](https://github.com/Intelligent-Intern/king/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/Intelligent-Intern/king?display_name=tag)](https://github.com/Intelligent-Intern/king/releases)
[![PHP 8.1-8.5](https://img.shields.io/badge/PHP-8.1--8.5-777BB4?logo=php&logoColor=white)](https://github.com/Intelligent-Intern/king)
[![Linux x86_64 / arm64](https://img.shields.io/badge/Linux-x86__64%20%2F%20arm64-333333?logo=linux&logoColor=white)](https://github.com/Intelligent-Intern/king/blob/main/.github/workflows/docker.yml)
[![Docker](https://github.com/Intelligent-Intern/king/actions/workflows/docker.yml/badge.svg)](https://github.com/Intelligent-Intern/king/actions/workflows/docker.yml)

> This README is intentionally stable.
> It describes the target system, not the moving implementation state.
> It is not a changelog, migration log, issue list, or verification report.
> Public API signatures live in [`stubs/king.php`](stubs/king.php).
> Stable program charter and exit criteria live in [`EPIC.md`](EPIC.md).
> Current implementation state lives in [`PROJECT_ASSESSMENT.md`](PROJECT_ASSESSMENT.md).
> The single moving roadmap and execution queue live in [`ISSUES.md`](ISSUES.md).
> The long-form completion tracker lives in [`READYNESS_TRACKER.md`](READYNESS_TRACKER.md).

## What King Is

King is a PHP extension for building long-lived, high-throughput, protocol-heavy
systems directly from PHP without pushing the critical path out into a sidecar,
gateway, or FFI layer.

The target system combines transport, control-plane, data-plane, and operational
primitives in one native runtime:

- QUIC, HTTP/1, HTTP/2, HTTP/3, TLS, streaming, cancellation, and upgrade flows
- client and server APIs over explicit session and stream state
- WebSocket and WebTransport-class realtime communication
- Smart DNS / Semantic DNS for service discovery and routing
- router and load-balancer control-plane configuration and policy
- IIBIN for schema-defined native binary encoding and decoding
- MCP for agent and tool protocol integration
- object-store and CDN primitives
- telemetry, metrics, tracing, and admin control surfaces
- autoscaling, orchestration, and cluster-facing infrastructure hooks

King is meant for workloads where PHP is not just rendering templates or serving
short CGI-style requests, but acting as a systems language at the application
edge, in service meshes, in AI runtimes, and in transport-aware backend nodes.

## What King Is Not

King is not designed as:

- a PHP-FPM replacement
- a general-purpose event-loop framework
- a thin FFI wrapper over external networking libraries
- a userland-only async abstraction
- a pile of unrelated helper functions

The target is a coherent native systems platform with explicit ownership,
deterministic lifecycle, typed failures, and protocol-aware state.

## System Model

King follows a few hard rules:

- Configuration is explicit. A `King\Config` snapshot governs behavior.
- State is explicit. A `King\Session` owns connection or listener state.
- Streams are explicit. A `King\Stream` models bidirectional protocol work.
- Responses are explicit. A `King\Response` models structured receive state.
- Ownership is deterministic. Native resources are tied to PHP-visible handles.
- The OO and procedural APIs are parallel surfaces over the same native kernels.
- Security defaults stay conservative unless an operator explicitly loosens them.
- Runtime policy beats convenience. There is no hidden global magic pool.
- The target contract is not allowed to shrink just because the correct
  implementation is harder. If a subsystem matters for v1, the work is to make
  the stronger contract real, not to quietly redefine it downward.

## Target Subsystems

### Transport and Protocols

King is intended to expose a native transport stack for:

- QUIC transport
- HTTP/1 request and response handling
- HTTP/2 multiplexed transport
- HTTP/3 over QUIC
- TLS policy, certificate handling, and ticket reuse
- cancellation, timeouts, retry policy, and streaming response control
- upgrade-oriented flows such as WebSocket and related realtime protocols

### Client and Server Runtime

King targets symmetric client and server operation:

- outbound request clients for protocol-specific and generic HTTP use
- inbound listener and dispatch surfaces for server use
- session-scoped protocol metadata
- request and response streaming
- early hints, upgrade, close, and control hooks
- admin and operational control APIs

### Discovery and Control Plane

King includes a native control-plane model around:

- Smart DNS and Semantic DNS service registration
- topology awareness
- route selection
- router/loadbalancer backend discovery, configuration, and policy
- mother-node or control-node coordination
- policy-aware service discovery
- control and telemetry endpoints

### Data Plane

King is also a data and protocol runtime:

- IIBIN for schema-defined binary serialization
- MCP for tool and agent protocol traffic
- object-store primitives
- CDN-oriented cache distribution hooks
- pipeline orchestration for multi-step workloads

### Observability and Operations

Operational visibility is a first-class concern:

- OpenTelemetry-compatible tracing and metrics surfaces
- health and status reporting
- performance and component introspection
- config policy enforcement
- ticket, certificate, and reload lifecycle management
- autoscaling and cluster integration hooks

## Public Programming Model

The core programming model is:

- `King\Config` defines transport, protocol, security, and subsystem policy.
- `King\Session` represents a live native runtime context.
- `King\Stream` represents one unit of protocol work inside a session.
- `King\Response` represents structured receive state for request flows.
- `King\Client\*` and `King\Server\*` expose higher-level protocol roles.
- `King\MCP`, `King\IIBIN`, and `King\WebSocket\Connection` expose
  subsystem-specific runtime surfaces.

The procedural API exists for direct systems work and low-friction interop.
The OO API exists for typed composition and long-lived application structure.
Neither exists only as a thin wrapper around the other.

## Design Priorities

King optimizes for:

- predictable ownership and teardown
- native protocol semantics instead of generic adapter layers
- typed error boundaries
- config-driven behavior
- minimal impedance between PHP code and native transport state
- operator control over policy and security
- compatibility with serious production environments

## Architecture

At a high level, the target architecture is:

```text
PHP Userland
  -> procedural functions and OO classes

PHP Extension Surface
  -> arginfo, object handlers, resource handlers, exception hierarchy

Native Subsystem Kernels
  -> client, server, semantic DNS, IIBIN, MCP, object store, telemetry, etc.

Configuration and Lifecycle Layer
  -> defaults, ini, config snapshot, runtime policy, shutdown semantics

External Backends
  -> quiche, OpenSSL, libcurl, kernel networking facilities
```

The important boundary is this:
King is not supposed to look like "PHP calling random native helpers".
It is supposed to look like one native system with a PHP-facing control surface.

## Repository Map

- `extension/`
  Native extension sources, canonical project headers, build scripts, and PHPT coverage.
- `extension/include/`
  Canonical home of project-owned C headers; generated `extension/config.h` is the build-time exception.
- [`stubs/`](stubs/)
  Public PHP signature surface and IDE-facing type stubs.
- [`EPIC.md`](EPIC.md)
  Stable charter and release bar.
- [`PROJECT_ASSESSMENT.md`](PROJECT_ASSESSMENT.md)
  Verified current implementation reach.
- [`ISSUES.md`](ISSUES.md)
  Single moving roadmap and execution queue.
- [`READYNESS_TRACKER.md`](READYNESS_TRACKER.md)
  Long-form completion tracker derived from the verified tree; not the active queue.
- [`CONTRIBUTE.md`](CONTRIBUTE.md)
  Contribution rules and development expectations.
- `documentation/`
  Product documentation, reference manuals, configuration indexes, and example guides.
- `benchmarks/`
  Performance-oriented fixtures and measurements.
- `demo/`
  Example applications and integration exercises.
- `infra/`
  Environment and multi-version support assets.
- `libcurl/`
  Vendored libcurl source used for headers and integration work.
- `quiche/`
  Vendored QUIC/HTTP/3 backend source.

## Documentation Boundaries

This file is the root product-level description.
Other documents serve different purposes:

- [`README.md`](README.md)
  Root target-system description.
- [`EPIC.md`](EPIC.md)
  Stable charter and release-level exit criteria.
- [`PROJECT_ASSESSMENT.md`](PROJECT_ASSESSMENT.md)
  Verified current implementation state and reach.
- [`ISSUES.md`](ISSUES.md)
  Single moving roadmap and open execution queue.
- [`READYNESS_TRACKER.md`](READYNESS_TRACKER.md)
  Long-form completion tracker and broad closure reference.
- [`CONTRIBUTE.md`](CONTRIBUTE.md)
  Contribution and workflow rules.
- [`stubs/king.php`](stubs/king.php)
  Public signature and type surface.

If a statement is about the target system and should remain true over time, it
belongs here. If a statement is about current reach, current gaps, test counts,
or migration sequencing, it does not.

## Documentation

The handbook lives under [`documentation/`](documentation/README.md).
Use it in this order:

### Core Manuals

- [`documentation/README.md`](documentation/README.md)
  Master handbook index with reading paths for beginners, operators, engineers, and product readers.
- [`documentation/getting-started.md`](documentation/getting-started.md)
  First concepts, first programs, and the fastest way into the platform.
- [`documentation/glossary.md`](documentation/glossary.md)
  Plain-English definitions for recurring terms such as object, artifact, blob, edge, hotset, checkpoint, and rehydration.
- [`documentation/platform-model.md`](documentation/platform-model.md)
  Runtime model, lifecycle, subsystem boundaries, and deployment shape.
- [`documentation/solution-blueprints.md`](documentation/solution-blueprints.md)
  Concrete starting paths from one-node deployment to a full chat and video platform.
- [`documentation/http-clients-and-streams.md`](documentation/http-clients-and-streams.md)
  HTTP requests, sessions, responses, streaming, and protocol choice.
- [`documentation/quic-and-tls.md`](documentation/quic-and-tls.md)
  QUIC transport, TLS policy, tickets, and handshake model.
- [`documentation/websocket.md`](documentation/websocket.md)
  WebSocket concepts, client lifecycle, server upgrade, ping/pong, and close handling.
- [`documentation/server-runtime.md`](documentation/server-runtime.md)
  Listener model, server dispatch, upgrade flow, admin API, and TLS reload.
- [`documentation/mcp.md`](documentation/mcp.md)
  Model Context Protocol runtime, requests, transfers, deadlines, and peers.
- [`documentation/iibin.md`](documentation/iibin.md)
  IIBIN schemas, binary encoding, hydration, and compatibility.
- [`documentation/object-store-and-cdn.md`](documentation/object-store-and-cdn.md)
  Object identity, metadata, redundancy, edge distribution, hotset promotion, and recovery.
- [`documentation/semantic-dns.md`](documentation/semantic-dns.md)
  Smart DNS, Semantic-DNS registration, routing, and topology.
- [`documentation/telemetry.md`](documentation/telemetry.md)
  Metrics, spans, logs, flush, and collector integration.
- [`documentation/autoscaling.md`](documentation/autoscaling.md)
  Node lifecycle, budget gates, provider control, and readiness.
- [`documentation/pipeline-orchestrator.md`](documentation/pipeline-orchestrator.md)
  Tool registry, run state, workers, cancellation, and remote backends.
- [`documentation/ssh-over-quic.md`](documentation/ssh-over-quic.md)
  SSH gateway concepts, target mapping, and policy model.
- [`documentation/router-and-load-balancer.md`](documentation/router-and-load-balancer.md)
  Router mode, backend discovery, and forwarding policy.
- [`documentation/advanced-subsystems.md`](documentation/advanced-subsystems.md)
  Semantic geometry, smart contracts, compute, and state subsystems.
- [`documentation/configuration-handbook.md`](documentation/configuration-handbook.md)
  Reader-friendly guide to runtime and deployment configuration.
- [`documentation/operations-and-release.md`](documentation/operations-and-release.md)
  Build, package, install, verify, and release workflows.

### API Reference

- [`documentation/procedural-api.md`](documentation/procedural-api.md)
  Complete procedural function index with subsystem grouping.
- [`documentation/object-api.md`](documentation/object-api.md)
  Complete OO class, method, and exception index.
- [`stubs/king.php`](stubs/king.php)
  Canonical PHP signatures and IDE-facing type information.

### Configuration Reference

- [`documentation/configuration-handbook.md`](documentation/configuration-handbook.md)
  Explanatory configuration guide with naming rules and operating patterns.
- [`documentation/runtime-configuration.md`](documentation/runtime-configuration.md)
  Runtime override keys for `king_new_config()` and `King\Config`.
- [`documentation/system-ini-reference.md`](documentation/system-ini-reference.md)
  Deployment-level `php.ini` directives and subsystem grouping.

### Example Guides

- [`documentation/01-hetzner-self-bootstrapping-edge-cluster/README.md`](documentation/01-hetzner-self-bootstrapping-edge-cluster/README.md)
  Hetzner self-bootstrapping edge cluster from one ingress node to a worker fleet.
- [`documentation/02-realtime-control-plane-websocket-iibin-semantic-dns/README.md`](documentation/02-realtime-control-plane-websocket-iibin-semantic-dns/README.md)
  Realtime control plane with WebSocket, IIBIN, and Semantic-DNS routing.
- [`documentation/03-semantic-dns-routing-policies/README.md`](documentation/03-semantic-dns-routing-policies/README.md)
  Semantic-DNS registration, routing, and topology policy.
- [`documentation/04-http2-multiplexing-and-push/README.md`](documentation/04-http2-multiplexing-and-push/README.md)
  HTTP/2 multiplexing, push capture, and pooled session reuse.
- [`documentation/05-http3-roundtrip-and-reuse/README.md`](documentation/05-http3-roundtrip-and-reuse/README.md)
  HTTP/3 roundtrips, connection reuse, and ticket carry-forward.
- [`documentation/06-websocket-local-runtime/README.md`](documentation/06-websocket-local-runtime/README.md)
  WebSocket client sessions, frames, heartbeats, and close handling.
- [`documentation/07-streaming-response-timeout-recovery/README.md`](documentation/07-streaming-response-timeout-recovery/README.md)
  Incremental reads, timeouts, aborts, and response-stream recovery.
- [`documentation/08-object-store-cdn-ha/README.md`](documentation/08-object-store-cdn-ha/README.md)
  Durable object lifecycles, edge warmup, restore, and invalidation.
- [`documentation/09-mcp-transfer-persistence/README.md`](documentation/09-mcp-transfer-persistence/README.md)
  MCP request, upload, download, and transfer persistence flows.
- [`documentation/10-iibin-object-hydration/README.md`](documentation/10-iibin-object-hydration/README.md)
  IIBIN schemas, enums, defaults, maps, oneofs, and hydration.
- [`documentation/11-pipeline-orchestrator-tools/README.md`](documentation/11-pipeline-orchestrator-tools/README.md)
  Tool registry, dispatch, remote execution, and run state.
- [`documentation/12-server-upgrade-and-early-hints/README.md`](documentation/12-server-upgrade-and-early-hints/README.md)
  Server listeners, upgrades, early hints, and stream ownership.
- [`documentation/13-admin-api-and-tls-reload/README.md`](documentation/13-admin-api-and-tls-reload/README.md)
  Admin listener boot, mTLS policy, and live TLS reload.
- [`documentation/14-config-policy-and-overrides/README.md`](documentation/14-config-policy-and-overrides/README.md)
  Runtime config policy, namespaces, and override discipline.
- [`documentation/15-cancel-token-across-clients/README.md`](documentation/15-cancel-token-across-clients/README.md)
  Cancellation across client requests, MCP, and pipeline execution.
- [`documentation/16-proto-wire-compatibility/README.md`](documentation/16-proto-wire-compatibility/README.md)
  IIBIN wire compatibility, packed fields, maps, and schema evolution.
- [`documentation/17-system-lifecycle-coordination/README.md`](documentation/17-system-lifecycle-coordination/README.md)
  System init, coordinated component lifecycle, restart, and shutdown.
- [`documentation/18-benchmark-baseline-compare/README.md`](documentation/18-benchmark-baseline-compare/README.md)
  Canonical benchmark execution, baseline comparison, and budget gates.
- [`documentation/19-release-package-verification/README.md`](documentation/19-release-package-verification/README.md)
  Reproducible package verification and release artifact validation.
- [`documentation/20-fuzz-and-stress-harnesses/README.md`](documentation/20-fuzz-and-stress-harnesses/README.md)
  Seeded fuzzing, churn suites, and long-running stress harnesses.
- [`documentation/global-chat-video-platform.md`](documentation/global-chat-video-platform.md)
  Full end-to-end collaboration platform from domain and DNS to OAuth, chat, video, storage, routing, and autoscaling.

## Build

To build the extension from source:

```bash
git clone --recurse-submodules https://github.com/Intelligent-Intern/king.git
cd king/extension
./scripts/build-extension.sh
```

For a fully runnable local release profile, including `libquiche.so` and
`quiche-server`, use:

```bash
cd extension
./scripts/build-profile.sh release
```

The build path above bootstraps the pinned QUIC dependency checkout recorded in
[`extension/scripts/quiche-bootstrap.lock`](extension/scripts/quiche-bootstrap.lock)
and normalizes the matching workspace lockfile before cargo is invoked. Do not
replace it with ad hoc local `quiche` clones or unlocked cargo retries.

The build entrypoint above is the repository build path.
Canonical release-install verification then runs through
`./scripts/package-release.sh`, `./scripts/install-package-matrix.sh`, and
`./scripts/container-smoke-matrix.sh`.
The supported host/runtime verification matrix spans PHP `8.1` through `8.5`.

Implementation maturity and subsystem completeness are intentionally documented
in [`PROJECT_ASSESSMENT.md`](PROJECT_ASSESSMENT.md) and [`ISSUES.md`](ISSUES.md),
not in this file.

## Contributing

See [CONTRIBUTE.md](CONTRIBUTE.md).

## License

MIT. See <https://opensource.org/licenses/MIT>.
