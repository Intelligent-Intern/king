static void king_config_object_snapshot_to_array(zval *target, king_cfg_t *cfg)
{
    array_init(target);

    king_config_object_add_quic_snapshot(target, &cfg->quic);
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
    add_assoc_string(
        target,
        "cdn.allowed_http_methods",
        cfg->cdn.allowed_http_methods != NULL ? cfg->cdn.allowed_http_methods : ""
    );
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
    add_assoc_bool(target, "inference.with_memory", cfg->compute_ai.inference_with_memory);
    add_assoc_string(
        target,
        "inference.preferred_model_profile",
        cfg->compute_ai.inference_preferred_model_profile != NULL
            ? cfg->compute_ai.inference_preferred_model_profile
            : ""
    );
    add_assoc_string(
        target,
        "inference.cpu_model_name",
        cfg->compute_ai.inference_cpu_model_name != NULL
            ? cfg->compute_ai.inference_cpu_model_name
            : ""
    );
    add_assoc_string(
        target,
        "inference.cpu_model_artifact",
        cfg->compute_ai.inference_cpu_model_artifact != NULL
            ? cfg->compute_ai.inference_cpu_model_artifact
            : ""
    );
    add_assoc_string(
        target,
        "inference.gpu_model_name",
        cfg->compute_ai.inference_gpu_model_name != NULL
            ? cfg->compute_ai.inference_gpu_model_name
            : ""
    );
    add_assoc_string(
        target,
        "inference.gpu_model_artifact",
        cfg->compute_ai.inference_gpu_model_artifact != NULL
            ? cfg->compute_ai.inference_gpu_model_artifact
            : ""
    );
    add_assoc_long(
        target,
        "inference.gpu_max_gpu_layers",
        cfg->compute_ai.inference_gpu_max_gpu_layers
    );
    add_assoc_long(
        target,
        "inference.gpu_vram_reserve_mb",
        cfg->compute_ai.inference_gpu_vram_reserve_mb
    );
    add_assoc_string(
        target,
        "inference.gpu_thermal_sensor_path",
        cfg->compute_ai.inference_gpu_thermal_sensor_path != NULL
            ? cfg->compute_ai.inference_gpu_thermal_sensor_path
            : ""
    );
    add_assoc_string(
        target,
        "inference.gpu_thermal_sensor_command",
        cfg->compute_ai.inference_gpu_thermal_sensor_command != NULL
            ? cfg->compute_ai.inference_gpu_thermal_sensor_command
            : ""
    );
    add_assoc_double(
        target,
        "inference.gpu_thermal_max_temperature_c",
        cfg->compute_ai.inference_gpu_thermal_max_temperature_c
    );
    add_assoc_bool(
        target,
        "inference.gpu_allow_unmonitored",
        cfg->compute_ai.inference_gpu_allow_unmonitored
    );
    add_assoc_bool(target, "inference.llm_cache_enable", cfg->compute_ai.inference_llm_cache_enable);
    add_assoc_string(
        target,
        "inference.llm_cache_path",
        cfg->compute_ai.inference_llm_cache_path != NULL
            ? cfg->compute_ai.inference_llm_cache_path
            : ""
    );
    add_assoc_long(
        target,
        "inference.llm_cache_min_free_mb",
        cfg->compute_ai.inference_llm_cache_min_free_mb
    );
    add_assoc_bool(
        target,
        "inference.llm_cache_fail_closed",
        cfg->compute_ai.inference_llm_cache_fail_closed
    );
    add_assoc_string(
        target,
        "inference.llm_cache_disk_alert_webhook",
        cfg->compute_ai.inference_llm_cache_disk_alert_webhook != NULL
            ? cfg->compute_ai.inference_llm_cache_disk_alert_webhook
            : ""
    );
    add_assoc_string(
        target,
        "inference.llm_cache_disk_alert_mcp_service",
        cfg->compute_ai.inference_llm_cache_disk_alert_mcp_service != NULL
            ? cfg->compute_ai.inference_llm_cache_disk_alert_mcp_service
            : ""
    );
    add_assoc_string(
        target,
        "inference.llm_cache_disk_alert_mcp_method",
        cfg->compute_ai.inference_llm_cache_disk_alert_mcp_method != NULL
            ? cfg->compute_ai.inference_llm_cache_disk_alert_mcp_method
            : ""
    );
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
