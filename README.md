# King PHP Extension
**Systems-grade networking and infrastructure primitives for PHP**

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](https://opensource.org/licenses/MIT)

> This README is intentionally stable.
> It describes the target system, not the moving implementation state.
> It is not a changelog, migration log, issue list, or verification report.
> Public API signatures live in [`stubs/king.php`](stubs/king.php).
> Strategic program structure lives in [`EPIC.md`](EPIC.md).
> Current implementation state lives in [`PROJECT_ASSESSMENT.md`](PROJECT_ASSESSMENT.md).
> Active repo-local execution work lives in [`ISSUES.md`](ISSUES.md).

## What King Is

King is a PHP extension for building long-lived, high-throughput, protocol-heavy
systems directly from PHP without pushing the critical path out into a sidecar,
gateway, or FFI layer.

The target system combines transport, control-plane, data-plane, and operational
primitives in one native runtime:

- QUIC, HTTP/1, HTTP/2, HTTP/3, TLS, streaming, cancellation, and upgrade flows
- client and server APIs over explicit session and stream state
- WebSocket and WebTransport-class realtime communication
- Semantic DNS for service discovery and routing
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

- Semantic DNS service registration
- topology awareness
- route selection
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

The stable mental model is:

- `King\Config` defines transport, protocol, security, and subsystem policy.
- `King\Session` represents a live native runtime context.
- `King\Stream` represents one unit of protocol work inside a session.
- `King\Response` represents structured receive state for request flows.
- `King\Client\*` and `King\Server\*` expose higher-level protocol roles.
- `King\MCP`, `King\IIBIN`, and `King\WebSocket\Connection` expose
  subsystem-specific runtime surfaces.

The procedural API exists for direct systems work and low-friction interop.
The OO API exists for typed composition and long-lived application structure.
Neither is meant to be a toy wrapper around the other.

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
  Native extension sources, headers, build scripts, and PHPT coverage.
- [`stubs/`](stubs/)
  Public PHP signature surface and IDE-facing type stubs.
- [`EPIC.md`](EPIC.md)
  Strategic delivery map.
- [`PROJECT_ASSESSMENT.md`](PROJECT_ASSESSMENT.md)
  Verified current implementation reach.
- [`ISSUES.md`](ISSUES.md)
  Active repo-local execution queue.
- [`CONTRIBUTE.md`](CONTRIBUTE.md)
  Contribution rules and development expectations.
- `benchmarks/`
  Performance-oriented fixtures and measurements.
- `demo/`
  Example applications and integration demos.
- `infra/`
  Environment and multi-version support assets.
- `libcurl/`
  Vendored libcurl source used for headers and integration work.
- `quiche/`
  Vendored QUIC/HTTP/3 backend source.

## Documentation Boundaries

This file is the permanent product-level description.
Other documents serve different purposes:

- [`README.md`](README.md)
  Permanent target-system description.
- [`EPIC.md`](EPIC.md)
  Strategic delivery decomposition and ordering.
- [`PROJECT_ASSESSMENT.md`](PROJECT_ASSESSMENT.md)
  Verified current implementation state and reach.
- [`ISSUES.md`](ISSUES.md)
  Open repo-local execution queue.
- [`CONTRIBUTE.md`](CONTRIBUTE.md)
  Contribution and workflow rules.
- [`stubs/king.php`](stubs/king.php)
  Public signature and type surface.

If a statement is about the target system and should remain true over time, it
belongs here. If a statement is about current reach, current gaps, test counts,
or migration sequencing, it does not.

## Build

To build the extension from source:

```bash
git submodule update --init --recursive
cd extension
./scripts/build-skeleton.sh
```

The build entrypoint above is the repository build path.
Implementation maturity and subsystem completeness are intentionally documented
in [`PROJECT_ASSESSMENT.md`](PROJECT_ASSESSMENT.md) and [`ISSUES.md`](ISSUES.md),
not in this file.

## Contributing

See [CONTRIBUTE.md](CONTRIBUTE.md).

## License

MIT. See <https://opensource.org/licenses/MIT>.
