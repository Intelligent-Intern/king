# King Documentation

King documentation starts here. It explains what the platform does, how its
parts fit together, how the public API is organized, and how the operational
workflows are meant to be run.

The handbook is written for several kinds of readers at once. A new reader
needs the big ideas explained in plain English. An experienced engineer needs a
way to find the right subsystem quickly. An operator or release engineer needs
the hard reference material for configuration, lifecycle, verification, and
shipping.

The structure below is meant to support all three. The early chapters explain
the common language and the shape of the runtime. The middle chapters explain
the subsystems in depth. The reference chapters list the exact public surface.
The example guides show complete flows that tie several subsystems together in
one place.

If a technical word is unfamiliar, open the [Glossary](./glossary.md) first and
keep it nearby while reading. The glossary exists so the handbook can use
technical language without assuming every reader already lives inside that
language every day.

## How To Read The Handbook

If you are new to King, begin with [Getting Started](./getting-started.md).
Then read [Glossary](./glossary.md), [Platform Model](./platform-model.md), and
[Configuration Handbook](./configuration-handbook.md). That path teaches the
main runtime objects, the language used across the rest of the book, and the
shape of configuration before the subsystem chapters become more detailed.

If you already know why you are here, use the table of contents as a map. Each
part has a different job. The handbook chapters explain what a subsystem is,
why it exists, how it works, and how it is used. The reference chapters list
the public surface directly. The example guides show one complete operational
story at a time so the reader can see how the parts fit together in practice.

## Table Of Contents

### Part I: Foundations

The first part explains the shared language of the extension. Readers who skip
this part can still use the later chapters, but they will miss the vocabulary
that ties transport, storage, orchestration, lifecycle, and operations
together.

| Chapter | What it explains | When to read it |
| --- | --- | --- |
| [Getting Started](./getting-started.md) | What King is, what problems it solves, and what the first successful program looks like. | Read first if you are new to the extension. |
| [Glossary](./glossary.md) | Plain-language definitions for recurring technical terms. | Read when a term is unfamiliar, or once up front. |
| [Platform Model](./platform-model.md) | How sessions, streams, responses, config, storage, orchestration, telemetry, and operations fit together. | Read when you want the whole system picture. |
| [Configuration Handbook](./configuration-handbook.md) | How runtime config and deployment config work, how namespacing is used, and how changes are applied safely. | Read before tuning any subsystem. |
| [Solution Blueprints](./solution-blueprints.md) | Three concrete starting paths, from one-node service to a full chat and video platform. | Read when you want to pick a deployment shape quickly. |

### Part II: Networking And Realtime

The second part explains how King opens connections, keeps them alive, moves
requests and messages, and serves network traffic. These chapters are useful
for both client-side and server-side readers because the same runtime ideas
show up in both directions.

| Chapter | What it explains | When to read it |
| --- | --- | --- |
| [QUIC and TLS](./quic-and-tls.md) | QUIC transport, TLS trust, session tickets, stream thinking, and the settings that shape transport behavior. | Read before tuning HTTP/3 or session reuse. |
| [HTTP Clients and Streams](./http-clients-and-streams.md) | HTTP/1, HTTP/2, and HTTP/3 request flow, responses, streaming reads, cancellation, and reuse. | Read when you send outbound requests. |
| [WebSocket](./websocket.md) | Long-lived bidirectional channels, message frames, ping and pong, close behavior, and IIBIN over WebSocket. | Read when the traffic is a conversation rather than a one-shot request. |
| [Server Runtime](./server-runtime.md) | Listeners, upgrades, admin APIs, early hints, cancel hooks, and server-owned session state. | Read when King is receiving traffic instead of only sending it. |
| [SSH over QUIC](./ssh-over-quic.md) | How SSH traffic is carried through the platform and mapped to target systems. | Read when building remote access or control gateways. |

### Part III: Data, Control Plane, And Operations

The third part explains how King moves structured data, stores durable state,
coordinates work, discovers services, and runs control loops. This is the part
of the book where the extension stops looking like "a protocol library" and
starts looking like a complete runtime platform.

| Chapter | What it explains | When to read it |
| --- | --- | --- |
| [MCP](./mcp.md) | Remote peers, request flow, uploads, downloads, transfer identifiers, and connection lifecycle. | Read when building control-plane or model-context traffic. |
| [IIBIN](./iibin.md) | Binary schemas, enums, field numbers, compatibility, encode and decode flow, and object hydration. | Read when JSON is no longer enough. |
| [Object Store and CDN](./object-store-and-cdn.md) | Durable object storage, metadata, cache distribution, hot data, restore paths, and delivery flow. | Read when the application needs durable payloads or edge distribution. |
| [Flow PHP and ETL on King](./flow-php-etl.md) | The contract between King runtime services and userland dataflow or ETL layers, including current repo-local source and sink adapters plus the runtime guarantees they must preserve. | Read when building ETL, batch movement, or dataflow pipelines on top of King. |
| [Smart DNS and Semantic-DNS](./semantic-dns.md) | Service registration, route choice, health state, topology, and mother-node behavior. | Read when the system needs service discovery or smart routing. |
| [Telemetry](./telemetry.md) | Metrics, spans, logs, flush behavior, collector export, and degraded delivery semantics. | Read when you need visibility into runtime behavior. |
| [Autoscaling](./autoscaling.md) | Node lifecycle, provider control, readiness, budgets, rollback, and monitor loops. | Read when the platform must grow or shrink under load. |
| [Pipeline Orchestrator](./pipeline-orchestrator.md) | Tool registry, run state, worker models, cancellation, persistence, and remote peers. | Read when work must be coordinated instead of run inline. |
| [Router and Load Balancer](./router-and-load-balancer.md) | Request routing, backend selection, forwarding policy, and traffic distribution. | Read when one node must direct traffic toward others. |
| [Advanced Subsystems](./advanced-subsystems.md) | Smaller but still important subsystems that do not need a full standalone part of the handbook. | Read when you need the rest of the platform map. |
| [Operations and Release](./operations-and-release.md) | Build, verification, package, install, matrix checks, and release workflows. | Read when operating or shipping the extension. |

### Part IV: Reference

The reference section is for readers who already know the topic and want the
exact surface quickly. It is meant to be used together with the subsystem
chapters, not instead of them.

| Chapter | What it lists |
| --- | --- |
| [Procedural API Reference](./procedural-api.md) | Exported functions grouped by subsystem. |
| [Object API Reference](./object-api.md) | Public classes and methods. |
| [Runtime Configuration Reference](./runtime-configuration.md) | Keys passed to `king_new_config()` and `King\Config`. |
| [System INI Reference](./system-ini-reference.md) | Deployment-level `king.*` directives. |

### Part V: Example Guides

The example guides are focused walkthroughs. Each guide follows one complete
idea from start to finish so the reader can see how several parts of the
extension fit together in practice. They are especially useful after reading
the matching subsystem chapter because they show what the architecture looks
like when it becomes a real workflow.

| Guide | Topic |
| --- | --- |
| [`01-hetzner-self-bootstrapping-edge-cluster`](./01-hetzner-self-bootstrapping-edge-cluster/README.md) | Hetzner self-bootstrapping edge cluster: one node starts as ingress, load balancer, and worker, then grows a prepared worker fleet under load. |
| [`02-realtime-control-plane-websocket-iibin-semantic-dns`](./02-realtime-control-plane-websocket-iibin-semantic-dns/README.md) | Realtime control plane with WebSocket, IIBIN, and Semantic-DNS route selection. |
| [`03-semantic-dns-routing-policies`](./03-semantic-dns-routing-policies/README.md) | Service registration, topology, and route selection. |
| [`04-http2-multiplexing-and-push`](./04-http2-multiplexing-and-push/README.md) | HTTP/2 multiplexing, push capture, and pooled reuse. |
| [`05-http3-roundtrip-and-reuse`](./05-http3-roundtrip-and-reuse/README.md) | HTTP/3 request flow, transport reuse, and tickets. |
| [`06-websocket-local-runtime`](./06-websocket-local-runtime/README.md) | WebSocket connection lifecycle, frames, and close handling. |
| [`07-streaming-response-timeout-recovery`](./07-streaming-response-timeout-recovery/README.md) | Streaming reads, timeout handling, and recovery. |
| [`08-object-store-cdn-ha`](./08-object-store-cdn-ha/README.md) | Durable objects, cache warmup, restore, and invalidation. |
| [`09-mcp-transfer-persistence`](./09-mcp-transfer-persistence/README.md) | MCP request and transfer flows backed by storage. |
| [`10-iibin-object-hydration`](./10-iibin-object-hydration/README.md) | Schema-driven binary payloads and object hydration. |
| [`11-pipeline-orchestrator-tools`](./11-pipeline-orchestrator-tools/README.md) | Tool registry, dispatch, workers, and run state. |
| [`12-server-upgrade-and-early-hints`](./12-server-upgrade-and-early-hints/README.md) | Listener upgrade flow, early hints, and stream ownership. |
| [`13-admin-api-and-tls-reload`](./13-admin-api-and-tls-reload/README.md) | Admin listeners, mTLS policy, and live TLS reload. |
| [`14-config-policy-and-overrides`](./14-config-policy-and-overrides/README.md) | Configuration policy, namespaced overrides, and safe changes. |
| [`15-cancel-token-across-clients`](./15-cancel-token-across-clients/README.md) | Cancellation across requests, MCP, and orchestration. |
| [`16-proto-wire-compatibility`](./16-proto-wire-compatibility/README.md) | Binary compatibility and schema evolution on the wire. |
| [`17-system-lifecycle-coordination`](./17-system-lifecycle-coordination/README.md) | System init, restart, shutdown, and coordinated lifecycle control. |
| [`18-benchmark-baseline-compare`](./18-benchmark-baseline-compare/README.md) | Benchmark runs, baseline comparison, and repeatable measurement. |
| [`19-release-package-verification`](./19-release-package-verification/README.md) | Package verification and reproducible release validation. |
| [`20-fuzz-and-stress-harnesses`](./20-fuzz-and-stress-harnesses/README.md) | Fuzzing and stress coverage for high-risk surfaces. |
| [`global-chat-video-platform.md`](./global-chat-video-platform.md) | Full end-to-end collaboration platform from domain and DNS to OAuth, chat, video, object storage, routing, and autoscaling. |

## Suggested Reading Paths

There is no single required order, but a few reading paths work especially
well.

If you are learning the extension from scratch, read
[Getting Started](./getting-started.md), then the [Glossary](./glossary.md),
then [Platform Model](./platform-model.md), and then
[Configuration Handbook](./configuration-handbook.md), and then
[Solution Blueprints](./solution-blueprints.md).

If you care most about network protocols, continue with
[QUIC and TLS](./quic-and-tls.md),
[HTTP Clients and Streams](./http-clients-and-streams.md),
[WebSocket](./websocket.md), and [Server Runtime](./server-runtime.md).

If you care most about data movement and control-plane design, continue with
[IIBIN](./iibin.md),
[Object Store and CDN](./object-store-and-cdn.md),
[Flow PHP and ETL on King](./flow-php-etl.md),
[MCP](./mcp.md),
[Pipeline Orchestrator](./pipeline-orchestrator.md),
[Telemetry](./telemetry.md), and [Autoscaling](./autoscaling.md).

If you are reading for operations, release work, or incident response, read
[Platform Model](./platform-model.md), [Server Runtime](./server-runtime.md),
[Telemetry](./telemetry.md), [Autoscaling](./autoscaling.md),
[Operations and Release](./operations-and-release.md), and the example guides
on system lifecycle, benchmarks, release verification, and fuzz or stress
coverage.

If you want one complete deployment story instead of subsystem-by-subsystem
reading, open [Global Chat And Video Platform](./global-chat-video-platform.md).
