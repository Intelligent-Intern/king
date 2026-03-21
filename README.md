# King PHP Extension
**High-Performance QUIC/HTTP3 Networking for PHP**

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

---

## Overview

The King PHP extension targets high-performance QUIC/HTTP3 networking, binary serialization, distributed discovery, object storage, telemetry, and related infrastructure primitives for PHP applications.

Current repository state: the default build is a **tested skeleton build**. It
already exposes real local runtimes for Config, a client-session runtime with
local cancel state plus first transport-backed cancel contracts, a real non-blocking UDP transport substrate, a local client
TLS runtime with session-ticket import/export and a shared ticket ring, a
real local HTTP/1 client path over native TCP sockets with chunked decoding,
optional redirect following, and request-scoped same-origin keep-alive reuse,
plus an opt-in self-delimited `response_stream` bridge consumed via
`king_receive_response()`, plus a first `King\Session` / `King\Stream`
response bridge that replays `Session::sendRequest()` /
`Stream::receiveResponse()` onto that same live HTTP/1 `King\Response` kernel
with in-flight `CancelToken` aborts on that replay transport,
plus a manual `king_client_early_hints_process()` /
`king_client_early_hints_get_pending()` request-context surface for parsed
HTTP 103-style `Link` hints, plus a local MCP connection/transfer slice where
`king_mcp_upload_from_stream()` / `king_mcp_download_to_stream()` and
`King\MCP::uploadFromStream()` / `downloadToStream()` move real PHP stream
bytes through a per-connection in-memory transfer store while unary
`king_mcp_request()` / `King\MCP::request()` remain explicitly unavailable,
and a minimal real
HTTP/2 client request path for both cleartext h2c and HTTPS/ALPN via
runtime-loaded system libcurl with per-origin session-pool reuse plus a
same-origin `king_http2_request_send_multi()` multiplex leaf plus an opt-in
HTTP/2 `capture_push` surface with nested pushed responses and finer lifecycle
metadata, plus a live local HTTPS-over-QUIC HTTP/3 request path via
runtime-loaded `libquiche` directly, over the dispatcher, and through
`King\Client\Http3Client`, plus a local server-session capability/close/peer-cert
slice over the active `King\Session` runtime, plus a local config-aware
`king_server_listen()` dispatcher slice and local single-dispatch
`king_http1_server_listen()` / `king_http2_server_listen()` /
`king_http3_server_listen()` leaves over the same session runtime, plus a local
server-control slice for `king_server_on_cancel()`,
`king_server_send_early_hints()`, `king_server_upgrade_to_websocket()`, and
`king_admin_api_listen()` plus `king_server_reload_tls_config()` and
`king_server_init_telemetry()` over that same session runtime, while local
server listeners also expose config-backed `cors`/`telemetry` helper metadata
and deterministic wildcard CORS defaults, plus a validated local
`king_client_websocket_connect()` WebSocket connection-state surface for
absolute `ws://`/`wss://` URLs plus a local frame/lifecycle slice via
send/receive/ping/status/close over the same `King\WebSocket` state, alongside the
Proto/IIBIN subset, Semantic DNS,
Object Store/CDN, and several system/telemetry snapshots. It still does
**not** yet provide a full QUIC/HTTP3 product stack, HTTP/3 server/runtime
pooling, a general HTTP/2 `response_stream`/receive bridge, or a full
WebSocket handshake/backend stack.

## Target Features

The table below describes the intended product surface, not the current default
Skeleton-Build in `extension/config.m4`.

| Area                       | Feature                                                     | Status |
| -------------------------- | ----------------------------------------------------------- | :----: |
| **QUIC Transport Layer** | Full Client & Server Implementation (QUIC v1, RFC 9000)     |   ✅   |
|                            | Configurable "Happy Eyeballs" (RFC 8305) for IPv4/IPv6      |   ✅   |
|                            | Transparent Connection Migration                            |   ✅   |
|                            | Zero-RTT Session Resumption (SHM Ticket Cache)              |   ✅   |
| **Cluster Supervisor** | C-Native Multi-Process Supervisor (`fork`-based)            |   ✅   |
|                            | Automatic Worker Restart & Health Monitoring                |   ✅   |
|                            | CPU Affinity, Scheduling Policies, Resource Limits          |   ✅   |
| **MCP Layer** | Client & Server for Model Context Protocol                  |   ✅   |
|                            | Unary (Request-Response) and Streaming RPCs                 |   ✅   |
| **IIBIN Serialization**| Protobuf-inspired C-Native Binary Serialization            |   ✅   |
|                            | Schema & Enum Definition API (`IIBIN::defineSchema`)        |   ✅   |
|                            | High-Performance Encode/Decode of PHP Arrays/Objects        |   ✅   |
| **Pipeline Orchestrator** | C-Native Workflow Engine                                    |   ✅   |
|                            | Declarative, Multi-Step Pipeline Definition                 |   ✅   |
|                            | Conditional Logic & Parallel Step Execution Hooks           |   ✅   |
| **WebSocket Layer** | WebSocket over HTTP/3 (RFC 9220)                            |   ✅   |
|                            | PHP Stream Wrapper for WebSocket Connections                |   ✅   |
| **Performance** | Optional AF_XDP / Zero-Copy Path                            |   ✅   |
|                            | Optional Busy-Polling for Ultra-Low Latency                 |   ✅   |
| **Compatibility** | Linux (glibc/musl), macOS, WSL2                             |   ✅   |
|                            | PHP 8.1 - 8.4 (with version-specific optimizations)        |   ✅   |

## Installation

### Prerequisites

- Git submodules initialized (`git submodule update --init --recursive`)
- PHP 8.1 or higher with development headers
- `phpize`
- C compiler and `make`
- Rust/Cargo for the vendored `quiche` build used by the active HTTP/3 path
- For the active HTTP/2 client paths at runtime: a system `libcurl` with HTTP/2 support

### Current Skeleton Build

The active repository build path is the Skeleton-Build in `extension/`.
`./scripts/build-skeleton.sh` now builds the vendored `libquiche` runtime and
`quiche-server` fixture alongside the extension, while the extension itself
still keeps HTTP/3 on a runtime-loaded `libquiche` path instead of a fixed
link. The active HTTP/1 path is dependency-free and does not use `libcurl`;
the active HTTP/2 h2c/HTTPS path consumes vendored `libcurl` headers from the
submodule and loads a system `libcurl` with HTTP/2 support at runtime; the
prepared `./configure --enable-king --with-king-quiche=/path` path remains the
future non-skeleton wiring hook.

### Build from Source

```bash
git submodule update --init --recursive

# Configure and build the current skeleton extension
cd extension
./scripts/build-skeleton.sh
```

Direct equivalent:

```bash
cd extension
phpize
./configure --enable-king
make -j"$(nproc)"
```

Optional prepared path for future non-skeleton wiring:

```bash
cd extension
phpize
./configure --enable-king --with-king-quiche=/abs/path/to/quiche
make -j"$(nproc)"
```

See also: [`docs/build_workflow.md`](../docs/build_workflow.md)

### Verify Installation

```bash
php -d extension=/abs/path/to/king/extension/modules/king.so -r \
  'var_export(["version" => king_version(), "health" => king_health()]);'
```

### Audit Skeleton Surface

```bash
cd extension
./scripts/audit-skeleton-surface.sh
```

## Quick Start

The current skeleton build exposes both the procedural resource surface and
live OO wrappers for `King\Config`, `King\Session`, `King\Stream`, `King\Response`,
`King\Client\HttpClient` including `Http3Client`, and
`King\WebSocket\Connection`.

### Procedural Connection

```php
<?php
$cfg = king_new_config([
    'quic.cc_algorithm' => 'bbr',
    'tls.verify_peer' => true,
    'http2.max_concurrent_streams' => 32,
    'storage.enable' => true,
    'otel.service_name' => 'king-demo',
]);

$session = king_connect('example.com', 443, $cfg);
if ($session === false) {
    throw new RuntimeException(king_get_last_error() ?: 'king_connect() failed');
}

var_dump(get_resource_type($cfg));     // King\Config
var_dump(get_resource_type($session)); // King\Session
$stats = king_get_stats($session);
var_dump($stats['config_binding']);            // resource
var_dump($stats['config_quic_cc_algorithm']); // bbr
king_poll($session, 5);
king_cancel_stream(3, 'read', $session);
king_close($session);
?>
```

### Procedural Introspection

```php
<?php
var_dump(king_object_store_list());
var_dump(king_proto_get_defined_schemas());
var_dump(king_proto_get_defined_enums());
var_dump(king_system_get_metrics());
?>
```

### Procedural HTTP Request

```php
<?php
$response = king_client_send_request(
    'http://127.0.0.1:8080/health',
    'GET',
    ['Accept' => 'application/json'],
    null,
    ['timeout_ms' => 2000]
);

var_dump($response['status']);
var_dump($response['protocol']);          // http/1.1
var_dump($response['transport_backend']); // tcp_socket

$responseH2 = king_client_send_request(
    'http://127.0.0.1:8080/health',
    'GET',
    ['Accept' => 'application/json'],
    null,
    ['timeout_ms' => 2000, 'preferred_protocol' => 'http2']
);

var_dump($responseH2['protocol']);          // http/2
var_dump($responseH2['transport_backend']); // libcurl_h2c

$responseH3 = king_client_send_request(
    'https://localhost:4443/health',
    'GET',
    ['Accept' => 'application/json'],
    null,
    ['timeout_ms' => 2000, 'preferred_protocol' => 'http3']
);

var_dump($responseH3['protocol']);          // http/3
var_dump($responseH3['transport_backend']); // quiche_h3
?>
```

## Configuration

The King extension supports extensive configuration through php.ini settings with the `king.` prefix:

```ini
; Core settings
king.workers = 0                              ; Auto-detect CPU cores
king.security_allow_config_override = 1       ; Allow runtime config override

; QUIC transport
king.quic.max_idle_timeout_ms = 30000         ; Connection timeout
king.quic.cc_algorithm = "cubic"              ; Congestion control

; TLS settings
king.tls.verify_peer = true                   ; Verify certificates
king.tls.session_cache = true                 ; Enable session caching

; Performance tuning
king.performance.busy_poll_duration_us = 0    ; Busy polling
king.performance.enable_zero_copy = false     ; Zero-copy I/O
```

## Architecture

King is built with a layered architecture:

1. **C-Native Core**: High-performance C implementation with Rust QUIC transport
2. **Configuration System**: Comprehensive php.ini integration with runtime overrides
3. **PHP API Layer**: Procedural functions plus live OO wrappers over the same native kernels
4. **Validation Layer**: Type-safe configuration parameter validation
5. **Build System**: Automated build with dependency management

## API Reference

### Runtime Resources / Wrappers

- `King\Config` - resource returned by `king_new_config()`
- `King\Session` - resource returned by `king_connect()`, also carrying the active local server-session capability and close metadata
- `King\Stream` - object-backed local client stream wrapper returned by `King\Session::sendRequest()`, now replaying `receiveResponse()` onto the active HTTP/1 `response_stream` / `King\Response` bridge
- `King\Response` - object-backed HTTP response wrapper used by both the active dispatcher clients and the `King\Stream::receiveResponse()` bridge
- `King\MCP` - object-backed wrapper over the active local MCP connection and transfer state used by the procedural MCP leaves
- `King\WebSocket` - resource returned by `king_client_websocket_connect()` and `king_server_upgrade_to_websocket()` for the active local WebSocket connect/upgrade plus frame/lifecycle slice
- `King\WebSocket\Connection` - object-backed client wrapper over the same local WebSocket state used by the procedural WebSocket leaves
- `King\Client\Http1Client` / `Http2Client` / `Http3Client` - OO wrappers over the active dispatcher/runtime surface

### Core Functions

- `king_connect()` - Create a `King\Session` skeleton handle backed by a real non-blocking UDP socket
- `king_new_config()` - Create a config resource with namespaced overrides under `quic.`, `tls.`, `http2.`, `tcp.`, `autoscale.`, `mcp.`, `orchestrator.`, `geometry.`, `smartcontract.`, `ssh.`, `storage.`, `cdn.`, `dns.`, and `otel.`
- `king_poll()` - Drive the active UDP socket with `poll(2)`, update counters, and drain received datagrams into the local snapshot
- `king_send_request()` - Legacy procedural alias for the active one-shot client dispatcher; `response_stream => true` can return a live single-use HTTP/1 request context instead of a final response array
- `king_client_send_request()` - Active request dispatcher that routes real traffic onto the local HTTP/1 runtime by default, can force the local HTTP/2 runtime via `preferred_protocol => 'http2'` for both `http://` h2c and `https://` ALPN-backed requests, can force the local HTTP/3 runtime via `preferred_protocol => 'http3'` for `https://` URLs, can opt into HTTP/2 push capture with `capture_push => true`, and can opt into the HTTP/1 single-use `response_stream` contract
- `king_http1_request_send()` - Live HTTP/1 one-shot request path over a native TCP socket for absolute `http://` URLs, including chunked response decoding, optional redirect following, request-scoped same-origin keep-alive reuse, and an opt-in single-use `response_stream` request-context mode for self-delimited responses
- `king_http2_request_send()` - Minimal live HTTP/2 request path via runtime-loaded system `libcurl`, using cleartext h2c for `http://` URLs and HTTPS/ALPN for `https://` URLs, with per-origin session-pool reuse, lifecycle metadata (`response_complete`, `body_bytes`, `header_bytes`, `stream_kind`) and optional nested pushed responses via `capture_push => true`
- `king_http2_request_send_multi()` - Same-origin HTTP/2 multiplex leaf that drives multiple request definitions concurrently on one active libcurl-backed h2 session, returns normalized response arrays in input order, and can attach per-request pushed responses via `capture_push => true`
- `king_http3_request_send()` - Live one-shot HTTP/3 client path over runtime-loaded `libquiche` for absolute `https://` URLs, with normalized response metadata and TLS defaults sourced from the active config/runtime snapshot
- `king_mcp_connect()` - Create the active local MCP connection-state resource with host, port, optional `King\Config`, explicit closed/open lifecycle, and a per-connection transfer store
- `king_mcp_request()` - Validate the active local MCP request path and fail honestly with the stable unavailable protocol error until a real MCP unary transport backend exists
- `king_mcp_upload_from_stream()` - Drain a source PHP stream into the active local MCP transfer store under `(service, method, stream_identifier)`
- `king_mcp_download_to_stream()` - Resolve `request_payload` as the local MCP transfer identifier and write the stored bytes into the destination PHP stream
- `king_mcp_close()` - Close the active local MCP connection state while keeping post-close validation deterministic
- `king_client_websocket_connect()` - Active local WebSocket connect leaf that validates absolute `ws://`/`wss://` URLs, snapshots optional headers plus `connection_config`/size/timeout options into a `King\WebSocket` resource, and shares that same state with `King\WebSocket\Connection`
- `king_client_websocket_send()` / `king_websocket_send()` - Queue local text or binary frames on an active `King\WebSocket` resource or `King\WebSocket\Connection` object with payload-size validation
- `king_client_websocket_receive()` - Drain the next locally queued WebSocket payload from an active `King\WebSocket` resource or `King\WebSocket\Connection`, return `""` while the connection remains open and no queued frame is available, and fail cleanly after close
- `king_client_websocket_ping()` - Record a validated local ping payload on an active `King\WebSocket` resource or `King\WebSocket\Connection`
- `king_client_websocket_get_status()` - Return the current local WebSocket state (`open`, `closed`, etc.) as a numeric status value for an active resource or object
- `king_client_websocket_close()` - Close the local `King\WebSocket` state immediately with validated close metadata while preserving already queued frames for draining
- `king_cancel_stream()` - Record local per-session stream-cancel state in the active client skeleton runtime
- `king_client_stream_cancel()` - Alias for the same local cancel runtime
- `king_set_ca_file()` - Validate and store the default CA file path for the active skeleton TLS runtime
- `king_set_client_cert()` - Validate and store default client certificate and key paths for the active skeleton TLS runtime
- `king_export_session_ticket()` - Export the current session-local ticket blob from the active client runtime
- `king_import_session_ticket()` - Import a session ticket into the active client runtime and publish it into the shared ticket ring
- `king_close()` - Mark skeleton sessions closed
- `king_http1_server_listen()` - Active local HTTP/1 single-dispatch server leaf that validates host, port, config, and callback, materializes one normalized HTTP/1 request snapshot over the active `King\Session` runtime, invokes the handler once, validates the returned response array, and then closes the local session snapshot
- `king_http2_server_listen()` - Active local HTTP/2 single-dispatch server leaf over the same `King\Session` runtime, exposing normalized pseudo-header-style request metadata plus `h2` ALPN/transport snapshot data and the same one-shot response validation contract
- `king_http3_server_listen()` - Active local HTTP/3 single-dispatch server leaf over the same `King\Session` runtime, exposing normalized pseudo-header-style request metadata plus `h3` ALPN/transport snapshot data and the same one-shot response validation contract; broad QUIC listener, accept loop, and streaming server state remain outside this local leaf
- `king_server_listen()` - Active local server-index dispatcher that resolves `null`, inline override arrays, and `King\Config` snapshots, chooses HTTP/3 when TCP is disabled, otherwise HTTP/2 when `http2.enable` is active, otherwise HTTP/1, and then forwards to the selected listener leaf; HTTP/1, HTTP/2, and HTTP/3 are all active today as local single-dispatch leaves
- `king_server_on_cancel()` - Register a local per-stream cancel callback on an active server `King\Session`; the handler is invoked when the same local stream is later cancelled via `king_cancel_stream()`
- `king_server_send_early_hints()` - Validate and normalize a local server-side Early Hints batch onto an active `King\Session`, storing the last normalized hint list plus counters in `king_get_stats()`
- `king_server_upgrade_to_websocket()` - Materialize a local `King\WebSocket` resource for one server-side stream, derive `ws://` or `wss://` metadata from the active listener/session snapshot, and reject duplicate or locally cancelled upgrades
- `king_admin_api_listen()` - Validate a local admin-listener config from `null`, inline arrays, or `King\Config`, require explicit enablement plus readable mTLS material, and record the last admin bind/auth snapshot plus reload counters on an active `King\Session`
- `king_server_reload_tls_config()` - Validate replacement certificate/key paths for an active server `King\Session`, require the configured `tls_ticket_key_file` to be readable when set, and record the last local server-TLS reload snapshot plus apply/reload counters in `king_get_stats()`
- `king_server_init_telemetry()` - Validate a local server-telemetry snapshot from `null`, inline arrays, or `King\Config`, activate telemetry state on an open `King\Session`, and record the last locally instrumented request protocol/status plus exporter/service metadata in `king_get_stats()`
- `king_session_get_peer_cert_subject()` - Read the current local server-session peer certificate subject on a `King\Session` resource or object when the presented capability matches the current process/thread
- `king_session_close_server_initiated()` - Record a server-initiated close on a `King\Session` resource or object, close the live transport socket, and rotate the session capability on success
- `king_version()` - Get extension version
- `king_health()` - Extension/build health summary
- `king_get_stats()` - Stable per-session skeleton snapshot, including transport socket, local/peer endpoint, datagram, poll, cancel, TLS-default, session-ticket, local server-session capability/close counters, and server-control metadata for cancel handlers, Early Hints, and the last local WebSocket upgrade
- `king_receive_response()` - Live legacy receive bridge that turns a single-use HTTP/1 `response_stream` request context into a `King\Response`; the current slice is limited to self-delimited responses and now also works after HTTP/1 redirect-following resolves onto a final response
- `king_client_early_hints_process()` - Parse a user-supplied HTTP 103-style header array onto a live HTTP/1 `King\HttpRequestContext` and append parsed `Link` hints to that context's pending list
- `king_client_early_hints_get_pending()` - Return the parsed pending Early Hints stored on a live HTTP/1 `King\HttpRequestContext`
- `king_object_store_init()` - Initialize local skeleton object-store/CDN runtime settings
- `king_object_store_get()` - Object-store lookup
- `king_object_store_delete()` - Object-store delete
- `king_object_store_list()` - Stable object-store inventory snapshot
- `king_object_store_optimize()` - Stable local maintenance summary for the skeleton registry
- `king_cdn_cache_object()` - Cache an existing local object-store entry in the skeleton CDN cache
- `king_cdn_invalidate_cache()` - Invalidate one or all entries from the local skeleton CDN cache
- `king_proto_is_defined()` - Stable registry lookup against the skeleton runtime
- `king_proto_is_schema_defined()` - Stable schema lookup against the skeleton runtime
- `king_proto_is_enum_defined()` - Stable enum lookup against the skeleton runtime
- `king_proto_get_defined_schemas()` - Stable schema inventory snapshot
- `king_proto_get_defined_enums()` - Stable enum inventory snapshot
- `king_system_get_metrics()` - Stable system metrics snapshot
- `king_system_get_performance_report()` - Small system performance snapshot getter
- `king_system_get_component_info()` - Small per-component skeleton descriptor

### Skeleton Introspection

The current skeleton build also exposes a few safe introspection getters that
do not require native transport/runtime state.

Shared error accessors:

- `king_get_last_error()`
- `king_client_websocket_get_last_error()` covers the active local WebSocket runtime
- `king_mcp_get_error()`

These currently read from the same shared skeleton error buffer. The default
"no error" value is an empty string.

Config-backed status getters:

- `king_telemetry_get_metrics()`
- `king_telemetry_flush()` currently returns a stable flush report with zero exported spans, metrics, and logs
- `king_telemetry_get_status()`
- `king_telemetry_get_trace_context()` currently returns `null`
- `king_telemetry_inject_context()` currently returns the input headers unchanged
- `king_telemetry_extract_context()` currently returns `false`
- `king_object_store_get_stats()` now exposes config-backed object-store/CDN status plus live local registry accounting via `local_registry_initialized`, `object_count`, `stored_bytes`, `latest_object_at`, and local CDN cache accounting via `local_cache_initialized`, `cached_object_count`, `cached_bytes`, and `latest_cached_at`
- `king_object_store_init()` now validates and stores a local runtime config overlay for the skeleton object-store/CDN layer; `king_object_store_get_stats()` exposes it via `runtime_*` fields and `king_object_store_put()` honors `runtime_max_storage_size_bytes`
- `king_object_store_put()` writes into the local skeleton object-store registry
- `king_object_store_get()` now returns the stored payload for local registry hits and `false` on miss
- `king_object_store_delete()` now returns `true` on local registry hits and `false` on miss
- `king_object_store_list()` now exposes the live local object inventory
- `king_object_store_optimize()` now returns a local maintenance summary with `mode`, `scanned_objects`, `total_size_bytes`, `orphaned_entries_removed`, `bytes_reclaimed`, and `optimized_at`
- `king_cdn_cache_object()` now caches existing local object-store entries into a separate local CDN cache registry and returns `false` for object-store misses; it accepts optional `ttl_sec`
- `king_cdn_invalidate_cache()` now removes one cached object by `object_id` or flushes the full local CDN cache when called without an ID, returning the number of removed entries
- `king_cdn_get_edge_nodes()`
- `king_autoscaling_get_status()`
- `king_autoscaling_get_metrics()`
- `king_semantic_dns_init()` now captures a validated local Semantic-DNS config/runtime snapshot, including bind/port, TTL/discovery caps, semantic-mode and optional mother-node/routing hints
- `king_semantic_dns_start_server()` now activates the local Semantic-DNS server-state slice on top of that config and is idempotent; it is not yet a real UDP/TCP DNS listener
- `king_semantic_dns_register_service()` writes into the local skeleton service registry and validates the minimal service payload (`service_id`, `service_name`, `service_type`, `hostname`, `port`, plus optional status/load/attributes)
- `king_semantic_dns_register_mother_node()` writes into the local skeleton mother-node registry and validates the minimal payload (`node_id`, `hostname`, `port`, plus optional status, `managed_services_count`, and `trust_score`)
- `king_semantic_dns_update_service_status()` updates a registered service status and can optionally patch the live load counters (`current_load_percent`, `active_connections`, `total_requests`)
- `king_semantic_dns_get_service_topology()` now exposes the live local skeleton service and mother-node registries
- `king_semantic_dns_discover_service()` now returns routeable registered services for the requested `service_type`; without matching registrations it still falls back to the stable empty discovery snapshot with `services`, `service_type`, `discovered_at`, and `service_count`
- `king_semantic_dns_get_optimal_route()` now picks the best registered healthy/degraded service for the requested `service_name`; without a routeable match it still returns the stable no-route snapshot with `service_id`, `error`, and `routed_at`
- `king_proto_define_enum()` registers enum names plus minimal member validation for the skeleton lookup surface
- `king_proto_define_schema()` registers schema names and compiles persistent runtime metadata for the active skeleton lookup and runtime subset, now including floating-point and fixed-width primitives, nested message fields whose child schemas are already runtime-supported, repeated primitive/enum and repeated nested message fields, plus `map<key, scalar|enum|message>` fields whose supported key types are currently `string`, `bool`, and the 32-bit integer variants (`int32`, `uint32`, `sint32`, `fixed32`, `sfixed32`) while message values must already be runtime-supported; runtime-supported scalar/enum/message fields may also opt into `oneof => 'group'`; packable repeated numeric/enum fields may opt into packed encode with `packed => true`, and packed decode is accepted for those wire-compatible fields while message fields and maps remain unpacked; optional, non-repeated scalar/enum fields may also declare a decode-time `default`; map fields use `type => 'map<key,T>'`; `oneof` fields cannot be repeated, maps, required, or defaulted; enum values encode from integers or registered member-name strings and decode numerically
- `king_proto_is_defined()` reflects names declared through the active skeleton proto registry
- `king_proto_is_schema_defined()` reflects names declared through the active skeleton proto registry
- `king_proto_is_enum_defined()` reflects names declared through the active skeleton proto registry
- `king_proto_get_defined_schemas()` returns names declared through the active skeleton proto registry
- `king_proto_get_defined_enums()` returns names declared through the active skeleton proto registry
- `king_proto_encode()` throws `King\Exception` for unknown schemas and for unsupported registered schemas; registered zero-field schemas plus a minimal primitive and numeric enum subset, now including floating-point and fixed-width primitives, nested message fields whose child schemas are already runtime-supported, repeated primitive/enum plus repeated nested message fields, `map<key, scalar|enum|message>` fields over the supported key types, and `oneof` members over the currently runtime-supported scalar/enum/message field shapes, already encode, with packable repeated numeric/enum fields emitted unpacked by default or packed when the schema field opts in via `packed => true`; decode-time defaults are not auto-encoded; encode rejects payloads that set multiple fields from the same `oneof` group, rejects unexpected top-level and nested message fields against the compiled schema field-name cache, and rejects numeric/object-property keys that cannot map to public schema fields; enum values additionally accept registered enum member-name strings during encode
- `king_proto_decode()` throws `King\Exception` for unknown schemas and for unsupported registered schemas; registered zero-field schemas plus a minimal primitive and numeric enum subset, now including floating-point and fixed-width primitives, nested message fields whose child schemas are already runtime-supported, repeated primitive/enum plus repeated nested message fields, `map<key, scalar|enum|message>` fields over the supported key types, and `oneof` members over the currently runtime-supported scalar/enum/message field shapes, already decode, and packable repeated numeric/enum fields also accept packed payloads while `decode_as_object` now accepts `true` for recursive `stdClass`, a class string for top-level hydration, or an `array<string,string>` schema-to-class map for recursive message hydration; map fields themselves still decode as associative arrays, but map message values follow the same recursive object/class decode when requested; target classes must be concrete userland classes without constructors, and hydration failures surface as validation errors; when multiple members from the same `oneof` group appear on the wire, the last member wins; optional, non-repeated scalar/enum fields also apply their registered `default` when absent from the payload; omitted map-entry keys still decode through the scalar key default for the configured key kind; enum decode remains numeric
- `King\IIBIN` is now a real final internal static class whose `defineEnum()` / `defineSchema()` / `encode()` / `decode()` plus lookup and inventory methods delegate into the active `src/iibin` backend slice; `defineSchema()` now compiles persistent runtime schemas with cached field metadata and field-name validation caches before encode/decode consume them, `decode()` shares the same `decode_as_object=false|true|string|array` object/class hydration contract as the procedural API, and the procedural `king_proto_*` surface reaches the same backend through a thin `src/core/introspection/proto_api/*.inc` bridge
- `king_system_health_check()`
- `king_system_get_status()`
- `king_system_get_metrics()`
- `king_system_get_performance_report()` returns a small local snapshot with `performance_overview`, `component_performance`, `recommendations`, and `report_generated_at`
- `king_system_get_component_info()` accepts the archived component names (`config`, `client`, `server`, `semantic_dns`, `object_store`, `cdn`, `telemetry`, `autoscaling`, `mcp`, `iibin`, `pipeline_orchestrator`) and returns a small skeleton descriptor with `implementation`, `configuration`, and `info_generated_at`

## Development

### Building

```bash
cd extension
./scripts/build-skeleton.sh
```

### Testing

```bash
cd extension
./scripts/test-skeleton.sh
```

For the full direct workflow and cleanup notes, see
[`docs/build_workflow.md`](../docs/build_workflow.md).

## Performance

King is designed for high-performance applications:

- **Zero-copy I/O**: Optional zero-copy networking paths
- **Multi-core scaling**: Native process supervisor for CPU utilization
- **Connection migration**: Seamless network transitions
- **Session resumption**: 0-RTT handshakes with shared memory caching
- **Binary serialization**: C-native IIBIN format for minimal overhead

## Compatibility

- **PHP Versions**: 8.1, 8.2, 8.3, 8.4
- **Operating Systems**: Linux (glibc/musl), macOS, WSL2
- **Architectures**: x86_64, ARM64

## Contributing

Contributions are welcome! Please read [CONTRIBUTE.md](CONTRIBUTE.md) for development guidelines.

## License

MIT License - see [LICENSE](LICENSE) file for details.

## Support

- **Documentation**: See `/docs` directory
- **Examples**: See `/examples` directory
- **Issues**: GitHub Issues
- **Discussions**: GitHub Discussions
