/*
 * Shared runtime-state helpers for the object store. Covers integrity checks,
 * metadata-cache state and the small bounded helpers reused across backends.
 */

static zend_bool king_object_store_verify_integrity_buffer(
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata
)
{
    char computed[65];

    if (metadata == NULL || metadata->integrity_sha256[0] == '\0') {
        return 1;
    }

    king_object_store_compute_sha256_hex(data, data_size, computed);
    return strcmp(computed, metadata->integrity_sha256) == 0;
}

typedef struct _king_object_store_runtime_metadata_cache_entry_t {
    king_object_metadata_t metadata;
    time_t cached_at;
    uint64_t last_access_sequence;
} king_object_store_runtime_metadata_cache_entry_t;

static uint64_t king_object_store_runtime_metadata_cache_sequence = 0;
static uint64_t king_object_store_runtime_metadata_cache_eviction_count = 0;

static zend_bool king_object_store_runtime_metadata_cache_enabled(void)
{
    return king_native_object_store_config.metadata_cache_enable ? 1 : 0;
}

static zend_long king_object_store_runtime_metadata_cache_ttl_sec(void)
{
    if (king_native_object_store_config.metadata_cache_ttl_sec <= 0) {
        return 1;
    }

    return king_native_object_store_config.metadata_cache_ttl_sec;
}

static zend_long king_object_store_runtime_metadata_cache_max_entries(void)
{
    if (king_native_object_store_config.metadata_cache_max_entries <= 0) {
        return 1;
    }

    return king_native_object_store_config.metadata_cache_max_entries;
}

static uint64_t king_object_store_runtime_metadata_cache_next_sequence(void)
{
    king_object_store_runtime_metadata_cache_sequence++;
    if (king_object_store_runtime_metadata_cache_sequence == 0) {
        king_object_store_runtime_metadata_cache_sequence = 1;
    }

    return king_object_store_runtime_metadata_cache_sequence;
}

static void king_object_store_runtime_metadata_cache_note_eviction(void)
{
    if (king_object_store_runtime_metadata_cache_eviction_count < UINT64_MAX) {
        king_object_store_runtime_metadata_cache_eviction_count++;
    }
}

static zend_bool king_object_store_runtime_metadata_cache_entry_is_expired(
    const king_object_store_runtime_metadata_cache_entry_t *entry,
    time_t now
)
{
    zend_long ttl_sec;

    if (entry == NULL || entry->cached_at <= 0) {
        return 1;
    }

    ttl_sec = king_object_store_runtime_metadata_cache_ttl_sec();
    if (ttl_sec <= 0) {
        return 0;
    }

    if (now < entry->cached_at) {
        return 0;
    }

    return (zend_long) (now - entry->cached_at) >= ttl_sec;
}

static void king_object_store_runtime_metadata_cache_entry_free(
    king_object_store_runtime_metadata_cache_entry_t *entry
)
{
    if (entry == NULL) {
        return;
    }

    pefree(entry, 1);
}

static void king_object_store_runtime_metadata_cache_zval_dtor(zval *zv)
{
    if (Z_TYPE_P(zv) == IS_PTR) {
        king_object_store_runtime_metadata_cache_entry_free(
            (king_object_store_runtime_metadata_cache_entry_t *) Z_PTR_P(zv)
        );
    }
}

static zend_bool king_object_store_runtime_metadata_cache_remove_expired_once(time_t now)
{
    zend_string *expired_key = NULL;
    zend_string *key;
    zval *stored_entry;

    if (!king_object_store_runtime_metadata_cache_initialized) {
        return 0;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(&king_object_store_runtime_metadata_cache, key, stored_entry) {
        king_object_store_runtime_metadata_cache_entry_t *entry;

        if (key == NULL || Z_TYPE_P(stored_entry) != IS_PTR || Z_PTR_P(stored_entry) == NULL) {
            continue;
        }

        entry = (king_object_store_runtime_metadata_cache_entry_t *) Z_PTR_P(stored_entry);
        if (!king_object_store_runtime_metadata_cache_entry_is_expired(entry, now)) {
            continue;
        }

        expired_key = zend_string_copy(key);
        break;
    } ZEND_HASH_FOREACH_END();

    if (expired_key == NULL) {
        return 0;
    }

    zend_hash_del(&king_object_store_runtime_metadata_cache, expired_key);
    zend_string_release(expired_key);
    king_object_store_runtime_metadata_cache_note_eviction();
    return 1;
}

static void king_object_store_runtime_metadata_cache_prune_expired(void)
{
    time_t now;

    if (!king_object_store_runtime_metadata_cache_initialized) {
        return;
    }

    now = time(NULL);
    while (king_object_store_runtime_metadata_cache_remove_expired_once(now)) {
    }
}

static int king_object_store_runtime_metadata_cache_evict_oldest(void)
{
    zend_string *oldest_key = NULL;
    zend_string *key;
    zval *stored_entry;
    uint64_t oldest_sequence = UINT64_MAX;
    time_t oldest_cached_at = 0;

    if (!king_object_store_runtime_metadata_cache_initialized
        || zend_hash_num_elements(&king_object_store_runtime_metadata_cache) == 0) {
        return FAILURE;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(&king_object_store_runtime_metadata_cache, key, stored_entry) {
        king_object_store_runtime_metadata_cache_entry_t *entry;

        if (key == NULL || Z_TYPE_P(stored_entry) != IS_PTR || Z_PTR_P(stored_entry) == NULL) {
            continue;
        }

        entry = (king_object_store_runtime_metadata_cache_entry_t *) Z_PTR_P(stored_entry);
        if (oldest_key == NULL
            || entry->last_access_sequence < oldest_sequence
            || (entry->last_access_sequence == oldest_sequence
                && entry->cached_at < oldest_cached_at)) {
            if (oldest_key != NULL) {
                zend_string_release(oldest_key);
            }
            oldest_key = zend_string_copy(key);
            oldest_sequence = entry->last_access_sequence;
            oldest_cached_at = entry->cached_at;
        }
    } ZEND_HASH_FOREACH_END();

    if (oldest_key == NULL) {
        return FAILURE;
    }

    zend_hash_del(&king_object_store_runtime_metadata_cache, oldest_key);
    zend_string_release(oldest_key);
    king_object_store_runtime_metadata_cache_note_eviction();
    return SUCCESS;
}

static int king_object_store_runtime_metadata_cache_ensure(void)
{
    if (!king_object_store_runtime_metadata_cache_enabled()) {
        return SUCCESS;
    }

    if (king_object_store_runtime_metadata_cache_initialized) {
        return SUCCESS;
    }

    zend_hash_init(
        &king_object_store_runtime_metadata_cache,
        8,
        NULL,
        king_object_store_runtime_metadata_cache_zval_dtor,
        1
    );
    king_object_store_runtime_metadata_cache_initialized = true;
    return SUCCESS;
}

static void king_object_store_runtime_metadata_cache_reset(void)
{
    if (king_object_store_runtime_metadata_cache_initialized) {
        zend_hash_destroy(&king_object_store_runtime_metadata_cache);
        king_object_store_runtime_metadata_cache_initialized = false;
    }

    king_object_store_runtime_metadata_cache_sequence = 0;
    king_object_store_runtime_metadata_cache_eviction_count = 0;
}

static void king_object_store_runtime_metadata_cache_clear(void)
{
    if (king_object_store_runtime_metadata_cache_initialized) {
        zend_hash_clean(&king_object_store_runtime_metadata_cache);
    }

    king_object_store_runtime_metadata_cache_sequence = 0;
    king_object_store_runtime_metadata_cache_eviction_count = 0;
}

static int king_object_store_runtime_metadata_cache_store(
    const char *object_id,
    const king_object_metadata_t *metadata
)
{
    zend_string *persistent_key;
    king_object_store_runtime_metadata_cache_entry_t *copy;
    zval stored_metadata;
    zend_bool has_existing_entry;
    zend_long max_entries;

    if (object_id == NULL || object_id[0] == '\0' || metadata == NULL) {
        return FAILURE;
    }

    if (!king_object_store_runtime_metadata_cache_enabled()) {
        return SUCCESS;
    }

    if (king_object_store_runtime_metadata_cache_ensure() != SUCCESS) {
        return FAILURE;
    }

    king_object_store_runtime_metadata_cache_prune_expired();
    has_existing_entry = zend_hash_str_exists(
        &king_object_store_runtime_metadata_cache,
        object_id,
        strlen(object_id)
    );
    max_entries = king_object_store_runtime_metadata_cache_max_entries();

    while (!has_existing_entry
        && zend_hash_num_elements(&king_object_store_runtime_metadata_cache) >= (uint32_t) max_entries) {
        if (king_object_store_runtime_metadata_cache_evict_oldest() != SUCCESS) {
            return FAILURE;
        }
    }

    copy = pemalloc(sizeof(*copy), 1);
    if (copy == NULL) {
        return FAILURE;
    }
    memset(copy, 0, sizeof(*copy));
    copy->metadata = *metadata;
    copy->cached_at = time(NULL);
    copy->last_access_sequence = king_object_store_runtime_metadata_cache_next_sequence();

    ZVAL_PTR(&stored_metadata, copy);
    persistent_key = zend_string_init(object_id, strlen(object_id), 1);
    zend_hash_update(&king_object_store_runtime_metadata_cache, persistent_key, &stored_metadata);
    zend_string_release_ex(persistent_key, 1);

    return SUCCESS;
}

static void king_object_store_runtime_metadata_cache_remove(const char *object_id);

int king_object_store_runtime_metadata_cache_read(
    const char *object_id,
    king_object_metadata_t *metadata
)
{
    zval *cached_entry;
    king_object_store_runtime_metadata_cache_entry_t *entry;

    if (object_id == NULL
        || metadata == NULL
        || !king_object_store_runtime_metadata_cache_initialized
        || !king_object_store_runtime_metadata_cache_enabled()) {
        return FAILURE;
    }

    king_object_store_runtime_metadata_cache_prune_expired();
    cached_entry = zend_hash_str_find(
        &king_object_store_runtime_metadata_cache,
        object_id,
        strlen(object_id)
    );
    if (cached_entry == NULL || Z_TYPE_P(cached_entry) != IS_PTR || Z_PTR_P(cached_entry) == NULL) {
        return FAILURE;
    }

    entry = (king_object_store_runtime_metadata_cache_entry_t *) Z_PTR_P(cached_entry);
    if (king_object_store_runtime_metadata_cache_entry_is_expired(entry, time(NULL))) {
        king_object_store_runtime_metadata_cache_remove(object_id);
        king_object_store_runtime_metadata_cache_note_eviction();
        return FAILURE;
    }

    entry->last_access_sequence = king_object_store_runtime_metadata_cache_next_sequence();
    *metadata = entry->metadata;
    return SUCCESS;
}

static void king_object_store_runtime_metadata_cache_remove(const char *object_id)
{
    if (object_id == NULL || !king_object_store_runtime_metadata_cache_initialized) {
        return;
    }

    zend_hash_str_del(
        &king_object_store_runtime_metadata_cache,
        object_id,
        strlen(object_id)
    );
}

void king_object_store_runtime_metadata_cache_collect_stats(
    zend_long *entry_count,
    zend_long *eviction_count
)
{
    king_object_store_runtime_metadata_cache_prune_expired();

    if (entry_count != NULL) {
        *entry_count = king_object_store_runtime_metadata_cache_initialized
            ? (zend_long) zend_hash_num_elements(&king_object_store_runtime_metadata_cache)
            : 0;
    }

    if (eviction_count != NULL) {
        *eviction_count = (zend_long) king_object_store_runtime_metadata_cache_eviction_count;
    }
}

