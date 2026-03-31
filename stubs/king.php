<?php
declare(strict_types=1);

/**
 * King PHP Extension Stubs
 *
 * IDE/type stubs mirroring the current procedural/resource runtime API.
 * Keep in sync with C arginfo (generated stubs).
 *
 * @api
 * @version 1.0.0
 */

namespace {
    /**
     * Establish a local runtime QUIC session handle backed by a real
     * non-blocking UDP socket and return a `King\Session` resource.
     * Accepts either an inline config array or a `King\Config` resource.
     * @param mixed $config
     * @return resource|false
     * @throws \King\NetworkException
     */
    function king_connect(string $host, int $port, mixed $config = null) {}

    /**
     * Close a low-level session handle.
     * The current runtime keeps the resource readable so stats can
     * still report a closed snapshot after shutdown.
     */
    function king_close($session): bool {}

    /**
     * Send a one-shot client request through the active runtime dispatcher.
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
     * Only absolute `http://` URLs are live in the current runtime;
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
     * Direct live HTTP/3 one-shot request path over the active QUIC runtime.
     * Supports absolute `https://` URLs and returns the normalized response
     * snapshot used across the active client leaves.
     * @param array<string,mixed>|null $headers
     * @param array<string,mixed>|null $options
     * @return array<string,mixed>|false
     * @throws \King\NetworkException
     * @throws \King\TimeoutException
     * @throws \King\TlsException
     * @throws \King\ProtocolException
     */
    function king_http3_request_send(string $url, ?string $method = 'GET', ?array $headers = null, mixed $body = null, ?array $options = null): array|false {}

    /**
     * Materialize an on-wire validated WebSocket client connection resource.
     * The current runtime accepts absolute `ws://` and `wss://` URLs,
     * performs a real client handshake, snapshots optional handshake headers
     * plus `connection_config`, `max_payload_size`, `ping_interval_ms`, and
     * `handshake_timeout_ms`, and returns a `King\WebSocket` resource.
     * @param array<string,mixed>|null $headers
     * @param array<string,mixed>|null $options
     * @return resource|false
     */
    function king_client_websocket_connect(string $url, ?array $headers = null, ?array $options = null) {}

    /**
     * Send a WebSocket text or binary frame on an active `King\WebSocket`
     * resource.
     * Client-created resources use a live socket; server-upgrade resources
     * still use the local server-side runtime slice in v1.
     * @param mixed $websocket
     */
    function king_client_websocket_send(mixed $websocket, string $data, bool $is_binary = false): bool {}

    /**
     * Receive the next available frame payload from an active
     * `King\WebSocket` resource. Returns `""` when no payload is currently
     * queued and the connection remains open.
     * @param mixed $websocket
     * @return string|false
     */
    function king_client_websocket_receive(mixed $websocket, int $timeout_ms = -1): string|false {}

    /**
     * Send a WebSocket ping on an active `King\WebSocket` resource.
     * @param mixed $websocket
     */
    function king_client_websocket_ping(mixed $websocket, string $payload = ""): bool {}

    /**
     * Return the current numeric connection state for an active
     * `King\WebSocket` resource.
     * @param mixed $websocket
     */
    function king_client_websocket_get_status(mixed $websocket): int {}

    /**
     * Close the active `King\WebSocket` resource with optional close metadata.
     * Client-created resources send a real close frame and keep already
     * received payloads drainable until empty.
     * @param mixed $websocket
     */
    function king_client_websocket_close(mixed $websocket, int $status_code = 1000, string $reason = ""): bool {}

    /**
     * Alias for `king_client_websocket_send()` over the same runtime.
     * @param mixed $websocket
     */
    function king_websocket_send(mixed $websocket, string $data, bool $is_binary = false): bool {}

    /**
     * Create an MCP connection-state resource for the active runtime.
     * This stores host, port, optional `King\Config`, and the explicit
     * open/closed lifecycle for the active remote peer socket.
     * @param mixed $config
     * @param array<string,mixed>|null $options
     * @return resource|false
     */
    function king_mcp_connect(string $host, int $port, mixed $config, ?array $options = null) {}

    /**
     * Validate the active MCP connection state and request shape, then
     * exchange a unary request with the configured remote peer and return the
     * normalized response payload.
     * @param mixed $connection
     * `options` may include `timeout_ms`, `deadline_ms` (monotonic deadline
     * in milliseconds), and `cancel`.
     * @param array<string,mixed>|null $options
     * @return string|false
     */
    function king_mcp_request(mixed $connection, string $service_name, string $method_name, string $request_payload, ?array $options = null): string|false {}

    /**
     * Drain a PHP stream and upload the bytes to the active remote MCP peer
     * under the `(service, method, stream_identifier)` tuple. The tuple is
     * encoded internally so binary-safe identifiers stay collision-free.
     * `options` may include `timeout_ms`, `deadline_ms` (monotonic deadline
     * in milliseconds), and `cancel`.
     * @param mixed $connection
     * @param resource $stream
     */
    function king_mcp_upload_from_stream(mixed $connection, string $service_name, string $method_name, string $stream_identifier, $stream, ?array $options = null): bool {}

    /**
     * Resolve a previously uploaded remote MCP transfer by treating
     * `$request_payload` as the opaque collision-free transfer identifier,
     * then stream the fetched bytes into the destination stream.
     * `options` may include `timeout_ms`, `deadline_ms` (monotonic deadline
     * in milliseconds), and `cancel`.
     * @param mixed $connection
     * @param resource $stream
     */
    function king_mcp_download_to_stream(mixed $connection, string $service_name, string $method_name, string $request_payload, $stream, ?array $options = null): bool {}

    /**
     * Close an MCP connection-state resource and the active remote peer
     * socket. The resource remains readable for stable post-close validation.
     * @param mixed $connection
     */
    function king_mcp_close(mixed $connection): bool {}

    /**
     * Drive events on a low-level session handle.
     * In the current runtime this waits on the active UDP socket via
     * `poll(2)`, updates transport counters, may emit a controlled probe
     * datagram when the configured QUIC ping interval is forced to `0`, and
     * drains received datagrams into the local session snapshot.
     * Validation failures still return `false`; native socket failures throw.
     * @throws \King\NetworkException
     */
    function king_poll($session, int $timeout_ms = 0): bool {}

    /**
     * Record a local stream-cancel intent on a `King\Session` resource.
     * The current runtime does not have a live transport backend yet,
     * but it stores cancel state, rejects duplicate stream IDs per session,
     * and exposes the result via `king_get_stats()`.
     * @param mixed $session
     */
    function king_cancel_stream(int $stream_id, string $how = 'both', mixed $session = null): bool {}

    /**
     * Client-facing alias for `king_cancel_stream()` with the same local
     * runtime semantics.
     * @param mixed $session
     */
    function king_client_stream_cancel(int $stream_id, string $how = 'both', mixed $session = null): bool {}

    /**
     * Validate and store the default CA file path for the active local
     * runtime TLS runtime.
     */
    function king_set_ca_file(string $path): bool {}

    /**
     * Client-facing alias for `king_set_ca_file()`.
     */
    function king_client_tls_set_ca_file(string $path): bool {}

    /**
     * Validate and store the default client certificate and key paths for the
     * active local runtime TLS runtime.
     */
    function king_set_client_cert(string $cert, string $key): bool {}

    /**
     * Client-facing alias for `king_set_client_cert()`.
     */
    function king_client_tls_set_client_cert(string $cert, string $key): bool {}

    /**
     * Export the current session-local ticket blob from the active client
     * runtime.
     * @param mixed $session
     */
    function king_export_session_ticket(mixed $session): string {}

    /**
     * Client-facing alias for `king_export_session_ticket()`.
     * @param mixed $session
     */
    function king_client_tls_export_session_ticket(mixed $session): string {}

    /**
     * Import a session ticket into the active client runtime and
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
     * Active one-shot on-wire HTTP/1 listener leaf.
     * Binds a real TCP socket, accepts exactly one request, materializes one
     * `King\Session` snapshot over the accepted socket, invokes the handler,
     * writes one HTTP/1 response when no websocket upgrade takes ownership,
     * and then closes the listener/session. This is the narrow v1 wire leaf
     * for real server-side websocket upgrade verification.
     * @param mixed $config
     */
    function king_http1_server_listen_once(string $host, int $port, mixed $config, callable $handler): bool {}

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
     * Active one-shot on-wire HTTP/2 listener leaf.
     * Binds a real TCP socket, accepts exactly one h2c connection, reads one
     * request stream, materializes one `King\Session` snapshot over the
     * accepted socket, invokes the handler once with a normalized HTTP/2-style
     * request array, writes one HTTP/2 response, sends a clean GOAWAY, and
     * then closes the listener/session.
     * @param mixed $config
     */
    function king_http2_server_listen_once(string $host, int $port, mixed $config, callable $handler): bool {}

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
     * Active one-shot on-wire HTTP/3 listener leaf.
     * Binds a real UDP socket, accepts exactly one QUIC connection, drives one
     * HTTP/3 request stream over the active `quiche` runtime, materializes one
     * `King\Session` snapshot over the accepted socket, invokes the handler
     * once with a normalized HTTP/3-style request array, writes one HTTP/3
     * response, sends a clean `GOAWAY`, and then closes the listener/session.
     * @param mixed $config
     */
    function king_http3_server_listen_once(string $host, int $port, mixed $config, callable $handler): bool {}

    /**
     * Active local server-index dispatcher.
     * Resolves `null`, inline config arrays, and `King\Config` handles,
     * chooses HTTP/3 when TCP is disabled, otherwise HTTP/2 when
     * `http2.enable` is active, otherwise HTTP/1, and forwards to the
     * selected listener leaf. In the current runtime, HTTP/1, HTTP/2,
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
     * The current runtime stores the normalized header pairs on the
     * session snapshot and exposes the last batch plus counters via
     * `king_get_stats()`.
     * @param mixed $session
     * @param array<int|string,mixed> $hints
     */
    function king_server_send_early_hints(mixed $session, int $stream_id, array $hints): bool {}

    /**
     * Materialize a server-side `King\WebSocket` resource for one stream on
     * an open `King\Session` resource or object.
     * Local HTTP/1/2/3 listener leaves still produce a local-only marker
     * resource with close/status metadata but no frame I/O. The on-wire
     * `king_http1_server_listen_once()` leaf upgrades a real HTTP/1 websocket
     * request, attaches socket-backed frame I/O, and keeps the resource
     * handler-owned for the callback lifetime.
     * @param mixed $session
     * @return resource|false
     */
    function king_server_upgrade_to_websocket(mixed $session, int $stream_id) {}

    /**
     * Validate and apply a local server-side TLS reload snapshot on an open
     * `King\Session` resource or object.
     * The current runtime requires readable replacement certificate
     * and key paths, also requires the configured `tls_ticket_key_file` to
     * be readable when set, and stores the last local server-TLS snapshot
     * plus apply/reload counters on the shared session stats.
     * @param mixed $session
     */
    function king_server_reload_tls_config(mixed $session, string $cert_file_path, string $key_file_path): bool {}

    /**
     * Initialize the local server-side telemetry snapshot on an open
     * `King\Session` resource or object.
     * Accepts `null`, inline config arrays, and `King\Config` handles.
     * @param mixed $session
     * @param mixed $config
     */
    function king_server_init_telemetry(mixed $session, mixed $config): bool {}

    /**
     * Return the normalized peer-certificate subject snapshot for a live
     * server-capable session when the provided capability matches.
     * @param mixed $session
     * @return array<string,mixed>|false
     */
    function king_session_get_peer_cert_subject(mixed $session, int $capability): array|false {}

    /**
     * Mark a live server-capable session as closed by the server side when
     * the provided capability matches the active session snapshot.
     * @param mixed $session
     */
    function king_session_close_server_initiated(mixed $session, int $capability, int $error_code = 0, string $reason = ''): bool {}

    /**
     * Validate and materialize a local admin-listener snapshot for an open
     * server `King\Session` resource or object.
     * The current runtime resolves `null`, inline config arrays, and
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
     * The canonical runtime-build override surface uses namespaced keys
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
     * Last error message from the shared runtime error buffer
     * (prefer exceptions).
     */
    function king_get_last_error(): string {}

    /**
     * Compatibility alias for the shared runtime error buffer used by the
     * active local WebSocket runtime.
     */
    function king_client_websocket_get_last_error(): string {}

    /** Compatibility alias for the shared error buffer used by the MCP runtime. */
    function king_mcp_get_error(): string {}

    /**
     * Transport stats for a low-level handle.
     * The current runtime returns a stable local snapshot with
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
     * config binding fields for the currently attached runtime config,
     * and `config_option_count`.
     * @return array<string,mixed>|false
     */
    function king_get_stats($session): array|false {}

    /**
     * Initializes local runtime settings for the object-store/CDN
     * layer without replacing the extension-wide INI configuration.
     * The verified v1 storage contract is `local_fs`, `distributed`,
     * `cloud_s3`, `cloud_gcs`, and `cloud_azure` payload storage with local
     * `.meta` sidecars.
     * `memory_cache` is accepted as a compatibility alias that resolves
     * to the same local filesystem backend. The `distributed` backend now
     * stores committed payloads under `storage_root_path/.king-distributed/`
     * and also persists a private coordinator-state file under
     * `storage_root_path/.king-distributed/coordinator.state`, exposing that
     * runtime recovery/status surface through `king_object_store_get_stats()`.
     * Supported keys are
     * `primary_backend`, `backup_backend`, `storage_root_path`,
     * `max_storage_size_bytes`, `replication_factor`, `chunk_size_kb`,
     * `cloud_credentials`, and `cdn_config` with `enabled`,
     * `cache_size_mb`, `default_ttl_seconds`. `cloud_credentials`
     * currently accepts `api_endpoint` or `endpoint`, `bucket`,
     * provider-specific auth material such as `access_key` plus
     * `secret_key` for `cloud_s3`, `access_token` for `cloud_gcs`, or
     * `access_token` plus `container` for `cloud_azure`, plus optional
     * `region`, `session_token`, `path_style`, `account_name`, and
     * `verify_tls`.
     * `max_storage_size_bytes` is now the shared runtime quota on committed
     * primary-inventory bytes across `local_fs` and the real cloud backends.
     * It is not multiplied by replication/backups and it is not a claim about
     * provider-native bucket/account quotas.
     * @param array<string,mixed> $config
     * @throws \King\ValidationException
     */
    function king_object_store_init(array $config): bool {}

    /**
     * Stores one fully materialized object payload in the current runtime
     * object-store registry. Supported write options today are
     * `content_type`, `content_encoding`, `expires_at`, `cache_ttl_sec`,
     * `object_type`, `cache_policy`, `integrity_sha256`, `if_match`,
     * `if_none_match`, and `expected_version`.
     * `if_match`/`if_none_match` follow the stored strong-validator/ETag
     * contract in the spirit of RFC 7232, while `expected_version`
     * provides the matching object-store-native version precondition.
     * Object mutation is exclusive per object id: conflicting writes,
     * deletes, or active resumable upload sessions on the same object fail
     * with `King\RuntimeException` instead of racing hidden last-writer-wins
     * behavior into the runtime.
     * For bounded-memory ingress, use `king_object_store_put_from_stream()`.
     * For explicit provider-native cloud upload sessions, use
     * `king_object_store_begin_resumable_upload()` and its append/complete
     * companions.
     * @param array<string,mixed>|null $options
     * @throws \King\ValidationException|\King\RuntimeException|\King\SystemException
     */
    function king_object_store_put(string $object_id, string $data, ?array $options = null): bool {}

    /**
     * Stores one object payload from a readable stream using bounded-memory
     * staging and the same metadata/precondition contract as
     * `king_object_store_put()`. Supported write options today are
     * `content_type`, `content_encoding`, `expires_at`, `cache_ttl_sec`,
     * `object_type`, `cache_policy`, `integrity_sha256`, `if_match`,
     * `if_none_match`, and `expected_version`.
     * This surface provides bounded-memory ingress for large objects. For
     * explicit provider-native cloud upload sessions, use
     * `king_object_store_begin_resumable_upload()` and its append/complete
     * companions.
     * Like `king_object_store_put()`, this is an exclusive per-object
     * mutation surface.
     * @param array<string,mixed>|null $options
     * @throws \King\ValidationException|\King\RuntimeException|\King\SystemException
     */
    function king_object_store_put_from_stream(string $object_id, mixed $stream, ?array $options = null): bool {}

    /**
     * Starts a provider-native cloud upload session for the active primary
     * object-store backend. The public contract is one resumable upload
     * session shape that maps to S3 multipart upload, GCS resumable upload,
     * and Azure Block Blob block-list staging internally.
     * The returned session snapshot now also exposes the active
     * `chunk_size_bytes` contract for sequential appends. Each appended chunk
     * must be non-empty and no larger than that value; the final chunk may be
     * shorter. The session holds the object's mutation lock until completion
     * or abort, so conflicting writes/deletes fail instead of racing the
     * active upload.
     * Supported begin options today are the same metadata/precondition
     * options as `king_object_store_put()`: `content_type`,
     * `content_encoding`, `expires_at`, `cache_ttl_sec`, `object_type`,
     * `cache_policy`, `integrity_sha256`, `if_match`, `if_none_match`, and
     * `expected_version`.
     * @return array<string,mixed>
     * @throws \King\ValidationException|\King\RuntimeException|\King\SystemException
     */
    function king_object_store_begin_resumable_upload(string $object_id, ?array $options = null): array {}

    /**
     * Appends one sequential chunk to an active provider-native cloud upload
     * session. Supported options today are `final` to mark the last chunk.
     * Each appended chunk must contain at least one byte and no more than the
     * session's exported `chunk_size_bytes`; `final=true` only marks the tail
     * chunk and does not widen that limit. Chunks that would push the
     * committed object past `max_storage_size_bytes` are rejected with
     * `King\ValidationException`.
     * @return array<string,mixed>
     * @throws \King\ValidationException|\King\RuntimeException|\King\SystemException
     */
    function king_object_store_append_resumable_upload_chunk(string $upload_id, mixed $stream, ?array $options = null): array {}

    /**
     * Completes an active provider-native cloud upload session and returns the
     * final session snapshot that was committed into the object-store.
     * Upload sessions now survive `king_object_store_init()` re-entry and
     * process/request restart against the same `storage_root_path`; resumed
     * sessions surface `recovered_after_restart=true` in their status
     * snapshots until they are completed or aborted.
     * @return array<string,mixed>
     * @throws \King\ValidationException|\King\RuntimeException|\King\SystemException
     */
    function king_object_store_complete_resumable_upload(string $upload_id): array {}

    /**
     * Aborts an active provider-native cloud upload session. Restart-recovered
     * sessions may also be aborted through the same `upload_id`, and abort
     * releases the per-object mutation lock for a replacement session.
     * @throws \King\RuntimeException|\King\SystemException
     */
    function king_object_store_abort_resumable_upload(string $upload_id): bool {}

    /**
     * Returns the current provider-native cloud upload session snapshot, or
     * `false` when the session does not exist anymore. The stable snapshot now
     * includes `chunk_size_bytes`, `sequential_chunks_required`, and
     * `final_chunk_may_be_shorter`, plus `recovered_after_restart` for
     * sessions rehydrated from persisted upload state.
     * @return array<string,mixed>|false
     */
    function king_object_store_get_resumable_upload_status(string $upload_id): array|false {}

    /**
     * Stable object-store inventory snapshot for the current runtime.
     * @return list<array<string,mixed>>
     * @throws \King\RuntimeException|\King\SystemException
     */
    function king_object_store_list(): array {}

    /**
     * Object-store lookup for the current runtime.
     * Returns the stored payload as one materialized string for local registry
     * hits and `false` on miss. Supported read options today are `offset`
     * and `length`, using byte-range semantics aligned with RFC 7233.
     * Full-object reads validate stored `integrity_sha256` metadata when
     * present. For bounded-memory egress, use
     * `king_object_store_get_to_stream()`.
     * @param array<string,mixed>|null $options
      * @throws \King\ValidationException|\King\RuntimeException|\King\SystemException
     */
    function king_object_store_get(string $object_id, ?array $options = null): string|false {}

    /**
     * Streams one object payload into a writable stream using bounded-memory
     * staged egress. Supported read options today are `offset` and `length`,
     * using byte-range semantics aligned with RFC 7233. Full-object reads
     * validate stored `integrity_sha256` metadata before any payload bytes are
     * written into the destination stream. Reads stay lock-free and observe
     * the last committed local payload/sidecar state.
     * @param array<string,mixed>|null $options
     * @throws \King\ValidationException|\King\RuntimeException|\King\SystemException
     */
    function king_object_store_get_to_stream(string $object_id, mixed $stream, ?array $options = null): bool {}

    /**
     * Object-store delete for the current runtime.
     * Returns `true` on local registry hits and `false` on miss or when a
     * conflicting per-object mutation is still active.
     * @throws \King\RuntimeException|\King\SystemException
     */
    function king_object_store_delete(string $object_id): bool {}

    /**
     * Exports one object and its `.meta` sidecar to a filesystem directory.
     * The destination directory is created when missing.
     * @return bool
     * @throws \King\RuntimeException|\King\SystemException
     */
    function king_object_store_backup_object(string $object_id, string $destination_path): bool {}

    /**
     * Imports one object (and optional `.meta`) from a filesystem directory.
     * Source path points to the backup export directory containing payload and
     * matching `<object_id>`/`<object_id>.meta` files.
     * @return bool
     * @throws \King\RuntimeException|\King\SystemException
     */
    function king_object_store_restore_object(string $object_id, string $source_path): bool {}

    /**
     * Exports all objects and sidecar metadata from the active object-store.
     * @return bool
     * @throws \King\RuntimeException|\King\SystemException
     */
    function king_object_store_backup_all_objects(string $path): bool {}

    /**
     * Imports all object payload/metadata files from a filesystem backup directory.
     * @return bool
     * @throws \King\RuntimeException|\King\SystemException
     */
    function king_object_store_restore_all_objects(string $path): bool {}

    /**
     * Runs a no-op maintenance pass over the local runtime object-store
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
     * Remove expired local object-store entries and return a maintenance
     * summary for the active runtime.
     * `expires_at` is the ordinary-read visibility boundary, while cleanup
     * is the destructive sweep that physically removes already-expired
     * objects.
     * @return array{
     *   mode:string,
     *   scanned_objects:int,
     *   expired_objects_removed:int,
     *   bytes_reclaimed:int,
     *   removal_failures:int,
     *   cleanup_at:int
     * }
     */
    function king_object_store_cleanup_expired_objects(): array {}

    /**
     * Return the stored metadata snapshot for one object-store entry, or
     * `false` when the object is absent. The current snapshot includes
     * payload metadata such as `content_type`, `content_encoding`,
     * `etag`, `integrity_sha256`, `content_length`, `version`,
     * `expires_at`, `object_type`, `object_type_name`, `cache_policy`,
     * `cache_policy_name`, `cache_ttl_seconds`, `is_expired`,
     * backend-presence flags, and HA/distribution state.
     * @return array<string,mixed>|false
     * @throws \King\ValidationException|\King\RuntimeException|\King\SystemException
     */
    function king_object_store_get_metadata(string $object_id): array|false {}

    /**
     * Stable schema inventory snapshot for the current runtime.
     * @return list<string>
     */
    function king_proto_get_defined_schemas(): array {}

    /**
     * Registers an enum name for the active runtime.
     * Successful registrations become visible via the proto lookup helpers.
     * @param array<string,int> $enum_values
     * @throws \King\ValidationException|\King\SystemException
     */
    function king_proto_define_enum(string $enum_name, array $enum_values): bool {}

    /**
     * Registers a schema name for the active runtime.
     * The current runtime validates the top-level field shape and stores the
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
     * packed and the runtime still does not build a compiled backend.
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
     * Stable enum inventory snapshot for the current runtime.
     * @return list<string>
     */
    function king_proto_get_defined_enums(): array {}

    /**
     * Encodes user data using a named IIBIN schema.
     * Unknown schemas throw immediately. The current runtime supports
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
     * Unknown schemas throw immediately. The current runtime supports
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
     * Registrations are name-only in the active runtime.
     */
    function king_proto_is_defined(string $name): bool {}

    /**
     * Checks whether a schema is currently registered.
     * Registrations are name-only in the active runtime.
     */
    function king_proto_is_schema_defined(string $schema_name): bool {}

    /**
     * Checks whether an enum is currently registered.
     * Registrations are name-only in the active runtime.
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
     * Initialize the active telemetry runtime from an inline config array.
     * @param array<string,mixed> $config
     */
    function king_telemetry_init(array $config): bool {}

    /**
     * Start one local telemetry span and return its active span identifier.
     * @param array<string,mixed>|null $attributes
     */
    function king_telemetry_start_span(string $operation_name, ?array $attributes = null, ?string $parent_span_id = null): string {}

    /**
     * End one local telemetry span and optionally merge final attributes.
     * @param array<string,mixed>|null $final_attributes
     */
    function king_telemetry_end_span(string $span_id, ?array $final_attributes = null): bool {}

    /**
     * Record one metric datapoint in the active telemetry runtime.
     * @param array<string,string>|null $labels
     */
    function king_telemetry_record_metric(string $metric_name, float $value, ?array $labels = null, string $metric_type = 'gauge'): bool {}

    /**
     * Record one structured log entry in the active telemetry runtime.
     * @param array<string,mixed>|null $attributes
     */
    function king_telemetry_log(string $level, string $message, ?array $attributes = null): bool {}

    /**
     * Flushes pending telemetry data for the current runtime.
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
     * Returns null until the current runtime has a live span runtime.
     * @return array<string,mixed>|null
     */
    function king_telemetry_get_trace_context(): ?array {}

    /**
     * Returns the provided headers unchanged until the current runtime has a
     * live span runtime to inject.
     * @param array<string,string>|null $headers
     * @return array<string,string>
     */
    function king_telemetry_inject_context(?array $headers = null): array {}

    /**
     * Returns false until the current runtime has a tracing runtime that can
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
     *     metadata_cache_max_entries:int,
     *     directstorage_enabled:bool,
     *     local_registry_initialized:bool,
     *     runtime_initialized:bool,
     *     runtime_primary_backend:string,
     *     runtime_storage_root_path:string,
     *     runtime_max_storage_size_bytes:int,
     *     runtime_capacity_mode:string,
     *     runtime_capacity_scope:string,
     *     runtime_capacity_enforced:bool,
     *     runtime_capacity_available_bytes:int|null,
     *     runtime_replication_factor:int,
     *     runtime_chunk_size_kb:int,
     *     runtime_metadata_cache_entries:int,
     *     runtime_metadata_cache_eviction_count:int,
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
     * Caches an existing local object-store entry in the runtime CDN cache.
     * Returns `false` when the object does not exist in the local object store.
     * @param array{ttl_sec?:int}|null $options
     * @throws \King\ValidationException|\King\RuntimeException|\King\SystemException
     */
    function king_cdn_cache_object(string $object_id, ?array $options = null): bool {}

    /**
     * Invalidates one cached object or clears the full local runtime CDN cache.
     * Returns the number of removed cache entries.
     * @throws \King\ValidationException|\King\RuntimeException
     */
    function king_cdn_invalidate_cache(?string $object_id = null): int {}

    /**
     * CDN edge-node inventory for the active runtime.
     * @return list<array<string,mixed>>
     */
    function king_cdn_get_edge_nodes(): array {}

    /**
     * Autoscaling runtime status for the active controller/process.
     * `provider_mode` is honest about current depth:
     * `hetzner_active` when a controller token is configured,
     * `hetzner_readonly` when the Hetzner backend is selected without a token,
     * and simulated modes for every non-Hetzner provider path.
     * @return array{
     *   initialized:bool,
     *   monitoring_active:bool,
     *   current_instances:int,
     *   provider:string,
     *   provider_mode:string,
     *   controller_token_configured:bool,
     *   managed_nodes:int,
     *   active_managed_nodes:int,
     *   provisioned_managed_nodes:int,
     *   registered_managed_nodes:int,
     *   draining_managed_nodes:int,
     *   cooldown_remaining_sec:int,
     *   last_monitor_tick_at:int,
     *   action_count:int,
     *   api_endpoint:string,
     *   state_path:string,
     *   last_action_kind:string,
     *   last_signal_source:string,
     *   last_decision_reason:string,
     *   last_error:string,
     *   last_warning:string
     * }
     */
    function king_autoscaling_get_status(): array {}

    /**
     * Autoscaling metrics collected by the active runtime.
     * @return array{
     *   cpu_utilization:float,
     *   memory_utilization:float,
     *   active_connections:int,
     *   requests_per_second:int,
     *   response_time_ms:int,
     *   queue_depth:int,
     *   timestamp:int
     * }
     */
    function king_autoscaling_get_metrics(): array {}

    /**
     * Initialize the active autoscaling runtime from an inline config array.
     * The provider contract stays generic, but only the Hetzner path is
     * production-honest in-tree today; other providers intentionally remain
     * simulated behind the same interface. Cloud API tokens stay
     * `php.ini`-only and are not accepted through this userland config.
     * @param array<string,mixed> $config
     */
    function king_autoscaling_init(array $config): bool {}

    /**
     * Start the active local autoscaling monitoring loop.
     * In the current runtime each call also executes one synchronous
     * controller tick because there is no detached background worker yet.
     */
    function king_autoscaling_start_monitoring(): bool {}

    /**
     * Stop the active local autoscaling monitoring loop.
     */
    function king_autoscaling_stop_monitoring(): bool {}

    /**
     * Return the current managed-node inventory for the active autoscaling runtime.
     * Hetzner nodes stay `provisioned` until they register back and only become
     * traffic-bearing after an explicit ready transition.
     * @return list<array{
     *   server_id:int,
     *   name:string,
     *   provider_status:string,
     *   lifecycle:string,
     *   active:bool,
     *   created_at:int,
     *   registered_at:int,
     *   ready_at:int,
     *   draining_at:int,
     *   deleted_at:int
     * }>
     */
    function king_autoscaling_get_nodes(): array {}

    /**
     * Trigger a local autoscaling scale-up decision.
     */
    function king_autoscaling_scale_up(int $instances = 1): bool {}

    /**
     * Trigger a local autoscaling scale-down decision.
     */
    function king_autoscaling_scale_down(int $instances = 1): bool {}

    /**
     * Mark one provisioned managed node as registered with the controller.
     */
    function king_autoscaling_register_node(int $server_id, ?string $name = null): bool {}

    /**
     * Admit one registered managed node into the ready pool.
     */
    function king_autoscaling_mark_node_ready(int $server_id): bool {}

    /**
     * Drain one ready managed node before provider-side termination.
     */
    function king_autoscaling_drain_node(int $server_id): bool {}

    /**
     * Initializes the local semantic-DNS core/runtime config snapshot.
     * Supported keys include bind/port, TTL/discovery limits, semantic mode,
     * mother-node URI, and optional `routing_policies`.
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
     * Semantic-DNS topology snapshot for the active runtime.
     * Registered services and mother nodes are exposed from the local
     * in-memory runtime registries.
     * @return array{
     *   services:list<array<string,mixed>>,
     *   mother_nodes:list<array<string,mixed>>,
     *   statistics:array<string,int>,
     *   topology_generated_at:int
     * }
     */
    function king_semantic_dns_get_service_topology(): array {}

    /**
     * Registers a semantic-DNS service record in the active runtime.
     * Required keys: `service_id`, `service_name`, `service_type`, `hostname`,
     * and `port`. Optional keys: `status`, `current_load_percent`,
     * `active_connections`, `total_requests`, and scalar `attributes`.
     * @param array<string,mixed> $service_info
     * @throws \King\ValidationException|\King\RuntimeException|\King\SystemException
     */
    function king_semantic_dns_register_service(array $service_info): bool {}

    /**
     * Registers a semantic-DNS mother node in the active runtime.
     * Required keys: `node_id`, `hostname`, and `port`. Optional keys:
     * `status`, `managed_services_count`, and `trust_score`.
     * @param array<string,mixed> $mother_node_info
     * @throws \King\ValidationException|\King\RuntimeException|\King\SystemException
     */
    function king_semantic_dns_register_mother_node(array $mother_node_info): bool {}

    /**
     * Semantic-DNS service discovery snapshot for the active runtime.
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
     * Semantic-DNS routing decision for the active runtime.
     * Returns the best registered healthy/degraded service for the requested
     * name, or the stable no-route response when none matches.
     * @param array<string,mixed>|null $client_info
     * @return array<string,mixed>
     */
    function king_semantic_dns_get_optimal_route(string $service_name, ?array $client_info = null): array {}

    /**
     * Updates the status of a registered semantic-DNS service in the active
     * runtime and may also patch live load counters.
     * @param array{
     *   current_load_percent?:int,
     *   active_connections?:int,
     *   total_requests?:int
     * }|null $metrics
     * @throws \King\ValidationException
     */
    function king_semantic_dns_update_service_status(string $service_id, string $status, ?array $metrics = null): bool {}

    /**
     * System health summary for the active runtime.
     * @return array{
     *   overall_healthy:bool,
     *   build:string,
     *   version:string,
     *   config_override_allowed:bool
     * }
     */
    function king_system_health_check(): array {}

    /**
     * Initialize the active system-integration runtime from an inline config
     * array and materialize the coordinated component snapshot.
     * @param array<string,mixed> $config
     */
    function king_system_init(array $config): bool {}

    /**
     * System status summary for the active runtime.
     * @return array{
     *   system_info: array<string,mixed>,
     *   configuration: array<string,mixed>,
     *   autoscaling: array<string,mixed>
     * }
     */
    function king_system_get_status(): array {}

    /**
     * System metrics snapshot for the active runtime.
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
     * Small system performance snapshot for the active runtime.
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
     * Small per-component descriptor for the active runtime.
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

    /**
     * Process one normalized request payload through the active system
     * integration runtime.
     * @param array<string,mixed> $request_data
     * @return array<string,mixed>
     */
    function king_system_process_request(array $request_data): array {}

    /**
     * Restart one named local system component inside the active runtime.
     */
    function king_system_restart_component(string $name): bool {}

    /**
     * Shut down the active system-integration runtime and coordinated
     * component snapshots.
     */
    function king_system_shutdown(): bool {}

    /**
     * Execute the current local pipeline-orchestrator runtime over the
     * provided initial data and normalized step list.
     * This local path is unavailable when
     * `king.orchestrator_execution_backend=file_worker`; in that mode the
     * run must be queued via `king_pipeline_orchestrator_dispatch()` and
     * consumed by `king_pipeline_orchestrator_worker_run_next()`. When
     * `king.orchestrator_execution_backend=remote_peer`, the controller
     * sends the run to the configured remote host/port worker peer and
     * still persists the run snapshot locally.
     * @param array<int,array<string,mixed>> $pipeline
     * @param array<string,mixed>|null $exec_options
     * @return array<string,mixed>
     */
    function king_pipeline_orchestrator_run(mixed $initial_data, array $pipeline, ?array $exec_options = null): array {}

    /**
     * Queue one pipeline run onto the configured file-worker backend.
     * Requires `king.orchestrator_execution_backend=file_worker` plus a
     * non-empty `king.orchestrator_worker_queue_path`.
     * @param array<int,array<string,mixed>> $pipeline
     * @param array<string,mixed>|null $exec_options
     * @return array<string,mixed>
     */
    function king_pipeline_orchestrator_dispatch(mixed $initial_data, array $pipeline, ?array $exec_options = null): array {}

    /**
     * Register or replace one tool definition in the active pipeline
     * orchestrator registry.
     * @param array<string,mixed> $config
     */
    function king_pipeline_orchestrator_register_tool(string $tool_name, array $config): bool {}

    /**
     * Configure the active pipeline-orchestrator logging snapshot.
     * @param array<string,mixed> $config
     */
    function king_pipeline_orchestrator_configure_logging(array $config): bool {}

    /**
     * Claim and execute the next queued file-worker run from the configured
     * orchestrator queue. Returns `false` when the queue is empty.
     * @return array<string,mixed>|false
     */
    function king_pipeline_orchestrator_worker_run_next(): array|false {}

    /**
     * Resume one persisted non-terminal pipeline run after controller
     * restart. This continuation path is for the local and `remote_peer`
     * orchestrator backends. The file-worker backend continues work through
     * `king_pipeline_orchestrator_worker_run_next()`.
     * @return array<string,mixed>
     */
    function king_pipeline_orchestrator_resume_run(string $run_id): array {}

    /**
     * Read one persisted pipeline-run snapshot from the active orchestrator
     * state registry.
     * @return array<string,mixed>|false
     */
    function king_pipeline_orchestrator_get_run(string $run_id): array|false {}

    /**
     * Request cancellation for one persisted file-worker pipeline run.
     * Returns `false` when the run cannot be cancelled anymore.
     */
    function king_pipeline_orchestrator_cancel_run(string $run_id): bool {}
}

/* The OO surface below mirrors the currently exported runtime classes. */
namespace King {
    /**
     * Base exception for all King errors.
     */
    class Exception extends \Exception {}

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
        /** Receive the active local response object for this stream and honor optional cancel state. */
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
         * Materialize the MCP connection-state wrapper for host, port,
         * optional `King\Config`, and the explicit open/closed lifecycle over
         * the active remote peer socket.
         * @throws ValidationException
         */
        public function __construct(string $host, int $port, ?Config $config = null) {}

        /**
         * Unary RPC call over the MCP connection-state wrapper.
         * Closed connections and already cancelled tokens fail deterministically;
         * otherwise the active runtime exchanges the request with the remote
         * peer and returns the normalized response payload.
         * @throws RuntimeException|ValidationException|MCPConnectionException|MCPProtocolException|MCPTimeoutException
         */
        public function request(string $service, string $method, string $payload, ?CancelToken $cancel = null, ?array $options = null): string {}

        /**
         * Drain a source stream into the remote MCP transfer store keyed by
         * `(service, method, streamIdentifier)`. The tuple is encoded
         * internally so binary-safe identifiers stay collision-free.
         * @param resource $stream
         * @throws RuntimeException|ValidationException|MCPConnectionException|MCPProtocolException|MCPTimeoutException|MCPDataException
         */
        public function uploadFromStream(string $service, string $method, string $streamIdentifier, $stream, ?array $options = null): void {}

        /**
         * Resolve a previously uploaded remote MCP transfer by treating
         * `$payload` as the opaque collision-free transfer identifier and
         * write the fetched bytes into the provided destination stream.
         * @param resource $stream
         * @throws RuntimeException|ValidationException|MCPConnectionException|MCPProtocolException|MCPTimeoutException|MCPDataException
         */
        public function downloadToStream(string $service, string $method, string $payload, $stream, ?array $options = null): void {}

        /** Close the MCP connection state and active remote peer socket. */
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
 * WebSocket over HTTP/3
 * =========================== */
namespace King\WebSocket {
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
