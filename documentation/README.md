# King v1 Documentation

This directory is for runnable King v1 example sets.
Each numbered folder is intended to contain:

- one focused `README.md`
- one or more PHP scripts
- a concrete subsystem story that is unusual enough to be worth copying into a real system

## Planned Example Set

| Folder | Theme | State |
| --- | --- | --- |
| `01-telemetry-driven-autoscaling` | Live telemetry driving the autoscaling controller | ready |
| `02-hetzner-controller-mock` | Honest Hetzner controller flow against a local mock API | planned |
| `03-semantic-dns-routing-policies` | Topology-aware service discovery and route selection | planned |
| `04-http2-multiplexing-and-push` | Multiplexed HTTP/2 and push capture | planned |
| `05-http3-roundtrip-and-reuse` | HTTP/3 request flow and session reuse | planned |
| `06-websocket-local-runtime` | Stateful local WebSocket connection and frame handling | planned |
| `07-streaming-response-timeout-recovery` | Streaming response reads, timeouts, and recovery | planned |
| `08-object-store-cdn-ha` | Object Store plus CDN cache distribution and invalidation | planned |
| `09-mcp-transfer-persistence` | MCP uploads/downloads backed by Object Store | planned |
| `10-iibin-object-hydration` | Binary schemas, defaults, and object hydration | planned |
| `11-pipeline-orchestrator-tools` | Tool registry and multi-step pipeline execution | planned |
| `12-server-upgrade-and-early-hints` | Server upgrade, cancel, and early-hints control flow | planned |
| `13-admin-api-and-tls-reload` | Admin API listener and live TLS reload paths | planned |
| `14-config-policy-and-overrides` | Config policy gates and namespaced overrides | planned |
| `15-cancel-token-across-clients` | Cancellation propagation across client runtimes | planned |
| `16-proto-wire-compatibility` | Schema evolution and wire-level compatibility edges | planned |
| `17-system-lifecycle-coordination` | Coordinated system init, restart, and shutdown | planned |
| `18-benchmark-baseline-compare` | Canonical benchmark runs and baseline comparisons | planned |
| `19-release-package-verification` | Reproducible packaging and extracted package verification | planned |
| `20-fuzz-and-stress-harnesses` | Seeded fuzz/stress harnesses for high-risk surfaces | planned |

Start with [`01-telemetry-driven-autoscaling`](./01-telemetry-driven-autoscaling/README.md).
