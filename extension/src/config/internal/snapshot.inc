static void king_config_snapshot_globals(king_cfg_t *cfg)
{
    cfg->quiche_cfg = NULL;
    cfg->frozen = 0;
    cfg->userland_overrides_applied = 0;
    cfg->override_count = 0;
    cfg->owns_autoscale_strings = 0;
    cfg->owns_storage_strings = 0;
    cfg->owns_cdn_strings = 0;
    cfg->owns_dns_strings = 0;
    cfg->owns_otel_strings = 0;
    cfg->owns_geometry_strings = 0;
    cfg->owns_smart_contract_strings = 0;
    cfg->owns_ssh_strings = 0;
    cfg->owns_tls_strings = 0;
    cfg->owns_tcp_strings = 0;
    cfg->owns_quic_cc_algorithm = 0;
    cfg->owns_mcp_orchestrator_strings = 0;

    cfg->app_protocols = king_app_protocols_config;
    cfg->bare_metal = king_bare_metal_config;
    cfg->autoscale = king_cloud_autoscale_config;
    cfg->cluster = king_cluster_config;
    cfg->admin_api = king_dynamic_admin_api_config;
    cfg->compute_ai = king_high_perf_compute_ai_config;
    cfg->http2 = king_http2_config;
    cfg->serialization = king_iibin_config;
    cfg->mcp = king_mcp_orchestrator_config;
    cfg->cdn = king_native_cdn_config;
    cfg->storage = king_native_object_store_config;
    cfg->observability = king_open_telemetry_config;
    cfg->quic = king_quic_transport_config;
    cfg->router = king_router_loadbalancer_config;
    cfg->security = king_security_config;
    cfg->semantic_geometry = king_semantic_geometry_config;
    cfg->smart_contract = king_smart_contracts_config;
    cfg->dns = king_smart_dns_config;
    cfg->ssh = king_ssh_over_quic_config;
    cfg->state = king_state_management_config;
    cfg->tcp = king_tcp_transport_config;
    cfg->tls = king_tls_and_crypto_config;

    king_config_clone_owned_strings(cfg);
}
