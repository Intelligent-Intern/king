/* --- backup / restore / snapshot transfer helpers --- */

#define KING_OBJECT_STORE_SNAPSHOT_MANIFEST_FILENAME ".king_snapshot_manifest"
#define KING_OBJECT_STORE_SNAPSHOT_MANIFEST_FORMAT "king_object_store_snapshot_v1"
#define KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND_FULL "full_backup"
#define KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND_INCREMENTAL "incremental_backup"
#define KING_OBJECT_STORE_SNAPSHOT_MANIFEST_CONSISTENCY "per_object_locked_commit"

static int king_object_store_is_meta_filename(const char *name)
{
    size_t name_len;

    if (name == NULL || name[0] == '\0' || name[0] == '.') {
        return 1;
    }

    name_len = strlen(name);
    if (name_len > 5 && strcmp(name + name_len - 5, ".meta") == 0) {
        return 1;
    }

    return 0;
}

static int king_object_store_build_snapshot_manifest_path(
    char *dest,
    size_t dest_len,
    const char *directory
)
{
    int written;

    if (dest == NULL || dest_len == 0 || directory == NULL || directory[0] == '\0') {
        return FAILURE;
    }

    written = snprintf(
        dest,
        dest_len,
        "%s/%s",
        directory,
        KING_OBJECT_STORE_SNAPSHOT_MANIFEST_FILENAME
    );

    return (written >= 0 && (size_t) written < dest_len) ? SUCCESS : FAILURE;
}

static int king_object_store_remove_tree(const char *path)
{
    if (path == NULL || path[0] == '\0') {
        return FAILURE;
    }

    {
        DIR *dir = opendir(path);
        struct dirent *entry;

        if (dir == NULL) {
            if (errno == ENOENT) {
                return SUCCESS;
            }
            if (errno == ENOTDIR) {
                return unlink(path) == 0 ? SUCCESS : FAILURE;
            }
            return FAILURE;
        }

        while ((entry = readdir(dir)) != NULL) {
            char child_path[1024];

            if (strcmp(entry->d_name, ".") == 0 || strcmp(entry->d_name, "..") == 0) {
                continue;
            }

            if (snprintf(child_path, sizeof(child_path), "%s/%s", path, entry->d_name) >= (int) sizeof(child_path)) {
                closedir(dir);
                return FAILURE;
            }

            if (king_object_store_remove_tree(child_path) != SUCCESS) {
                closedir(dir);
                return FAILURE;
            }
        }

        closedir(dir);
    }

    return rmdir(path) == 0 ? SUCCESS : FAILURE;
}

static int king_object_store_create_unique_directory(
    const char *base_path,
    const char *label,
    char *destination,
    size_t destination_size
)
{
    if (base_path == NULL || base_path[0] == '\0'
        || label == NULL || label[0] == '\0'
        || destination == NULL || destination_size == 0) {
        return FAILURE;
    }

    if (snprintf(
            destination,
            destination_size,
            "%s.%s.XXXXXX",
            base_path,
            label
        ) >= (int) destination_size) {
        return FAILURE;
    }

    if (king_object_store_mkdir_parents(destination) != SUCCESS) {
        return FAILURE;
    }

    return mkdtemp(destination) != NULL ? SUCCESS : FAILURE;
}

static void king_object_store_fill_fallback_metadata(
    king_object_metadata_t *metadata,
    const char *object_id,
    uint64_t content_length,
    time_t fallback_timestamp
);

static int king_object_store_snapshot_manifest_fingerprint_is_hex(const char *value)
{
    const unsigned char *cursor;

    if (value == NULL || strlen(value) != 64) {
        return 0;
    }

    for (cursor = (const unsigned char *) value; *cursor != '\0'; ++cursor) {
        if (!((*cursor >= '0' && *cursor <= '9')
                || (*cursor >= 'a' && *cursor <= 'f')
                || (*cursor >= 'A' && *cursor <= 'F'))) {
            return 0;
        }
    }

    return 1;
}

static int king_object_store_snapshot_inventory_set(
    zval *inventory,
    const char *object_id,
    const char *fingerprint
)
{
    zval stored;

    if (inventory == NULL || Z_TYPE_P(inventory) != IS_ARRAY
        || object_id == NULL || object_id[0] == '\0'
        || fingerprint == NULL) {
        return FAILURE;
    }

    ZVAL_STRING(&stored, fingerprint);
    return zend_hash_str_update(
               Z_ARRVAL_P(inventory),
               object_id,
               strlen(object_id),
               &stored
           ) != NULL
        ? SUCCESS
        : FAILURE;
}

static int king_object_store_snapshot_string_list_push(zval *entries, const char *value)
{
    if (entries == NULL || Z_TYPE_P(entries) != IS_ARRAY || value == NULL || value[0] == '\0') {
        return FAILURE;
    }

    add_next_index_string(entries, value);
    return SUCCESS;
}

static int king_object_store_snapshot_string_list_contains(zval *entries, const char *value)
{
    zval *entry;

    if (entries == NULL || Z_TYPE_P(entries) != IS_ARRAY || value == NULL || value[0] == '\0') {
        return 0;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(entries), entry) {
        if (Z_TYPE_P(entry) == IS_STRING && strcmp(Z_STRVAL_P(entry), value) == 0) {
            return 1;
        }
    } ZEND_HASH_FOREACH_END();

    return 0;
}

static int king_object_store_snapshot_metadata_fingerprint(
    const king_object_metadata_t *metadata,
    char output[65]
)
{
    char normalized[1024];
    int written;

    if (metadata == NULL || output == NULL) {
        return FAILURE;
    }

    written = snprintf(
        normalized,
        sizeof(normalized),
        "object_id=%s\n"
        "content_type=%s\n"
        "content_encoding=%s\n"
        "etag=%s\n"
        "integrity_sha256=%s\n"
        "content_length=%" PRIu64 "\n"
        "version=%" PRIu64 "\n"
        "created_at=%" PRId64 "\n"
        "modified_at=%" PRId64 "\n"
        "expires_at=%" PRId64 "\n"
        "object_type=%d\n"
        "cache_policy=%d\n"
        "cache_ttl_seconds=%u\n"
        "local_fs_present=%u\n"
        "distributed_present=%u\n"
        "cloud_s3_present=%u\n"
        "cloud_gcs_present=%u\n"
        "cloud_azure_present=%u\n"
        "is_backed_up=%u\n"
        "replication_status=%u\n"
        "is_distributed=%u\n"
        "distribution_peer_count=%u\n",
        metadata->object_id,
        metadata->content_type,
        metadata->content_encoding,
        metadata->etag,
        metadata->integrity_sha256,
        metadata->content_length,
        metadata->version,
        (int64_t) metadata->created_at,
        (int64_t) metadata->modified_at,
        (int64_t) metadata->expires_at,
        (int) metadata->object_type,
        (int) metadata->cache_policy,
        (unsigned) metadata->cache_ttl_seconds,
        (unsigned) metadata->local_fs_present,
        (unsigned) metadata->distributed_present,
        (unsigned) metadata->cloud_s3_present,
        (unsigned) metadata->cloud_gcs_present,
        (unsigned) metadata->cloud_azure_present,
        (unsigned) metadata->is_backed_up,
        (unsigned) metadata->replication_status,
        (unsigned) metadata->is_distributed,
        (unsigned) metadata->distribution_peer_count
    );
    if (written < 0 || (size_t) written >= sizeof(normalized)) {
        return FAILURE;
    }

    king_object_store_compute_sha256_hex(normalized, (size_t) written, output);
    return SUCCESS;
}

static int king_object_store_snapshot_capture_object(
    const char *object_id,
    const char *destination_directory,
    const char *base_fingerprint,
    char fingerprint_out[65],
    zend_bool *exported_out
)
{
    char destination_path[1024];
    char destination_meta_path[1024];
    char lock_error[256];
    void *data = NULL;
    size_t data_size = 0;
    king_object_metadata_t metadata;
    int mutation_lock_fd = -1;
    int rc = FAILURE;
    zend_bool should_export = 0;
    int metadata_rc;

    if (fingerprint_out != NULL) {
        fingerprint_out[0] = '\0';
    }
    if (exported_out != NULL) {
        *exported_out = 0;
    }
    if (!king_object_store_runtime.initialized
        || object_id == NULL
        || king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (destination_directory != NULL && destination_directory[0] != '\0') {
        if (king_object_store_ensure_directory_recursive(destination_directory) != SUCCESS) {
            return FAILURE;
        }
    }

    if (king_object_store_acquire_object_lock(
            object_id,
            &mutation_lock_fd,
            lock_error,
            sizeof(lock_error)
        ) != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            lock_error[0] != '\0'
                ? lock_error
                : "Failed to acquire object-store mutation lock for snapshot export."
        );
        return FAILURE;
    }

    memset(&metadata, 0, sizeof(metadata));
    metadata_rc = king_object_store_backend_read_metadata(object_id, &metadata);
    if (metadata_rc != SUCCESS) {
        if (king_object_store_read_object(object_id, &data, &data_size, &metadata) != SUCCESS) {
            goto cleanup;
        }
    }

    if (metadata.object_id[0] == '\0') {
        if (data != NULL) {
            king_object_store_fill_fallback_metadata(&metadata, object_id, (uint64_t) data_size, time(NULL));
        } else {
            strncpy(metadata.object_id, object_id, sizeof(metadata.object_id) - 1);
        }
    }
    if (metadata.content_length == 0 && data != NULL) {
        metadata.content_length = (uint64_t) data_size;
    }
    if (king_object_store_snapshot_metadata_fingerprint(&metadata, fingerprint_out) != SUCCESS) {
        goto cleanup;
    }

    should_export =
        destination_directory != NULL
        && destination_directory[0] != '\0'
        && (base_fingerprint == NULL
            || base_fingerprint[0] == '\0'
            || strcmp(base_fingerprint, fingerprint_out) != 0);
    if (!should_export) {
        rc = SUCCESS;
        goto cleanup;
    }

    if (data == NULL && king_object_store_read_object(object_id, &data, &data_size, &metadata) != SUCCESS) {
        goto cleanup;
    }
    if (metadata.object_id[0] == '\0') {
        king_object_store_fill_fallback_metadata(&metadata, object_id, (uint64_t) data_size, time(NULL));
    }

    if (king_object_store_build_path_in_directory(
            destination_path,
            sizeof(destination_path),
            destination_directory,
            object_id,
            NULL
        ) != SUCCESS
        || king_object_store_build_path_in_directory(
            destination_meta_path,
            sizeof(destination_meta_path),
            destination_directory,
            object_id,
            ".meta"
        ) != SUCCESS) {
        goto cleanup;
    }

    if (king_object_store_mkdir_parents(destination_path) != SUCCESS
        || king_object_store_atomic_write_file(destination_path, data, data_size) != SUCCESS
        || king_object_store_meta_write_to_path(destination_meta_path, &metadata) != SUCCESS) {
        unlink(destination_path);
        unlink(destination_meta_path);
        goto cleanup;
    }

    if (exported_out != NULL) {
        *exported_out = 1;
    }
    rc = SUCCESS;

cleanup:
    if (data != NULL) {
        pefree(data, 1);
    }
    king_object_store_release_object_lock(&mutation_lock_fd);
    return rc;
}

static int king_object_store_snapshot_manifest_write(
    const char *directory,
    const char *kind,
    zval *inventory,
    zval *upserts,
    zval *deletes
)
{
    char manifest_path[1024];
    char temp_path[1024];
    FILE *fp = NULL;
    int temp_fd = -1;
    size_t inventory_count = 0;
    size_t upsert_count = 0;
    size_t delete_count = 0;
    zend_string *key;
    zval *entry;

    if (directory == NULL || directory[0] == '\0'
        || kind == NULL || kind[0] == '\0'
        || inventory == NULL || Z_TYPE_P(inventory) != IS_ARRAY
        || upserts == NULL || Z_TYPE_P(upserts) != IS_ARRAY
        || deletes == NULL || Z_TYPE_P(deletes) != IS_ARRAY) {
        return FAILURE;
    }

    if (king_object_store_build_snapshot_manifest_path(
            manifest_path,
            sizeof(manifest_path),
            directory
        ) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_mkdir_parents(manifest_path) != SUCCESS) {
        return FAILURE;
    }
    if (snprintf(temp_path, sizeof(temp_path), "%s.XXXXXX", manifest_path) >= (int) sizeof(temp_path)) {
        return FAILURE;
    }

    temp_fd = mkstemp(temp_path);
    if (temp_fd < 0) {
        return FAILURE;
    }

    fp = fdopen(temp_fd, "wb");
    if (fp == NULL) {
        close(temp_fd);
        unlink(temp_path);
        return FAILURE;
    }
    temp_fd = -1;

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(inventory), key, entry) {
        if (key != NULL && Z_TYPE_P(entry) == IS_STRING) {
            inventory_count++;
        }
    } ZEND_HASH_FOREACH_END();
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(upserts), entry) {
        if (Z_TYPE_P(entry) == IS_STRING) {
            upsert_count++;
        }
    } ZEND_HASH_FOREACH_END();
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(deletes), entry) {
        if (Z_TYPE_P(entry) == IS_STRING) {
            delete_count++;
        }
    } ZEND_HASH_FOREACH_END();

    fprintf(
        fp,
        "format=%s\n"
        "kind=%s\n"
        "consistency=%s\n"
        "inventory_count=%zu\n"
        "upsert_count=%zu\n"
        "delete_count=%zu\n",
        KING_OBJECT_STORE_SNAPSHOT_MANIFEST_FORMAT,
        kind,
        KING_OBJECT_STORE_SNAPSHOT_MANIFEST_CONSISTENCY,
        inventory_count,
        upsert_count,
        delete_count
    );

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(inventory), key, entry) {
        if (key == NULL || Z_TYPE_P(entry) != IS_STRING) {
            continue;
        }

        fprintf(
            fp,
            "inventory_object_id=%s\n"
            "inventory_fingerprint=%s\n",
            ZSTR_VAL(key),
            Z_STRVAL_P(entry)
        );
    } ZEND_HASH_FOREACH_END();

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(upserts), entry) {
        if (Z_TYPE_P(entry) != IS_STRING) {
            continue;
        }

        fprintf(fp, "upsert_object_id=%s\n", Z_STRVAL_P(entry));
    } ZEND_HASH_FOREACH_END();

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(deletes), entry) {
        if (Z_TYPE_P(entry) != IS_STRING) {
            continue;
        }

        fprintf(fp, "delete_object_id=%s\n", Z_STRVAL_P(entry));
    } ZEND_HASH_FOREACH_END();

    if (fclose(fp) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    if (rename(temp_path, manifest_path) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    return SUCCESS;
}

static int king_object_store_snapshot_manifest_parse(
    const char *manifest_path,
    zval *inventory,
    zval *upserts,
    zval *deletes,
    char *kind_out,
    size_t kind_out_size
)
{
    FILE *fp;
    char line[640];
    int saw_format = 0;
    int saw_kind = 0;
    int saw_consistency = 0;
    char pending_inventory_object_id[128] = {0};

    if (manifest_path == NULL || manifest_path[0] == '\0'
        || inventory == NULL || Z_TYPE_P(inventory) != IS_ARRAY
        || upserts == NULL || Z_TYPE_P(upserts) != IS_ARRAY
        || deletes == NULL || Z_TYPE_P(deletes) != IS_ARRAY
        || kind_out == NULL || kind_out_size == 0) {
        return FAILURE;
    }

    kind_out[0] = '\0';
    fp = fopen(manifest_path, "rb");
    if (fp == NULL) {
        return FAILURE;
    }

    while (fgets(line, sizeof(line), fp) != NULL) {
        char *value = strchr(line, '=');
        size_t value_len;

        if (value == NULL) {
            continue;
        }

        *value = '\0';
        value++;
        value_len = strlen(value);
        while (value_len > 0 && (value[value_len - 1] == '\n' || value[value_len - 1] == '\r')) {
            value[--value_len] = '\0';
        }

        if (strcmp(line, "format") == 0) {
            if (strcmp(value, KING_OBJECT_STORE_SNAPSHOT_MANIFEST_FORMAT) != 0) {
                fclose(fp);
                return FAILURE;
            }
            saw_format = 1;
            continue;
        }
        if (strcmp(line, "kind") == 0) {
            if (strcmp(value, KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND_FULL) != 0
                && strcmp(value, KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND_INCREMENTAL) != 0) {
                fclose(fp);
                return FAILURE;
            }
            snprintf(kind_out, kind_out_size, "%s", value);
            saw_kind = 1;
            continue;
        }
        if (strcmp(line, "consistency") == 0) {
            if (strcmp(value, KING_OBJECT_STORE_SNAPSHOT_MANIFEST_CONSISTENCY) != 0) {
                fclose(fp);
                return FAILURE;
            }
            saw_consistency = 1;
            continue;
        }
        if (strcmp(line, "inventory_object_id") == 0) {
            if (pending_inventory_object_id[0] != '\0'
                || king_object_store_object_id_validate(value) != NULL) {
                fclose(fp);
                return FAILURE;
            }

            snprintf(
                pending_inventory_object_id,
                sizeof(pending_inventory_object_id),
                "%s",
                value
            );
            continue;
        }
        if (strcmp(line, "inventory_fingerprint") == 0) {
            if (pending_inventory_object_id[0] == '\0'
                || !king_object_store_snapshot_manifest_fingerprint_is_hex(value)
                || king_object_store_snapshot_inventory_set(
                    inventory,
                    pending_inventory_object_id,
                    value
                ) != SUCCESS) {
                fclose(fp);
                return FAILURE;
            }

            pending_inventory_object_id[0] = '\0';
            continue;
        }
        if (strcmp(line, "object_id") == 0 || strcmp(line, "upsert_object_id") == 0) {
            if (king_object_store_object_id_validate(value) != NULL
                || king_object_store_snapshot_string_list_push(upserts, value) != SUCCESS) {
                fclose(fp);
                return FAILURE;
            }
            if (strcmp(line, "object_id") == 0
                && zend_hash_str_find(Z_ARRVAL_P(inventory), value, strlen(value)) == NULL
                && king_object_store_snapshot_inventory_set(inventory, value, "") != SUCCESS) {
                fclose(fp);
                return FAILURE;
            }
            continue;
        }
        if (strcmp(line, "delete_object_id") == 0) {
            if (king_object_store_object_id_validate(value) != NULL
                || king_object_store_snapshot_string_list_push(deletes, value) != SUCCESS) {
                fclose(fp);
                return FAILURE;
            }
        }
    }

    fclose(fp);

    if (pending_inventory_object_id[0] != '\0') {
        return FAILURE;
    }

    return (saw_format && saw_kind && saw_consistency) ? SUCCESS : FAILURE;
}

static int king_object_store_snapshot_manifest_validate_restore_shape(
    zval *inventory,
    zval *upserts,
    zval *deletes,
    const char *kind
)
{
    zend_string *inventory_object_id;
    zval *entry;

    if (inventory == NULL || Z_TYPE_P(inventory) != IS_ARRAY
        || upserts == NULL || Z_TYPE_P(upserts) != IS_ARRAY
        || deletes == NULL || Z_TYPE_P(deletes) != IS_ARRAY
        || kind == NULL || kind[0] == '\0') {
        return FAILURE;
    }

    if (strcmp(kind, KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND_FULL) == 0) {
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(deletes), entry) {
            if (Z_TYPE_P(entry) == IS_STRING) {
                return FAILURE;
            }
        } ZEND_HASH_FOREACH_END();
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(upserts), entry) {
        if (Z_TYPE_P(entry) != IS_STRING) {
            continue;
        }
        if (zend_hash_str_find(
                Z_ARRVAL_P(inventory),
                Z_STRVAL_P(entry),
                Z_STRLEN_P(entry)
            ) == NULL) {
            return FAILURE;
        }
        if (king_object_store_snapshot_string_list_contains(deletes, Z_STRVAL_P(entry))) {
            return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    ZEND_HASH_FOREACH_STR_KEY(Z_ARRVAL_P(inventory), inventory_object_id) {
        if (inventory_object_id == NULL) {
            continue;
        }

        if (strcmp(kind, KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND_FULL) == 0
            && !king_object_store_snapshot_string_list_contains(
                upserts,
                ZSTR_VAL(inventory_object_id)
            )) {
            return FAILURE;
        }
        if (king_object_store_snapshot_string_list_contains(
                deletes,
                ZSTR_VAL(inventory_object_id)
            )) {
            return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static int king_object_store_validate_restore_archive_metadata_for_buffer(
    const char *object_id,
    const king_object_metadata_t *metadata,
    const void *data,
    size_t data_size
)
{
    if (object_id == NULL || object_id[0] == '\0' || metadata == NULL) {
        return FAILURE;
    }
    if (metadata->object_id[0] != '\0' && strcmp(metadata->object_id, object_id) != 0) {
        return FAILURE;
    }
    if (metadata->content_length != 0
        && metadata->content_length != (uint64_t) data_size) {
        return FAILURE;
    }
    if (metadata->integrity_sha256[0] != '\0'
        && !king_object_store_verify_integrity_buffer(data, data_size, metadata)) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_object_store_validate_restore_archive_metadata_for_path(
    const char *object_id,
    const king_object_metadata_t *metadata,
    const char *source_path,
    uint64_t source_size
)
{
    char computed_sha256[65];
    uint64_t computed_size = 0;

    if (object_id == NULL || object_id[0] == '\0'
        || metadata == NULL
        || source_path == NULL
        || source_path[0] == '\0') {
        return FAILURE;
    }
    if (metadata->object_id[0] != '\0' && strcmp(metadata->object_id, object_id) != 0) {
        return FAILURE;
    }
    if (metadata->content_length != 0 && metadata->content_length != source_size) {
        return FAILURE;
    }
    if (metadata->integrity_sha256[0] != '\0') {
        if (king_object_store_compute_sha256_hex_for_path(
                source_path,
                computed_sha256,
                &computed_size
            ) != SUCCESS) {
            return FAILURE;
        }
        if (computed_size != source_size
            || strcmp(computed_sha256, metadata->integrity_sha256) != 0) {
            return FAILURE;
        }
    }

    return SUCCESS;
}

static int king_object_store_preflight_restore_object_archive(
    const char *object_id,
    const char *source_directory
)
{
    char source_path[1024];
    char source_meta_path[1024];
    FILE *source_fp = NULL;
    struct stat source_stat;
    king_object_metadata_t metadata;

    if (object_id == NULL || object_id[0] == '\0'
        || source_directory == NULL || source_directory[0] == '\0'
        || king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }

    if (king_object_store_build_path_in_directory(
            source_path,
            sizeof(source_path),
            source_directory,
            object_id,
            NULL
        ) != SUCCESS
        || king_object_store_build_path_in_directory(
            source_meta_path,
            sizeof(source_meta_path),
            source_directory,
            object_id,
            ".meta"
        ) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_open_regular_file_stream_readonly(
            source_path,
            &source_fp,
            &source_stat
        ) != SUCCESS) {
        return FAILURE;
    }
    fclose(source_fp);
    source_fp = NULL;

    memset(&metadata, 0, sizeof(metadata));
    if (king_object_store_meta_read_from_path(source_meta_path, &metadata) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_validate_restore_archive_metadata_for_path(
            object_id,
            &metadata,
            source_path,
            (uint64_t) source_stat.st_size
        ) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_object_store_restore_meta_filename_to_object_id(
    const char *meta_filename,
    char *object_id,
    size_t object_id_size
)
{
    size_t meta_filename_len;
    size_t object_id_len;

    if (meta_filename == NULL || object_id == NULL || object_id_size == 0) {
        return FAILURE;
    }

    meta_filename_len = strlen(meta_filename);
    if (meta_filename_len <= 5 || strcmp(meta_filename + meta_filename_len - 5, ".meta") != 0) {
        return FAILURE;
    }

    object_id_len = meta_filename_len - 5;
    if (object_id_len == 0 || object_id_len >= object_id_size) {
        return FAILURE;
    }

    memcpy(object_id, meta_filename, object_id_len);
    object_id[object_id_len] = '\0';

    return king_object_store_object_id_validate(object_id) == NULL ? SUCCESS : FAILURE;
}

static int king_object_store_collect_legacy_restore_objects(
    const char *source_directory,
    zval *object_ids
)
{
    DIR *dir;
    struct dirent *ent;
    char source_path[1024];
    struct stat st;

    if (source_directory == NULL || source_directory[0] == '\0'
        || object_ids == NULL || Z_TYPE_P(object_ids) != IS_ARRAY) {
        return FAILURE;
    }

    dir = opendir(source_directory);
    if (dir == NULL) {
        return FAILURE;
    }

    while ((ent = readdir(dir)) != NULL) {
        if (ent->d_name[0] == '.') {
            continue;
        }

        if (king_object_store_is_meta_filename(ent->d_name)) {
            char object_id[128];
            FILE *payload_fp = NULL;

            if (king_object_store_restore_meta_filename_to_object_id(
                    ent->d_name,
                    object_id,
                    sizeof(object_id)
                ) != SUCCESS
                || king_object_store_build_path_in_directory(
                    source_path,
                    sizeof(source_path),
                    source_directory,
                    object_id,
                    NULL
                ) != SUCCESS
                || king_object_store_open_regular_file_stream_readonly(
                    source_path,
                    &payload_fp,
                    &st
                ) != SUCCESS) {
                closedir(dir);
                return FAILURE;
            }

            fclose(payload_fp);
            continue;
        }

        if (king_object_store_object_id_validate(ent->d_name) != NULL) {
            closedir(dir);
            return FAILURE;
        }
        if (king_object_store_build_path_in_directory(
                source_path,
                sizeof(source_path),
                source_directory,
                ent->d_name,
                NULL
            ) != SUCCESS) {
            closedir(dir);
            return FAILURE;
        }
        if (stat(source_path, &st) != 0) {
            closedir(dir);
            return FAILURE;
        }
        if (!S_ISREG(st.st_mode)) {
            continue;
        }
        if (king_object_store_preflight_restore_object_archive(ent->d_name, source_directory) != SUCCESS) {
            closedir(dir);
            return FAILURE;
        }

        add_next_index_string(object_ids, ent->d_name);
    }

    closedir(dir);
    return SUCCESS;
}

static int king_object_store_restore_all_objects_from_manifest(
    const char *source_directory,
    const char *manifest_path
)
{
    zval inventory;
    zval upserts;
    zval deletes;
    zval *entry;
    char kind[32];

    if (source_directory == NULL || source_directory[0] == '\0'
        || manifest_path == NULL || manifest_path[0] == '\0') {
        return FAILURE;
    }

    array_init(&inventory);
    array_init(&upserts);
    array_init(&deletes);

    if (king_object_store_snapshot_manifest_parse(
            manifest_path,
            &inventory,
            &upserts,
            &deletes,
            kind,
            sizeof(kind)
        ) != SUCCESS) {
        zval_ptr_dtor(&inventory);
        zval_ptr_dtor(&upserts);
        zval_ptr_dtor(&deletes);
        return FAILURE;
    }
    if (king_object_store_snapshot_manifest_validate_restore_shape(
            &inventory,
            &upserts,
            &deletes,
            kind
        ) != SUCCESS) {
        zval_ptr_dtor(&inventory);
        zval_ptr_dtor(&upserts);
        zval_ptr_dtor(&deletes);
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(upserts), entry) {
        if (Z_TYPE_P(entry) != IS_STRING) {
            continue;
        }
        if (king_object_store_preflight_restore_object_archive(
                Z_STRVAL_P(entry),
                source_directory
            ) != SUCCESS) {
            zval_ptr_dtor(&inventory);
            zval_ptr_dtor(&upserts);
            zval_ptr_dtor(&deletes);
            return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(upserts), entry) {
        if (Z_TYPE_P(entry) != IS_STRING) {
            continue;
        }
        if (king_object_store_restore_object(Z_STRVAL_P(entry), source_directory) != SUCCESS) {
            zval_ptr_dtor(&inventory);
            zval_ptr_dtor(&upserts);
            zval_ptr_dtor(&deletes);
            return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    if (strcmp(kind, KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND_INCREMENTAL) == 0) {
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL(deletes), entry) {
            int remove_rc;

            if (Z_TYPE_P(entry) != IS_STRING) {
                continue;
            }
            remove_rc = king_object_store_remove_object(Z_STRVAL_P(entry));
            if (remove_rc != SUCCESS && remove_rc != KING_OBJECT_STORE_RESULT_MISS) {
                zval_ptr_dtor(&inventory);
                zval_ptr_dtor(&upserts);
                zval_ptr_dtor(&deletes);
                return FAILURE;
            }
        } ZEND_HASH_FOREACH_END();
    }

    zval_ptr_dtor(&inventory);
    zval_ptr_dtor(&upserts);
    zval_ptr_dtor(&deletes);
    return SUCCESS;
}

static int king_object_store_commit_snapshot_directory(
    const char *staging_directory,
    const char *destination_directory
)
{
    char previous_directory[1024];
    zend_bool had_existing_destination = 0;

    if (staging_directory == NULL || staging_directory[0] == '\0'
        || destination_directory == NULL || destination_directory[0] == '\0') {
        return FAILURE;
    }

    previous_directory[0] = '\0';

    if (king_object_store_create_unique_directory(
            destination_directory,
            "previous",
            previous_directory,
            sizeof(previous_directory)
        ) != SUCCESS) {
        return FAILURE;
    }
    if (rmdir(previous_directory) != 0) {
        king_object_store_remove_tree(previous_directory);
        return FAILURE;
    }

    if (rename(destination_directory, previous_directory) == 0) {
        DIR *moved_directory = opendir(previous_directory);

        if (moved_directory == NULL) {
            (void) rename(previous_directory, destination_directory);
            return FAILURE;
        }

        closedir(moved_directory);
        had_existing_destination = 1;
    } else if (errno != ENOENT) {
        return FAILURE;
    }

    if (rename(staging_directory, destination_directory) != 0) {
        if (had_existing_destination) {
            (void) rename(previous_directory, destination_directory);
        }
        return FAILURE;
    }

    if (had_existing_destination) {
        (void) king_object_store_remove_tree(previous_directory);
    }

    return SUCCESS;
}

static void king_object_store_fill_fallback_metadata(
    king_object_metadata_t *metadata,
    const char *object_id,
    uint64_t content_length,
    time_t fallback_timestamp)
{
    if (metadata == NULL || object_id == NULL) {
        return;
    }

    memset(metadata, 0, sizeof(*metadata));
    strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
    metadata->content_length = content_length;
    metadata->created_at = fallback_timestamp;
    metadata->modified_at = fallback_timestamp;
}

zend_bool king_object_store_runtime_capacity_is_enabled(void)
{
    return king_object_store_runtime.initialized
        && king_object_store_runtime.config.max_storage_size_bytes > 0;
}

const char *king_object_store_runtime_capacity_mode_to_string(void)
{
    return king_object_store_runtime_capacity_is_enabled()
        ? "logical_hard_limit"
        : "disabled";
}

uint64_t king_object_store_runtime_capacity_available_bytes(void)
{
    uint64_t limit_bytes;

    if (!king_object_store_runtime_capacity_is_enabled()) {
        return 0;
    }

    limit_bytes = king_object_store_runtime.config.max_storage_size_bytes;
    if (king_object_store_runtime.current_stored_bytes >= limit_bytes) {
        return 0;
    }

    return limit_bytes - king_object_store_runtime.current_stored_bytes;
}

static int king_object_store_runtime_capacity_resolve_existing_size(
    const char *object_id,
    uint64_t *old_size_out,
    zend_bool *had_existing_object_out
)
{
    char file_path[1024];
    struct stat st_old;
    king_object_metadata_t existing_metadata;
    king_storage_backend_t primary_backend;

    if (old_size_out == NULL || had_existing_object_out == NULL) {
        return FAILURE;
    }

    *old_size_out = 0;
    *had_existing_object_out = 0;

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_runtime_metadata_cache_read(object_id, &existing_metadata) == SUCCESS
        || king_object_store_meta_read(object_id, &existing_metadata) == SUCCESS) {
        *old_size_out = existing_metadata.content_length;
        *had_existing_object_out = 1;
        return SUCCESS;
    }

    primary_backend = king_object_store_normalize_backend(
        king_object_store_runtime.config.primary_backend
    );
    if (primary_backend == KING_STORAGE_BACKEND_DISTRIBUTED) {
        if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
            return FAILURE;
        }
    } else {
        king_object_store_build_path(file_path, sizeof(file_path), object_id);
    }
    if (stat(file_path, &st_old) == 0 && S_ISREG(st_old.st_mode)) {
        *old_size_out = (uint64_t) st_old.st_size;
        *had_existing_object_out = 1;
    }

    return SUCCESS;
}

static zend_bool king_object_store_runtime_capacity_allows_rewrite(
    uint64_t new_size,
    zend_bool had_existing_object,
    uint64_t old_size,
    uint64_t *projected_total_out
)
{
    uint64_t projected_total;

    if (!king_object_store_runtime_capacity_is_enabled()) {
        if (projected_total_out != NULL) {
            *projected_total_out = king_object_store_runtime.current_stored_bytes;
        }
        return 1;
    }

    projected_total = king_object_store_runtime.current_stored_bytes;
    if (had_existing_object) {
        if (projected_total >= old_size) {
            projected_total -= old_size;
        } else {
            projected_total = 0;
        }
    }

    if (UINT64_MAX - projected_total < new_size) {
        if (projected_total_out != NULL) {
            *projected_total_out = UINT64_MAX;
        }
        return 0;
    }

    projected_total += new_size;
    if (projected_total_out != NULL) {
        *projected_total_out = projected_total;
    }

    return projected_total <= king_object_store_runtime.config.max_storage_size_bytes;
}

int king_object_store_runtime_capacity_check_rewrite(
    uint64_t new_size,
    zend_bool had_existing_object,
    uint64_t old_size,
    char *error,
    size_t error_size
)
{
    if (!king_object_store_runtime_capacity_allows_rewrite(
            new_size,
            had_existing_object,
            old_size,
            NULL
        )) {
        if (error != NULL && error_size > 0) {
            snprintf(
                error,
                error_size,
                "Object-store operation would exceed the configured runtime capacity."
            );
        }
        return FAILURE;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

int king_object_store_runtime_capacity_check_object_size(
    const char *object_id,
    uint64_t new_size,
    char *error,
    size_t error_size
)
{
    uint64_t old_size = 0;
    zend_bool had_existing_object = 0;

    if (king_object_store_runtime_capacity_resolve_existing_size(
            object_id,
            &old_size,
            &had_existing_object
        ) != SUCCESS) {
        if (error != NULL && error_size > 0) {
            snprintf(
                error,
                error_size,
                "Object-store operation could not resolve the current runtime capacity baseline."
            );
        }
        return FAILURE;
    }

    return king_object_store_runtime_capacity_check_rewrite(
        new_size,
        had_existing_object,
        old_size,
        error,
        error_size
    );
}

static int king_object_store_apply_local_fs_counters_for_rewrite(
    const char *object_id,
    uint64_t new_size,
    int had_old,
    uint64_t old_size
)
{
    if (had_old) {
        if (king_object_store_runtime.current_stored_bytes >= old_size) {
            king_object_store_runtime.current_stored_bytes -= old_size;
        }
    } else {
        king_object_store_runtime.current_object_count++;
    }

    king_object_store_runtime.current_stored_bytes += new_size;
    king_object_store_runtime.latest_object_at = time(NULL);

    return SUCCESS;
}

static int king_object_store_export_object(const char *object_id, const char *destination_directory)
{
    char fingerprint[65];

    if (!king_object_store_runtime.initialized) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL || destination_directory == NULL || destination_directory[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "object export") == FAILURE) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(destination_directory, 1) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_ensure_directory_recursive(destination_directory) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(destination_directory, 0) != SUCCESS) {
        return FAILURE;
    }

    return king_object_store_snapshot_capture_object(
        object_id,
        destination_directory,
        NULL,
        fingerprint,
        NULL
    );
}

static int king_object_store_import_object(const char *object_id, const char *source_directory)
{
    char source_path[1024];
    char source_meta_path[1024];
    char capacity_error[256];
    char lock_error[256];
    struct stat source_stat;
    void *data = NULL;
    size_t data_size = 0;
    king_object_metadata_t metadata;
    int mutation_lock_fd = -1;
    int rc = FAILURE;

    if (!king_object_store_runtime.initialized) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL || source_directory == NULL || source_directory[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "object import") == FAILURE) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(source_directory, 0) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_build_path_in_directory(source_path, sizeof(source_path), source_directory, object_id, NULL) != SUCCESS) {
        return FAILURE;
    }
    if (stat(source_path, &source_stat) != 0 || !S_ISREG(source_stat.st_mode)) {
        return FAILURE;
    }
    if (king_object_store_acquire_object_lock(
            object_id,
            &mutation_lock_fd,
            lock_error,
            sizeof(lock_error)
        ) != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            lock_error[0] != '\0' ? lock_error : "Failed to acquire object-store mutation lock for restore."
        );
        return FAILURE;
    }
    capacity_error[0] = '\0';
    if (king_object_store_runtime_capacity_check_object_size(
            object_id,
            (uint64_t) source_stat.st_size,
            capacity_error,
            sizeof(capacity_error)
        ) != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            capacity_error
        );
        goto cleanup;
    }

    if (king_object_store_build_path_in_directory(
            source_meta_path,
            sizeof(source_meta_path),
            source_directory,
            object_id,
            ".meta"
        ) != SUCCESS
        || king_object_store_read_file_contents(source_path, &data, &data_size) != SUCCESS) {
        goto cleanup;
    }

    if (king_object_store_meta_read_from_path(source_meta_path, &metadata) != SUCCESS) {
        king_object_store_fill_fallback_metadata(&metadata, object_id, (uint64_t) source_stat.st_size, source_stat.st_mtime);
    } else if (king_object_store_validate_restore_archive_metadata_for_buffer(
            object_id,
            &metadata,
            data,
            data_size
        ) != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Restore archive integrity validation failed before the object became live."
        );
        goto cleanup;
    }

    if (metadata.object_id[0] == '\0') {
        strncpy(metadata.object_id, object_id, sizeof(metadata.object_id) - 1);
    }
    if (metadata.content_length == 0) {
        metadata.content_length = (uint64_t) source_stat.st_size;
    }

    if (king_object_store_write_object(object_id, data, data_size, &metadata) != SUCCESS) {
        goto cleanup;
    }

    rc = SUCCESS;

cleanup:
    if (data != NULL) {
        pefree(data, 1);
    }
    king_object_store_release_object_lock(&mutation_lock_fd);

    return rc;
}

int king_object_store_backup_object(const char *object_id, const char *destination_path)
{
    return king_object_store_export_object(object_id, destination_path);
}

int king_object_store_restore_object(const char *object_id, const char *source_path)
{
    return king_object_store_import_object(object_id, source_path);
}

int king_object_store_backup_all_objects(
    const char *destination_directory,
    zend_bool incremental,
    const char *base_snapshot_path
)
{
    zval objects;
    zval inventory;
    zval upserts;
    zval deletes;
    zval base_inventory;
    zval *entry;
    char staging_directory[1024];
    char base_manifest_path[1024];
    char snapshot_kind[32];
    struct stat base_manifest_stat;
    int rc = FAILURE;

    if (!king_object_store_runtime.initialized || destination_directory == NULL || destination_directory[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "batch object export") == FAILURE) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(destination_directory, 1) != SUCCESS) {
        return FAILURE;
    }
    if (incremental) {
        if (base_snapshot_path == NULL || base_snapshot_path[0] == '\0') {
            return FAILURE;
        }
        if (king_object_store_directory_is_within_storage_root(base_snapshot_path, 0) != SUCCESS) {
            return FAILURE;
        }
        if (king_object_store_build_snapshot_manifest_path(
                base_manifest_path,
                sizeof(base_manifest_path),
                base_snapshot_path
            ) != SUCCESS) {
            return FAILURE;
        }
        if (stat(base_manifest_path, &base_manifest_stat) != 0 || !S_ISREG(base_manifest_stat.st_mode)) {
            return FAILURE;
        }
    }
    if (king_object_store_create_unique_directory(
            destination_directory,
            "staging",
            staging_directory,
            sizeof(staging_directory)
        ) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(staging_directory, 0) != SUCCESS) {
        king_object_store_remove_tree(staging_directory);
        return FAILURE;
    }

    array_init(&objects);
    array_init(&inventory);
    array_init(&upserts);
    array_init(&deletes);
    array_init(&base_inventory);

    snapshot_kind[0] = '\0';
    if (king_object_store_list_object(&objects) != SUCCESS) {
        goto cleanup;
    }
    if (incremental
        && king_object_store_snapshot_manifest_parse(
            base_manifest_path,
            &base_inventory,
            &upserts,
            &deletes,
            snapshot_kind,
            sizeof(snapshot_kind)
        ) != SUCCESS) {
        goto cleanup;
    }
    zend_hash_clean(Z_ARRVAL(upserts));
    zend_hash_clean(Z_ARRVAL(deletes));

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(objects), entry) {
        zval *object_id_zv;
        zval *base_fingerprint_zv;
        const char *base_fingerprint = NULL;
        char fingerprint[65];
        zend_bool exported = 0;

        if (Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        object_id_zv = zend_hash_str_find(Z_ARRVAL_P(entry), "object_id", sizeof("object_id") - 1);
        if (object_id_zv == NULL || Z_TYPE_P(object_id_zv) != IS_STRING) {
            continue;
        }

        if (incremental) {
            base_fingerprint_zv = zend_hash_find(
                Z_ARRVAL(base_inventory),
                Z_STR_P(object_id_zv)
            );
            if (base_fingerprint_zv != NULL && Z_TYPE_P(base_fingerprint_zv) == IS_STRING) {
                base_fingerprint = Z_STRVAL_P(base_fingerprint_zv);
            }
        }

        if (king_object_store_snapshot_capture_object(
                Z_STRVAL_P(object_id_zv),
                staging_directory,
                base_fingerprint,
                fingerprint,
                &exported
            ) != SUCCESS
            || king_object_store_snapshot_inventory_set(
                &inventory,
                Z_STRVAL_P(object_id_zv),
                fingerprint
            ) != SUCCESS) {
            goto cleanup;
        }
        if (exported
            && king_object_store_snapshot_string_list_push(
                &upserts,
                Z_STRVAL_P(object_id_zv)
            ) != SUCCESS) {
            goto cleanup;
        }
    } ZEND_HASH_FOREACH_END();

    if (incremental) {
        zend_string *base_object_id;

        ZEND_HASH_FOREACH_STR_KEY(Z_ARRVAL(base_inventory), base_object_id) {
            if (base_object_id == NULL) {
                continue;
            }
            if (zend_hash_exists(Z_ARRVAL(inventory), base_object_id)) {
                continue;
            }
            if (king_object_store_snapshot_string_list_push(
                    &deletes,
                    ZSTR_VAL(base_object_id)
                ) != SUCCESS) {
                goto cleanup;
            }
        } ZEND_HASH_FOREACH_END();
    }

    if (king_object_store_snapshot_manifest_write(
            staging_directory,
            incremental
                ? KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND_INCREMENTAL
                : KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND_FULL,
            &inventory,
            &upserts,
            &deletes
        ) != SUCCESS) {
        goto cleanup;
    }

    rc = king_object_store_commit_snapshot_directory(staging_directory, destination_directory);

cleanup:
    zval_ptr_dtor(&objects);
    zval_ptr_dtor(&inventory);
    zval_ptr_dtor(&upserts);
    zval_ptr_dtor(&deletes);
    zval_ptr_dtor(&base_inventory);
    if (rc != SUCCESS) {
        king_object_store_remove_tree(staging_directory);
    }

    return rc;
}

int king_object_store_restore_all_objects(const char *source_directory)
{
    zval object_ids;
    zval *entry;
    char manifest_path[1024];
    char restore_error[256];
    struct stat st;
    int restore_barrier_fd = -1;
    int rc = FAILURE;
    zend_bool object_ids_initialized = 0;

    if (!king_object_store_runtime.initialized || source_directory == NULL || source_directory[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "batch object import") == FAILURE) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(source_directory, 0) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_acquire_exclusive_restore_barrier(
            &restore_barrier_fd,
            restore_error,
            sizeof(restore_error)
        ) != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            restore_error[0] != '\0'
                ? restore_error
                : "Failed to acquire the object-store batch restore barrier."
        );
        return FAILURE;
    }
    if (king_object_store_build_snapshot_manifest_path(
            manifest_path,
            sizeof(manifest_path),
            source_directory
        ) != SUCCESS) {
        goto cleanup;
    }
    if (stat(manifest_path, &st) == 0 && S_ISREG(st.st_mode)) {
        rc = king_object_store_restore_all_objects_from_manifest(source_directory, manifest_path);
        goto cleanup;
    }

    array_init(&object_ids);
    object_ids_initialized = 1;
    if (king_object_store_collect_legacy_restore_objects(source_directory, &object_ids) != SUCCESS) {
        goto cleanup;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(object_ids), entry) {
        if (Z_TYPE_P(entry) != IS_STRING) {
            continue;
        }
        if (king_object_store_restore_object(Z_STRVAL_P(entry), source_directory) != SUCCESS) {
            goto cleanup;
        }
    } ZEND_HASH_FOREACH_END();

    rc = SUCCESS;

cleanup:
    if (object_ids_initialized) {
        zval_ptr_dtor(&object_ids);
    }
    king_object_store_release_exclusive_restore_barrier(&restore_barrier_fd);
    return rc;
}
