
/*
 * Prelude object-store runtime slice: owns the process-local CDN cache
 * registry lifecycle, retained stale-body management, entry lookup, stats
 * collection, and expiry sweeping that the active object-store/CDN
 * introspection surfaces reuse.
 */


static void king_cdn_cache_entry_free(king_cdn_cache_entry *entry)
{
    if (entry == NULL) {
        return;
    }

    if (entry->object_id != NULL) {
        zend_string_release_ex(entry->object_id, 1);
    }
    if (entry->retained_payload != NULL) {
        zend_string_release_ex(entry->retained_payload, 1);
    }

    pefree(entry, 1);
}

static void king_cdn_cache_entry_replace_retained_payload(
    king_cdn_cache_entry *entry,
    const void *payload,
    size_t payload_size
)
{
    const char *payload_bytes;

    if (entry == NULL) {
        return;
    }

    if (entry->retained_payload != NULL) {
        zend_string_release_ex(entry->retained_payload, 1);
        entry->retained_payload = NULL;
    }

    if (payload == NULL && payload_size > 0) {
        return;
    }

    payload_bytes = payload != NULL ? (const char *) payload : "";
    entry->retained_payload = zend_string_init(payload_bytes, payload_size, 1);
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

static king_cdn_cache_entry *king_cdn_cache_registry_find_entry(
    zend_string *object_id,
    zend_bool allow_expired
)
{
    zval *entry_zv;
    king_cdn_cache_entry *entry;

    if (!king_cdn_cache_registry_initialized) {
        return NULL;
    }

    entry_zv = zend_hash_find(&king_cdn_cache_registry, object_id);
    if (entry_zv == NULL || Z_TYPE_P(entry_zv) != IS_PTR) {
        return NULL;
    }

    entry = (king_cdn_cache_entry *) Z_PTR_P(entry_zv);
    if (entry == NULL) {
        return NULL;
    }

    /*
     * Expired entries stay in the registry until explicit invalidation or the
     * sweep path removes them. Ordinary cache hits must treat them as misses,
     * but stale-on-error lookups may still need the retained body.
     */
    if (!allow_expired && entry->expires_at > 0 && time(NULL) > entry->expires_at) {
        return NULL;
    }

    return entry;
}

static king_cdn_cache_entry *king_cdn_cache_registry_get_entry(
    zend_string *object_id
)
{
    return king_cdn_cache_registry_find_entry(object_id, 0);
}

static king_cdn_cache_entry *king_cdn_cache_registry_get_entry_allow_expired(
    zend_string *object_id
)
{
    return king_cdn_cache_registry_find_entry(object_id, 1);
}

static void king_cdn_collect_live_stats(
    zend_long *cached_object_count,
    zend_long *cached_bytes,
    zend_long *latest_cached_at
)
{
    zval *entry_zv;
    time_t now = time(NULL);

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

        /* Skip expired entries in stats (they'll be evicted on next lookup) */
        if (entry->expires_at > 0 && now > entry->expires_at) {
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

/*
 * Purge all expired CDN cache entries. Called by king_object_store_cleanup_expired_objects.
 * We collect expired keys first, then delete them to avoid modifying the
 * hash table during iteration.
 */
void king_cdn_sweep_expired(void)
{
    zval *entry_zv;
    zend_string *key;
    time_t now;
    zend_string *expired_keys[256];
    int expired_count = 0;
    int i;

    if (!king_cdn_cache_registry_initialized) {
        return;
    }

    now = time(NULL);

    ZEND_HASH_FOREACH_STR_KEY_VAL(&king_cdn_cache_registry, key, entry_zv) {
        king_cdn_cache_entry *entry;

        if (key == NULL || Z_TYPE_P(entry_zv) != IS_PTR) {
            continue;
        }
        entry = (king_cdn_cache_entry *) Z_PTR_P(entry_zv);
        if (entry != NULL && entry->expires_at > 0 && now > entry->expires_at) {
            if (expired_count < 256) {
                expired_keys[expired_count++] = zend_string_copy(key);
            }
        }
    } ZEND_HASH_FOREACH_END();

    for (i = 0; i < expired_count; i++) {
        zend_hash_del(&king_cdn_cache_registry, expired_keys[i]);
        zend_string_release(expired_keys[i]);
    }
}
