/*
 * =========================================================================
 * FILENAME:   src/client/session/state/init.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Base state initialization for the client-session runtime. This slice seeds
 * cancel/server bookkeeping, TLS ticket state, negotiated protocol fields,
 * transport defaults, and the config-derived session snapshot before the
 * public connect/object leaves start using the session.
 * =========================================================================
 */

void king_client_session_init_cancel_state(king_client_session_t *session)
{
    zend_hash_init(
        &session->cancelled_streams,
        8,
        NULL,
        ZVAL_PTR_DTOR,
        0
    );
    zend_hash_init(
        &session->server_cancel_handlers,
        4,
        NULL,
        ZVAL_PTR_DTOR,
        0
    );
    zend_hash_init(
        &session->server_upgraded_streams,
        4,
        NULL,
        ZVAL_PTR_DTOR,
        0
    );
    session->cancelled_streams_initialized = true;
    session->server_cancel_handlers_initialized = true;
    session->server_upgraded_streams_initialized = true;
    session->cancel_calls = 0;
    session->canceled_stream_count = 0;
    session->last_canceled_stream_id = -1;
    session->next_client_bidi_stream_id = 0;
    session->server_cancel_handler_count = 0;
    session->server_cancel_handler_invocations = 0;
    session->server_last_cancel_handler_stream_id = -1;
    session->server_last_cancel_invoked_stream_id = -1;
    session->server_admin_api_listen_count = 0;
    session->server_admin_api_reload_count = 0;
    session->server_last_admin_api_port = 0;
    session->server_admin_api_active = false;
    session->server_last_admin_api_mtls_ready = false;
    session->server_cors_apply_count = 0;
    session->server_last_cors_allowed_origin_count = 0;
    session->server_cors_active = false;
    session->server_cors_allow_any_origin = false;
    session->server_last_cors_preflight = false;
    session->server_telemetry_init_count = 0;
    session->server_telemetry_request_count = 0;
    session->server_telemetry_last_status = 0;
    session->server_telemetry_active = false;
    session->server_telemetry_metrics_enable = false;
    session->server_telemetry_logs_enable = false;
    session->server_tls_apply_count = 0;
    session->server_tls_reload_count = 0;
    session->server_tls_active = false;
    session->server_last_tls_ticket_key_loaded = false;
    session->server_early_hints_count = 0;
    session->server_last_early_hints_stream_id = -1;
    session->server_last_early_hints_hint_count = 0;
    session->server_websocket_upgrade_count = 0;
    session->server_last_websocket_stream_id = -1;
    session->server_last_websocket_secure = false;
    session->last_cancel_mode = zend_string_init("none", sizeof("none") - 1, 0);
    session->server_last_admin_api_bind_host = zend_string_init("", 0, 0);
    session->server_last_admin_api_auth_mode = zend_string_init("", 0, 0);
    session->server_last_cors_policy = zend_string_init("", 0, 0);
    session->server_last_cors_origin = zend_string_init("", 0, 0);
    session->server_last_cors_allow_origin = zend_string_init("", 0, 0);
    session->server_telemetry_service_name = zend_string_init("", 0, 0);
    session->server_telemetry_exporter_endpoint = zend_string_init("", 0, 0);
    session->server_telemetry_exporter_protocol = zend_string_init("", 0, 0);
    session->server_telemetry_last_protocol = zend_string_init("", 0, 0);
    session->server_last_tls_cert_file = zend_string_init("", 0, 0);
    session->server_last_tls_key_file = zend_string_init("", 0, 0);
    session->server_last_tls_ticket_key_file = zend_string_init("", 0, 0);
    session->server_last_websocket_url = zend_string_init("", 0, 0);
    session->server_pending_request_target = NULL;
    session->server_pending_websocket_key = NULL;
    session->server_pending_websocket_upgrade = false;
    ZVAL_UNDEF(&session->server_last_early_hints);
}

static void king_client_session_init_tls_config_from_global(king_client_session_t *session)
{
    if (session->tls_session_ticket == NULL || ZSTR_LEN(session->tls_session_ticket) == 0) {
        session->tls_ticket_source = "none";
        session->tls_has_session_ticket = false;
        session->tls_session_ticket_length = 0;
    } else {
        session->tls_has_session_ticket = true;
        session->tls_session_ticket_length = (zend_long) ZSTR_LEN(session->tls_session_ticket);
    }

    session->tls_session_ticket_lifetime_sec = king_tls_and_crypto_config.tls_session_ticket_lifetime_sec;
    session->tls_enable_early_data = king_tls_and_crypto_config.tls_enable_early_data;

    king_client_session_set_string(
        &session->tls_default_ca_file,
        king_tls_and_crypto_config.tls_default_ca_file
    );
    king_client_session_set_string(
        &session->tls_default_cert_file,
        king_tls_and_crypto_config.tls_default_cert_file
    );
    king_client_session_set_string(
        &session->tls_default_key_file,
        king_tls_and_crypto_config.tls_default_key_file
    );
    king_client_session_set_string(
        &session->tls_ticket_key_file,
        king_tls_and_crypto_config.tls_ticket_key_file
    );
}

void king_client_session_init_tls_state(king_client_session_t *session)
{
    uint8_t ticket_buffer[KING_MAX_TICKET_SIZE];
    size_t ticket_len = sizeof(ticket_buffer);
    int ticket_status;

    king_client_session_init_tls_config_from_global(session);

    ticket_status = king_ticket_ring_get(ticket_buffer, &ticket_len);
    if (ticket_status == 1) {
        king_client_session_store_tls_ticket(
            session,
            ticket_buffer,
            ticket_len,
            "ring"
        );
    }
}

void king_client_session_init_config_state(king_client_session_t *session)
{
    session->config_binding = "none";
    session->config_option_count = 0;
    session->config_is_frozen = false;
    session->http_enable_early_hints = king_app_protocols_config.http_enable_early_hints;
    session->config_websocket_default_max_payload_size =
        king_app_protocols_config.websocket_default_max_payload_size;
    session->config_websocket_default_ping_interval_ms =
        king_app_protocols_config.websocket_default_ping_interval_ms;
    session->config_websocket_handshake_timeout_ms =
        king_app_protocols_config.websocket_handshake_timeout_ms;
    session->config_autoscale_max_nodes = king_cloud_autoscale_config.max_nodes;
    session->config_mcp_default_request_timeout_ms = king_mcp_orchestrator_config.mcp_default_request_timeout_ms;
    session->config_geometry_default_vector_dimensions = king_semantic_geometry_config.default_vector_dimensions;
    session->config_mcp_enable_request_caching = king_mcp_orchestrator_config.mcp_enable_request_caching;
    session->config_orchestrator_enable_distributed_tracing = king_mcp_orchestrator_config.orchestrator_enable_distributed_tracing;
    session->config_smartcontract_enable = king_smart_contracts_config.enable;
    session->config_ssh_gateway_enable = king_ssh_over_quic_config.gateway_enable;
    session->config_storage_enable = king_native_object_store_config.enable;
    session->config_cdn_enable = king_native_cdn_config.enable;
    session->config_dns_server_enable = king_smart_dns_config.server_enable;
    session->config_http2_enable = king_http2_config.enable;
    session->config_http2_max_concurrent_streams = king_http2_config.max_concurrent_streams;
    session->config_otel_enable = king_open_telemetry_config.enable;
    session->config_otel_metrics_enable = king_open_telemetry_config.metrics_enable;
    session->config_otel_logs_enable = king_open_telemetry_config.logs_enable;
    session->config_tcp_enable = king_tcp_transport_config.enable;
    session->config_tls_verify_peer = king_tls_and_crypto_config.tls_verify_peer;
    session->config_userland_overrides_applied = false;
    session->transport_connect_timeout_ms = king_tcp_transport_config.connect_timeout_ms;
    session->transport_ping_interval_ms = king_quic_transport_config.ping_interval_ms;
    session->transport_datagrams_enable = king_quic_transport_config.datagrams_enable;
    king_client_session_set_string(
        &session->negotiated_alpn,
        ""
    );
    king_client_session_set_string(
        &session->config_autoscale_provider,
        king_cloud_autoscale_config.provider
    );
    king_client_session_set_string(
        &session->config_quic_cc_algorithm,
        king_quic_transport_config.cc_algorithm
    );
    king_client_session_set_string(
        &session->config_storage_default_redundancy_mode,
        king_native_object_store_config.default_redundancy_mode
    );
    king_client_session_set_string(
        &session->config_cdn_cache_mode,
        king_native_cdn_config.cache_mode
    );
    king_client_session_set_string(
        &session->config_dns_mode,
        king_smart_dns_config.mode
    );
    king_client_session_set_string(
        &session->config_security_cors_allowed_origins,
        king_security_config.cors_allowed_origins
    );
    king_client_session_set_string(
        &session->config_otel_service_name,
        king_open_telemetry_config.service_name
    );
    if (session->config_otel_exporter_endpoint != NULL) {
        zend_string_release(session->config_otel_exporter_endpoint);
    }
    session->config_otel_exporter_endpoint =
        king_open_telemetry_build_public_exporter_endpoint(
            king_open_telemetry_config.exporter_endpoint,
            king_open_telemetry_config.exporter_endpoint != NULL
                ? strlen(king_open_telemetry_config.exporter_endpoint)
                : 0
        );
    king_client_session_set_string(
        &session->config_otel_exporter_protocol,
        king_open_telemetry_config.exporter_protocol
    );
    king_client_session_set_string(
        &session->config_geometry_calculation_precision,
        king_semantic_geometry_config.calculation_precision
    );
    king_client_session_set_string(
        &session->config_smartcontract_dlt_provider,
        king_smart_contracts_config.dlt_provider
    );
    king_client_session_set_string(
        &session->config_ssh_gateway_auth_mode,
        king_ssh_over_quic_config.gateway_auth_mode
    );
    king_client_session_set_string(
        &session->config_tcp_tls_min_version_allowed,
        king_tcp_transport_config.tls_min_version_allowed
    );
}
