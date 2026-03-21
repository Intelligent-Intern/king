/*
 * =========================================================================
 * FILENAME:   include/client/session.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Shared native session structure and helpers for the active skeleton client
 * runtime. This stays transport-free for now, but owns the real
 * King\Session resource lifecycle, config snapshots, and local stream-cancel
 * state used by the current build.
 * =========================================================================
 */

#ifndef KING_CLIENT_SESSION_H
#define KING_CLIENT_SESSION_H

#include <php.h>
#include <stdint.h>
#include <time.h>

typedef struct king_cfg_s king_cfg_t;

typedef struct _king_client_session {
    zend_string *host;
    zend_string *negotiated_alpn;
    zend_string *config_autoscale_provider;
    zend_string *config_quic_cc_algorithm;
    zend_string *config_storage_default_redundancy_mode;
    zend_string *config_cdn_cache_mode;
    zend_string *config_dns_mode;
    zend_string *config_security_cors_allowed_origins;
    zend_string *config_otel_service_name;
    zend_string *config_otel_exporter_endpoint;
    zend_string *config_otel_exporter_protocol;
    zend_string *config_geometry_calculation_precision;
    zend_string *config_smartcontract_dlt_provider;
    zend_string *config_ssh_gateway_auth_mode;
    zend_string *config_tcp_tls_min_version_allowed;
    zend_string *last_cancel_mode;
    zend_string *tls_default_ca_file;
    zend_string *tls_default_cert_file;
    zend_string *tls_default_key_file;
    zend_string *tls_ticket_key_file;
    zend_string *tls_session_ticket;
    zend_string *transport_backend;
    zend_string *transport_local_address;
    zend_string *transport_peer_address;
    zend_string *transport_socket_family;
    zend_string *transport_error_scope;
    zend_string *server_peer_cert_subject;
    zend_string *server_close_reason;
    zend_string *server_last_admin_api_bind_host;
    zend_string *server_last_admin_api_auth_mode;
    zend_string *server_last_cors_policy;
    zend_string *server_last_cors_origin;
    zend_string *server_last_cors_allow_origin;
    zend_string *server_telemetry_service_name;
    zend_string *server_telemetry_exporter_endpoint;
    zend_string *server_telemetry_exporter_protocol;
    zend_string *server_telemetry_last_protocol;
    zend_string *server_last_tls_cert_file;
    zend_string *server_last_tls_key_file;
    zend_string *server_last_tls_ticket_key_file;
    zend_string *server_last_websocket_url;
    HashTable cancelled_streams;
    HashTable server_cancel_handlers;
    HashTable server_upgraded_streams;
    zval server_last_early_hints;
    const char *config_binding;
    const char *tls_ticket_source;
    zend_long port;
    zend_long config_option_count;
    zend_long config_autoscale_max_nodes;
    zend_long config_http2_max_concurrent_streams;
    zend_long config_websocket_default_max_payload_size;
    zend_long config_websocket_default_ping_interval_ms;
    zend_long config_websocket_handshake_timeout_ms;
    zend_long config_mcp_default_request_timeout_ms;
    zend_long config_geometry_default_vector_dimensions;
    zend_long tls_session_ticket_lifetime_sec;
    zend_long poll_calls;
    zend_long next_client_bidi_stream_id;
    zend_long last_poll_timeout_ms;
    zend_long cancel_calls;
    zend_long canceled_stream_count;
    zend_long last_canceled_stream_id;
    zend_long server_cancel_handler_count;
    zend_long server_cancel_handler_invocations;
    zend_long server_last_cancel_handler_stream_id;
    zend_long server_last_cancel_invoked_stream_id;
    zend_long server_admin_api_listen_count;
    zend_long server_admin_api_reload_count;
    zend_long server_last_admin_api_port;
    zend_long server_cors_apply_count;
    zend_long server_last_cors_allowed_origin_count;
    zend_long server_telemetry_init_count;
    zend_long server_telemetry_request_count;
    zend_long server_telemetry_last_status;
    zend_long server_tls_apply_count;
    zend_long server_tls_reload_count;
    zend_long server_early_hints_count;
    zend_long server_last_early_hints_stream_id;
    zend_long server_last_early_hints_hint_count;
    zend_long server_websocket_upgrade_count;
    zend_long server_last_websocket_stream_id;
    zend_long tls_session_ticket_length;
    zend_long transport_connect_timeout_ms;
    zend_long transport_ping_interval_ms;
    zend_long transport_last_poll_result;
    zend_long transport_last_errno;
    zend_long transport_local_port;
    zend_long transport_peer_port;
    zend_long transport_rx_datagram_count;
    zend_long transport_rx_bytes;
    zend_long transport_tx_datagram_count;
    zend_long transport_tx_bytes;
    zend_long transport_last_tx_at_ms;
    zend_long transport_last_rx_at_ms;
    zend_long server_owner_pid;
    zend_long server_close_error_code;
    time_t connected_at;
    time_t last_activity_at;
    uint64_t server_cap_nonce;
    uint64_t server_owner_tid;
    int transport_socket_fd;
    bool config_is_frozen;
    bool config_mcp_enable_request_caching;
    bool config_orchestrator_enable_distributed_tracing;
    bool config_smartcontract_enable;
    bool config_ssh_gateway_enable;
    bool config_storage_enable;
    bool config_cdn_enable;
    bool config_dns_server_enable;
    bool config_http2_enable;
    bool http_enable_early_hints;
    bool config_otel_enable;
    bool config_otel_metrics_enable;
    bool config_otel_logs_enable;
    bool config_tcp_enable;
    bool config_tls_verify_peer;
    bool config_userland_overrides_applied;
    bool tls_enable_early_data;
    bool tls_has_session_ticket;
    bool transport_datagrams_enable;
    bool transport_has_socket;
    bool cancelled_streams_initialized;
    bool server_cancel_handlers_initialized;
    bool server_upgraded_streams_initialized;
    bool transport_probe_sent;
    bool server_close_initiated;
    bool server_admin_api_active;
    bool server_last_admin_api_mtls_ready;
    bool server_cors_active;
    bool server_cors_allow_any_origin;
    bool server_last_cors_preflight;
    bool server_telemetry_active;
    bool server_telemetry_metrics_enable;
    bool server_telemetry_logs_enable;
    bool server_tls_active;
    bool server_last_tls_ticket_key_loaded;
    bool server_last_websocket_secure;
    bool is_closed;
} king_client_session_t;

void king_client_session_init_cancel_state(king_client_session_t *session);
void king_client_session_init_transport_state(king_client_session_t *session);
void king_client_session_init_tls_state(king_client_session_t *session);
void king_client_session_init_config_state(king_client_session_t *session);
void king_client_session_apply_config_resource(
    king_client_session_t *session,
    king_cfg_t *cfg
);

king_client_session_t *king_client_session_fetch_resource(
    zval *zsession,
    uint32_t arg_num
);

void king_client_session_free(void *session_ptr);
void king_client_session_close_socket(king_client_session_t *session);

zend_result king_client_session_mark_cancelled(
    king_client_session_t *session,
    zend_long stream_id,
    const char *how,
    size_t how_len,
    const char *function_name
);

zend_result king_client_session_store_tls_ticket(
    king_client_session_t *session,
    const uint8_t *ticket,
    size_t len,
    const char *source
);

#endif /* KING_CLIENT_SESSION_H */
