static zend_result king_config_object_snapshot_read(
    king_cfg_t *cfg,
    zend_string *canonical_key,
    zval *return_value)
{
    if (zend_string_equals_cstr(canonical_key, "quic.cc_algorithm", sizeof("quic.cc_algorithm") - 1)) {
        ZVAL_STRING(return_value, cfg->quic.cc_algorithm != NULL ? cfg->quic.cc_algorithm : "");
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "tls.verify_peer", sizeof("tls.verify_peer") - 1)) {
        ZVAL_BOOL(return_value, cfg->tls.tls_verify_peer);
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "http2.enable", sizeof("http2.enable") - 1)) {
        ZVAL_BOOL(return_value, cfg->http2.enable);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "http2.max_concurrent_streams",
            sizeof("http2.max_concurrent_streams") - 1
        )
    ) {
        ZVAL_LONG(return_value, cfg->http2.max_concurrent_streams);
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "tcp.enable", sizeof("tcp.enable") - 1)) {
        ZVAL_BOOL(return_value, cfg->tcp.enable);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "tcp.tls_min_version_allowed",
            sizeof("tcp.tls_min_version_allowed") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->tcp.tls_min_version_allowed != NULL ? cfg->tcp.tls_min_version_allowed : ""
        );
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "storage.enable", sizeof("storage.enable") - 1)) {
        ZVAL_BOOL(return_value, cfg->storage.enable);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "storage.default_redundancy_mode",
            sizeof("storage.default_redundancy_mode") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->storage.default_redundancy_mode != NULL
                ? cfg->storage.default_redundancy_mode
                : ""
        );
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "cdn.enable", sizeof("cdn.enable") - 1)) {
        ZVAL_BOOL(return_value, cfg->cdn.enable);
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "cdn.cache_mode", sizeof("cdn.cache_mode") - 1)) {
        ZVAL_STRING(return_value, cfg->cdn.cache_mode != NULL ? cfg->cdn.cache_mode : "");
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "dns.server_enable",
            sizeof("dns.server_enable") - 1
        )
    ) {
        ZVAL_BOOL(return_value, cfg->dns.server_enable);
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "dns.mode", sizeof("dns.mode") - 1)) {
        ZVAL_STRING(return_value, cfg->dns.mode != NULL ? cfg->dns.mode : "");
        return SUCCESS;
    }

    if (zend_string_equals_cstr(canonical_key, "otel.enable", sizeof("otel.enable") - 1)) {
        ZVAL_BOOL(return_value, cfg->observability.enable);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "otel.service_name",
            sizeof("otel.service_name") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->observability.service_name != NULL ? cfg->observability.service_name : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "otel.queue_state_path",
            sizeof("otel.queue_state_path") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->observability.queue_state_path != NULL ? cfg->observability.queue_state_path : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "autoscale.provider",
            sizeof("autoscale.provider") - 1
        )
    ) {
        ZVAL_STRING(return_value, cfg->autoscale.provider != NULL ? cfg->autoscale.provider : "");
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "autoscale.max_nodes",
            sizeof("autoscale.max_nodes") - 1
        )
    ) {
        ZVAL_LONG(return_value, cfg->autoscale.max_nodes);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "mcp.enable_request_caching",
            sizeof("mcp.enable_request_caching") - 1
        )
    ) {
        ZVAL_BOOL(return_value, cfg->mcp.mcp_enable_request_caching);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "mcp.default_request_timeout_ms",
            sizeof("mcp.default_request_timeout_ms") - 1
        )
    ) {
        ZVAL_LONG(return_value, cfg->mcp.mcp_default_request_timeout_ms);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "mcp.allowed_peer_hosts",
            sizeof("mcp.allowed_peer_hosts") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->mcp.mcp_allowed_peer_hosts != NULL ? cfg->mcp.mcp_allowed_peer_hosts : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "mcp.transfer_state_path",
            sizeof("mcp.transfer_state_path") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->mcp.mcp_transfer_state_path != NULL ? cfg->mcp.mcp_transfer_state_path : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "orchestrator.enable_distributed_tracing",
            sizeof("orchestrator.enable_distributed_tracing") - 1
        )
    ) {
        ZVAL_BOOL(return_value, cfg->mcp.orchestrator_enable_distributed_tracing);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "orchestrator.execution_backend",
            sizeof("orchestrator.execution_backend") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->mcp.orchestrator_execution_backend != NULL
                ? cfg->mcp.orchestrator_execution_backend
                : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "orchestrator.worker_queue_path",
            sizeof("orchestrator.worker_queue_path") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->mcp.orchestrator_worker_queue_path != NULL
                ? cfg->mcp.orchestrator_worker_queue_path
                : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "orchestrator.remote_host",
            sizeof("orchestrator.remote_host") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->mcp.orchestrator_remote_host != NULL
                ? cfg->mcp.orchestrator_remote_host
                : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "orchestrator.remote_port",
            sizeof("orchestrator.remote_port") - 1
        )
    ) {
        ZVAL_LONG(return_value, cfg->mcp.orchestrator_remote_port);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "orchestrator.state_path",
            sizeof("orchestrator.state_path") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->mcp.orchestrator_state_path != NULL ? cfg->mcp.orchestrator_state_path : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "geometry.default_vector_dimensions",
            sizeof("geometry.default_vector_dimensions") - 1
        )
    ) {
        ZVAL_LONG(return_value, cfg->semantic_geometry.default_vector_dimensions);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "geometry.calculation_precision",
            sizeof("geometry.calculation_precision") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->semantic_geometry.calculation_precision != NULL
                ? cfg->semantic_geometry.calculation_precision
                : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "smartcontract.enable",
            sizeof("smartcontract.enable") - 1
        )
    ) {
        ZVAL_BOOL(return_value, cfg->smart_contract.enable);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "smartcontract.dlt_provider",
            sizeof("smartcontract.dlt_provider") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->smart_contract.dlt_provider != NULL ? cfg->smart_contract.dlt_provider : ""
        );
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "ssh.gateway_enable",
            sizeof("ssh.gateway_enable") - 1
        )
    ) {
        ZVAL_BOOL(return_value, cfg->ssh.gateway_enable);
        return SUCCESS;
    }

    if (
        zend_string_equals_cstr(
            canonical_key,
            "ssh.gateway_auth_mode",
            sizeof("ssh.gateway_auth_mode") - 1
        )
    ) {
        ZVAL_STRING(
            return_value,
            cfg->ssh.gateway_auth_mode != NULL ? cfg->ssh.gateway_auth_mode : ""
        );
        return SUCCESS;
    }

    return FAILURE;
}
