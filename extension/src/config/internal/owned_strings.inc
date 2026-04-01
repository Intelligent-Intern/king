static char *king_config_clone_persistent_string(const char *value)
{
    return pestrdup(value != NULL ? value : "", 1);
}

static void king_config_free_autoscale_strings(kg_cloud_autoscale_config_t *autoscale)
{
    pefree(autoscale->provider, 1);
    pefree(autoscale->region, 1);
    pefree(autoscale->credentials_path, 1);
    pefree(autoscale->api_endpoint, 1);
    pefree(autoscale->state_path, 1);
    pefree(autoscale->server_name_prefix, 1);
    pefree(autoscale->bootstrap_user_data, 1);
    pefree(autoscale->firewall_ids, 1);
    pefree(autoscale->placement_group_id, 1);
    pefree(autoscale->prepared_release_url, 1);
    pefree(autoscale->join_endpoint, 1);
    pefree(autoscale->hetzner_api_token, 1);
    pefree(autoscale->hetzner_budget_path, 1);
    pefree(autoscale->scale_up_policy, 1);
    pefree(autoscale->instance_type, 1);
    pefree(autoscale->instance_image_id, 1);
    pefree(autoscale->network_config, 1);
    pefree(autoscale->instance_tags, 1);
}

static void king_config_free_storage_strings(kg_native_object_store_config_t *storage)
{
    pefree(storage->default_redundancy_mode, 1);
    pefree(storage->erasure_coding_shards, 1);
    pefree(storage->metadata_agent_uri, 1);
    pefree(storage->node_discovery_mode, 1);
    pefree(storage->node_static_list, 1);
}

static void king_config_free_cdn_strings(kg_native_cdn_config_t *cdn)
{
    pefree(cdn->cache_mode, 1);
    pefree(cdn->cache_disk_path, 1);
    pefree(cdn->cache_vary_on_headers, 1);
    pefree(cdn->origin_mcp_endpoint, 1);
    pefree(cdn->origin_http_endpoint, 1);
    pefree(cdn->response_headers_to_add, 1);
    pefree(cdn->allowed_http_methods, 1);
}

static void king_config_free_dns_strings(kg_smart_dns_config_t *dns)
{
    pefree(dns->server_bind_host, 1);
    pefree(dns->mode, 1);
    pefree(dns->mothernode_uri, 1);
    pefree(dns->live_probe_allowed_hosts, 1);
}

static void king_config_free_otel_strings(kg_open_telemetry_config_t *otel)
{
    pefree(otel->service_name, 1);
    pefree(otel->exporter_endpoint, 1);
    pefree(otel->exporter_protocol, 1);
    pefree(otel->exporter_headers, 1);
    pefree(otel->traces_sampler_type, 1);
    pefree(otel->metrics_default_histogram_boundaries, 1);
}

static void king_config_free_geometry_strings(kg_semantic_geometry_config_t *geometry)
{
    pefree(geometry->calculation_precision, 1);
    pefree(geometry->convex_hull_algorithm, 1);
    pefree(geometry->point_in_polytope_algorithm, 1);
    pefree(geometry->hausdorff_distance_algorithm, 1);
}

static void king_config_free_smart_contract_strings(kg_smart_contracts_config_t *smart_contract)
{
    pefree(smart_contract->registry_uri, 1);
    pefree(smart_contract->dlt_provider, 1);
    pefree(smart_contract->dlt_rpc_endpoint, 1);
    pefree(smart_contract->default_wallet_path, 1);
    pefree(smart_contract->default_wallet_password_env_var, 1);
    pefree(smart_contract->hsm_pkcs11_library_path, 1);
    pefree(smart_contract->abi_directory, 1);
}

static void king_config_free_ssh_strings(kg_ssh_over_quic_config_t *ssh)
{
    pefree(ssh->gateway_listen_host, 1);
    pefree(ssh->gateway_default_target_host, 1);
    pefree(ssh->gateway_auth_mode, 1);
    pefree(ssh->gateway_mcp_auth_agent_uri, 1);
    pefree(ssh->gateway_target_mapping_mode, 1);
    pefree(ssh->gateway_user_profile_agent_uri, 1);
}

static void king_config_free_tls_strings(kg_tls_and_crypto_config_t *tls)
{
    pefree(tls->tls_default_ca_file, 1);
    pefree(tls->tls_default_cert_file, 1);
    pefree(tls->tls_default_key_file, 1);
    pefree(tls->tls_ticket_key_file, 1);
    pefree(tls->tls_ciphers_tls13, 1);
    pefree(tls->tls_ciphers_tls12, 1);
    pefree(tls->tls_curves, 1);
    pefree(tls->tcp_tls_min_version_allowed, 1);
    pefree(tls->storage_encryption_algorithm, 1);
    pefree(tls->storage_encryption_key_path, 1);
    pefree(tls->mcp_payload_encryption_psk_env_var, 1);
}

static void king_config_free_tcp_strings(kg_tcp_transport_config_t *tcp)
{
    pefree(tcp->tls_min_version_allowed, 1);
    pefree(tcp->tls_ciphers_tls12, 1);
}

static void king_config_free_mcp_orchestrator_strings(kg_mcp_orchestrator_config_t *mcp)
{
    pefree(mcp->mcp_transfer_state_path, 1);
    pefree(mcp->orchestrator_execution_backend, 1);
    pefree(mcp->orchestrator_worker_queue_path, 1);
    pefree(mcp->orchestrator_remote_host, 1);
    pefree(mcp->orchestrator_state_path, 1);
}

static void king_config_clone_owned_strings(king_cfg_t *cfg)
{
    cfg->autoscale.provider = king_config_clone_persistent_string(cfg->autoscale.provider);
    cfg->autoscale.region = king_config_clone_persistent_string(cfg->autoscale.region);
    cfg->autoscale.credentials_path = king_config_clone_persistent_string(cfg->autoscale.credentials_path);
    cfg->autoscale.api_endpoint = king_config_clone_persistent_string(cfg->autoscale.api_endpoint);
    cfg->autoscale.state_path = king_config_clone_persistent_string(cfg->autoscale.state_path);
    cfg->autoscale.server_name_prefix = king_config_clone_persistent_string(cfg->autoscale.server_name_prefix);
    cfg->autoscale.bootstrap_user_data = king_config_clone_persistent_string(cfg->autoscale.bootstrap_user_data);
    cfg->autoscale.firewall_ids = king_config_clone_persistent_string(cfg->autoscale.firewall_ids);
    cfg->autoscale.placement_group_id = king_config_clone_persistent_string(cfg->autoscale.placement_group_id);
    cfg->autoscale.prepared_release_url = king_config_clone_persistent_string(cfg->autoscale.prepared_release_url);
    cfg->autoscale.join_endpoint = king_config_clone_persistent_string(cfg->autoscale.join_endpoint);
    cfg->autoscale.hetzner_api_token = king_config_clone_persistent_string(cfg->autoscale.hetzner_api_token);
    cfg->autoscale.hetzner_budget_path = king_config_clone_persistent_string(cfg->autoscale.hetzner_budget_path);
    cfg->autoscale.scale_up_policy = king_config_clone_persistent_string(cfg->autoscale.scale_up_policy);
    cfg->autoscale.instance_type = king_config_clone_persistent_string(cfg->autoscale.instance_type);
    cfg->autoscale.instance_image_id = king_config_clone_persistent_string(cfg->autoscale.instance_image_id);
    cfg->autoscale.network_config = king_config_clone_persistent_string(cfg->autoscale.network_config);
    cfg->autoscale.instance_tags = king_config_clone_persistent_string(cfg->autoscale.instance_tags);
    cfg->owns_autoscale_strings = 1;

    cfg->storage.default_redundancy_mode = king_config_clone_persistent_string(cfg->storage.default_redundancy_mode);
    cfg->storage.erasure_coding_shards = king_config_clone_persistent_string(cfg->storage.erasure_coding_shards);
    cfg->storage.metadata_agent_uri = king_config_clone_persistent_string(cfg->storage.metadata_agent_uri);
    cfg->storage.node_discovery_mode = king_config_clone_persistent_string(cfg->storage.node_discovery_mode);
    cfg->storage.node_static_list = king_config_clone_persistent_string(cfg->storage.node_static_list);
    cfg->owns_storage_strings = 1;

    cfg->cdn.cache_mode = king_config_clone_persistent_string(cfg->cdn.cache_mode);
    cfg->cdn.cache_disk_path = king_config_clone_persistent_string(cfg->cdn.cache_disk_path);
    cfg->cdn.cache_vary_on_headers = king_config_clone_persistent_string(cfg->cdn.cache_vary_on_headers);
    cfg->cdn.origin_mcp_endpoint = king_config_clone_persistent_string(cfg->cdn.origin_mcp_endpoint);
    cfg->cdn.origin_http_endpoint = king_config_clone_persistent_string(cfg->cdn.origin_http_endpoint);
    cfg->cdn.response_headers_to_add = king_config_clone_persistent_string(cfg->cdn.response_headers_to_add);
    cfg->cdn.allowed_http_methods = king_config_clone_persistent_string(cfg->cdn.allowed_http_methods);
    cfg->owns_cdn_strings = 1;

    cfg->dns.server_bind_host = king_config_clone_persistent_string(cfg->dns.server_bind_host);
    cfg->dns.mode = king_config_clone_persistent_string(cfg->dns.mode);
    cfg->dns.mothernode_uri = king_config_clone_persistent_string(cfg->dns.mothernode_uri);
    cfg->dns.live_probe_allowed_hosts = king_config_clone_persistent_string(cfg->dns.live_probe_allowed_hosts);
    cfg->owns_dns_strings = 1;

    cfg->observability.service_name = king_config_clone_persistent_string(cfg->observability.service_name);
    cfg->observability.exporter_endpoint = king_config_clone_persistent_string(cfg->observability.exporter_endpoint);
    cfg->observability.exporter_protocol = king_config_clone_persistent_string(cfg->observability.exporter_protocol);
    cfg->observability.exporter_headers = king_config_clone_persistent_string(cfg->observability.exporter_headers);
    cfg->observability.traces_sampler_type = king_config_clone_persistent_string(cfg->observability.traces_sampler_type);
    cfg->observability.metrics_default_histogram_boundaries = king_config_clone_persistent_string(cfg->observability.metrics_default_histogram_boundaries);
    cfg->owns_otel_strings = 1;

    cfg->semantic_geometry.calculation_precision = king_config_clone_persistent_string(cfg->semantic_geometry.calculation_precision);
    cfg->semantic_geometry.convex_hull_algorithm = king_config_clone_persistent_string(cfg->semantic_geometry.convex_hull_algorithm);
    cfg->semantic_geometry.point_in_polytope_algorithm = king_config_clone_persistent_string(cfg->semantic_geometry.point_in_polytope_algorithm);
    cfg->semantic_geometry.hausdorff_distance_algorithm = king_config_clone_persistent_string(cfg->semantic_geometry.hausdorff_distance_algorithm);
    cfg->owns_geometry_strings = 1;

    cfg->smart_contract.registry_uri = king_config_clone_persistent_string(cfg->smart_contract.registry_uri);
    cfg->smart_contract.dlt_provider = king_config_clone_persistent_string(cfg->smart_contract.dlt_provider);
    cfg->smart_contract.dlt_rpc_endpoint = king_config_clone_persistent_string(cfg->smart_contract.dlt_rpc_endpoint);
    cfg->smart_contract.default_wallet_path = king_config_clone_persistent_string(cfg->smart_contract.default_wallet_path);
    cfg->smart_contract.default_wallet_password_env_var = king_config_clone_persistent_string(cfg->smart_contract.default_wallet_password_env_var);
    cfg->smart_contract.hsm_pkcs11_library_path = king_config_clone_persistent_string(cfg->smart_contract.hsm_pkcs11_library_path);
    cfg->smart_contract.abi_directory = king_config_clone_persistent_string(cfg->smart_contract.abi_directory);
    cfg->owns_smart_contract_strings = 1;

    cfg->ssh.gateway_listen_host = king_config_clone_persistent_string(cfg->ssh.gateway_listen_host);
    cfg->ssh.gateway_default_target_host = king_config_clone_persistent_string(cfg->ssh.gateway_default_target_host);
    cfg->ssh.gateway_auth_mode = king_config_clone_persistent_string(cfg->ssh.gateway_auth_mode);
    cfg->ssh.gateway_mcp_auth_agent_uri = king_config_clone_persistent_string(cfg->ssh.gateway_mcp_auth_agent_uri);
    cfg->ssh.gateway_target_mapping_mode = king_config_clone_persistent_string(cfg->ssh.gateway_target_mapping_mode);
    cfg->ssh.gateway_user_profile_agent_uri = king_config_clone_persistent_string(cfg->ssh.gateway_user_profile_agent_uri);
    cfg->owns_ssh_strings = 1;

    cfg->tls.tls_default_ca_file = king_config_clone_persistent_string(cfg->tls.tls_default_ca_file);
    cfg->tls.tls_default_cert_file = king_config_clone_persistent_string(cfg->tls.tls_default_cert_file);
    cfg->tls.tls_default_key_file = king_config_clone_persistent_string(cfg->tls.tls_default_key_file);
    cfg->tls.tls_ticket_key_file = king_config_clone_persistent_string(cfg->tls.tls_ticket_key_file);
    cfg->tls.tls_ciphers_tls13 = king_config_clone_persistent_string(cfg->tls.tls_ciphers_tls13);
    cfg->tls.tls_ciphers_tls12 = king_config_clone_persistent_string(cfg->tls.tls_ciphers_tls12);
    cfg->tls.tls_curves = king_config_clone_persistent_string(cfg->tls.tls_curves);
    cfg->tls.tcp_tls_min_version_allowed = king_config_clone_persistent_string(cfg->tls.tcp_tls_min_version_allowed);
    cfg->tls.storage_encryption_algorithm = king_config_clone_persistent_string(cfg->tls.storage_encryption_algorithm);
    cfg->tls.storage_encryption_key_path = king_config_clone_persistent_string(cfg->tls.storage_encryption_key_path);
    cfg->tls.mcp_payload_encryption_psk_env_var = king_config_clone_persistent_string(cfg->tls.mcp_payload_encryption_psk_env_var);
    cfg->owns_tls_strings = 1;

    cfg->tcp.tls_min_version_allowed = king_config_clone_persistent_string(cfg->tcp.tls_min_version_allowed);
    cfg->tcp.tls_ciphers_tls12 = king_config_clone_persistent_string(cfg->tcp.tls_ciphers_tls12);
    cfg->owns_tcp_strings = 1;

    cfg->mcp.mcp_transfer_state_path = king_config_clone_persistent_string(cfg->mcp.mcp_transfer_state_path);
    cfg->mcp.orchestrator_execution_backend = king_config_clone_persistent_string(cfg->mcp.orchestrator_execution_backend);
    cfg->mcp.orchestrator_worker_queue_path = king_config_clone_persistent_string(cfg->mcp.orchestrator_worker_queue_path);
    cfg->mcp.orchestrator_remote_host = king_config_clone_persistent_string(cfg->mcp.orchestrator_remote_host);
    cfg->mcp.orchestrator_state_path = king_config_clone_persistent_string(cfg->mcp.orchestrator_state_path);
    cfg->owns_mcp_orchestrator_strings = 1;

    cfg->quic.cc_algorithm = king_config_clone_persistent_string(cfg->quic.cc_algorithm);
    cfg->owns_quic_cc_algorithm = 1;
}

static void king_config_free_owned_overrides(king_cfg_t *cfg)
{
    if (!cfg) {
        return;
    }

    if (cfg->owns_autoscale_strings) {
        king_config_free_autoscale_strings(&cfg->autoscale);
        cfg->owns_autoscale_strings = 0;
    }

    if (cfg->owns_storage_strings) {
        king_config_free_storage_strings(&cfg->storage);
        cfg->owns_storage_strings = 0;
    }

    if (cfg->owns_cdn_strings) {
        king_config_free_cdn_strings(&cfg->cdn);
        cfg->owns_cdn_strings = 0;
    }

    if (cfg->owns_dns_strings) {
        king_config_free_dns_strings(&cfg->dns);
        cfg->owns_dns_strings = 0;
    }

    if (cfg->owns_otel_strings) {
        king_config_free_otel_strings(&cfg->observability);
        cfg->owns_otel_strings = 0;
    }

    if (cfg->owns_geometry_strings) {
        king_config_free_geometry_strings(&cfg->semantic_geometry);
        cfg->owns_geometry_strings = 0;
    }

    if (cfg->owns_smart_contract_strings) {
        king_config_free_smart_contract_strings(&cfg->smart_contract);
        cfg->owns_smart_contract_strings = 0;
    }

    if (cfg->owns_ssh_strings) {
        king_config_free_ssh_strings(&cfg->ssh);
        cfg->owns_ssh_strings = 0;
    }

    if (cfg->owns_tls_strings) {
        king_config_free_tls_strings(&cfg->tls);
        cfg->owns_tls_strings = 0;
    }

    if (cfg->owns_tcp_strings) {
        king_config_free_tcp_strings(&cfg->tcp);
        cfg->owns_tcp_strings = 0;
    }

    if (cfg->owns_mcp_orchestrator_strings) {
        king_config_free_mcp_orchestrator_strings(&cfg->mcp);
        cfg->owns_mcp_orchestrator_strings = 0;
    }

    if (cfg->owns_quic_cc_algorithm && cfg->quic.cc_algorithm != NULL) {
        pefree(cfg->quic.cc_algorithm, 1);
        cfg->quic.cc_algorithm = NULL;
        cfg->owns_quic_cc_algorithm = 0;
    }
}
