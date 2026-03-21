PHP_FUNCTION(king_object_store_get_stats)
{
    zval object_store;
    zval cdn;
    zend_long object_count;
    zend_long stored_bytes;
    zend_long latest_object_at;
    zend_long cached_object_count;
    zend_long cached_bytes;
    zend_long latest_cached_at;

    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);
    king_object_store_collect_live_stats(&object_count, &stored_bytes, &latest_object_at);
    king_cdn_collect_live_stats(&cached_object_count, &cached_bytes, &latest_cached_at);

    array_init(&object_store);
    add_assoc_bool(&object_store, "enabled", king_native_object_store_config.enable ? 1 : 0);
    add_assoc_string(&object_store, "redundancy_mode",
        king_safe_string(king_native_object_store_config.default_redundancy_mode));
    add_assoc_long(&object_store, "replication_factor",
        king_native_object_store_config.default_replication_factor);
    add_assoc_long(&object_store, "chunk_size_mb",
        king_native_object_store_config.default_chunk_size_mb);
    add_assoc_string(&object_store, "metadata_agent_uri",
        king_safe_string(king_native_object_store_config.metadata_agent_uri));
    add_assoc_string(&object_store, "node_discovery_mode",
        king_safe_string(king_native_object_store_config.node_discovery_mode));
    add_assoc_bool(&object_store, "metadata_cache_enabled",
        king_native_object_store_config.metadata_cache_enable ? 1 : 0);
    add_assoc_long(&object_store, "metadata_cache_ttl_sec",
        king_native_object_store_config.metadata_cache_ttl_sec);
    add_assoc_bool(&object_store, "directstorage_enabled",
        king_native_object_store_config.enable_directstorage ? 1 : 0);
    add_assoc_bool(&object_store, "local_registry_initialized",
        king_object_store_registry_initialized ? 1 : 0);
    add_assoc_bool(&object_store, "runtime_initialized",
        king_object_store_runtime.initialized ? 1 : 0);
    add_assoc_string(&object_store, "runtime_primary_backend",
        king_safe_string(king_object_store_runtime.primary_backend));
    add_assoc_string(&object_store, "runtime_storage_root_path",
        king_safe_string(king_object_store_runtime.storage_root_path));
    add_assoc_long(&object_store, "runtime_max_storage_size_bytes",
        king_object_store_runtime.max_storage_size_bytes);
    add_assoc_long(&object_store, "runtime_replication_factor",
        king_object_store_runtime.replication_factor);
    add_assoc_long(&object_store, "runtime_chunk_size_kb",
        king_object_store_runtime.chunk_size_kb);
    add_assoc_long(&object_store, "object_count", object_count);
    add_assoc_long(&object_store, "stored_bytes", stored_bytes);
    if (latest_object_at > 0) {
        add_assoc_long(&object_store, "latest_object_at", latest_object_at);
    } else {
        add_assoc_null(&object_store, "latest_object_at");
    }
    add_assoc_zval(return_value, "object_store", &object_store);

    array_init(&cdn);
    add_assoc_bool(&cdn, "enabled", king_native_cdn_config.enable ? 1 : 0);
    add_assoc_string(&cdn, "cache_mode",
        king_safe_string(king_native_cdn_config.cache_mode));
    add_assoc_long(&cdn, "cache_memory_limit_mb",
        king_native_cdn_config.cache_memory_limit_mb);
    add_assoc_long(&cdn, "default_ttl_sec",
        king_native_cdn_config.cache_default_ttl_sec);
    add_assoc_long(&cdn, "max_object_size_mb",
        king_native_cdn_config.cache_max_object_size_mb);
    add_assoc_string(&cdn, "origin_mcp_endpoint",
        king_safe_string(king_native_cdn_config.origin_mcp_endpoint));
    add_assoc_string(&cdn, "origin_http_endpoint",
        king_safe_string(king_native_cdn_config.origin_http_endpoint));
    add_assoc_long(&cdn, "origin_request_timeout_ms",
        king_native_cdn_config.origin_request_timeout_ms);
    add_assoc_bool(&cdn, "serve_stale_on_error",
        king_native_cdn_config.serve_stale_on_error ? 1 : 0);
    add_assoc_string(&cdn, "allowed_http_methods",
        king_safe_string(king_native_cdn_config.allowed_http_methods));
    add_assoc_bool(&cdn, "local_cache_initialized",
        king_cdn_cache_registry_initialized ? 1 : 0);
    add_assoc_bool(&cdn, "runtime_initialized",
        king_object_store_runtime.initialized ? 1 : 0);
    add_assoc_bool(&cdn, "runtime_enabled",
        king_object_store_runtime.cdn_enabled ? 1 : 0);
    add_assoc_long(&cdn, "runtime_cache_size_mb",
        king_object_store_runtime.cdn_cache_size_mb);
    add_assoc_long(&cdn, "runtime_default_ttl_sec",
        king_object_store_runtime.cdn_default_ttl_sec);
    add_assoc_long(&cdn, "cached_object_count", cached_object_count);
    add_assoc_long(&cdn, "cached_bytes", cached_bytes);
    if (latest_cached_at > 0) {
        add_assoc_long(&cdn, "latest_cached_at", latest_cached_at);
    } else {
        add_assoc_null(&cdn, "latest_cached_at");
    }
    add_assoc_zval(return_value, "cdn", &cdn);
}

PHP_FUNCTION(king_object_store_optimize)
{
    zend_long scanned_objects = 0;
    zend_long total_size_bytes = 0;
    zend_long latest_object_at = 0;

    ZEND_PARSE_PARAMETERS_NONE();

    if (!king_object_store_registry_initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }
    king_object_store_collect_live_stats(
        &scanned_objects,
        &total_size_bytes,
        &latest_object_at
    );

    array_init(return_value);
    add_assoc_string(return_value, "mode", "local_registry_noop");
    add_assoc_long(return_value, "scanned_objects", scanned_objects);
    add_assoc_long(return_value, "total_size_bytes", total_size_bytes);
    add_assoc_long(return_value, "orphaned_entries_removed", 0);
    add_assoc_long(return_value, "bytes_reclaimed", 0);
    /* The skeleton build can only summarize the in-memory registry today. */
    add_assoc_long(return_value, "optimized_at", (zend_long) time(NULL));
}
