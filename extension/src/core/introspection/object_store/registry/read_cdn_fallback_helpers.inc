static zend_long king_object_store_get_readthrough_cdn_ttl_sec(void)
{
    if (king_object_store_runtime.config.cdn_config.default_ttl_seconds > 0) {
        return (zend_long) king_object_store_runtime.config.cdn_config.default_ttl_seconds;
    }

    return (zend_long) king_native_cdn_config.cache_default_ttl_sec;
}

static void king_object_store_cdn_entry_note_served(
    king_cdn_cache_entry *entry,
    time_t served_at
)
{
    if (entry == NULL) {
        return;
    }

    if (entry->served_count < ZEND_LONG_MAX) {
        entry->served_count++;
    }
    entry->last_served_at = served_at;
}

static zend_bool king_object_store_cdn_can_retain_payload(size_t payload_size)
{
    uint64_t max_bytes;

    if (payload_size > (size_t) ZEND_LONG_MAX) {
        return 0;
    }

    if (king_native_cdn_config.cache_max_object_size_mb <= 0) {
        return 0;
    }

    if ((uint64_t) king_native_cdn_config.cache_max_object_size_mb
        > (UINT64_MAX / (1024ULL * 1024ULL))) {
        return 0;
    }

    max_bytes = (uint64_t) king_native_cdn_config.cache_max_object_size_mb * 1024ULL * 1024ULL;
    return (uint64_t) payload_size <= max_bytes;
}

static void king_object_store_fill_cdn_cache_on_read_success(
    const char *object_id,
    const king_object_metadata_t *metadata,
    const void *payload,
    size_t payload_size
)
{
    zend_string *object_id_zstr;
    king_cdn_cache_entry *existing;
    king_cdn_cache_entry *cache_entry;
    zval stored_entry;
    time_t now;
    zend_long ttl_sec;
    zend_bool retain_payload = 0;

    if (!king_cdn_cache_registry_initialized
        || !king_object_store_runtime.config.cdn_config.enabled
        || object_id == NULL
        || object_id[0] == '\0'
        || metadata == NULL
        || metadata->cache_policy != KING_CACHE_POLICY_SMART_CDN) {
        return;
    }

    if (payload_size == 0) {
        if (metadata->content_length > SIZE_MAX
            || metadata->content_length > (uint64_t) ZEND_LONG_MAX) {
            return;
        }
        payload_size = (size_t) metadata->content_length;
    } else if (payload_size > (size_t) ZEND_LONG_MAX) {
        return;
    }

    retain_payload = king_object_store_cdn_can_retain_payload(payload_size);

    object_id_zstr = zend_string_init(object_id, strlen(object_id), 1);
    if (object_id_zstr == NULL) {
        return;
    }

    now = time(NULL);
    existing = king_cdn_cache_registry_get_entry(object_id_zstr);
    if (existing != NULL) {
        existing->payload_size = (zend_long) payload_size;
        existing->cached_at = now;
        ttl_sec = king_object_store_get_readthrough_cdn_ttl_sec();
        existing->expires_at = ttl_sec > 0 ? now + ttl_sec : 0;
        if (retain_payload) {
            king_cdn_cache_entry_replace_retained_payload(existing, payload, payload_size);
        } else {
            king_cdn_cache_entry_replace_retained_payload(existing, NULL, 0);
        }
        king_object_store_cdn_entry_note_served(existing, now);
        zend_string_release_ex(object_id_zstr, 1);
        return;
    }

    cache_entry = pecalloc(1, sizeof(*cache_entry), 1);
    if (cache_entry == NULL) {
        zend_string_release_ex(object_id_zstr, 1);
        return;
    }

    ttl_sec = king_object_store_get_readthrough_cdn_ttl_sec();
    cache_entry->object_id = object_id_zstr;
    cache_entry->payload_size = (zend_long) payload_size;
    cache_entry->cached_at = now;
    cache_entry->expires_at = ttl_sec > 0 ? now + ttl_sec : 0;
    if (retain_payload) {
        king_cdn_cache_entry_replace_retained_payload(cache_entry, payload, payload_size);
    }
    king_object_store_cdn_entry_note_served(cache_entry, now);

    ZVAL_PTR(&stored_entry, cache_entry);
    if (zend_hash_update(&king_cdn_cache_registry, cache_entry->object_id, &stored_entry) == NULL) {
        king_cdn_cache_entry_free(cache_entry);
        return;
    }

    (void) king_cdn_distribute_object(object_id, NULL, 1);
}

static zend_string *king_object_store_copy_stale_cdn_payload(
    zend_string *object_id
)
{
    king_cdn_cache_entry *entry;
    time_t now;

    if (!king_native_cdn_config.serve_stale_on_error
        || !king_cdn_cache_registry_initialized
        || !king_object_store_runtime.config.cdn_config.enabled
        || object_id == NULL) {
        return NULL;
    }

    entry = king_cdn_cache_registry_get_entry_allow_expired(object_id);
    if (entry == NULL || entry->retained_payload == NULL) {
        return NULL;
    }

    now = time(NULL);
    king_object_store_cdn_entry_note_served(entry, now);

    return zend_string_init(
        ZSTR_VAL(entry->retained_payload),
        ZSTR_LEN(entry->retained_payload),
        0
    );
}

static zend_bool king_object_store_write_stale_cdn_payload_to_stream(
    zend_string *object_id,
    php_stream *destination_stream
)
{
    zend_string *payload;
    size_t payload_length;
    size_t written;

    if (destination_stream == NULL) {
        return 0;
    }

    payload = king_object_store_copy_stale_cdn_payload(object_id);
    if (payload == NULL) {
        return 0;
    }

    payload_length = ZSTR_LEN(payload);
    written = (size_t) php_stream_write(destination_stream, ZSTR_VAL(payload), payload_length);
    zend_string_release_ex(payload, 0);

    if (written != payload_length) {
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "Failed to write stale CDN payload to the destination stream."
        );
        return 0;
    }

    return 1;
}
