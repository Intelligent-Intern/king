#include "object_store/object_store_internal.h"

PHP_FUNCTION(king_object_store_get_stats)
{
    zval object_store;
    zval cdn;
    zend_long cached_object_count = 0;
    zend_long cached_bytes = 0;
    zend_long latest_cached_at = 0;

    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);
    king_cdn_collect_live_stats(&cached_object_count, &cached_bytes, &latest_cached_at);

    array_init(&object_store);
    add_assoc_bool(&object_store, "enabled", king_native_object_store_config.enable ? 1 : 0);
    add_assoc_string(&object_store, "redundancy_mode", king_safe_string(king_native_object_store_config.default_redundancy_mode));
    add_assoc_long(&object_store, "replication_factor", king_native_object_store_config.default_replication_factor);
    add_assoc_long(&object_store, "chunk_size_mb", king_native_object_store_config.default_chunk_size_mb);
    add_assoc_string(&object_store, "metadata_agent_uri", king_safe_string(king_native_object_store_config.metadata_agent_uri));
    add_assoc_string(&object_store, "node_discovery_mode", king_safe_string(king_native_object_store_config.node_discovery_mode));
    add_assoc_bool(&object_store, "metadata_cache_enabled", king_native_object_store_config.metadata_cache_enable ? 1 : 0);
    add_assoc_long(&object_store, "metadata_cache_ttl_sec", king_native_object_store_config.metadata_cache_ttl_sec);
    add_assoc_bool(&object_store, "directstorage_enabled", king_native_object_store_config.enable_directstorage ? 1 : 0);

    /* Use native runtime variables directly from the new C core */
    add_assoc_bool(&object_store, "runtime_initialized", king_object_store_runtime.initialized ? 1 : 0);
    add_assoc_string(&object_store, "runtime_primary_backend", (char *) king_storage_backend_to_string(king_object_store_runtime.config.primary_backend));
    add_assoc_string(&object_store, "runtime_primary_backend_contract", king_object_store_runtime.primary_adapter_contract[0] == '\0' ? "unknown" : king_object_store_runtime.primary_adapter_contract);
    add_assoc_string(&object_store, "runtime_storage_contract", "local_fs+distributed+cloud_s3+cloud_gcs+cloud_azure_sidecars");
    add_assoc_string(&object_store, "runtime_legacy_backend_alias", "memory_cache->local_fs");
    add_assoc_string(&object_store, "runtime_simulated_backends", "");
    add_assoc_string(
        &object_store,
        "runtime_distributed_coordinator_state_status",
        king_object_store_runtime.distributed_coordinator_state_status[0] == '\0'
            ? "inactive"
            : king_object_store_runtime.distributed_coordinator_state_status
    );
    if (king_object_store_runtime.distributed_coordinator_state_path[0] != '\0') {
        add_assoc_string(
            &object_store,
            "runtime_distributed_coordinator_state_path",
            king_object_store_runtime.distributed_coordinator_state_path
        );
    } else {
        add_assoc_null(&object_store, "runtime_distributed_coordinator_state_path");
    }
    add_assoc_bool(
        &object_store,
        "runtime_distributed_coordinator_state_present",
        king_object_store_runtime.distributed_coordinator_state_present ? 1 : 0
    );
    add_assoc_bool(
        &object_store,
        "runtime_distributed_coordinator_state_recovered",
        king_object_store_runtime.distributed_coordinator_state_recovered ? 1 : 0
    );
    add_assoc_long(
        &object_store,
        "runtime_distributed_coordinator_state_version",
        (zend_long) king_object_store_runtime.distributed_coordinator_state_version
    );
    add_assoc_long(
        &object_store,
        "runtime_distributed_coordinator_generation",
        (zend_long) king_object_store_runtime.distributed_coordinator_generation
    );
    if (king_object_store_runtime.distributed_coordinator_created_at > 0) {
        add_assoc_long(
            &object_store,
            "runtime_distributed_coordinator_created_at",
            (zend_long) king_object_store_runtime.distributed_coordinator_created_at
        );
    } else {
        add_assoc_null(&object_store, "runtime_distributed_coordinator_created_at");
    }
    if (king_object_store_runtime.distributed_coordinator_last_loaded_at > 0) {
        add_assoc_long(
            &object_store,
            "runtime_distributed_coordinator_last_loaded_at",
            (zend_long) king_object_store_runtime.distributed_coordinator_last_loaded_at
        );
    } else {
        add_assoc_null(&object_store, "runtime_distributed_coordinator_last_loaded_at");
    }
    add_assoc_string(
        &object_store,
        "runtime_distributed_coordinator_state_error",
        king_object_store_runtime.distributed_coordinator_state_error[0] == '\0'
            ? ""
            : king_object_store_runtime.distributed_coordinator_state_error
    );
    add_assoc_string(&object_store, "runtime_storage_root_path", king_safe_string(king_object_store_runtime.config.storage_root_path));
    add_assoc_long(&object_store, "runtime_max_storage_size_bytes", king_object_store_runtime.config.max_storage_size_bytes);
    add_assoc_string(&object_store, "runtime_capacity_mode", (char *) king_object_store_runtime_capacity_mode_to_string());
    add_assoc_string(&object_store, "runtime_capacity_scope", "committed_primary_inventory_bytes");
    add_assoc_bool(&object_store, "runtime_capacity_enforced", king_object_store_runtime_capacity_is_enabled() ? 1 : 0);
    if (king_object_store_runtime_capacity_is_enabled()) {
        add_assoc_long(&object_store, "runtime_capacity_available_bytes", (zend_long) king_object_store_runtime_capacity_available_bytes());
    } else {
        add_assoc_null(&object_store, "runtime_capacity_available_bytes");
    }
    add_assoc_long(&object_store, "runtime_replication_factor", king_object_store_runtime.config.replication_factor);
    add_assoc_long(&object_store, "runtime_chunk_size_kb", king_object_store_runtime.config.chunk_size_kb);
    add_assoc_string(&object_store, "runtime_backup_backend", (char *) king_storage_backend_to_string(king_object_store_runtime.config.backup_backend));
    add_assoc_string(&object_store, "runtime_backup_adapter_contract", king_object_store_runtime.backup_adapter_contract[0] == '\0' ? "unknown" : king_object_store_runtime.backup_adapter_contract);
    add_assoc_string(&object_store, "runtime_primary_adapter_status", king_object_store_runtime.primary_adapter_status[0] == '\0' ? "unknown" : king_object_store_runtime.primary_adapter_status);
    add_assoc_string(&object_store, "runtime_primary_adapter_error", king_object_store_runtime.primary_adapter_error[0] == '\0' ? "" : king_object_store_runtime.primary_adapter_error);
    add_assoc_string(&object_store, "runtime_backup_adapter_status", king_object_store_runtime.backup_adapter_status[0] == '\0' ? "unknown" : king_object_store_runtime.backup_adapter_status);
    add_assoc_string(&object_store, "runtime_backup_adapter_error", king_object_store_runtime.backup_adapter_error[0] == '\0' ? "" : king_object_store_runtime.backup_adapter_error);
    add_assoc_long(&object_store, "object_count", king_object_store_runtime.current_object_count);
    add_assoc_long(&object_store, "stored_bytes", king_object_store_runtime.current_stored_bytes);
    
    if (king_object_store_runtime.latest_object_at > 0) {
        add_assoc_long(&object_store, "latest_object_at", king_object_store_runtime.latest_object_at);
    } else {
        add_assoc_null(&object_store, "latest_object_at");
    }
    
    add_assoc_zval(return_value, "object_store", &object_store);

    array_init(&cdn);
    add_assoc_bool(&cdn, "enabled", king_native_cdn_config.enable ? 1 : 0);
    add_assoc_string(&cdn, "cache_mode", king_safe_string(king_native_cdn_config.cache_mode));
    add_assoc_long(&cdn, "cache_memory_limit_mb", king_native_cdn_config.cache_memory_limit_mb);
    add_assoc_long(&cdn, "default_ttl_sec", king_native_cdn_config.cache_default_ttl_sec);
    add_assoc_long(&cdn, "max_object_size_mb", king_native_cdn_config.cache_max_object_size_mb);
    add_assoc_string(&cdn, "origin_mcp_endpoint", king_safe_string(king_native_cdn_config.origin_mcp_endpoint));
    add_assoc_string(&cdn, "origin_http_endpoint", king_safe_string(king_native_cdn_config.origin_http_endpoint));
    add_assoc_long(&cdn, "origin_request_timeout_ms", king_native_cdn_config.origin_request_timeout_ms);
    add_assoc_bool(&cdn, "serve_stale_on_error", king_native_cdn_config.serve_stale_on_error ? 1 : 0);
    add_assoc_string(&cdn, "allowed_http_methods", king_safe_string(king_native_cdn_config.allowed_http_methods));
    add_assoc_bool(&cdn, "local_cache_initialized", king_cdn_cache_registry_initialized ? 1 : 0);

    /* Use native variables again here */
    add_assoc_bool(&cdn, "runtime_initialized", king_object_store_runtime.initialized ? 1 : 0);
    add_assoc_bool(&cdn, "runtime_enabled", king_object_store_runtime.config.cdn_config.enabled ? 1 : 0);
    add_assoc_long(&cdn, "runtime_cache_size_mb", king_object_store_runtime.config.cdn_config.cache_size_mb);
    add_assoc_long(&cdn, "runtime_default_ttl_sec", king_object_store_runtime.config.cdn_config.default_ttl_seconds);
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
    ZEND_PARSE_PARAMETERS_NONE();

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    array_init(return_value);
    add_assoc_string(return_value, "mode", "native_fs_noop");
    add_assoc_long(return_value, "scanned_objects", king_object_store_runtime.current_object_count);
    add_assoc_long(return_value, "total_size_bytes", king_object_store_runtime.current_stored_bytes);
    add_assoc_long(return_value, "orphaned_entries_removed", 0);
    add_assoc_long(return_value, "bytes_reclaimed", 0);
    add_assoc_long(return_value, "optimized_at", (zend_long) time(NULL));
}
