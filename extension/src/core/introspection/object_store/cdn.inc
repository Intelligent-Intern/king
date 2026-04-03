/*
 * Root CDN companion slice: implements the local cache/invalidation leaves,
 * resolves cache-entry size through the active local/distributed/cloud
 * metadata path without dragging full payload bytes when avoidable, and
 * exposes the current synthetic edge-node inventory that the runtime can
 * describe today.
 */

#include "object_store/object_store_internal.h"
#include <sys/stat.h>

PHP_FUNCTION(king_cdn_cache_object)
{
    zend_string *object_id;
    zval *options = NULL;
    void *data = NULL;
    size_t data_size = 0;
    king_object_metadata_t metadata;
    king_cdn_cache_entry *cache_entry;
    king_cdn_cache_entry *existing = NULL;
    zval stored_entry;
    zend_long ttl_sec;
    time_t cached_at;
    zend_bool size_resolved = 0;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(object_id) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Object ID cannot be empty");
        RETURN_THROWS();
    }

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    if (!king_cdn_cache_registry_initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "CDN cache registry is unavailable."
        );
        RETURN_THROWS();
    }

    /*
     * Determine TTL: prefer runtime CDN config when the object store was
     * initialised, then fall back to the INI-level cdn config.
     */
    if (king_object_store_runtime.initialized &&
        king_object_store_runtime.config.cdn_config.default_ttl_seconds > 0) {
        ttl_sec = (zend_long) king_object_store_runtime.config.cdn_config.default_ttl_seconds;
    } else {
        ttl_sec = (zend_long) king_native_cdn_config.cache_default_ttl_sec;
    }

    if (options != NULL) {
        zval *ttl_zv = zend_hash_str_find(Z_ARRVAL_P(options), "ttl_sec", sizeof("ttl_sec") - 1);
        if (ttl_zv != NULL) {
            if (Z_TYPE_P(ttl_zv) != IS_LONG) {
                zend_throw_exception_ex(
                    king_ce_validation_exception, 0,
                    "CDN cache option 'ttl_sec' must be of type int."
                );
                RETURN_THROWS();
            }
            if (Z_LVAL_P(ttl_zv) < 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception, 0,
                    "CDN cache option 'ttl_sec' cannot be negative."
                );
                RETURN_THROWS();
            }
            ttl_sec = Z_LVAL_P(ttl_zv);
        }
    }

    /*
     * The CDN entry only tracks payload size and TTL metadata. Avoid a full
     * payload read when the active backend can prove object existence and
     * content length through local/distributed sidecars or cloud HEAD metadata.
     */
    if (king_object_store_runtime.initialized) {
        king_storage_backend_t primary_backend = king_object_store_runtime.config.primary_backend;

        memset(&metadata, 0, sizeof(metadata));

        if ((primary_backend == KING_STORAGE_BACKEND_LOCAL_FS
                || primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE)
            && (king_object_store_runtime_metadata_cache_read(ZSTR_VAL(object_id), &metadata) == SUCCESS
                || king_object_store_meta_read(ZSTR_VAL(object_id), &metadata) == SUCCESS)
            && metadata.content_length <= (uint64_t) SIZE_MAX) {
            data_size = (size_t) metadata.content_length;
            size_resolved = 1;
        } else if ((primary_backend == KING_STORAGE_BACKEND_DISTRIBUTED
                || primary_backend == KING_STORAGE_BACKEND_CLOUD_S3
                || primary_backend == KING_STORAGE_BACKEND_CLOUD_GCS
                || primary_backend == KING_STORAGE_BACKEND_CLOUD_AZURE)
            && king_object_store_backend_read_metadata(ZSTR_VAL(object_id), &metadata) == SUCCESS
            && metadata.content_length <= (uint64_t) SIZE_MAX) {
            data_size = (size_t) metadata.content_length;
            size_resolved = 1;
        }
    }

    if (!size_resolved) {
        if (king_object_store_read_object(ZSTR_VAL(object_id), &data, &data_size, NULL) != SUCCESS) {
            RETURN_FALSE;
        }
        if (data) {
            if (data_size > 0) {
                memset(data, 0, data_size);
            }
            pefree(data, 1);
        }
    }

    /*
     * If a non-expired entry is already cached, update it in place to avoid
     * double-counting. If expired, we just overwrite via zend_hash_update.
     */
    cached_at = time(NULL);

    existing = king_cdn_cache_registry_get_entry(object_id);
    if (existing != NULL) {
        /* Update existing entry in place */
        existing->payload_size = (zend_long) data_size;
        existing->cached_at    = cached_at;
        existing->expires_at   = ttl_sec > 0 ? cached_at + ttl_sec : 0;
        RETURN_TRUE;
    }

    cache_entry = pecalloc(1, sizeof(*cache_entry), 1);
    cache_entry->object_id    = zend_string_dup(object_id, 1);
    cache_entry->payload_size = (zend_long) data_size;
    cache_entry->cached_at    = cached_at;
    cache_entry->expires_at   = ttl_sec > 0 ? cached_at + ttl_sec : 0;

    ZVAL_PTR(&stored_entry, cache_entry);
    if (zend_hash_update(&king_cdn_cache_registry, cache_entry->object_id, &stored_entry) == NULL) {
        zend_throw_exception_ex(
            king_ce_system_exception, 0,
            "Failed to cache object '%s'.",
            ZSTR_VAL(cache_entry->object_id)
        );
        king_cdn_cache_entry_free(cache_entry);
        RETURN_THROWS();
    }

    if (king_cdn_distribute_object(ZSTR_VAL(cache_entry->object_id), NULL, 1) != SUCCESS) {
         /* Ignore distribution errors in the runtime (not fatal for the local cache) */
    }

    RETURN_TRUE;
}

PHP_FUNCTION(king_cdn_invalidate_cache)
{
    zend_string *object_id = NULL;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_STR_OR_NULL(object_id)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_cdn_cache_registry_initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception, 0,
            "CDN cache registry is unavailable."
        );
        RETURN_THROWS();
    }

    if (object_id == NULL) {
        /* Flush all (including expired) */
        zend_long removed_count = (zend_long) zend_hash_num_elements(&king_cdn_cache_registry);
        zend_hash_clean(&king_cdn_cache_registry);
        RETURN_LONG(removed_count);
    }

    if (ZSTR_LEN(object_id) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Object ID cannot be empty");
        RETURN_THROWS();
    }

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    /* Single-object invalidation */
    if (king_cdn_cache_registry_get_entry(object_id) == NULL) {
        RETURN_LONG(0);
    }

    RETURN_LONG(zend_hash_del(&king_cdn_cache_registry, object_id) == SUCCESS ? 1 : 0);
}

/*
 * Expose a live snapshot of the CDN runtime configuration as a list of
 * virtual edge node descriptors derived from the active object-store config.
 *
 * In the current runtime there are no real distributed edge nodes, but we
 * return a single synthetic entry when the CDN is configured and enabled, so
 * callers can rely on a stable non-empty list.
 */
PHP_FUNCTION(king_cdn_get_edge_nodes)
{
    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);

    if (!king_object_store_runtime.initialized ||
        !king_object_store_runtime.config.cdn_config.enabled) {
        return; /* CDN disabled or store not yet initialised */
    }

    uint32_t node_count = king_object_store_runtime.config.cdn_config.edge_node_count;
    if (node_count == 0) node_count = 1;

    for (uint32_t i = 0; i < node_count; i++) {
        zval node;
        char node_id[32];
        snprintf(node_id, sizeof(node_id), "edge-%u", i);

        array_init(&node);
        add_assoc_string(&node, "node_id",        node_id);
        add_assoc_string(&node, "hostname",       "localhost");
        add_assoc_long  (&node, "port",           (zend_long)(8080 + i));
        add_assoc_string(&node, "backend",        "local_fs");
        add_assoc_bool  (&node, "is_cdn_edge",    1);
        add_assoc_bool  (&node, "is_healthy",     1);
        add_assoc_long  (&node, "cache_size_mb",  (zend_long) king_object_store_runtime.config.cdn_config.cache_size_mb);
        add_assoc_long  (&node, "default_ttl_sec",(zend_long) king_object_store_runtime.config.cdn_config.default_ttl_seconds);
        add_next_index_zval(return_value, &node);
    }
}
