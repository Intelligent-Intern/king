/*
 * =========================================================================
 * FILENAME:   src/client/session/state/config.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Config-snapshot capture for `King\\Session`. This file applies a frozen
 * `King\\Config` resource onto the live session state and mirrors the subset
 * of transport, TLS, HTTP/2, object-store, MCP, Smart-DNS, telemetry, and
 * other config fields that are exposed later through `Session::stats()`.
 * =========================================================================
 */

void king_client_session_apply_config_resource(
    king_client_session_t *session,
    king_cfg_t *cfg)
{
    king_config_mark_frozen(cfg);

    session->config_binding = "resource";
    session->config_option_count = cfg->override_count;
    session->config_is_frozen = cfg->frozen;
    session->http_enable_early_hints = cfg->app_protocols.http_enable_early_hints;
    session->config_websocket_default_max_payload_size =
        cfg->app_protocols.websocket_default_max_payload_size;
    session->config_websocket_default_ping_interval_ms =
        cfg->app_protocols.websocket_default_ping_interval_ms;
    session->config_websocket_handshake_timeout_ms =
        cfg->app_protocols.websocket_handshake_timeout_ms;
    session->config_autoscale_max_nodes = cfg->autoscale.max_nodes;
    session->config_mcp_default_request_timeout_ms = cfg->mcp.mcp_default_request_timeout_ms;
    session->config_geometry_default_vector_dimensions = cfg->semantic_geometry.default_vector_dimensions;
    session->config_mcp_enable_request_caching = cfg->mcp.mcp_enable_request_caching;
    session->config_orchestrator_enable_distributed_tracing = cfg->mcp.orchestrator_enable_distributed_tracing;
    session->config_smartcontract_enable = cfg->smart_contract.enable;
    session->config_ssh_gateway_enable = cfg->ssh.gateway_enable;
    session->config_storage_enable = cfg->storage.enable;
    session->config_cdn_enable = cfg->cdn.enable;
    session->config_dns_server_enable = cfg->dns.server_enable;
    session->config_http2_enable = cfg->http2.enable;
    session->config_http2_max_concurrent_streams = cfg->http2.max_concurrent_streams;
    session->config_otel_enable = cfg->observability.enable;
    session->config_otel_metrics_enable = cfg->observability.metrics_enable;
    session->config_otel_logs_enable = cfg->observability.logs_enable;
    session->config_tcp_enable = cfg->tcp.enable;
    session->config_tls_verify_peer = cfg->tls.tls_verify_peer;
    session->config_userland_overrides_applied = cfg->userland_overrides_applied;
    session->transport_connect_timeout_ms = cfg->tcp.connect_timeout_ms;
    session->transport_ping_interval_ms = cfg->quic.ping_interval_ms;
    session->transport_datagrams_enable = cfg->quic.datagrams_enable;
    king_client_session_set_string(
        &session->config_autoscale_provider,
        cfg->autoscale.provider
    );
    king_client_session_set_string(
        &session->config_quic_cc_algorithm,
        cfg->quic.cc_algorithm
    );
    king_client_session_set_string(
        &session->config_storage_default_redundancy_mode,
        cfg->storage.default_redundancy_mode
    );
    king_client_session_set_string(
        &session->config_cdn_cache_mode,
        cfg->cdn.cache_mode
    );
    king_client_session_set_string(
        &session->config_dns_mode,
        cfg->dns.mode
    );
    king_client_session_set_string(
        &session->config_security_cors_allowed_origins,
        cfg->security.cors_allowed_origins
    );
    king_client_session_set_string(
        &session->config_otel_service_name,
        cfg->observability.service_name
    );
    if (session->config_otel_exporter_endpoint != NULL) {
        zend_string_release(session->config_otel_exporter_endpoint);
    }
    session->config_otel_exporter_endpoint =
        king_open_telemetry_build_public_exporter_endpoint(
            cfg->observability.exporter_endpoint,
            cfg->observability.exporter_endpoint != NULL
                ? strlen(cfg->observability.exporter_endpoint)
                : 0
        );
    king_client_session_set_string(
        &session->config_otel_exporter_protocol,
        cfg->observability.exporter_protocol
    );
    king_client_session_set_string(
        &session->config_geometry_calculation_precision,
        cfg->semantic_geometry.calculation_precision
    );
    king_client_session_set_string(
        &session->config_smartcontract_dlt_provider,
        cfg->smart_contract.dlt_provider
    );
    king_client_session_set_string(
        &session->config_ssh_gateway_auth_mode,
        cfg->ssh.gateway_auth_mode
    );
    king_client_session_set_string(
        &session->config_tcp_tls_min_version_allowed,
        cfg->tcp.tls_min_version_allowed
    );
    session->tls_session_ticket_lifetime_sec = cfg->tls.tls_session_ticket_lifetime_sec;
    session->tls_enable_early_data = cfg->tls.tls_enable_early_data;
    king_client_session_set_string(
        &session->tls_default_ca_file,
        cfg->tls.tls_default_ca_file
    );
    king_client_session_set_string(
        &session->tls_default_cert_file,
        cfg->tls.tls_default_cert_file
    );
    king_client_session_set_string(
        &session->tls_default_key_file,
        cfg->tls.tls_default_key_file
    );
    king_client_session_set_string(
        &session->tls_ticket_key_file,
        cfg->tls.tls_ticket_key_file
    );
}
