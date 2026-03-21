static void king_object_store_entry_free(king_object_store_entry *entry)
{
    if (entry == NULL) {
        return;
    }

    if (entry->object_id != NULL) {
        zend_string_release_ex(entry->object_id, 1);
    }
    if (entry->payload != NULL) {
        zend_string_release_ex(entry->payload, 1);
    }

    pefree(entry, 1);
}

static void king_object_store_entry_zval_dtor(zval *zv)
{
    if (Z_TYPE_P(zv) == IS_PTR) {
        king_object_store_entry_free((king_object_store_entry *) Z_PTR_P(zv));
    }
}

int king_object_store_registry_minit(void)
{
    if (king_object_store_registry_initialized) {
        return SUCCESS;
    }

    zend_hash_init(
        &king_object_store_registry,
        8,
        NULL,
        king_object_store_entry_zval_dtor,
        1
    );
    king_object_store_registry_initialized = true;
    king_object_store_runtime_reset_defaults();

    return SUCCESS;
}

void king_object_store_registry_mshutdown(void)
{
    if (!king_object_store_registry_initialized) {
        return;
    }

    zend_hash_destroy(&king_object_store_registry);
    king_object_store_registry_initialized = false;
    king_object_store_runtime_reset_defaults();
}

static king_object_store_entry *king_object_store_registry_get_entry(
    zend_string *object_id
)
{
    zval *entry_zv;

    if (!king_object_store_registry_initialized) {
        return NULL;
    }

    entry_zv = zend_hash_find(&king_object_store_registry, object_id);
    if (entry_zv == NULL || Z_TYPE_P(entry_zv) != IS_PTR) {
        return NULL;
    }

    return (king_object_store_entry *) Z_PTR_P(entry_zv);
}

static void king_object_store_export_entry(zval *target, const king_object_store_entry *entry)
{
    array_init(target);
    add_assoc_str(target, "object_id", zend_string_copy(entry->object_id));
    add_assoc_long(target, "size_bytes", ZSTR_LEN(entry->payload));
    add_assoc_long(target, "stored_at", (zend_long) entry->stored_at);
}

static void king_object_store_collect_live_stats(
    zend_long *object_count,
    zend_long *stored_bytes,
    zend_long *latest_object_at
)
{
    zval *entry_zv;

    *object_count = 0;
    *stored_bytes = 0;
    *latest_object_at = 0;

    if (!king_object_store_registry_initialized) {
        return;
    }

    ZEND_HASH_FOREACH_VAL(&king_object_store_registry, entry_zv) {
        king_object_store_entry *entry;
        zend_long stored_at;

        if (Z_TYPE_P(entry_zv) != IS_PTR) {
            continue;
        }

        entry = (king_object_store_entry *) Z_PTR_P(entry_zv);
        if (entry == NULL || entry->payload == NULL) {
            continue;
        }

        (*object_count)++;
        *stored_bytes += (zend_long) ZSTR_LEN(entry->payload);

        stored_at = (zend_long) entry->stored_at;
        if (stored_at > *latest_object_at) {
            *latest_object_at = stored_at;
        }
    } ZEND_HASH_FOREACH_END();
}

static void king_cdn_cache_entry_free(king_cdn_cache_entry *entry)
{
    if (entry == NULL) {
        return;
    }

    if (entry->object_id != NULL) {
        zend_string_release_ex(entry->object_id, 1);
    }

    pefree(entry, 1);
}

static void king_cdn_cache_entry_zval_dtor(zval *zv)
{
    if (Z_TYPE_P(zv) == IS_PTR) {
        king_cdn_cache_entry_free((king_cdn_cache_entry *) Z_PTR_P(zv));
    }
}

int king_cdn_cache_registry_minit(void)
{
    if (king_cdn_cache_registry_initialized) {
        return SUCCESS;
    }

    zend_hash_init(
        &king_cdn_cache_registry,
        8,
        NULL,
        king_cdn_cache_entry_zval_dtor,
        1
    );
    king_cdn_cache_registry_initialized = true;

    return SUCCESS;
}

void king_cdn_cache_registry_mshutdown(void)
{
    if (!king_cdn_cache_registry_initialized) {
        return;
    }

    zend_hash_destroy(&king_cdn_cache_registry);
    king_cdn_cache_registry_initialized = false;
}

static king_cdn_cache_entry *king_cdn_cache_registry_get_entry(
    zend_string *object_id
)
{
    zval *entry_zv;

    if (!king_cdn_cache_registry_initialized) {
        return NULL;
    }

    entry_zv = zend_hash_find(&king_cdn_cache_registry, object_id);
    if (entry_zv == NULL || Z_TYPE_P(entry_zv) != IS_PTR) {
        return NULL;
    }

    return (king_cdn_cache_entry *) Z_PTR_P(entry_zv);
}

static void king_cdn_collect_live_stats(
    zend_long *cached_object_count,
    zend_long *cached_bytes,
    zend_long *latest_cached_at
)
{
    zval *entry_zv;

    *cached_object_count = 0;
    *cached_bytes = 0;
    *latest_cached_at = 0;

    if (!king_cdn_cache_registry_initialized) {
        return;
    }

    ZEND_HASH_FOREACH_VAL(&king_cdn_cache_registry, entry_zv) {
        king_cdn_cache_entry *entry;
        zend_long cached_at;

        if (Z_TYPE_P(entry_zv) != IS_PTR) {
            continue;
        }

        entry = (king_cdn_cache_entry *) Z_PTR_P(entry_zv);
        if (entry == NULL) {
            continue;
        }

        (*cached_object_count)++;
        *cached_bytes += entry->payload_size;

        cached_at = (zend_long) entry->cached_at;
        if (cached_at > *latest_cached_at) {
            *latest_cached_at = cached_at;
        }
    } ZEND_HASH_FOREACH_END();
}
