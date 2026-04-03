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

static void king_object_store_fill_cdn_cache_on_read_success(
    const char *object_id,
    const king_object_metadata_t *metadata,
    size_t payload_size
)
{
    zend_string *object_id_zstr;
    king_cdn_cache_entry *existing;
    king_cdn_cache_entry *cache_entry;
    zval stored_entry;
    time_t now;
    zend_long ttl_sec;

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

    object_id_zstr = zend_string_init(object_id, strlen(object_id), 1);
    if (object_id_zstr == NULL) {
        return;
    }

    now = time(NULL);
    existing = king_cdn_cache_registry_get_entry(object_id_zstr);
    if (existing != NULL) {
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
    king_object_store_cdn_entry_note_served(cache_entry, now);

    ZVAL_PTR(&stored_entry, cache_entry);
    if (zend_hash_update(&king_cdn_cache_registry, cache_entry->object_id, &stored_entry) == NULL) {
        king_cdn_cache_entry_free(cache_entry);
        return;
    }

    (void) king_cdn_distribute_object(object_id, NULL, 1);
}

PHP_FUNCTION(king_object_store_get)
{
    zend_string *object_id;
    zval *options = NULL;
    void *data = NULL;
    size_t data_size = 0;
    king_object_metadata_t metadata;
    size_t offset = 0;
    size_t length = 0;
    zend_bool has_length = 0;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    if (!king_object_store_registry_extract_range_options(
            options,
            &offset,
            &length,
            &has_length,
            "king_object_store_get"
        )) {
        RETURN_THROWS();
    }

    if (has_length && length == 0) {
        RETURN_EMPTY_STRING();
    }

    if (!king_object_store_runtime.initialized) {
        RETURN_FALSE;
    }

    memset(&metadata, 0, sizeof(metadata));
    {
        int result = (offset > 0 || has_length)
        ? king_object_store_read_object_range(
            ZSTR_VAL(object_id),
            offset,
            length,
            has_length,
            &data,
            &data_size,
            NULL
        )
        : king_object_store_read_object(ZSTR_VAL(object_id), &data, &data_size, &metadata);

        if (result == KING_OBJECT_STORE_RESULT_MISS) {
            RETURN_FALSE;
        }
        if (result != SUCCESS) {
            king_object_store_registry_throw_primary_failure(
                result,
                (offset > 0 || has_length)
                    ? "Primary object-store backend ranged read failed."
                    : "Primary object-store backend read failed."
            );
            RETURN_THROWS();
        }
    }

    /*
     * Full-object reads are the current honest read-through fill path for the
     * synthetic CDN layer: a smart_cdn miss warms the local cache registry
     * after the origin/backend read succeeds.
     */
    if (offset == 0 && !has_length) {
        king_object_store_fill_cdn_cache_on_read_success(
            ZSTR_VAL(object_id),
            &metadata,
            data_size
        );
    }

    zend_string *ret_str = zend_string_init(data, data_size, 0);
    if (data_size > 0) {
        memset(data, 0, data_size);
    }
    pefree(data, 1);
    RETURN_STR(ret_str);
}

PHP_FUNCTION(king_object_store_get_to_stream)
{
    zend_string *object_id;
    zval *stream_zv;
    zval *options = NULL;
    php_stream *destination_stream;
    size_t offset = 0;
    size_t length = 0;
    zend_bool has_length = 0;
    king_object_metadata_t metadata;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(object_id)
        Z_PARAM_ZVAL(stream_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    destination_stream = king_object_store_registry_fetch_stream_resource(stream_zv, 2, "king_object_store_get_to_stream");
    if (destination_stream == NULL) {
        RETURN_THROWS();
    }

    if (!king_object_store_registry_extract_range_options(
            options,
            &offset,
            &length,
            &has_length,
            "king_object_store_get_to_stream"
        )) {
        RETURN_THROWS();
    }

    if (has_length && length == 0) {
        RETURN_TRUE;
    }

    if (!king_object_store_runtime.initialized) {
        RETURN_FALSE;
    }

    memset(&metadata, 0, sizeof(metadata));
    {
        int result = king_object_store_read_object_to_stream(
            ZSTR_VAL(object_id),
            destination_stream,
            offset,
            length,
            has_length,
            (offset > 0 || has_length) ? NULL : &metadata
        );

        if (result == KING_OBJECT_STORE_RESULT_MISS) {
            RETURN_FALSE;
        }
        if (result != SUCCESS) {
            king_object_store_registry_throw_primary_failure(
                result,
                (offset > 0 || has_length)
                    ? "Primary object-store backend ranged read failed."
                    : "Primary object-store backend read failed."
            );
            RETURN_THROWS();
        }
    }

    RETURN_TRUE;
}

PHP_FUNCTION(king_object_store_delete)
{
    zend_string *object_id;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(object_id)
    ZEND_PARSE_PARAMETERS_END();

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    {
        int result = king_object_store_remove_object(ZSTR_VAL(object_id));

        if (result == SUCCESS) {
            RETURN_TRUE;
        }
        if (result == KING_OBJECT_STORE_RESULT_MISS || result == KING_OBJECT_STORE_RESULT_CONFLICT) {
            RETURN_FALSE;
        }

        king_object_store_registry_throw_primary_failure(
            result,
            "Primary object-store backend remove failed."
        );
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_object_store_list)
{
    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);

    if (!king_object_store_runtime.initialized) {
        return;
    }

    {
        int result = king_object_store_list_object(return_value);

        if (result != SUCCESS) {
            zval_ptr_dtor(return_value);
            array_init(return_value);
            king_object_store_registry_throw_primary_failure(
                result,
                "Primary object-store backend list failed."
            );
            RETURN_THROWS();
        }
    }
}

PHP_FUNCTION(king_object_store_cleanup_expired_objects)
{
    uint64_t scanned = 0;
    uint64_t removed = 0;
    uint64_t bytes_reclaimed = 0;
    uint64_t failures = 0;

    ZEND_PARSE_PARAMETERS_NONE();

    if (!king_object_store_runtime.initialized) {
        return;
    }

    if (king_object_store_cleanup_expired_objects(
            &scanned,
            &removed,
            &bytes_reclaimed,
            &failures
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    array_init(return_value);
    add_assoc_string(return_value, "mode", "expiry_cleanup");
    add_assoc_long(return_value, "scanned_objects", (zend_long) scanned);
    add_assoc_long(return_value, "expired_objects_removed", (zend_long) removed);
    add_assoc_long(return_value, "bytes_reclaimed", (zend_long) bytes_reclaimed);
    add_assoc_long(return_value, "removal_failures", (zend_long) failures);
    add_assoc_long(return_value, "cleanup_at", (zend_long) time(NULL));
}

PHP_FUNCTION(king_object_store_get_metadata)
{
    zend_string *object_id;
    king_object_metadata_t metadata;
    int result;
    king_storage_backend_t primary_backend;
    king_storage_backend_t backup_backend;
    struct stat root_stat;
    zend_bool use_local_cache_fallback = 0;
    zend_bool backup_backend_is_real_cloud = 0;
    zend_bool primary_cache_snapshot_matches_route = 0;
    zend_bool backup_cache_snapshot_matches_route = 0;
    const char *root_error = NULL;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(object_id)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_object_store_runtime.initialized) {
        return;
    }
    if (king_object_store_public_object_id_validate_zstr(object_id) != NULL) {
        return;
    }

    primary_backend = king_object_store_runtime.config.primary_backend;
    backup_backend = king_object_store_runtime.config.backup_backend;
    if (backup_backend == KING_STORAGE_BACKEND_MEMORY_CACHE) {
        backup_backend = KING_STORAGE_BACKEND_LOCAL_FS;
    }
    use_local_cache_fallback =
        primary_backend == KING_STORAGE_BACKEND_LOCAL_FS
        || primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE;

    if (use_local_cache_fallback) {
        if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
            root_error = "the configured storage_root_path is empty.";
        } else if (stat(king_object_store_runtime.config.storage_root_path, &root_stat) != 0) {
            root_error = strerror(errno);
        } else if (!S_ISDIR(root_stat.st_mode)) {
            root_error = "the configured storage_root_path is not a directory.";
        }

        if (root_error != NULL) {
            backup_backend_is_real_cloud =
                backup_backend == KING_STORAGE_BACKEND_CLOUD_S3
                || backup_backend == KING_STORAGE_BACKEND_CLOUD_GCS
                || backup_backend == KING_STORAGE_BACKEND_CLOUD_AZURE;
            if (king_object_store_runtime_metadata_cache_read(ZSTR_VAL(object_id), &metadata) == SUCCESS) {
                primary_cache_snapshot_matches_route =
                    metadata.local_fs_present != 0
                    || metadata.is_backed_up != 0;
                backup_cache_snapshot_matches_route =
                    (backup_backend == KING_STORAGE_BACKEND_DISTRIBUTED
                        && metadata.distributed_present != 0)
                    ||
                    (backup_backend == KING_STORAGE_BACKEND_CLOUD_S3
                        && (metadata.cloud_s3_present != 0 || metadata.is_backed_up != 0))
                    || (backup_backend == KING_STORAGE_BACKEND_CLOUD_GCS
                        && metadata.cloud_gcs_present != 0)
                    || (backup_backend == KING_STORAGE_BACKEND_CLOUD_AZURE
                        && metadata.cloud_azure_present != 0);
            }

            if (backup_backend != primary_backend
                && backup_backend_is_real_cloud
                && primary_cache_snapshot_matches_route
                && backup_cache_snapshot_matches_route) {
                result = SUCCESS;
            } else {
                snprintf(
                    king_object_store_runtime.primary_adapter_error,
                    sizeof(king_object_store_runtime.primary_adapter_error),
                    "local_fs metadata read failed: %s",
                    root_error
                );
                result = FAILURE;
            }
        } else {
            result = king_object_store_backend_read_metadata(ZSTR_VAL(object_id), &metadata);
        }
    } else {
        result = king_object_store_backend_read_metadata(ZSTR_VAL(object_id), &metadata);
    }

    if (result == KING_OBJECT_STORE_RESULT_MISS) {
        RETURN_FALSE;
    }
    if (result != SUCCESS) {
        king_object_store_registry_throw_primary_failure(
            result,
            "Primary object-store backend metadata read failed."
        );
        RETURN_THROWS();
    }

    array_init(return_value);
    add_assoc_string(return_value, "object_id", metadata.object_id);
    add_assoc_string(return_value, "content_type", metadata.content_type[0] != '\0' ? metadata.content_type : "");
    add_assoc_string(return_value, "content_encoding", metadata.content_encoding[0] != '\0' ? metadata.content_encoding : "");
    add_assoc_string(return_value, "etag", metadata.etag[0] != '\0' ? metadata.etag : "");
    add_assoc_string(return_value, "integrity_sha256", metadata.integrity_sha256[0] != '\0' ? metadata.integrity_sha256 : "");
    add_assoc_string(return_value, "object_type_name", king_object_type_to_string(metadata.object_type));
    add_assoc_string(return_value, "cache_policy_name", king_cache_policy_to_string(metadata.cache_policy));
    add_assoc_long(return_value, "content_length", (zend_long) metadata.content_length);
    add_assoc_long(return_value, "version", (zend_long) metadata.version);
    add_assoc_long(return_value, "created_at", (zend_long) metadata.created_at);
    add_assoc_long(return_value, "modified_at", (zend_long) metadata.modified_at);
    add_assoc_long(return_value, "expires_at", (zend_long) metadata.expires_at);
    add_assoc_bool(return_value, "is_expired", king_object_store_metadata_is_expired_now(&metadata));
    add_assoc_long(return_value, "object_type", (zend_long) metadata.object_type);
    add_assoc_long(return_value, "cache_policy", (zend_long) metadata.cache_policy);
    add_assoc_long(return_value, "cache_ttl_seconds", (zend_long) metadata.cache_ttl_seconds);
    add_assoc_long(return_value, "local_fs_present", (zend_long) metadata.local_fs_present);
    add_assoc_long(return_value, "distributed_present", (zend_long) metadata.distributed_present);
    add_assoc_long(return_value, "cloud_s3_present", (zend_long) metadata.cloud_s3_present);
    add_assoc_long(return_value, "cloud_gcs_present", (zend_long) metadata.cloud_gcs_present);
    add_assoc_long(return_value, "cloud_azure_present", (zend_long) metadata.cloud_azure_present);
    add_assoc_long(return_value, "is_backed_up", (zend_long) metadata.is_backed_up);
    add_assoc_long(return_value, "replication_status", (zend_long) metadata.replication_status);
    add_assoc_long(return_value, "is_distributed", (zend_long) metadata.is_distributed);
    add_assoc_long(return_value, "distribution_peer_count", (zend_long) metadata.distribution_peer_count);
}

PHP_FUNCTION(king_object_store_backup_object)
{
    zend_string *object_id;
    zend_string *destination_path;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_STR(destination_path)
    ZEND_PARSE_PARAMETERS_END();

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s",
                object_id_error
            );
            RETURN_THROWS();
        }
    }

    if (ZSTR_LEN(destination_path) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Destination path must be a non-empty string."
        );
        RETURN_THROWS();
    }

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    RETURN_BOOL(
        king_object_store_backup_object(
            ZSTR_VAL(object_id),
            ZSTR_VAL(destination_path)
        ) == SUCCESS
    );
}

PHP_FUNCTION(king_object_store_restore_object)
{
    zend_string *object_id;
    zend_string *source_path;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_STR(source_path)
    ZEND_PARSE_PARAMETERS_END();

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "%s",
                object_id_error
            );
            RETURN_THROWS();
        }
    }

    if (ZSTR_LEN(source_path) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Source path must be a non-empty string."
        );
        RETURN_THROWS();
    }

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    RETURN_BOOL(
        king_object_store_restore_object(
            ZSTR_VAL(object_id),
            ZSTR_VAL(source_path)
        ) == SUCCESS
    );
}

PHP_FUNCTION(king_object_store_backup_all_objects)
{
    zend_string *destination_path;
    zval *options = NULL;
    zval *mode_zv;
    zval *base_snapshot_path_zv;
    const char *mode = "full";
    const char *base_snapshot_path = NULL;
    zend_bool incremental = 0;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(destination_path)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(destination_path) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Destination path must be a non-empty string."
        );
        RETURN_THROWS();
    }
    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }
    if (options != NULL) {
        mode_zv = zend_hash_str_find(
            Z_ARRVAL_P(options),
            "mode",
            sizeof("mode") - 1
        );
        if (mode_zv != NULL) {
            if (Z_TYPE_P(mode_zv) != IS_STRING || Z_STRLEN_P(mode_zv) == 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "king_object_store_backup_all_objects() option 'mode' must be a non-empty string when provided."
                );
                RETURN_THROWS();
            }

            mode = Z_STRVAL_P(mode_zv);
        }

        if (strcmp(mode, "full") == 0) {
            incremental = 0;
        } else if (strcmp(mode, "incremental") == 0) {
            incremental = 1;
        } else {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "king_object_store_backup_all_objects() option 'mode' must be either 'full' or 'incremental'."
            );
            RETURN_THROWS();
        }

        base_snapshot_path_zv = zend_hash_str_find(
            Z_ARRVAL_P(options),
            "base_snapshot_path",
            sizeof("base_snapshot_path") - 1
        );
        if (base_snapshot_path_zv != NULL) {
            if (Z_TYPE_P(base_snapshot_path_zv) != IS_STRING || Z_STRLEN_P(base_snapshot_path_zv) == 0) {
                zend_throw_exception_ex(
                    king_ce_validation_exception,
                    0,
                    "king_object_store_backup_all_objects() option 'base_snapshot_path' must be a non-empty string when provided."
                );
                RETURN_THROWS();
            }

            base_snapshot_path = Z_STRVAL_P(base_snapshot_path_zv);
        }
    }
    if (incremental && base_snapshot_path == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_object_store_backup_all_objects() incremental mode requires option 'base_snapshot_path'."
        );
        RETURN_THROWS();
    }
    if (!incremental && base_snapshot_path != NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_object_store_backup_all_objects() option 'base_snapshot_path' is only valid when mode='incremental'."
        );
        RETURN_THROWS();
    }

    RETURN_BOOL(
        king_object_store_backup_all_objects(
            ZSTR_VAL(destination_path),
            incremental,
            base_snapshot_path
        ) == SUCCESS
    );
}

PHP_FUNCTION(king_object_store_restore_all_objects)
{
    zend_string *source_path;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(source_path)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(source_path) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Source path must be a non-empty string."
        );
        RETURN_THROWS();
    }
    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    RETURN_BOOL(
        king_object_store_restore_all_objects(ZSTR_VAL(source_path)) == SUCCESS
    );
}
