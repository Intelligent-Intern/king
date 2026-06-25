# King Primitives

This documentation shows the public King primitives in practical terms without
release language. Each primitive follows the same structure:

- Function, example 1: compact example
- Function, example 2: extended example
- OO, example 1: compact example
- OO, example 2: extended example

If King does not export a native OO class for a primitive yet, the respective
file states that explicitly. In that case the OO examples are small userland
adapters around the real `king_*` functions.

## Table of Contents

- [Source Layout](source-layout.md)
- [Async and Awaitables](async-awaitables.md)
- [Config, Session, TLS, and QUIC](config-session-tls-quic.md)
- [HTTP/1](http1.md)
- [HTTP/2](http2.md)
- [HTTP/3](http3.md)
- [WebSocket](websocket.md)
- [Pipeline Orchestrator](pipeline-orchestrator.md)
- [XSLT 2.0/3.0 for E-Invoicing](xslt.md)
- [MCP](mcp.md)
- [IIBIN](iibin.md)
- [Object Store](object-store.md)
- [CDN](cdn.md)
- [Semantic DNS](semantic-dns.md)
- [Autoscaling](autoscaling.md)
- [Telemetry](telemetry.md)
- [System Runtime](system-runtime.md)
- [RTP](rtp.md)
- [DB Ingest](db-ingest.md)
- [Local Quantized Inference](inference.md)
- [OpenAI-Compatible Inference Router](openai-compatible-inference.md)
- [E-Invoicing, EDI, and B2B Commerce Platform](e-invoicing-commerce-platform.md)
- [E-Invoicing, EDI, and B2B Commerce Platform: OO Implementation](e-invoicing-commerce-platform-oo.md)

## Ground Rules

- Procedural APIs use the `king_*` naming scheme.
- Native OO APIs live under `King\...`, `King\Client\...`, or
  `King\WebSocket\...`.
- Async methods return `King\Awaitable` and are resolved with `king_await()`
  or `$awaitable->await()`.
- Errors should be handled through exceptions. `king_get_last_error()` exists
  for older integration points.
- Configuration flows through either `King\Config` or, for older functions,
  an array or `king_new_config()`.
