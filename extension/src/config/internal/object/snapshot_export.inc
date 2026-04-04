static void king_config_object_snapshot_to_array(zval *target, king_cfg_t *cfg)
{
    array_init(target);

    add_assoc_string(target, "quic.cc_algorithm", cfg->quic.cc_algorithm != NULL ? cfg->quic.cc_algorithm : "");
    add_assoc_bool(target, "tls.verify_peer", cfg->tls.tls_verify_peer);
    add_assoc_bool(target, "http2.enable", cfg->http2.enable);
    add_assoc_long(target, "http2.max_concurrent_streams", cfg->http2.max_concurrent_streams);
    add_assoc_bool(target, "tcp.enable", cfg->tcp.enable);
    add_assoc_string(
        target,
        "tcp.tls_min_version_allowed",
        cfg->tcp.tls_min_version_allowed != NULL ? cfg->tcp.tls_min_version_allowed : ""
    );
    add_assoc_bool(target, "storage.enable", cfg->storage.enable);
    add_assoc_string(
        target,
        "storage.default_redundancy_mode",
        cfg->storage.default_redundancy_mode != NULL ? cfg->storage.default_redundancy_mode : ""
    );
    add_assoc_bool(target, "cdn.enable", cfg->cdn.enable);
    add_assoc_string(target, "cdn.cache_mode", cfg->cdn.cache_mode != NULL ? cfg->cdn.cache_mode : "");
    add_assoc_bool(target, "dns.server_enable", cfg->dns.server_enable);
    add_assoc_string(target, "dns.mode", cfg->dns.mode != NULL ? cfg->dns.mode : "");
    add_assoc_bool(target, "otel.enable", cfg->observability.enable);
    add_assoc_string(
        target,
        "otel.service_name",
        cfg->observability.service_name != NULL ? cfg->observability.service_name : ""
    );
    add_assoc_string(
        target,
        "otel.queue_state_path",
        cfg->observability.queue_state_path != NULL ? cfg->observability.queue_state_path : ""
    );
    add_assoc_string(
        target,
        "autoscale.provider",
        cfg->autoscale.provider != NULL ? cfg->autoscale.provider : ""
    );
    add_assoc_long(target, "autoscale.max_nodes", cfg->autoscale.max_nodes);
    add_assoc_bool(target, "mcp.enable_request_caching", cfg->mcp.mcp_enable_request_caching);
    add_assoc_long(
        target,
        "mcp.default_request_timeout_ms",
        cfg->mcp.mcp_default_request_timeout_ms
    );
    add_assoc_string(
        target,
        "mcp.allowed_peer_hosts",
        cfg->mcp.mcp_allowed_peer_hosts != NULL ? cfg->mcp.mcp_allowed_peer_hosts : ""
    );
    add_assoc_string(
        target,
        "mcp.transfer_state_path",
        cfg->mcp.mcp_transfer_state_path != NULL ? cfg->mcp.mcp_transfer_state_path : ""
    );
    add_assoc_bool(
        target,
        "orchestrator.enable_distributed_tracing",
        cfg->mcp.orchestrator_enable_distributed_tracing
    );
    add_assoc_string(
        target,
        "orchestrator.execution_backend",
        cfg->mcp.orchestrator_execution_backend != NULL ? cfg->mcp.orchestrator_execution_backend : ""
    );
    add_assoc_string(
        target,
        "orchestrator.worker_queue_path",
        cfg->mcp.orchestrator_worker_queue_path != NULL ? cfg->mcp.orchestrator_worker_queue_path : ""
    );
    add_assoc_string(
        target,
        "orchestrator.remote_host",
        cfg->mcp.orchestrator_remote_host != NULL ? cfg->mcp.orchestrator_remote_host : ""
    );
    add_assoc_long(
        target,
        "orchestrator.remote_port",
        cfg->mcp.orchestrator_remote_port
    );
    add_assoc_string(
        target,
        "orchestrator.state_path",
        cfg->mcp.orchestrator_state_path != NULL ? cfg->mcp.orchestrator_state_path : ""
    );
    add_assoc_long(
        target,
        "geometry.default_vector_dimensions",
        cfg->semantic_geometry.default_vector_dimensions
    );
    add_assoc_string(
        target,
        "geometry.calculation_precision",
        cfg->semantic_geometry.calculation_precision != NULL
            ? cfg->semantic_geometry.calculation_precision
            : ""
    );
    add_assoc_bool(target, "smartcontract.enable", cfg->smart_contract.enable);
    add_assoc_string(
        target,
        "smartcontract.dlt_provider",
        cfg->smart_contract.dlt_provider != NULL ? cfg->smart_contract.dlt_provider : ""
    );
    add_assoc_bool(target, "ssh.gateway_enable", cfg->ssh.gateway_enable);
    add_assoc_string(
        target,
        "ssh.gateway_auth_mode",
        cfg->ssh.gateway_auth_mode != NULL ? cfg->ssh.gateway_auth_mode : ""
    );
}

static void king_config_object_overlay_explicit_overrides(
    zval *target,
    king_config_object *intern)
{
    zend_string *key;
    zval *value;
    zval value_copy;

    if (Z_ISUNDEF(intern->overrides) || Z_TYPE(intern->overrides) != IS_ARRAY) {
        return;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL(intern->overrides), key, value) {
        if (key == NULL) {
            continue;
        }

        ZVAL_COPY(&value_copy, value);
        zend_hash_update(Z_ARRVAL_P(target), key, &value_copy);
    } ZEND_HASH_FOREACH_END();
}
