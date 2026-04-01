PHP_FUNCTION(king_system_health_check)
{
    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);
    add_assoc_bool(return_value, "overall_healthy", 1);
    add_assoc_string(return_value, "build", "v1");
    add_assoc_string(return_value, "version", PHP_KING_VERSION);
    king_add_config_policy_info(return_value);
}

PHP_FUNCTION(king_system_get_metrics)
{
    zval resource_metrics;

    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);

    array_init(&resource_metrics);
    add_assoc_long(&resource_metrics, "memory_usage_bytes",
        (zend_long) zend_memory_usage(0));
    add_assoc_long(&resource_metrics, "memory_peak_bytes",
        (zend_long) zend_memory_peak_usage(0));
    add_assoc_zval(return_value, "resource_metrics", &resource_metrics);

    /*
     * Expose only runtime values the active runtime can measure locally
     * today. The richer system report from src_bak depends on component state
     * and counters that are not wired into the current build.
     */
    add_assoc_long(return_value, "metrics_collected_at", (zend_long) time(NULL));
}

PHP_FUNCTION(king_system_get_performance_report)
{
    zval performance_overview;
    zval component_performance;
    zval recommendations;

    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);

    array_init(&performance_overview);
    add_assoc_string(&performance_overview, "build", "v1");
    add_assoc_long(&performance_overview, "memory_usage_mb",
        (zend_long) (zend_memory_usage(0) / (1024 * 1024)));
    add_assoc_long(&performance_overview, "memory_peak_mb",
        (zend_long) (zend_memory_peak_usage(0) / (1024 * 1024)));
    add_assoc_zval(return_value, "performance_overview", &performance_overview);

    /*
     * The active build has no live component registry, throughput counters, or
     * recommendation engine yet. Keep those sections stable but empty until
     * the system-orchestration runtime is actually wired in.
     */
    array_init(&component_performance);
    add_assoc_zval(return_value, "component_performance", &component_performance);

    array_init(&recommendations);
    add_assoc_zval(return_value, "recommendations", &recommendations);

    add_assoc_long(return_value, "report_generated_at", (zend_long) time(NULL));
}

PHP_FUNCTION(king_system_get_component_info)
{
    zend_string *component_name;
    zval configuration;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(component_name)
    ZEND_PARSE_PARAMETERS_END();

    /*
     * The active build has no dynamic component registry. Expose a stable
     * fixed inventory keyed by the archived component names, with live config
     * snippets for modules that already have active base-layer globals.
     */
    if (zend_string_equals_literal(component_name, "config")) {
        king_component_info_init(return_value, "config", "config_backed");
        array_init(&configuration);
        king_add_config_policy_info(&configuration);
    } else if (zend_string_equals_literal(component_name, "telemetry")) {
        king_component_info_init(return_value, "telemetry", "config_backed");
        array_init(&configuration);
        add_assoc_bool(&configuration, "enabled", king_open_telemetry_config.enable ? 1 : 0);
        add_assoc_string(&configuration, "service_name",
            king_safe_string(king_open_telemetry_config.service_name));
        add_assoc_string(&configuration, "exporter_endpoint",
            king_safe_string(king_open_telemetry_config.exporter_endpoint));
        add_assoc_string(&configuration, "exporter_protocol",
            king_safe_string(king_open_telemetry_config.exporter_protocol));
        add_assoc_bool(&configuration, "metrics_enable",
            king_open_telemetry_config.metrics_enable ? 1 : 0);
        add_assoc_bool(&configuration, "logs_enable",
            king_open_telemetry_config.logs_enable ? 1 : 0);
        add_assoc_string(&configuration, "delivery_contract",
            "best_effort_bounded_retry");
        add_assoc_string(&configuration, "queue_persistence",
            "process_local_non_persistent");
        add_assoc_string(&configuration, "restart_replay",
            "not_supported");
        add_assoc_string(&configuration, "drain_behavior",
            "single_batch_per_flush");
    } else if (zend_string_equals_literal(component_name, "autoscaling")) {
        king_component_info_init(return_value, "autoscaling", "config_backed");
        array_init(&configuration);
        add_assoc_string(&configuration, "provider",
            king_safe_string(king_cloud_autoscale_config.provider));
        add_assoc_string(&configuration, "region",
            king_safe_string(king_cloud_autoscale_config.region));
        add_assoc_long(&configuration, "min_nodes",
            king_cloud_autoscale_config.min_nodes);
        add_assoc_long(&configuration, "max_nodes",
            king_cloud_autoscale_config.max_nodes);
        add_assoc_string(&configuration, "scale_up_policy",
            king_safe_string(king_cloud_autoscale_config.scale_up_policy));
    } else if (
        zend_string_equals_literal(component_name, "router") ||
        zend_string_equals_literal(component_name, "loadbalancer") ||
        zend_string_equals_literal(component_name, "router_loadbalancer")
    ) {
        king_component_info_init(
            return_value,
            "router_loadbalancer",
            "config_backed"
        );
        array_init(&configuration);
        add_assoc_bool(
            &configuration,
            "router_mode_enable",
            king_router_loadbalancer_config.router_mode_enable ? 1 : 0
        );
        add_assoc_string(
            &configuration,
            "hashing_algorithm",
            king_safe_string(king_router_loadbalancer_config.hashing_algorithm)
        );
        add_assoc_string(
            &configuration,
            "backend_discovery_mode",
            king_safe_string(king_router_loadbalancer_config.backend_discovery_mode)
        );
        add_assoc_string(
            &configuration,
            "backend_static_list",
            king_safe_string(king_router_loadbalancer_config.backend_static_list)
        );
        add_assoc_string(
            &configuration,
            "backend_mcp_endpoint",
            king_safe_string(king_router_loadbalancer_config.backend_mcp_endpoint)
        );
        add_assoc_long(
            &configuration,
            "backend_mcp_poll_interval_sec",
            king_router_loadbalancer_config.backend_mcp_poll_interval_sec
        );
        add_assoc_long(
            &configuration,
            "max_forwarding_pps",
            king_router_loadbalancer_config.max_forwarding_pps
        );
        add_assoc_string(&configuration, "forwarding_contract", "config_only");
    } else if (zend_string_equals_literal(component_name, "object_store")) {
        king_component_info_init(return_value, "object_store", "config_backed");
        array_init(&configuration);
        add_assoc_bool(&configuration, "enabled", king_native_object_store_config.enable ? 1 : 0);
        add_assoc_string(&configuration, "storage_contract", "local_fs+distributed+cloud_s3+cloud_gcs+cloud_azure_sidecars");
        add_assoc_string(&configuration, "legacy_backend_alias", "memory_cache->local_fs");
        add_assoc_string(&configuration, "simulated_backends", "");
        add_assoc_string(&configuration, "redundancy_mode",
            king_safe_string(king_native_object_store_config.default_redundancy_mode));
        add_assoc_long(&configuration, "replication_factor",
            king_native_object_store_config.default_replication_factor);
        add_assoc_long(&configuration, "chunk_size_mb",
            king_native_object_store_config.default_chunk_size_mb);
    } else if (zend_string_equals_literal(component_name, "cdn")) {
        king_component_info_init(return_value, "cdn", "config_backed");
        array_init(&configuration);
        add_assoc_bool(&configuration, "enabled", king_native_cdn_config.enable ? 1 : 0);
        add_assoc_string(&configuration, "cache_mode",
            king_safe_string(king_native_cdn_config.cache_mode));
        add_assoc_long(&configuration, "default_ttl_sec",
            king_native_cdn_config.cache_default_ttl_sec);
        add_assoc_string(&configuration, "origin_mcp_endpoint",
            king_safe_string(king_native_cdn_config.origin_mcp_endpoint));
        add_assoc_string(&configuration, "origin_http_endpoint",
            king_safe_string(king_native_cdn_config.origin_http_endpoint));
    } else if (zend_string_equals_literal(component_name, "semantic_dns")) {
        king_component_info_init(return_value, "semantic_dns", "config_backed");
        array_init(&configuration);
        add_assoc_bool(&configuration, "server_enable",
            king_smart_dns_config.server_enable ? 1 : 0);
        add_assoc_string(&configuration, "server_bind_host",
            king_safe_string(king_smart_dns_config.server_bind_host));
        add_assoc_long(&configuration, "server_port",
            king_smart_dns_config.server_port);
        add_assoc_string(&configuration, "mode",
            king_safe_string(king_smart_dns_config.mode));
        add_assoc_long(&configuration, "default_record_ttl_sec",
            king_smart_dns_config.default_record_ttl_sec);
        add_assoc_long(&configuration, "service_discovery_max_ips_per_response",
            king_smart_dns_config.service_discovery_max_ips_per_response);
        add_assoc_bool(&configuration, "semantic_mode_enable",
            king_smart_dns_config.semantic_mode_enable ? 1 : 0);
        add_assoc_string(&configuration, "live_probe_allowed_hosts",
            king_safe_string(king_smart_dns_config.live_probe_allowed_hosts));
        add_assoc_string(&configuration, "mothernode_uri",
            king_safe_string(king_smart_dns_config.mothernode_uri));
    } else if (zend_string_equals_literal(component_name, "mcp")) {
        king_component_info_init(return_value, "mcp", "config_backed");
        array_init(&configuration);
        add_assoc_long(&configuration, "default_request_timeout_ms",
            king_mcp_orchestrator_config.mcp_default_request_timeout_ms);
        add_assoc_long(&configuration, "max_message_size_bytes",
            king_mcp_orchestrator_config.mcp_max_message_size_bytes);
        add_assoc_bool(&configuration, "request_caching_enabled",
            king_mcp_orchestrator_config.mcp_enable_request_caching ? 1 : 0);
        add_assoc_long(&configuration, "request_cache_ttl_sec",
            king_mcp_orchestrator_config.mcp_request_cache_ttl_sec);
        add_assoc_string(&configuration, "allowed_peer_hosts",
            king_safe_string(king_mcp_orchestrator_config.mcp_allowed_peer_hosts));
        add_assoc_string(&configuration, "transfer_state_path",
            king_safe_string(king_mcp_orchestrator_config.mcp_transfer_state_path));
        add_assoc_string(
            &configuration,
            "transfer_queue_persistence",
            (
                king_mcp_orchestrator_config.mcp_transfer_state_path != NULL
                && king_mcp_orchestrator_config.mcp_transfer_state_path[0] != '\0'
            )
                ? "restart_rehydratable_local_queue"
                : "process_local_non_persistent"
        );
        add_assoc_string(&configuration, "transfer_cleanup_policy",
            "consume_on_successful_download");
        add_assoc_string(&configuration, "topology_scope", "tcp_host_port_peer");
    } else if (zend_string_equals_literal(component_name, "pipeline_orchestrator")) {
        king_component_info_init(return_value, "pipeline_orchestrator", "config_backed");
        array_init(&configuration);
        add_assoc_long(&configuration, "default_pipeline_timeout_ms",
            king_mcp_orchestrator_config.orchestrator_default_pipeline_timeout_ms);
        add_assoc_long(&configuration, "max_recursion_depth",
            king_mcp_orchestrator_config.orchestrator_max_recursion_depth);
        add_assoc_long(&configuration, "loop_concurrency_default",
            king_mcp_orchestrator_config.orchestrator_loop_concurrency_default);
        add_assoc_bool(&configuration, "distributed_tracing_enabled",
            king_mcp_orchestrator_config.orchestrator_enable_distributed_tracing ? 1 : 0);
        king_orchestrator_append_component_info(&configuration);
    } else if (zend_string_equals_literal(component_name, "iibin")) {
        king_component_info_init(return_value, "iibin", "config_backed");
        array_init(&configuration);
        add_assoc_long(&configuration, "max_schema_fields",
            king_iibin_config.max_schema_fields);
        add_assoc_long(&configuration, "max_recursion_depth",
            king_iibin_config.max_recursion_depth);
        add_assoc_bool(&configuration, "string_interning_enable",
            king_iibin_config.string_interning_enable ? 1 : 0);
        add_assoc_bool(&configuration, "shared_memory_buffers",
            king_iibin_config.use_shared_memory_buffers ? 1 : 0);
    } else if (
        zend_string_equals_literal(component_name, "client") ||
        zend_string_equals_literal(component_name, "server")
    ) {
        king_component_info_init(return_value, ZSTR_VAL(component_name), "local_runtime");
        array_init(&configuration);
    } else {
        RETURN_FALSE;
    }

    king_component_info_finish(return_value, &configuration);
}
