PHP_FUNCTION(king_cdn_cache_object)
{
    zend_string *object_id;
    zval *options = NULL;
    void *data = NULL;
    size_t data_size = 0;
    king_cdn_cache_entry *cache_entry;
    zval stored_entry;
    zend_long ttl_sec;
    time_t cached_at;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(object_id) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Object ID cannot be empty");
        RETURN_THROWS();
    }

    if (!king_cdn_cache_registry_initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "CDN cache registry is unavailable."
        );
        RETURN_THROWS();
    }

    ttl_sec = king_object_store_runtime.initialized
        ? king_object_store_runtime.config.cdn_config.default_ttl_seconds
        : king_native_cdn_config.cache_default_ttl_sec;
    if (options != NULL) {
        zval *ttl_zv;

        ttl_zv = zend_hash_str_find(Z_ARRVAL_P(options), "ttl_sec", sizeof("ttl_sec") - 1);
        if (ttl_zv != NULL) {
            if (Z_TYPE_P(ttl_zv) != IS_LONG) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "CDN cache option 'ttl_sec' must be of type int."
                );
                RETURN_THROWS();
            }
            if (Z_LVAL_P(ttl_zv) < 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "CDN cache option 'ttl_sec' cannot be negative."
                );
                RETURN_THROWS();
            }
            ttl_sec = Z_LVAL_P(ttl_zv);
        }
    }

    if (king_object_store_read_object(ZSTR_VAL(object_id), &data, &data_size, NULL) != SUCCESS) {
        RETURN_FALSE;
    }

    cache_entry = pecalloc(1, sizeof(*cache_entry), 1);
    cache_entry->object_id = zend_string_dup(object_id, 1);
    cache_entry->payload_size = (zend_long) data_size;
    
    if (data) {
        pefree(data, 1);
    }
    cached_at = time(NULL);
    cache_entry->cached_at = cached_at;
    cache_entry->expires_at = ttl_sec > 0 ? cached_at + ttl_sec : 0;

    ZVAL_PTR(&stored_entry, cache_entry);
    if (zend_hash_update(&king_cdn_cache_registry, cache_entry->object_id, &stored_entry) == NULL) {
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "Failed to cache object '%s'.",
            ZSTR_VAL(cache_entry->object_id)
        );
        king_cdn_cache_entry_free(cache_entry);
        RETURN_THROWS();
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
            king_ce_runtime_exception,
            0,
            "CDN cache registry is unavailable."
        );
        RETURN_THROWS();
    }

    if (object_id != NULL && ZSTR_LEN(object_id) == 0) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "Object ID cannot be empty");
        RETURN_THROWS();
    }

    if (object_id == NULL) {
        zend_long removed_count;

        removed_count = (zend_long) zend_hash_num_elements(&king_cdn_cache_registry);
        zend_hash_clean(&king_cdn_cache_registry);
        RETURN_LONG(removed_count);
    }

    if (king_cdn_cache_registry_get_entry(object_id) == NULL) {
        RETURN_LONG(0);
    }

    RETURN_LONG(zend_hash_del(&king_cdn_cache_registry, object_id) == SUCCESS ? 1 : 0);
}

PHP_FUNCTION(king_cdn_get_edge_nodes)
{
    ZEND_PARSE_PARAMETERS_NONE();

    /*
     * The active skeleton build does not maintain a live CDN edge inventory
     * yet. Expose a stable empty list instead of a stub failure so callers
     * can rely on the current runtime shape.
     */
    array_init(return_value);
}
