<?php
declare(strict_types=1);

/**
 * King PHP Extension Stubs
 *
 * IDE/type stubs mirroring the current procedural/resource skeleton API.
 * Keep in sync with C arginfo (generated stubs).
 *
 * @api
 * @version 1.0.0
 */

namespace {
    /**
     * Establish a local skeleton QUIC session handle backed by a real
     * non-blocking UDP socket and return a `King\Session` resource.
     * Accepts either an inline config array or a `King\Config` resource.
     * @param mixed $config
     * @return resource|false
     * @throws \King\NetworkException
     */
    function king_connect(string $host, int $port, mixed $config = null) {}

    /**
     * Close a low-level session handle.
     * The current skeleton build keeps the resource readable so stats can
     * still report a closed snapshot after shutdown.
     */
    function king_close($session): bool {}

    /**
     * Send a one-shot client request through the active skeleton dispatcher.
     * The current build routes real traffic only onto the active HTTP/1
     * runtime for absolute `http://` URLs and returns a normalized response
     * snapshot with `status`, `status_line`, `headers`, `body`, `protocol`,
     * `transport_backend`, and `effective_url`; chunked responses are
     * decoded, redirects can be followed via `follow_redirects` plus
     * `max_redirects`, and self-delimited same-origin HTTP/1.1 responses
     * may reuse a request-scoped keep-alive socket. With
     * `options['response_stream'] => true`, the live HTTP/1 path instead
     * returns a single-use `King\HttpRequestContext` resource for
     * self-delimited responses; redirect following still resolves onto the
     * final HTTP/1 response context, and this mode remains unavailable on
     * HTTP/2 and HTTP/3.
     * Validation failures return `false`; unsupported protocol/TLS/runtime
     * states throw specialized King exceptions.
     * @param array<string,mixed>|null $headers
     * @param array<string,mixed>|null $options
     * @return array<string,mixed>|resource|false
     * @throws \King\ProtocolException
     * @throws \King\NetworkException
     * @throws \King\TimeoutException
     * @throws \King\TlsException
     */
    function king_send_request(string $url, ?string $method = 'GET', ?array $headers = null, mixed $body = null, ?array $options = null): mixed {}

    /**
     * Legacy receive half of the historical request/response split.
     * Consumes a live single-use `King\HttpRequestContext` resource produced
     * by the HTTP/1 `response_stream` slice and materializes a `King\Response`.
     * The active slice covers bodyless, `Content-Length`-delimited, and
     * `chunked` HTTP/1 responses.
     */
    function king_receive_response(mixed $request_context): \King\Response {}

    /**
     * Parse a user-provided HTTP 103-style header array against a live
     * `King\HttpRequestContext` and append any `Link` hints to the pending
     * hint list stored on that request context.
     * This is a request-context API surface; the active HTTP/1 runtime does
     * not yet auto-harvest on-wire interim `103` responses on its own.
     * @param mixed $request_context
     * @param array<string,mixed> $headers
     */
    function king_client_early_hints_process(mixed $request_context, array $headers): bool {}

    /**
     * Return the currently stored pending Early Hints for a live
     * `King\HttpRequestContext`.
     * Each parsed hint is exposed as an associative array with `url` plus any
     * parsed `Link` parameters such as `rel`, `as`, or `crossorigin`.
     * @param mixed $request_context
     * @return array<int,array<string,mixed>>
     */
    function king_client_early_hints_get_pending(mixed $request_context): array {}

    /**
     * Client-facing alias for `king_send_request()` with the same active
     * HTTP/1 dispatcher semantics, including chunked decoding, optional
     * redirect following via `follow_redirects` plus `max_redirects`, and
     * request-scoped same-origin keep-alive reuse for self-delimited
     * HTTP/1.1 responses. With `options['response_stream'] => true`, the
     * live HTTP/1 path instead returns a single-use
     * `King\HttpRequestContext` resource
     * for self-delimited responses; redirect following still resolves onto
     * the final HTTP/1 response context, and this mode remains unavailable
     * on HTTP/2 and HTTP/3.
     * @param array<string,mixed>|null $headers
     * @param array<string,mixed>|null $options
     * @return array<string,mixed>|resource|false
     * @throws \King\ProtocolException
     * @throws \King\NetworkException
     * @throws \King\TimeoutException
     * @throws \King\TlsException
     */
    function king_client_send_request(string $url, ?string $method = 'GET', ?array $headers = null, mixed $body = null, ?array $options = null): mixed {}

    /**
     * Direct live HTTP/1 one-shot request path over a native TCP socket.
     * Only absolute `http://` URLs are live in the current skeleton build;
     * chunked responses are decoded, redirects can be followed via
     * `follow_redirects` plus `max_redirects`, and self-delimited
     * same-origin HTTP/1.1 responses may reuse a request-scoped
     * keep-alive socket. With `options['response_stream'] => true`, the
     * live HTTP/1 path instead returns a single-use
     * `King\HttpRequestContext` resource
     * for self-delimited responses; redirect following still resolves onto
     * the final HTTP/1 response context.
     * @param array<string,mixed>|null $headers
     * @param array<string,mixed>|null $options
     * @return array<string,mixed>|resource|false
     * @throws \King\NetworkException
     * @throws \King\TimeoutException
     * @throws \King\TlsException
     * @throws \King\ProtocolException
     */
    function king_http1_request_send(string $url, ?string $method = 'GET', ?array $headers = null, mixed $body = null, ?array $options = null): mixed {}

    /**
     * Direct live HTTP/2 one-shot request path over the active libcurl runtime.
     * Supports absolute `http://` h2c and `https://` ALPN-backed URLs, keeps a
     * TLS-sensitive per-origin session pool alive for reuse, and returns a
     * normalized response array with lifecycle metadata like
     * `response_complete`, `body_bytes`, `header_bytes`, and `stream_kind`.
     * With `options['capture_push'] => true`, nested pushed responses are
     * attached under `pushes`.
     * @param array<string,mixed>|null $headers
     * @param array<string,mixed>|null $options
     * @return array<string,mixed>|false
     * @throws \King\NetworkException
     * @throws \King\TimeoutException
     * @throws \King\TlsException
     * @throws \King\ProtocolException
     */
    function king_http2_request_send(string $url, ?string $method = 'GET', ?array $headers = null, mixed $body = null, ?array $options = null): array|false {}

    /**
     * Direct live HTTP/2 multiplex leaf over the active libcurl session pool.
     * Each request entry accepts `url`, optional `method`, optional `headers`,
     * and optional `body`. The current leaf requires every entry to target the
     * same absolute `http://` or `https://` origin plus TLS profile so it can
     * share one HTTP/2 session honestly; responses are returned in input order.
     * With `options['capture_push'] => true`, pushed responses are attached to
     * the originating request under `pushes`.
     * @param array<int,array<string,mixed>> $requests
     * @param array<string,mixed>|null $options
     * @return array<int,array<string,mixed>>|false
     * @throws \King\NetworkException
     * @throws \King\TimeoutException
     * @throws \King\TlsException
     * @throws \King\ProtocolException
     */
    function king_http2_request_send_multi(array $requests, ?array $options = null): array|false {}

    /**
     * Materialize a local validated WebSocket connection-state resource.
     * The current skeleton build accepts absolute `ws://` and `wss://` URLs,
     * snapshots optional handshake headers plus `connection_config`,
     * `max_payload_size`, `ping_interval_ms`, and `handshake_timeout_ms`,
     * and returns a `King\WebSocket` resource. The same local runtime also
     * backs send/receive/ping/status/close; only an on-wire handshake,
     * transport backend, and OO parity remain outside the active leaf.
     * @param array<string,mixed>|null $headers
     * @param array<string,mixed>|null $options
     * @return resource|false
     */
    function king_client_websocket_connect(string $url, ?array $headers = null, ?array $options = null) {}

    /**
     * Queue a local WebSocket text or binary frame on an active
     * `King\WebSocket` resource.
     * @param mixed $websocket
     */
    function king_client_websocket_send(mixed $websocket, string $data, bool $is_binary = false): bool {}

    /**
     * Receive the next queued local frame payload from an active
     * `King\WebSocket` resource. Returns `""` when the local queue is empty
     * and the connection remains open.
     * @param mixed $websocket
     * @return string|false
     */
    function king_client_websocket_receive(mixed $websocket, int $timeout_ms = -1): string|false {}

    /**
     * Record a local WebSocket ping on an active `King\WebSocket` resource.
     * @param mixed $websocket
     */
    function king_client_websocket_ping(mixed $websocket, string $payload = ""): bool {}

    /**
     * Return the current numeric local connection state for an active
     * `King\WebSocket` resource.
     * @param mixed $websocket
     */
    function king_client_websocket_get_status(mixed $websocket): int {}

    /**
     * Close the local `King\WebSocket` resource immediately with optional
     * close metadata. The local queue remains drainable until empty.
     * @param mixed $websocket
     */
    function king_client_websocket_close(mixed $websocket, int $status_code = 1000, string $reason = ""): bool {}

    /**
     * Alias for `king_client_websocket_send()` over the same local runtime.
     * @param mixed $websocket
     */
    function king_websocket_send(mixed $websocket, string $data, bool $is_binary = false): bool {}

    /**
     * Create a local MCP connection-state resource for the active skeleton
     * runtime. This stores host, port, optional `King\Config`, and open/closed
     * state, but does not yet open a live transport backend.
     * @param mixed $config
     * @param array<string,mixed>|null $options
     * @return resource|false
     */
    function king_mcp_connect(string $host, int $port, mixed $config, ?array $options = null) {}

    /**
     * Validate the active local MCP connection state and request shape.
     * The current skeleton build still has no live MCP request transport and
     * therefore returns `false` with a stable unavailable error after
     * successful validation.
     * @param mixed $connection
     * @param array<string,mixed>|null $options
     * @return string|false
     */
    function king_mcp_request(mixed $connection, string $service_name, string $method_name, string $request_payload, ?array $options = null): string|false {}

    /**
     * Drain a PHP stream into the active local MCP transfer store.
     * The current skeleton build keeps the bytes per connection under the
     * `(service, method, stream_identifier)` tuple instead of sending them to
     * a live MCP backend.
     * @param mixed $connection
     * @param resource $stream
     */
    function king_mcp_upload_from_stream(mixed $connection, string $service_name, string $method_name, string $stream_identifier, $stream): bool {}

    /**
     * Resolve a previously uploaded local MCP transfer by treating
     * `$request_payload` as the opaque transfer identifier in the active
     * skeleton build, then stream the bytes into the destination stream.
     * @param mixed $connection
     * @param resource $stream
     */
    function king_mcp_download_to_stream(mixed $connection, string $service_name, string $method_name, string $request_payload, $stream): bool {}

    /**
     * Close a local MCP connection-state resource.
     * The resource remains readable for stable post-close validation.
     * @param mixed $connection
     */
    function king_mcp_close(mixed $connection): bool {}

    /**
     * Drive events on a low-level session handle.
     * In the current skeleton build this waits on the active UDP socket via
     * `poll(2)`, updates transport counters, may emit a controlled probe
     * datagram when the configured QUIC ping interval is forced to `0`, and
     * drains received datagrams into the local session snapshot.
     * Validation failures still return `false`; native socket failures throw.
     * @throws \King\NetworkException
     */
    function king_poll($session, int $timeout_ms = 0): bool {}

    /**
     * Record a local stream-cancel intent on a `King\Session` resource.
     * The current skeleton build does not have a live transport backend yet,
     * but it stores cancel state, rejects duplicate stream IDs per session,
     * and exposes the result via `king_get_stats()`.
     * @param mixed $session
     */
    function king_cancel_stream(int $stream_id, string $how = 'both', mixed $session = null): bool {}

    /**
     * Client-facing alias for `king_cancel_stream()` with the same local
     * skeleton semantics.
     * @param mixed $session
     */
    function king_client_stream_cancel(int $stream_id, string $how = 'both', mixed $session = null): bool {}

    /**
     * Validate and store the default CA file path for the active local
     * skeleton TLS runtime.
     */
    function king_set_ca_file(string $path): bool {}

    /**
     * Client-facing alias for `king_set_ca_file()`.
     */
    function king_client_tls_set_ca_file(string $path): bool {}

    /**
     * Validate and store the default client certificate and key paths for the
     * active local skeleton TLS runtime.
     */
    function king_set_client_cert(string $cert, string $key): bool {}

    /**
     * Client-facing alias for `king_set_client_cert()`.
     */
    function king_client_tls_set_client_cert(string $cert, string $key): bool {}

    /**
     * Export the current session-local ticket blob from the active client
     * skeleton runtime.
     * @param mixed $session
     */
    function king_export_session_ticket(mixed $session): string {}

    /**
     * Client-facing alias for `king_export_session_ticket()`.
     * @param mixed $session
     */
    function king_client_tls_export_session_ticket(mixed $session): string {}

    /**
     * Import a session ticket into the active client skeleton runtime and
     * publish it into the shared ticket ring.
     * @param mixed $session
     */
    function king_import_session_ticket(mixed $session, string $ticket): bool {}

    /**
     * Client-facing alias for `king_import_session_ticket()`.
     * @param mixed $session
     */
    function king_client_tls_import_session_ticket(mixed $session, string $ticket): bool {}

    /**
     * Active local HTTP/1 single-dispatch server leaf.
     * Validates host, port, config, and callback, materializes one local
     * `King\Session` snapshot, invokes the handler once with a normalized
     * HTTP/1-style request array, validates the returned response array, and
     * then closes the local session snapshot.
     * @param mixed $config
     */
    function king_http1_server_listen(string $host, int $port, mixed $config, callable $handler): bool {}

    /**
     * Active local HTTP/2 single-dispatch server leaf over the same
     * `King\Session` runtime.
     * The handler receives a normalized HTTP/2-style request array with
     * pseudo-header-style metadata and the same local session snapshot
     * contract as the HTTP/1 leaf.
     * @param mixed $config
     */
    function king_http2_server_listen(string $host, int $port, mixed $config, callable $handler): bool {}

    /**
     * Active local HTTP/3 single-dispatch server leaf over the same
     * `King\Session` runtime.
     * The handler receives a normalized HTTP/3-style request array with
     * pseudo-header-style metadata plus local `h3` ALPN/transport snapshot
     * fields; a real QUIC listener/accept/streaming backend remains outside
     * this leaf.
     * @param mixed $config
     */
    function king_http3_server_listen(string $host, int $port, mixed $config, callable $handler): bool {}

    /**
     * Active local server-index dispatcher.
     * Resolves `null`, inline config arrays, and `King\Config` handles,
     * chooses HTTP/3 when TCP is disabled, otherwise HTTP/2 when
     * `http2.enable` is active, otherwise HTTP/1, and forwards to the
     * selected listener leaf. In the current skeleton build, HTTP/1, HTTP/2,
     * and HTTP/3 are active local single-dispatch leaves.
     * @param mixed $config
     */
    function king_server_listen(string $host, int $port, mixed $config, callable $handler): bool {}

    /**
     * Register a local server-side cancel callback for one stream on an open
     * `King\Session` resource or object.
     * The handler is stored on the shared session snapshot and invoked once
     * when the same local stream is later cancelled via `king_cancel_stream()`.
     * @param mixed $session
     */
    function king_server_on_cancel(mixed $session, int $stream_id, callable $handler): bool {}

    /**
     * Validate and normalize a local server-side Early Hints batch for one
     * stream on an open `King\Session` resource or object.
     * The current skeleton build stores the normalized header pairs on the
     * session snapshot and exposes the last batch plus counters via
     * `king_get_stats()`.
     * @param mixed $session
     * @param array<int|string,mixed> $hints
     */
    function king_server_send_early_hints(mixed $session, int $stream_id, array $hints): bool {}

    /**
     * Materialize a local server-side `King\WebSocket` resource for one
     * stream on an open `King\Session` resource or object.
     * The current skeleton build records upgrade metadata on the session
     * snapshot, derives a local `ws://` or `wss://` URL from the active
     * listener/session state, and rejects duplicate or locally cancelled
     * stream IDs.
     * @param mixed $session
     * @return resource|false
     */
    function king_server_upgrade_to_websocket(mixed $session, int $stream_id) {}

    /**
     * Validate and apply a local server-side TLS reload snapshot on an open
     * `King\Session` resource or object.
     * The current skeleton build requires readable replacement certificate
     * and key paths, also requires the configured `tls_ticket_key_file` to
     * be readable when set, and stores the last local server-TLS snapshot
     * plus apply/reload counters on the shared session stats.
     * @param mixed $session
     */
    function king_server_reload_tls_config(mixed $session, string $cert_file_path, string $key_file_path): bool {}

    /**
     * Validate and materialize a local admin-listener snapshot for an open
     * server `King\Session` resource or object.
     * The current skeleton build resolves `null`, inline config arrays, and
     * `King\Config` handles, requires explicit enablement plus readable
     * `mtls` material, and stores the last bind/auth snapshot plus reload
     * counters on the shared session stats.
     * @param mixed $target_server
     * @param mixed $config
     */
    function king_admin_api_listen(mixed $target_server, mixed $config): bool {}

    /**
     * Extension version string.
     */
    function king_version(): string {}

    /**
     * Create a King\Config resource from optional overrides.
     * The canonical skeleton-build override surface uses namespaced keys
     * under `quic.`, `tls.`, `http2.`, `tcp.`, `autoscale.`, `mcp.`,
     * `orchestrator.`, `geometry.`, `smartcontract.`, `ssh.`,
     * `storage.`, `cdn.`, `dns.`, and `otel.`.
     * @param array<string,mixed>|null $options
     * @return resource|false
     * @throws \King\RuntimeException
     */
    function king_new_config(?array $options = null) {}

    /**
     * Build and extension health information for the active module.
     * @return array{
     *   status:string,
     *   build:string,
     *   version:string,
     *   php_version:string,
     *   pid:int,
     *   config_override_allowed:bool,
     *   active_runtime_count:int,
     *   active_runtimes:list<string>,
     *   stubbed_api_group_count:int,
     *   stubbed_api_groups:list<string>
     * }
     */
    function king_health(): array {}

    /**
     * Last error message from the shared skeleton error buffer
     * (prefer exceptions).
     */
    function king_get_last_error(): string {}

    /**
     * Compatibility alias for the shared skeleton error buffer used by the
     * active local WebSocket runtime.
     */
    function king_client_websocket_get_last_error(): string {}

    /**
     * Compatibility alias for the shared error buffer used by the current
     * local MCP skeleton runtime.
     */
    function king_mcp_get_error(): string {}

    /**
     * Transport stats for a low-level handle.
     * The current skeleton build returns a stable local snapshot with
     * `build`, `transport`, `host`, `port`, `state`, `connected_at`,
     * `last_activity_at`, `poll_calls`, `last_poll_timeout_ms`,
     * `cancel_calls`, `canceled_stream_count`, `last_canceled_stream_id`,
     * `last_cancel_mode`, TLS defaults such as `tls_default_ca_file`,
     * `tls_default_cert_file`, `tls_default_key_file`,
     * `tls_ticket_key_file`, and ticket state such as
     * `tls_enable_early_data`, `tls_session_ticket_lifetime_sec`,
     * `tls_has_session_ticket`, `tls_session_ticket_length`,
     * `tls_ticket_source`, transport fields such as `transport_backend`,
     * `transport_state`, `transport_has_socket`, `transport_socket_family`,
     * `transport_local_address`, `transport_local_port`,
     * `transport_peer_address`, `transport_peer_port`,
     * `transport_connect_timeout_ms`, `transport_ping_interval_ms`,
     * `transport_datagrams_enable`, `transport_last_poll_result`,
     * `transport_last_errno`, `transport_error_scope`,
     * `transport_rx_datagram_count`, `transport_rx_bytes`,
     * `transport_tx_datagram_count`, `transport_tx_bytes`,
     * config binding fields for the currently attached skeleton config,
     * and `config_option_count`.
     * @return array<string,mixed>|false
     */
    function king_get_stats($session): array|false {}

    /**
     * Initializes local skeleton runtime settings for the object-store/CDN
     * layer without replacing the extension-wide INI configuration.
     * Supported keys are `primary_backend`, `storage_root_path`,
     * `max_storage_size_bytes`, `replication_factor`, `chunk_size_kb`,
     * and `cdn_config` with `enabled`, `cache_size_mb`,
     * `default_ttl_seconds`.
     * @param array<string,mixed> $config
     * @throws \King\ValidationException
     */
    function king_object_store_init(array $config): bool {}

    /**
     * Stores an object in the local skeleton object-store registry.
     * @param array<string,mixed>|null $options
     * @throws \King\ValidationException|\King\RuntimeException|\King\SystemException
     */
    function king_object_store_put(string $object_id, string $data, ?array $options = null): bool {}

    /**
     * Stable object-store inventory snapshot for the skeleton build.
     * @return list<array<string,mixed>>
     */
    function king_object_store_list(): array {}

    /**
     * Object-store lookup for the skeleton build.
     * Returns the stored payload for local registry hits and `false` on miss.
     * @param array<string,mixed>|null $options
     */
    function king_object_store_get(string $object_id, ?array $options = null): string|false {}

    /**
     * Object-store delete for the skeleton build.
     * Returns `true` on local registry hits and `false` on miss.
     */
    function king_object_store_delete(string $object_id): bool {}

    /**
     * Runs a no-op maintenance pass over the local skeleton object-store
     * registry and returns a small summary.
     * @return array{
     *   mode:string,
     *   scanned_objects:int,
     *   total_size_bytes:int,
     *   orphaned_entries_removed:int,
     *   bytes_reclaimed:int,
     *   optimized_at:int
     * }
     * @throws \King\RuntimeException
     */
    function king_object_store_optimize(): array {}

    /**
     * Stable schema inventory snapshot for the skeleton build.
     * @return list<string>
     */
    function king_proto_get_defined_schemas(): array {}

    /**
     * Registers an enum name for the active skeleton runtime.
     * Successful registrations become visible via the proto lookup helpers.
     * @param array<string,int> $enum_values
     * @throws \King\ValidationException|\King\SystemException
     */
    function king_proto_define_enum(string $enum_name, array $enum_values): bool {}

    /**
     * Registers a schema name for the active skeleton runtime.
     * The skeleton build validates the top-level field shape and stores the
     * declared shape for lookup/introspection plus a minimal primitive and
     * numeric enum encode/decode subset, including floating-point and
     * fixed-width primitives, nested message fields whose child schemas are
     * already runtime-supported, repeated primitive/enum and repeated nested
     * message fields, plus `map<key, scalar|enum|message>` fields whose
     * supported key types are currently `string`, `bool`, and the 32-bit
     * integer variants (`int32`, `uint32`, `sint32`, `fixed32`,
     * `sfixed32`); message values must already be runtime-supported.
     * Runtime-supported scalar/enum/message fields may also opt into
     * `oneof => 'group'`. Packable repeated numeric/enum fields may opt into
     * packed encode with `packed => true`, and packed decode is accepted for
     * those wire-compatible fields, but message fields and maps are never
     * packed and the skeleton still does not build a compiled backend.
     * Optional, non-repeated scalar/enum fields may also define `default`,
     * which is applied only during decode when the field is absent from the
     * payload. Map fields use `type => 'map<key,T>'` with the supported key
     * types above. `oneof` fields
     * cannot be repeated, maps, required, or defaulted. Enum values encode
     * from integers or registered member-name strings, while decode still
     * yields numeric enum values.
     * @param array<string,array<string,mixed>> $schema_definition
     * @throws \King\ValidationException|\King\SystemException
     */
    function king_proto_define_schema(string $schema_name, array $schema_definition): bool {}

    /**
     * Stable enum inventory snapshot for the skeleton build.
     * @return list<string>
     */
    function king_proto_get_defined_enums(): array {}

    /**
     * Encodes user data using a named IIBIN schema.
     * Unknown schemas throw immediately. The skeleton build currently supports
     * registered zero-field schemas plus a minimal primitive and numeric enum
     * subset, including floating-point and fixed-width primitives, nested
     * message fields whose child schemas are already runtime-supported,
     * repeated primitive/enum and repeated nested message fields,
     * `map<key, scalar|enum|message>` fields over the supported key types,
     * and `oneof` members over the
     * currently runtime-supported scalar/enum/message field shapes. Packable
     * repeated numeric/enum fields encode in unpacked form by default and
     * switch to packed form when the schema field sets `packed => true`;
     * message fields and maps remain unpacked and more complex registered
     * schemas still throw. Decode-time defaults are not auto-encoded;
     * missing optional fields still stay omitted on the wire. Encode rejects
     * payloads that set multiple fields from the same `oneof` group. Enum
     * values accept integers or registered enum member-name strings during
     * encode.
     * @param array<string,mixed>|object $data
     * @throws \King\ValidationException|\King\RuntimeException
     */
    function king_proto_encode(string $schema_name, mixed $data): string {}

    /**
     * Decodes an IIBIN payload using a named schema.
     * Unknown schemas throw immediately. The skeleton build currently supports
     * registered zero-field schemas plus a minimal primitive and numeric enum
     * subset, including floating-point and fixed-width primitives, nested
     * message fields whose child schemas are already runtime-supported,
     * repeated primitive/enum and repeated nested message fields,
     * `map<key, scalar|enum|message>` fields over the supported key types,
     * and `oneof` members over the
     * currently runtime-supported scalar/enum/message field shapes. The
     * decoder also accepts packed repeated payloads for packable numeric/enum
     * fields; `decode_as_object` may be `true` for recursive `stdClass`,
     * a class-string for top-level hydration, or an
     * `array<string,string>` schema-to-class map for recursive message
     * hydration. Map fields themselves still decode as associative arrays,
     * while message values inside maps follow the same object/class
     * materialization. Target classes must be concrete userland classes
     * without constructors; hydration failures surface as validation errors.
     * When multiple members from the same `oneof` group appear on the wire,
     * the last member wins. Optional, non-repeated scalar/enum fields apply
     * their registered `default` only when absent from the payload. Message
     * fields and maps are never treated as packed, and more complex
     * registered schemas still throw. Enum decode remains numeric even when
     * encode accepted member-name strings.
     * @param bool|string|array<string,string> $decode_as_object
     * @return array<string,mixed>|object
     * @throws \King\ValidationException|\King\RuntimeException
     */
    function king_proto_decode(string $schema_name, string $binary_data, bool|string|array $decode_as_object = false): array|object {}

    /**
     * Checks whether any schema or enum entry is currently registered.
     * Registrations are name-only in the active skeleton runtime.
     */
    function king_proto_is_defined(string $name): bool {}

    /**
     * Checks whether a schema is currently registered.
     * Registrations are name-only in the active skeleton runtime.
     */
    function king_proto_is_schema_defined(string $schema_name): bool {}

    /**
     * Checks whether an enum is currently registered.
     * Registrations are name-only in the active skeleton runtime.
     */
    function king_proto_is_enum_defined(string $enum_name): bool {}

    /**
     * Telemetry exporter and feature status from active config.
     * @return array{
     *   system_status: array{
     *     enabled:bool,
     *     service_name:string,
     *     exporter_endpoint:string,
     *     exporter_protocol:string
     *   },
     *   feature_status: array{
     *     metrics_enable:bool,
     *     logs_enable:bool
     *   }
     * }
     */
    function king_telemetry_get_status(): array {}

    /**
     * Telemetry metrics collected by the active runtime.
     * @return list<array<string,mixed>>
     */
    function king_telemetry_get_metrics(): array {}

    /**
     * Flushes pending telemetry data for the skeleton build.
     * The active build has no exporter queues yet, so all exported counts are
     * currently zero.
     * @return array{
     *   spans_exported:int,
     *   metrics_exported:int,
     *   logs_exported:int,
     *   export_timestamp:int
     * }
     */
    function king_telemetry_flush(): array {}

    /**
     * Current telemetry trace context for the active runtime.
     * Returns null until the skeleton build has a live span runtime.
     * @return array<string,mixed>|null
     */
    function king_telemetry_get_trace_context(): ?array {}

    /**
     * Returns the provided headers unchanged until the skeleton build has a
     * live span runtime to inject.
     * @param array<string,string>|null $headers
     * @return array<string,string>
     */
    function king_telemetry_inject_context(?array $headers = null): array {}

    /**
     * Returns false until the skeleton build has a tracing runtime that can
     * accept extracted context.
     * @param array<string,string> $headers
     */
    function king_telemetry_extract_context(array $headers): bool {}

    /**
     * Object-store and CDN status from active config.
     * @return array{
     *   object_store: array{
     *     enabled:bool,
     *     redundancy_mode:string,
     *     replication_factor:int,
     *     chunk_size_mb:int,
     *     metadata_agent_uri:string,
     *     node_discovery_mode:string,
     *     metadata_cache_enabled:bool,
     *     metadata_cache_ttl_sec:int,
     *     directstorage_enabled:bool,
     *     local_registry_initialized:bool,
     *     runtime_initialized:bool,
     *     runtime_primary_backend:string,
     *     runtime_storage_root_path:string,
     *     runtime_max_storage_size_bytes:int,
     *     runtime_replication_factor:int,
     *     runtime_chunk_size_kb:int,
     *     object_count:int,
     *     stored_bytes:int,
     *     latest_object_at:int|null
     *   },
     *   cdn: array{
     *     enabled:bool,
     *     cache_mode:string,
     *     cache_memory_limit_mb:int,
     *     default_ttl_sec:int,
     *     max_object_size_mb:int,
     *     origin_mcp_endpoint:string,
     *     origin_http_endpoint:string,
     *     origin_request_timeout_ms:int,
     *     serve_stale_on_error:bool,
     *     allowed_http_methods:string,
     *     local_cache_initialized:bool,
     *     runtime_initialized:bool,
     *     runtime_enabled:bool,
     *     runtime_cache_size_mb:int,
     *     runtime_default_ttl_sec:int,
     *     cached_object_count:int,
     *     cached_bytes:int,
     *     latest_cached_at:int|null
     *   }
     * }
     */
    function king_object_store_get_stats(): array {}

    /**
     * Caches an existing local object-store entry in the skeleton CDN cache.
     * Returns `false` when the object does not exist in the local object store.
     * @param array{ttl_sec?:int}|null $options
     * @throws \King\ValidationException|\King\RuntimeException|\King\SystemException
     */
    function king_cdn_cache_object(string $object_id, ?array $options = null): bool {}

    /**
     * Invalidates one cached object or clears the full local skeleton CDN cache.
     * Returns the number of removed cache entries.
     * @throws \King\ValidationException|\King\RuntimeException
     */
    function king_cdn_invalidate_cache(?string $object_id = null): int {}

    /**
     * CDN edge-node inventory for the active skeleton runtime.
     * @return list<array<string,mixed>>
     */
    function king_cdn_get_edge_nodes(): array {}

    /**
     * Autoscaling policy status from active config.
     * @return array{
     *   provider:string,
     *   region:string,
     *   min_nodes:int,
     *   max_nodes:int,
     *   scale_up_cpu_threshold_percent:int,
     *   scale_down_cpu_threshold_percent:int,
     *   scale_up_policy:string,
     *   cooldown_period_sec:int,
     *   idle_node_timeout_sec:int,
     *   instance_type:string,
     *   instance_image_id:string,
     *   network_config:string,
     *   instance_tags:string
     * }
     */
    function king_autoscaling_get_status(): array {}

    /**
     * Autoscaling metrics collected by the active runtime.
     * @return list<array<string,mixed>>
     */
    function king_autoscaling_get_metrics(): array {}

    /**
     * Initializes the local semantic-DNS core/runtime config snapshot.
     * Supported keys include bind/port, TTL/discovery limits, semantic mode,
     * mother-node sync hints, and optional `routing_policies`.
     * @param array<string,mixed> $config
     * @throws \King\ValidationException|\King\SystemException
     */
    function king_semantic_dns_init(array $config): bool {}

    /**
     * Starts the local semantic-DNS server-state slice for the active runtime.
     * This is a local lifecycle toggle, not yet a real network DNS listener.
     * @throws \King\RuntimeException
     */
    function king_semantic_dns_start_server(): bool {}

    /**
     * Semantic-DNS topology snapshot for the active skeleton runtime.
     * Registered services and mother nodes are exposed from the local
     * in-memory skeleton registries.
     * @return array{
     *   services:list<array<string,mixed>>,
     *   mother_nodes:list<array<string,mixed>>,
     *   statistics:array<string,int>,
     *   topology_generated_at:int
     * }
     */
    function king_semantic_dns_get_service_topology(): array {}

    /**
     * Registers a semantic-DNS service record in the active skeleton runtime.
     * Required keys: `service_id`, `service_name`, `service_type`, `hostname`,
     * and `port`. Optional keys: `status`, `current_load_percent`,
     * `active_connections`, `total_requests`, and scalar `attributes`.
     * @param array<string,mixed> $service_info
     * @throws \King\ValidationException|\King\RuntimeException|\King\SystemException
     */
    function king_semantic_dns_register_service(array $service_info): bool {}

    /**
     * Registers a semantic-DNS mother node in the active skeleton runtime.
     * Required keys: `node_id`, `hostname`, and `port`. Optional keys:
     * `status`, `managed_services_count`, and `trust_score`.
     * @param array<string,mixed> $mother_node_info
     * @throws \King\ValidationException|\King\RuntimeException|\King\SystemException
     */
    function king_semantic_dns_register_mother_node(array $mother_node_info): bool {}

    /**
     * Semantic-DNS service discovery snapshot for the active skeleton runtime.
     * Returns routeable registered services that match the requested
     * `service_type` and any scalar criteria. When no routeable service is
     * registered, `services` is a stable empty list and `service_count` is `0`.
     * @param array<string,mixed>|null $criteria
     * @return array{
     *   services:list<array<string,mixed>>,
     *   service_type:string,
     *   discovered_at:int,
     *   service_count:int
     * }
     */
    function king_semantic_dns_discover_service(string $service_type, ?array $criteria = null): array {}

    /**
     * Semantic-DNS routing decision for the active skeleton runtime.
     * Returns the best registered healthy/degraded service for the requested
     * name, or the stable no-route response when none matches.
     * @param array<string,mixed>|null $client_info
     * @return array<string,mixed>
     */
    function king_semantic_dns_get_optimal_route(string $service_name, ?array $client_info = null): array {}

    /**
     * Updates the status of a registered semantic-DNS service in the active
     * skeleton runtime and may also patch live load counters.
     * @param array{
     *   current_load_percent?:int,
     *   active_connections?:int,
     *   total_requests?:int
     * }|null $metrics
     * @throws \King\ValidationException
     */
    function king_semantic_dns_update_service_status(string $service_id, string $status, ?array $metrics = null): bool {}

    /**
     * System health summary for the active skeleton runtime.
     * @return array{
     *   overall_healthy:bool,
     *   build:string,
     *   version:string,
     *   config_override_allowed:bool
     * }
     */
    function king_system_health_check(): array {}

    /**
     * System status summary for the active skeleton runtime.
     * @return array{
     *   system_info: array<string,mixed>,
     *   configuration: array<string,mixed>,
     *   autoscaling: array<string,mixed>
     * }
     */
    function king_system_get_status(): array {}

    /**
     * System metrics snapshot for the active skeleton runtime.
     * @return array{
     *   resource_metrics: array{
     *     memory_usage_bytes:int,
     *     memory_peak_bytes:int
     *   },
     *   metrics_collected_at:int
     * }
     */
    function king_system_get_metrics(): array {}

    /**
     * Small system performance snapshot for the active skeleton runtime.
     * No-arg getter.
     * @return array{
     *   performance_overview: array{
     *     build:string,
     *     memory_usage_mb:int,
     *     memory_peak_mb:int
     *   },
     *   component_performance: array<string,array<string,mixed>>,
     *   recommendations: list<string>,
     *   report_generated_at:int
     * }
     */
    function king_system_get_performance_report(): array {}

    /**
     * Small per-component descriptor for the active skeleton runtime.
     * Accepted names currently mirror the archived component inventory:
     * `config`, `client`, `server`, `semantic_dns`, `object_store`, `cdn`,
     * `telemetry`, `autoscaling`, `mcp`, `iibin`, `pipeline_orchestrator`.
     * @return array{
     *   name:string,
     *   build:string,
     *   version:string,
     *   implementation:string,
     *   configuration:array<string,mixed>,
     *   info_generated_at:int
     * }|false
     */
    function king_system_get_component_info(string $name): array|false {}
}

/* OO surface stubs remain the main shape reference for the remaining object
 * API. The current skeleton build now has active wrappers for Config,
 * Session, Stream, Response and HttpClient over the same native runtime
 * kernels, including the live HTTP/3 request path via Http3Client, but
 * broader parity for MCP transport-backed upload/download, WebSocket objects,
 * and transport-wide
 * cancellation is still pending.
 */
namespace King {
    /* ===========================
     * Error model
     * =========================== */

    enum ErrorCode: int {
        case TIMEOUT   = 1;
        case TLS       = 2;
        case QUIC      = 3;
        case VALIDATION= 4;
        case NETWORK   = 5;
        case PROTOCOL  = 6;
        case STREAM    = 7;
        case SYSTEM    = 8;
    }

    /**
     * Base exception for all King errors.
     * @property-read ?ErrorCode $codeEnum
     */
    class Exception extends \Exception {
        public ?ErrorCode $codeEnum = null;
    }

    class RuntimeException    extends Exception {}
    class SystemException     extends Exception {}
    class ValidationException extends Exception {}
    class TimeoutException     extends Exception {}
    class NetworkException     extends Exception {}
    class TlsException         extends Exception {}
    class QuicException        extends Exception {}
    class ProtocolException    extends Exception {}
    class StreamException      extends Exception {}

    // Fine-grained stream/transport exceptions (map to internal codes)
    class InvalidStateException      extends StreamException {}
    class UnknownStreamException     extends StreamException {}
    class StreamBlockedException     extends StreamException {}
    class StreamLimitException       extends StreamException {}
    class FinalSizeException         extends StreamException {}
    class StreamStoppedException     extends StreamException {}
    class FinExpectedException       extends StreamException {}
    class InvalidFinStateException   extends StreamException {}
    class DoneException              extends StreamException {}
    class CongestionControlException extends QuicException {}
    class TooManyStreamsException    extends StreamException {}

    // MCP
    class MCPException           extends Exception {}
    class MCPConnectionException extends MCPException {}
    class MCPProtocolException   extends MCPException {}
    class MCPTimeoutException    extends MCPException {}
    class MCPDataException       extends MCPException {}

    // WebSocket
    class WebSocketException           extends Exception {}
    class WebSocketConnectionException extends WebSocketException {}
    class WebSocketProtocolException   extends WebSocketException {}
    class WebSocketTimeoutException    extends WebSocketException {}
    class WebSocketClosedException     extends WebSocketException {}

    /* ===========================
     * Cancellation
     * =========================== */
    final class CancelToken {
        /** Mark this local cancellation token as cancelled. */
        public function cancel(): void {}

        /** Check whether this local cancellation token was already cancelled. */
        public function isCancelled(): bool {}
    }

    /* ===========================
     * Config
     * =========================== */
    class Config {
        /**
         * Create configuration object.
         * @param array<string,mixed> $options
         */
        public function __construct(?array $options = null) {}

        /**
         * Create configuration object.
         * @param array<string,mixed> $options
         */
        public static function new(array $options = []): static {}

        /**
         * Read a key from the active OO config parity slice.
         */
        public function get(string $key): mixed {}

        /**
         * Apply a validated override onto the current config snapshot.
         */
        public function set(string $key, mixed $value): void {}

        /**
         * Export the active OO config parity slice plus explicit OO overrides.
         * @return array<string,mixed>
         */
        public function toArray(): array {}
    }

    /* ===========================
     * QUIC / HTTP/3 (client)
     * =========================== */
    final class Session {
        /**
         * @param ?Config $config
         * @param array<string,mixed> $connect_options e.g. ["sni"=>"example.com"]
         * @throws TlsException|QuicException|ValidationException|NetworkException
         */
        public function __construct(string $host, int $port, ?Config $config = null, array $connect_options = []) {}

        public function isConnected(): bool {}

        /**
         * Allocate a local client-bidirectional stream on the active session kernel.
         * A pending CancelToken is bound to the local stream state and can stop it later.
         * @param array<string,string|string[]> $headers
         * @throws ValidationException|RuntimeException|ProtocolException|StreamException
         */
        public function sendRequest(
            string $method,
            string $path,
            array $headers = [],
            string $body = '',
            ?CancelToken $cancel = null
        ): Stream {}

        /** Drive the event loop; returns true if events processed. */
        public function poll(int $timeout_ms = 0): bool {}

        /** Graceful close (optional app code/reason). */
        public function close(int $code = 0, ?string $reason = null): void {}

        /**
         * @return array{
         *  quic_conns?:int,h3_streams?:int,rx_bytes?:int,tx_bytes?:int,
         *  rtt_ms_p50?:int,rtt_ms_p99?:int,retransmits?:int,zero_rtt?:bool
         * }
         */
        public function stats(): array {}

        /** Negotiated ALPN, e.g. "h3". */
        public function alpn(): string {}

        /** Enable/disable client-side Early Hints handling (103). */
        public function enableEarlyHints(bool $enable = true): void {}
    }

    final class Stream {
        /** The active skeleton runtime currently returns null until a real response path exists; a cancelled token stops the local stream. */
        public function receiveResponse(?int $timeout_ms = null, ?CancelToken $cancel = null): ?Response {}

        /** Buffer a request body chunk on the local stream runtime; returns bytes accepted and honors local cancel state. */
        public function send(string $data, ?CancelToken $cancel = null): int {}

        /** Mark the local stream write side as finished, optionally with a final chunk. */
        public function finish(?string $finalData = null): void {}

        /** Check whether the local stream is already closed or locally cancelled. */
        public function isClosed(): bool {}

        /** Close the local stream and record a local cancel intent on the session. */
        public function close(): void {}
    }

    final class Response {
        public function getStatusCode(): int {}

        /** @return array<string,string[]> */
        public function getHeaders(): array {}

        /** Read the full response body; streaming responses drain the live HTTP/1 request context. */
        public function getBody(): string {}

        /** Incremental read over either the buffered body or the live HTTP/1 streaming context. */
        public function read(int $length = 8192): string {}

        /** Reflect buffered EOF or the end of the live HTTP/1 streaming context. */
        public function isEndOfBody(): bool {}
    }

    /* ===========================
     * MCP (Model Context Protocol)
     * =========================== */
    final class MCP {
        /**
         * Materialize the local MCP connection-state wrapper for host, port,
         * optional `King\Config`, and the explicit open/closed lifecycle.
         * The active skeleton build does not open a transport here yet.
         * @throws ValidationException
         */
        public function __construct(string $host, int $port, ?Config $config = null) {}

        /**
         * Unary RPC call over the local MCP connection-state wrapper.
         * Closed connections and already cancelled tokens fail deterministically;
         * otherwise the active skeleton build still raises the stable
         * "unavailable" protocol error until a real MCP backend exists.
         * @throws RuntimeException|ValidationException|MCPProtocolException
         */
        public function request(string $service, string $method, string $payload, ?CancelToken $cancel = null): string {}

        /**
         * Drain a source stream into the local MCP transfer store keyed by
         * `(service, method, streamIdentifier)`.
         * @param resource $stream
         * @throws RuntimeException|ValidationException|MCPDataException
         */
        public function uploadFromStream(string $service, string $method, string $streamIdentifier, $stream): void {}

        /**
         * Resolve a previously uploaded local MCP transfer by treating
         * `$payload` as the transfer identifier and write the bytes into the
         * provided destination stream.
         * @param resource $stream
         * @throws RuntimeException|ValidationException|MCPDataException
         */
        public function downloadToStream(string $service, string $method, string $payload, $stream): void {}

        /** Close the local MCP connection state. */
        public function close(): void {}
    }

    /* ===========================
     * IIBIN (binary serialization)
     * =========================== */
    final class IIBIN {
        /** @param array<string,int> $values */
        public static function defineEnum(string $name, array $values): bool {}

        /** @param array<string,array<string,mixed>> $fields */
        public static function defineSchema(string $name, array $fields): bool {}

        /** @param array<string,mixed>|object $data */
        public static function encode(string $schema, mixed $data): string {}

        /**
         * @param bool|string|array<string,string> $decodeAsObject
         * @return array<string,mixed>|object
         */
        public static function decode(string $schema, string $data, bool|string|array $decodeAsObject = false): array|object {}

        public static function isDefined(string $name): bool {}

        public static function isSchemaDefined(string $schema): bool {}

        public static function isEnumDefined(string $name): bool {}

        /** @return list<string> */
        public static function getDefinedSchemas(): array {}

        /** @return list<string> */
        public static function getDefinedEnums(): array {}
    }

    /* ===========================
     * Cluster Supervisor
     * =========================== */
    final class Cluster {
        public function __construct(string $worker_script, string $payload = "{}", ?Config $config = null) {}

        /** Blocking run; returns when workers exit or stop() called. */
        public function run(?CancelToken $cancel = null): void {}

        /** Graceful stop with timeout. */
        public function stop(int $grace_ms = 30000): void {}

        /**
         * Register lifecycle hooks: 'worker_start','worker_exit','error'.
         * @param callable(array<string,mixed>):void $handler
         */
        public function on(string $event, callable $handler): void {}
    }

    /* ===========================
     * Pipeline Orchestrator
     * =========================== */
    final class PipelineOrchestrator {
        /**
         * @param array<string,mixed> $pipeline
         * @throws ValidationException
         */
        public static function run(mixed $input, array $pipeline, ?CancelToken $cancel = null): PipelineResult {}

        /** @param callable(array<string,mixed>):mixed $handler */
        public static function registerToolHandler(string $tool_name, callable $handler): void {}
    }

    final class PipelineResult {
        public function isSuccess(): bool {}
        public function getFinalOutput(): mixed {}
        public function getErrorMessage(): ?string {}

        /** @return array<string,mixed> step timings/results */
        public function getTrace(): array {}
    }
}

/* ===========================
 * HTTP Client (1/2/3)
 * =========================== */
namespace King\Client {
    use King\CancelToken;
    use King\Config;
    use King\Response;

    /** Unified HTTP client (auto ALPN); version-specific subclasses available. */
    class HttpClient {
        public function __construct(?Config $config = null) {}

        /**
         * @param array<string,string|string[]> $headers
         * A pre-cancelled token aborts before dispatch; live transport cancellation is still pending.
         */
        public function request(string $method, string $url, array $headers = [], string $body = '', ?CancelToken $cancel = null): Response {}

        public function close(): void {}
    }

    final class Http1Client extends HttpClient {}
    final class Http2Client extends HttpClient {}
    final class Http3Client extends HttpClient {}
}

/* ===========================
 * HTTP Servers (1/2/3) + Early Hints + Admin API
 * =========================== */
namespace King\Server {
    use King\CancelToken;
    use King\Config;

    final class Request {
        public function method(): string {}
        public function path(): string {}
        /** @return array<string,string[]> */
        public function headers(): array {}
        public function body(): string {}
        public function remoteAddr(): string {}
        public function protocol(): string {} // "h1","h2","h3"
    }

    final class Responder {
        /**
         * Send final response.
         * @param array<string,string|string[]> $headers
         */
        public function send(int $status, array $headers = [], string $body = ''): void {}

        /**
         * Send HTTP 103 Early Hints (e.g. Link: rel=preload) prior to final response.
         * @param array<string,string|string[]> $linkHeaders
         */
        public function sendEarlyHints(array $linkHeaders): void {}

        /** Flush buffered data (if applicable). */
        public function flush(): void {}
    }

    abstract class HttpServer {
        public function __construct(string $host, int $port, ?Config $config = null) {}

        /** @param callable(Request,Responder):void $handler */
        public function onRequest(callable $handler): void {}

        public function run(?CancelToken $cancel = null): void {}
        public function stop(int $grace_ms = 30000): void {}
    }

    final class Http1Server extends HttpServer {}
    final class Http2Server extends HttpServer {}
    final class Http3Server extends HttpServer {}

    final class EarlyHints {
        /**
         * Build a set of Link headers for 103 Early Hints from a simple asset array.
         * @param array<int,array{href:string, rel?:string, as?:string, type?:string, crossorigin?:string}> $assets
         * @return array<string,string|string[]>
         */
        public static function buildLinkHeaders(array $assets): array {}
    }

    /** Control-plane HTTP server for runtime admin/ops. */
    final class AdminAPI {
        public function __construct(string $host = "127.0.0.1", int $port = 2019, ?Config $config = null) {}
        public function run(?CancelToken $cancel = null): void {}
        public function stop(int $grace_ms = 10000): void {}

        /**
         * Programmatic control entrypoint (implementation-defined commands).
         * @param array<string,mixed> $payload
         * @return array<string,mixed>
         */
        public function command(string $name, array $payload = []): array {}
    }
}

/* ===========================
 * ObjectStore (RAM-first, erasure-coded, training-aware)
 * =========================== */
namespace King\ObjectStore {
    use King\CancelToken;
    use King\Config;
    use King\Exception;

    /* Errors */
    class ObjectStoreException extends Exception {}
    class NotFoundException extends ObjectStoreException {}
    class QuorumException extends ObjectStoreException {}
    class PlacementException extends ObjectStoreException {}
    class RepairInProgressException extends ObjectStoreException {}
    class NodeUnavailableException extends ObjectStoreException {}
    class ValidationException extends ObjectStoreException {}

    /* Tiers & policies */
    enum Tier: string { case RAM='ram'; case SSD='ssd'; case DISK='disk'; case REMOTE='remote'; }

    /** Erasure coding: k data + m parity; N=k+m. */
    final class ErasureScheme {
        public function __construct(
            public readonly int $k,
            public readonly int $m,
            public readonly int $stripeSize,
            public readonly string $algo = 'rs' // 'rs' (Reed–Solomon), 'rq' (RaptorQ)
        ) {}
    }

    /** Placement constraints & anti-affinity. */
    final class PlacementPolicy {
        /**
         * @param array<string,string> $affinityTags e.g. ["dataset"=>"imagenet"]
         */
        public function __construct(
            public readonly int $minZones = 3,
            public readonly bool $rackAware = true,
            public readonly int $maxShardsPerNode = 1,
            public readonly array $affinityTags = []
        ) {}
    }

    /** Consistency quorum. */
    final class Quorum {
        public function __construct(
            public readonly int $n, // total shards placed (k+m)
            public readonly int $w, // write quorum (>=k recommended)
            public readonly int $r  // read quorum (>=k recommended)
        ) {}
    }

    final class Client {
        /**
         * Recommended config keys (via Config::new([...])):
         *  - cluster_endpoints: list<string> (quic://node:port)
         *  - default_scheme: ErasureScheme
         *  - default_quorum: Quorum
         *  - default_policy: PlacementPolicy
         *  - timeouts: array{connect_ms?:int, io_ms?:int}
         *  - verify_peer?: bool
         *  - compression?: 'none'|'zstd'
         */
        public function __construct(Config $config) {}

        /**
         * Store an object with erasure coding and intelligent placement.
         * @param array{
         *   metadata?: array<string,string>,
         *   tier?: Tier,
         *   ttl_secs?: int,                 // pin in RAM hot set
         *   scheme?: ErasureScheme,
         *   quorum?: Quorum,
         *   policy?: PlacementPolicy,
         *   compress?: bool,
         *   hints?: array<string,string>    // e.g. ["dataset"=>"foo","epoch"=>"12"]
         * } $opts
         */
        public function put(string $key, string $data, array $opts = [], ?CancelToken $cancel = null): PutResult {}

        /** Fetch metadata only. */
        public function head(string $key): HeadResult {}

        /**
         * Reconstruct and fetch object (reads any k shards).
         * @param array{as_stream?:bool, prefer_local?:bool} $opts
         */
        public function get(string $key, array $opts = [], ?CancelToken $cancel = null): GetResult {}

        /** Efficient range read (offset/length) using stripe maps. */
        public function getRange(string $key, int $offset, int $length, array $opts = [], ?CancelToken $cancel = null): GetResult {}

        /** Logical delete (background GC/repair will clean shards). */
        public function delete(string $key): bool {}

        /** Pin/unpin in RAM hot set (with TTL). */
        public function pin(string $key, int $ttlSeconds): bool {}
        public function unpin(string $key): bool {}

        /**
         * Schedule prefetch of multiple objects/shards into a tier; returns count scheduled.
         * @param list<string> $keys
         */
        public function prefetch(array $keys, Tier $tier = Tier::RAM): int {}

        /**
         * List keys with prefix/paging.
         * @param array{prefix?:string, limit?:int, cursor?:string} $opts
         */
        public function list(array $opts = []): ListResult {}

        /* Training-aware planning */

        /**
         * Build a distributed read plan (node→keys/stripes) favoring locality & RAM.
         * @param list<string> $keys
         * @param array{
         *   world_size:int, local_rank:int,
         *   max_concurrency?:int,
         *   shard_granularity?:'object'|'stripe',
         *   prefer_zone_local?:bool
         * } $opts
         */
        public function planRead(array $keys, array $opts): ReadPlan {}

        /** Short leases to avoid hot-set thrash. */
        public function acquireLease(string $key, int $ttl_ms = 5000): Lease {}
        public function renewLease(Lease $lease): bool {}
        public function releaseLease(Lease $lease): void {}

        /* Health, repair, rebalancing */

        public function clusterInfo(): ClusterInfo {}
        public function nodeInfo(string $nodeId): NodeInfo {}

        public function repairStatus(string $key): RepairStatus {}
        public function triggerRepair(string $key): bool {}
        public function rebalance(?PlacementPolicy $policy = null): bool {}
    }

    /* DTOs */

    final class PutResult {
        public function key(): string {}
        public function version(): int {}
        public function size(): int {}
        public function scheme(): ErasureScheme {}
        public function quorum(): Quorum {}
        /** @return list<ShardLocation> */
        public function shards(): array {}
        /** @return array<string,string> */
        public function metadata(): array {}
        public function hotTtlSeconds(): ?int {}
    }

    final class HeadResult {
        public function exists(): bool {}
        public function key(): string {}
        public function version(): int {}
        public function size(): int {}
        public function scheme(): ErasureScheme {}
        public function quorum(): Quorum {}
        public function primaryTier(): Tier {}
        /** @return array<string,string> */
        public function metadata(): array {}
        /** @return list<ShardLocation> */
        public function shards(): array {}
        public function lastModified(): int {}
    }

    final class GetResult {
        public function statusCode(): int {}         // 200/206
        public function body(): string {}            // empty if as_stream=true
        /** @return resource|null */
        public function stream() {}
        public function contentRange(): ?string {}
        public function size(): int {}
        /** @return array<string,string> */
        public function headers(): array {}
    }

    final class ListResult {
        /** @return list<string> */
        public function keys(): array {}
        public function isTruncated(): bool {}
        public function nextCursor(): ?string {}
    }

    final class Lease {
        public function key(): string {}
        public function holder(): string {}
        public function expiresAtMs(): int {}
        public function token(): string {}
    }

    final class ShardLocation {
        public function key(): string {}
        public function index(): int {}       // 0..(k+m-1)
        public function nodeId(): string {}
        public function zone(): string {}
        public function tier(): Tier {}
        public function checksum(): string {} // e.g. sha256 hex
        public function size(): int {}
        public function isParity(): bool {}
        public function isLocal(): bool {}
    }

    final class RepairStatus {
        public function key(): string {}
        public function neededShards(): int {}
        public function repairedShards(): int {}
        public function progressPercent(): float {}
        public function lastError(): ?string {}
        public function active(): bool {}
    }

    final class ClusterInfo {
        /** @return list<NodeInfo> */
        public function nodes(): array {}
        public function zones(): int {}
        public function totalRamBytes(): int {}
        public function totalSsdBytes(): int {}
        public function totalDiskBytes(): int {}
    }

    final class NodeInfo {
        public function id(): string {}
        public function zone(): string {}
        public function healthy(): bool {}
        public function cpuLoad(): float {}
        public function memUsedBytes(): int {}
        public function memTotalBytes(): int {}
        public function ssdUsedBytes(): int {}
        public function ssdTotalBytes(): int {}
        public function diskUsedBytes(): int {}
        public function diskTotalBytes(): int {}
        public function hotSetObjects(): int {}
        public function shardsHeld(): int {}
    }

    final class ReadPlan {
        /** @return list<Assignment> */
        public function assignments(): array {}
        public function worldSize(): int {}
        public function localRank(): int {}
        public function concurrency(): int {}
        public function estimatedBytes(): int {}
        /** @return array<string,mixed> */
        public function costModel(): array {}
    }

    final class Assignment {
        public function nodeId(): string {}
        /** @return list<string> keys or stripe IDs */
        public function keys(): array {}
        public function preferLocal(): bool {}
    }
}

/* ===========================
 * Semantic DNS
 * =========================== */
namespace King\SemanticDNS {
    use King\Config;

    final class Resolver {
        public function __construct(?Config $config = null) {}

        /**
         * Resolve a service by semantic attributes.
         * @param array<string,string> $attributes
         * @return list<ServiceRecord>
         */
        public function resolve(string $service, array $attributes = []): array {}

        public function motherNodeDiscovery(string $namespace): MotherNode {}
    }

    final class ServiceRecord {
        public function host(): string {}
        public function port(): int {}
        /** @return array<string,string> */
        public function meta(): array {}
        public function protocol(): string {} // e.g. "h3","quic","grpc"
        public function weight(): int {}
    }

    final class RouteDecision {
        public function selected(): ServiceRecord {}
        /** @return list<ServiceRecord> */
        public function alternatives(): array {}
    }

    final class MotherNode {
        public function address(): string {}
        public function id(): string {}
        /** @return array<string,string> */
        public function attributes(): array {}
    }
}

/* ===========================
 * Telemetry (Metrics + Tracing)
 * =========================== */
namespace King\Telemetry {
    use King\Config;

    final class Metrics {
        public function __construct(?Config $config = null) {}
        public function counter(string $name, string $description = ''): Counter {}
        public function gauge(string $name, string $description = ''): Gauge {}
        public function histogram(string $name, string $description = ''): Histogram {}
        /** Export snapshot (e.g. Prometheus/OpenMetrics). */
        public function export(): string {}
    }

    interface Metric {}

    final class Counter implements Metric {
        /** @param array<string,string> $labels */
        public function add(float $value = 1.0, array $labels = []): void {}
    }

    final class Gauge implements Metric {
        /** @param array<string,string> $labels */
        public function set(float $value, array $labels = []): void {}
    }

    final class Histogram implements Metric {
        /** @param array<string,string> $labels */
        public function observe(float $value, array $labels = []): void {}
    }

    final class Tracer {
        public function __construct(?Config $config = null) {}
        /** @param array<string,mixed> $attrs */
        public function startSpan(string $name, array $attrs = []): Span {}
        public function exporter(): ?OTelExporter {}
    }

    final class Span {
        /** @param array<string,mixed> $attrs */
        public function setAttributes(array $attrs): void {}
        /** @param array<string,mixed> $attrs */
        public function addEvent(string $name, array $attrs = []): void {}
        public function end(): void {}
    }

    /** OpenTelemetry exporter (protocol depends on build). */
    final class OTelExporter {
        /** @param array<string,mixed> $resourceAttrs */
        public function configure(string $endpoint, array $resourceAttrs = []): void {}
        public function forceFlush(): void {}
    }
}

/* ===========================
 * Autoscaling
 * =========================== */
namespace King\Autoscaling {
    use King\Config;

    final class Supervisor {
        public function __construct(?Config $config = null) {}
        public function setPolicy(Policy $policy): void {}
        public function evaluate(Resources $current): Decision {}
    }

    final class Resources {
        public function __construct(float $cpuLoad, float $memLoad, int $rqps, int $conns) {}
        public function cpuLoad(): float {}
        public function memLoad(): float {}
        public function requestsPerSec(): int {}
        public function connections(): int {}
    }

    interface Policy {
        public function decide(Resources $r): Decision;
    }

    final class DefaultPolicy implements Policy {
        public function __construct(
            float $scaleUpCpu = 0.80,
            float $scaleDownCpu = 0.30,
            int $minWorkers = 1,
            int $maxWorkers = 0 /* 0 = cores */
        ) {}
        public function decide(Resources $r): Decision {}
    }

    final class Decision {
        public function desiredWorkers(): int {}
        public function reason(): string {}
    }
}

/* ===========================
 * WebSocket over HTTP/3
 * =========================== */
namespace King\WebSocket {
    use King\CancelToken;
    use King\Config;

    final class Server {
        public function __construct(string $host, int $port, ?Config $config = null) {}
        /** Events: "connect","message","close","error". */
        public function on(string $event, callable $handler): void {}
        public function run(?CancelToken $cancel = null): void {}
        public function stop(int $grace_ms = 30000): void {}
    }

    final class Connection {
        public function __construct(string $url, ?array $headers = null, ?array $options = null) {}
        public function send(string $message): void {}
        public function sendBinary(string $payload): void {}
        public function ping(?string $data = null): void {}
        public function close(int $code = 1000, ?string $reason = null): void {}
        /**
         * @return array{
         *   id:string,remote_addr:string,protocol?:string,headers:array<string,string[]>
         * }
         */
        public function getInfo(): array {}
    }
}
