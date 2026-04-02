/*
 * =========================================================================
 * FILENAME:   src/client/session/api/stats.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Session stats materialization for the public client-session surface. This
 * slice snapshots the current transport, server-observation, cancel, TLS,
 * early-hints, and config-binding fields into the `king_stats()` /
 * `Session::stats()` array contract.
 * =========================================================================
 */

static void king_client_session_populate_stats(
    zval *return_value,
    king_client_session_t *session)
{
    array_init(return_value);
    add_assoc_string(return_value, "build", "v1");
    add_assoc_string(return_value, "transport", "quic");
    add_assoc_str(return_value, "host", zend_string_copy(session->host));
    add_assoc_str(return_value, "alpn", zend_string_copy(session->negotiated_alpn));
    add_assoc_long(return_value, "port", session->port);
    add_assoc_string(
        return_value,
        "state",
        session->is_closed ? "closed" : "open"
    );
    add_assoc_str(
        return_value,
        "transport_backend",
        zend_string_copy(session->transport_backend)
    );
    add_assoc_string(
        return_value,
        "transport_state",
        session->is_closed ? "closed" : "connected"
    );
    add_assoc_bool(return_value, "transport_has_socket", session->transport_has_socket);
    add_assoc_str(
        return_value,
        "transport_socket_family",
        zend_string_copy(session->transport_socket_family)
    );
    add_assoc_str(
        return_value,
        "transport_local_address",
        zend_string_copy(session->transport_local_address)
    );
    add_assoc_long(return_value, "transport_local_port", session->transport_local_port);
    add_assoc_str(
        return_value,
        "transport_peer_address",
        zend_string_copy(session->transport_peer_address)
    );
    add_assoc_long(return_value, "transport_peer_port", session->transport_peer_port);
    add_assoc_long(
        return_value,
        "transport_connect_timeout_ms",
        session->transport_connect_timeout_ms
    );
    add_assoc_long(
        return_value,
        "transport_ping_interval_ms",
        session->transport_ping_interval_ms
    );
    add_assoc_bool(
        return_value,
        "transport_datagrams_enable",
        session->transport_datagrams_enable
    );
    add_assoc_long(
        return_value,
        "transport_last_poll_result",
        session->transport_last_poll_result
    );
    add_assoc_long(
        return_value,
        "transport_last_errno",
        session->transport_last_errno
    );
    add_assoc_str(
        return_value,
        "transport_error_scope",
        zend_string_copy(session->transport_error_scope)
    );
    add_assoc_long(
        return_value,
        "transport_rx_datagram_count",
        session->transport_rx_datagram_count
    );
    add_assoc_long(return_value, "transport_rx_bytes", session->transport_rx_bytes);
    add_assoc_long(
        return_value,
        "transport_tx_datagram_count",
        session->transport_tx_datagram_count
    );
    add_assoc_long(return_value, "transport_tx_bytes", session->transport_tx_bytes);
    add_assoc_long(
        return_value,
        "transport_last_tx_at_ms",
        session->transport_last_tx_at_ms
    );
    add_assoc_long(
        return_value,
        "transport_last_rx_at_ms",
        session->transport_last_rx_at_ms
    );
    add_assoc_long(return_value, "connected_at", (zend_long) session->connected_at);
    add_assoc_long(return_value, "last_activity_at", (zend_long) session->last_activity_at);
    add_assoc_long(
        return_value,
        "server_session_capability",
        king_server_session_capability(session)
    );
    add_assoc_bool(
        return_value,
        "server_close_initiated",
        session->server_close_initiated
    );
    add_assoc_long(
        return_value,
        "server_close_error_code",
        session->server_close_error_code
    );
    add_assoc_str(
        return_value,
        "server_close_reason",
        session->server_close_reason != NULL
            ? zend_string_copy(session->server_close_reason)
            : zend_string_init("", 0, 0)
    );
    if (session->server_peer_cert_subject != NULL) {
        add_assoc_str(
            return_value,
            "server_peer_cert_subject",
            zend_string_copy(session->server_peer_cert_subject)
        );
    } else {
        add_assoc_null(return_value, "server_peer_cert_subject");
    }
    add_assoc_long(
        return_value,
        "server_cancel_handler_count",
        session->server_cancel_handler_count
    );
    add_assoc_long(
        return_value,
        "server_cancel_handler_invocations",
        session->server_cancel_handler_invocations
    );
    add_assoc_long(
        return_value,
        "server_last_cancel_handler_stream_id",
        session->server_last_cancel_handler_stream_id
    );
    add_assoc_long(
        return_value,
        "server_last_cancel_invoked_stream_id",
        session->server_last_cancel_invoked_stream_id
    );
    add_assoc_bool(
        return_value,
        "server_admin_api_active",
        session->server_admin_api_active
    );
    add_assoc_long(
        return_value,
        "server_admin_api_listen_count",
        session->server_admin_api_listen_count
    );
    add_assoc_long(
        return_value,
        "server_admin_api_reload_count",
        session->server_admin_api_reload_count
    );
    add_assoc_str(
        return_value,
        "server_last_admin_api_bind_host",
        session->server_last_admin_api_bind_host != NULL
            ? zend_string_copy(session->server_last_admin_api_bind_host)
            : zend_string_init("", 0, 0)
    );
    add_assoc_long(
        return_value,
        "server_last_admin_api_port",
        session->server_last_admin_api_port
    );
    add_assoc_str(
        return_value,
        "server_last_admin_api_auth_mode",
        session->server_last_admin_api_auth_mode != NULL
            ? zend_string_copy(session->server_last_admin_api_auth_mode)
            : zend_string_init("", 0, 0)
    );
    add_assoc_bool(
        return_value,
        "server_last_admin_api_mtls_ready",
        session->server_last_admin_api_mtls_ready
    );
    add_assoc_bool(
        return_value,
        "server_cors_active",
        session->server_cors_active
    );
    add_assoc_bool(
        return_value,
        "server_cors_allow_any_origin",
        session->server_cors_allow_any_origin
    );
    add_assoc_bool(
        return_value,
        "server_last_cors_preflight",
        session->server_last_cors_preflight
    );
    add_assoc_long(
        return_value,
        "server_cors_apply_count",
        session->server_cors_apply_count
    );
    add_assoc_long(
        return_value,
        "server_last_cors_allowed_origin_count",
        session->server_last_cors_allowed_origin_count
    );
    add_assoc_str(
        return_value,
        "server_last_cors_policy",
        session->server_last_cors_policy != NULL
            ? zend_string_copy(session->server_last_cors_policy)
            : zend_string_init("", 0, 0)
    );
    add_assoc_str(
        return_value,
        "server_last_cors_origin",
        session->server_last_cors_origin != NULL
            ? zend_string_copy(session->server_last_cors_origin)
            : zend_string_init("", 0, 0)
    );
    add_assoc_str(
        return_value,
        "server_last_cors_allow_origin",
        session->server_last_cors_allow_origin != NULL
            ? zend_string_copy(session->server_last_cors_allow_origin)
            : zend_string_init("", 0, 0)
    );
    add_assoc_bool(
        return_value,
        "server_telemetry_active",
        session->server_telemetry_active
    );
    add_assoc_bool(
        return_value,
        "server_telemetry_metrics_enable",
        session->server_telemetry_metrics_enable
    );
    add_assoc_bool(
        return_value,
        "server_telemetry_logs_enable",
        session->server_telemetry_logs_enable
    );
    add_assoc_long(
        return_value,
        "server_telemetry_init_count",
        session->server_telemetry_init_count
    );
    add_assoc_long(
        return_value,
        "server_telemetry_request_count",
        session->server_telemetry_request_count
    );
    add_assoc_long(
        return_value,
        "server_telemetry_last_status",
        session->server_telemetry_last_status
    );
    add_assoc_str(
        return_value,
        "server_telemetry_service_name",
        session->server_telemetry_service_name != NULL
            ? zend_string_copy(session->server_telemetry_service_name)
            : zend_string_init("", 0, 0)
    );
    add_assoc_str(
        return_value,
        "server_telemetry_exporter_endpoint",
        session->server_telemetry_exporter_endpoint != NULL
            ? zend_string_copy(session->server_telemetry_exporter_endpoint)
            : zend_string_init("", 0, 0)
    );
    add_assoc_str(
        return_value,
        "server_telemetry_exporter_protocol",
        session->server_telemetry_exporter_protocol != NULL
            ? zend_string_copy(session->server_telemetry_exporter_protocol)
            : zend_string_init("", 0, 0)
    );
    add_assoc_str(
        return_value,
        "server_telemetry_last_protocol",
        session->server_telemetry_last_protocol != NULL
            ? zend_string_copy(session->server_telemetry_last_protocol)
            : zend_string_init("", 0, 0)
    );
    add_assoc_bool(
        return_value,
        "server_tls_active",
        session->server_tls_active
    );
    add_assoc_long(
        return_value,
        "server_tls_apply_count",
        session->server_tls_apply_count
    );
    add_assoc_long(
        return_value,
        "server_tls_reload_count",
        session->server_tls_reload_count
    );
    add_assoc_str(
        return_value,
        "server_last_tls_cert_file",
        session->server_last_tls_cert_file != NULL
            ? zend_string_copy(session->server_last_tls_cert_file)
            : zend_string_init("", 0, 0)
    );
    add_assoc_str(
        return_value,
        "server_last_tls_key_file",
        session->server_last_tls_key_file != NULL
            ? zend_string_copy(session->server_last_tls_key_file)
            : zend_string_init("", 0, 0)
    );
    add_assoc_str(
        return_value,
        "server_last_tls_ticket_key_file",
        session->server_last_tls_ticket_key_file != NULL
            ? zend_string_copy(session->server_last_tls_ticket_key_file)
            : zend_string_init("", 0, 0)
    );
    add_assoc_bool(
        return_value,
        "server_last_tls_ticket_key_loaded",
        session->server_last_tls_ticket_key_loaded
    );
    add_assoc_long(
        return_value,
        "server_early_hints_count",
        session->server_early_hints_count
    );
    add_assoc_long(
        return_value,
        "server_last_early_hints_stream_id",
        session->server_last_early_hints_stream_id
    );
    add_assoc_long(
        return_value,
        "server_last_early_hints_hint_count",
        session->server_last_early_hints_hint_count
    );
    if (!Z_ISUNDEF(session->server_last_early_hints)) {
        zval hints_copy;

        ZVAL_COPY(&hints_copy, &session->server_last_early_hints);
        add_assoc_zval(return_value, "server_last_early_hints", &hints_copy);
    } else {
        zval hints;

        array_init(&hints);
        add_assoc_zval(return_value, "server_last_early_hints", &hints);
    }
    add_assoc_long(
        return_value,
        "server_websocket_upgrade_count",
        session->server_websocket_upgrade_count
    );
    add_assoc_long(
        return_value,
        "server_last_websocket_stream_id",
        session->server_last_websocket_stream_id
    );
    add_assoc_bool(
        return_value,
        "server_last_websocket_secure",
        session->server_last_websocket_secure
    );
    add_assoc_str(
        return_value,
        "server_last_websocket_url",
        session->server_last_websocket_url != NULL
            ? zend_string_copy(session->server_last_websocket_url)
            : zend_string_init("", 0, 0)
    );
    add_assoc_long(return_value, "poll_calls", session->poll_calls);
    add_assoc_long(return_value, "last_poll_timeout_ms", session->last_poll_timeout_ms);
    add_assoc_long(return_value, "cancel_calls", session->cancel_calls);
    add_assoc_long(return_value, "canceled_stream_count", session->canceled_stream_count);
    add_assoc_long(
        return_value,
        "last_canceled_stream_id",
        session->last_canceled_stream_id
    );
    add_assoc_str(
        return_value,
        "last_cancel_mode",
        zend_string_copy(session->last_cancel_mode)
    );
    add_assoc_str(
        return_value,
        "tls_default_ca_file",
        zend_string_copy(session->tls_default_ca_file)
    );
    add_assoc_str(
        return_value,
        "tls_default_cert_file",
        zend_string_copy(session->tls_default_cert_file)
    );
    add_assoc_str(
        return_value,
        "tls_default_key_file",
        zend_string_copy(session->tls_default_key_file)
    );
    add_assoc_str(
        return_value,
        "tls_ticket_key_file",
        zend_string_copy(session->tls_ticket_key_file)
    );
    add_assoc_bool(return_value, "tls_enable_early_data", session->tls_enable_early_data);
    add_assoc_long(
        return_value,
        "tls_session_ticket_lifetime_sec",
        session->tls_session_ticket_lifetime_sec
    );
    add_assoc_bool(return_value, "tls_has_session_ticket", session->tls_has_session_ticket);
    add_assoc_long(
        return_value,
        "tls_session_ticket_length",
        session->tls_session_ticket_length
    );
    add_assoc_string(
        return_value,
        "tls_ticket_source",
        (char *) session->tls_ticket_source
    );
    add_assoc_string(return_value, "config_binding", (char *) session->config_binding);
    add_assoc_bool(return_value, "config_is_frozen", session->config_is_frozen);
    add_assoc_str(
        return_value,
        "config_autoscale_provider",
        zend_string_copy(session->config_autoscale_provider)
    );
    add_assoc_long(
        return_value,
        "config_autoscale_max_nodes",
        session->config_autoscale_max_nodes
    );
    add_assoc_bool(
        return_value,
        "config_mcp_enable_request_caching",
        session->config_mcp_enable_request_caching
    );
    add_assoc_long(
        return_value,
        "config_mcp_default_request_timeout_ms",
        session->config_mcp_default_request_timeout_ms
    );
    add_assoc_bool(
        return_value,
        "config_orchestrator_enable_distributed_tracing",
        session->config_orchestrator_enable_distributed_tracing
    );
    add_assoc_long(
        return_value,
        "config_geometry_default_vector_dimensions",
        session->config_geometry_default_vector_dimensions
    );
    add_assoc_str(
        return_value,
        "config_geometry_calculation_precision",
        zend_string_copy(session->config_geometry_calculation_precision)
    );
    add_assoc_bool(
        return_value,
        "config_smartcontract_enable",
        session->config_smartcontract_enable
    );
    add_assoc_str(
        return_value,
        "config_smartcontract_dlt_provider",
        zend_string_copy(session->config_smartcontract_dlt_provider)
    );
    add_assoc_bool(
        return_value,
        "config_ssh_gateway_enable",
        session->config_ssh_gateway_enable
    );
    add_assoc_str(
        return_value,
        "config_ssh_gateway_auth_mode",
        zend_string_copy(session->config_ssh_gateway_auth_mode)
    );
    add_assoc_bool(return_value, "config_storage_enable", session->config_storage_enable);
    add_assoc_str(
        return_value,
        "config_storage_default_redundancy_mode",
        zend_string_copy(session->config_storage_default_redundancy_mode)
    );
    add_assoc_bool(return_value, "config_cdn_enable", session->config_cdn_enable);
    add_assoc_str(
        return_value,
        "config_cdn_cache_mode",
        zend_string_copy(session->config_cdn_cache_mode)
    );
    add_assoc_bool(return_value, "config_dns_server_enable", session->config_dns_server_enable);
    add_assoc_str(
        return_value,
        "config_dns_mode",
        zend_string_copy(session->config_dns_mode)
    );
    add_assoc_str(
        return_value,
        "config_security_cors_allowed_origins",
        zend_string_copy(session->config_security_cors_allowed_origins)
    );
    add_assoc_bool(return_value, "config_http2_enable", session->config_http2_enable);
    add_assoc_bool(
        return_value,
        "http_enable_early_hints",
        session->http_enable_early_hints
    );
    add_assoc_long(
        return_value,
        "config_websocket_default_max_payload_size",
        session->config_websocket_default_max_payload_size
    );
    add_assoc_long(
        return_value,
        "config_websocket_default_ping_interval_ms",
        session->config_websocket_default_ping_interval_ms
    );
    add_assoc_long(
        return_value,
        "config_websocket_handshake_timeout_ms",
        session->config_websocket_handshake_timeout_ms
    );
    add_assoc_long(
        return_value,
        "config_http2_max_concurrent_streams",
        session->config_http2_max_concurrent_streams
    );
    add_assoc_bool(return_value, "config_otel_enable", session->config_otel_enable);
    add_assoc_str(
        return_value,
        "config_otel_service_name",
        zend_string_copy(session->config_otel_service_name)
    );
    add_assoc_str(
        return_value,
        "config_otel_exporter_endpoint",
        zend_string_copy(session->config_otel_exporter_endpoint)
    );
    add_assoc_str(
        return_value,
        "config_otel_exporter_protocol",
        zend_string_copy(session->config_otel_exporter_protocol)
    );
    add_assoc_bool(
        return_value,
        "config_otel_metrics_enable",
        session->config_otel_metrics_enable
    );
    add_assoc_bool(
        return_value,
        "config_otel_logs_enable",
        session->config_otel_logs_enable
    );
    add_assoc_bool(return_value, "config_tcp_enable", session->config_tcp_enable);
    add_assoc_str(
        return_value,
        "config_tcp_tls_min_version_allowed",
        zend_string_copy(session->config_tcp_tls_min_version_allowed)
    );
    add_assoc_bool(return_value, "config_tls_verify_peer", session->config_tls_verify_peer);
    add_assoc_str(
        return_value,
        "config_quic_cc_algorithm",
        zend_string_copy(session->config_quic_cc_algorithm)
    );
    add_assoc_bool(
        return_value,
        "config_userland_overrides_applied",
        session->config_userland_overrides_applied
    );
    add_assoc_long(return_value, "config_option_count", session->config_option_count);
}

PHP_FUNCTION(king_get_stats)
{
    zval *zsession;
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ZVAL(zsession)
    ZEND_PARSE_PARAMETERS_END();

    session = king_client_session_fetch_resource(zsession, 1);
    if (session == NULL) {
        RETURN_THROWS();
    }

    king_client_session_populate_stats(return_value, session);
}
