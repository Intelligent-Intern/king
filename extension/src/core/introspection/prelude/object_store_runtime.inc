
/*
 * Prelude object-store runtime slice: owns the process-local CDN cache
 * registry lifecycle, retained stale-body management, bounded memory-limit
 * enforcement, entry lookup, lifecycle observability counters, stats
 * collection, and expiry sweeping that the active object-store/CDN
 * introspection surfaces reuse.
 */

static uint64_t king_cdn_cache_touch_counter = 0;
static zend_long king_cdn_cache_served_count = 0;
static time_t king_cdn_cache_latest_served_at = 0;
static zend_long king_cdn_cache_eviction_count = 0;
static zend_long king_cdn_cache_invalidation_count = 0;
static zend_long king_cdn_cache_expiration_count = 0;
static zend_long king_cdn_cache_stale_serve_count = 0;

static king_cdn_cache_entry *king_cdn_cache_registry_get_entry_allow_expired(
    zend_string *object_id
);
void king_cdn_sweep_expired(void);

static void king_cdn_counter_add(zend_long *counter, zend_long delta)
{
    if (counter == NULL || delta <= 0) {
        return;
    }

    if (*counter > ZEND_LONG_MAX - delta) {
        *counter = ZEND_LONG_MAX;
        return;
    }

    *counter += delta;
}

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

static void king_cdn_cache_entry_note_touched(king_cdn_cache_entry *entry)
{
    if (entry == NULL) {
        return;
    }

    if (king_cdn_cache_touch_counter < UINT64_MAX) {
        king_cdn_cache_touch_counter++;
    }

    entry->touch_serial = king_cdn_cache_touch_counter;
}

static uint64_t king_cdn_cache_entry_estimated_memory_bytes(const king_cdn_cache_entry *entry)
{
    uint64_t total;

    if (entry == NULL) {
        return 0;
    }

    total = (uint64_t) sizeof(*entry);

    if (entry->object_id != NULL) {
        total += (uint64_t) sizeof(zend_string) + (uint64_t) ZSTR_LEN(entry->object_id) + 1U;
    }
    if (entry->retained_payload != NULL) {
        total += (uint64_t) sizeof(zend_string) + (uint64_t) ZSTR_LEN(entry->retained_payload) + 1U;
    }

    return total;
}

static uint64_t king_cdn_cache_memory_limit_bytes(void)
{
    if (king_native_cdn_config.cache_memory_limit_mb <= 0) {
        return 0;
    }

    if ((uint64_t) king_native_cdn_config.cache_memory_limit_mb > (UINT64_MAX / (1024ULL * 1024ULL))) {
        return UINT64_MAX;
    }

    return (uint64_t) king_native_cdn_config.cache_memory_limit_mb * 1024ULL * 1024ULL;
}

static uint64_t king_cdn_cache_collect_memory_bytes(void)
{
    zval *entry_zv;
    uint64_t total = 0;

    if (!king_cdn_cache_registry_initialized) {
        return 0;
    }

    ZEND_HASH_FOREACH_VAL(&king_cdn_cache_registry, entry_zv) {
        king_cdn_cache_entry *entry;

        if (Z_TYPE_P(entry_zv) != IS_PTR) {
            continue;
        }

        entry = (king_cdn_cache_entry *) Z_PTR_P(entry_zv);
        total += king_cdn_cache_entry_estimated_memory_bytes(entry);
    } ZEND_HASH_FOREACH_END();

    return total;
}

static zend_string *king_cdn_cache_find_eviction_candidate(
    zend_string *protected_object_id
)
{
    zend_string *candidate_key = NULL;
    uint64_t candidate_touch = UINT64_MAX;
    zval *entry_zv;
    zend_string *key;

    if (!king_cdn_cache_registry_initialized) {
        return NULL;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(&king_cdn_cache_registry, key, entry_zv) {
        king_cdn_cache_entry *entry;

        if (key == NULL || Z_TYPE_P(entry_zv) != IS_PTR) {
            continue;
        }
        if (protected_object_id != NULL && zend_string_equals(key, protected_object_id)) {
            continue;
        }

        entry = (king_cdn_cache_entry *) Z_PTR_P(entry_zv);
        if (entry == NULL) {
            continue;
        }

        if (candidate_key == NULL
            || entry->touch_serial < candidate_touch
            || (entry->touch_serial == candidate_touch
                && zend_binary_strcmp(
                    ZSTR_VAL(key),
                    ZSTR_LEN(key),
                    ZSTR_VAL(candidate_key),
                    ZSTR_LEN(candidate_key)
                ) < 0)) {
            candidate_key = key;
            candidate_touch = entry->touch_serial;
        }
    } ZEND_HASH_FOREACH_END();

    return candidate_key != NULL ? zend_string_copy(candidate_key) : NULL;
}

static void king_cdn_enforce_memory_limit_for_object(zend_string *protected_object_id)
{
    king_cdn_cache_entry *protected_entry = NULL;
    uint64_t limit_bytes;
    uint64_t current_bytes;

    if (!king_cdn_cache_registry_initialized) {
        return;
    }

    king_cdn_sweep_expired();
    limit_bytes = king_cdn_cache_memory_limit_bytes();
    if (limit_bytes == 0) {
        return;
    }

    if (protected_object_id != NULL) {
        protected_entry = king_cdn_cache_registry_get_entry_allow_expired(protected_object_id);
        if (protected_entry != NULL
            && king_cdn_cache_entry_estimated_memory_bytes(protected_entry) > limit_bytes) {
            /*
             * A single oversize candidate is not allowed to flush the rest of
             * the resident CDN registry just to fail admission itself.
             */
            if (zend_hash_del(&king_cdn_cache_registry, protected_object_id) == SUCCESS) {
                king_cdn_counter_add(&king_cdn_cache_eviction_count, 1);
            }
            return;
        }
    }

    current_bytes = king_cdn_cache_collect_memory_bytes();
    while (current_bytes > limit_bytes && zend_hash_num_elements(&king_cdn_cache_registry) > 0) {
        zend_string *candidate_key = king_cdn_cache_find_eviction_candidate(protected_object_id);

        if (candidate_key == NULL) {
            if (protected_object_id != NULL) {
                if (zend_hash_del(&king_cdn_cache_registry, protected_object_id) == SUCCESS) {
                    king_cdn_counter_add(&king_cdn_cache_eviction_count, 1);
                }
            }
            break;
        }

        if (zend_hash_del(&king_cdn_cache_registry, candidate_key) == SUCCESS) {
            king_cdn_counter_add(&king_cdn_cache_eviction_count, 1);
        }
        zend_string_release(candidate_key);
        current_bytes = king_cdn_cache_collect_memory_bytes();
    }
}

static void king_cdn_enforce_memory_limit(void)
{
    king_cdn_enforce_memory_limit_for_object(NULL);
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
    king_cdn_cache_touch_counter = 0;
    king_cdn_cache_served_count = 0;
    king_cdn_cache_latest_served_at = 0;
    king_cdn_cache_eviction_count = 0;
    king_cdn_cache_invalidation_count = 0;
    king_cdn_cache_expiration_count = 0;
    king_cdn_cache_stale_serve_count = 0;

    return SUCCESS;
}

void king_cdn_cache_registry_mshutdown(void)
{
    if (!king_cdn_cache_registry_initialized) {
        return;
    }

    zend_hash_destroy(&king_cdn_cache_registry);
    king_cdn_cache_registry_initialized = false;
    king_cdn_cache_touch_counter = 0;
    king_cdn_cache_served_count = 0;
    king_cdn_cache_latest_served_at = 0;
    king_cdn_cache_eviction_count = 0;
    king_cdn_cache_invalidation_count = 0;
    king_cdn_cache_expiration_count = 0;
    king_cdn_cache_stale_serve_count = 0;
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
    zend_long *latest_cached_at,
    zend_long *retained_object_count,
    zend_long *metadata_only_object_count,
    zend_long *retained_bytes
)
{
    zval *entry_zv;
    time_t now = time(NULL);

    *cached_object_count = 0;
    *cached_bytes = 0;
    *latest_cached_at = 0;
    *retained_object_count = 0;
    *metadata_only_object_count = 0;
    *retained_bytes = 0;

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
        if (entry->retained_payload != NULL) {
            (*retained_object_count)++;
            *retained_bytes += (zend_long) ZSTR_LEN(entry->retained_payload);
        } else {
            (*metadata_only_object_count)++;
        }

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
        if (zend_hash_del(&king_cdn_cache_registry, expired_keys[i]) == SUCCESS) {
            king_cdn_counter_add(&king_cdn_cache_expiration_count, 1);
        }
        zend_string_release(expired_keys[i]);
    }
}
