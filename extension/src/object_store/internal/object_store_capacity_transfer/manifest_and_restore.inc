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

static int king_object_store_snapshot_manifest_parse_count(
    const char *value,
    size_t *count_out
)
{
    const unsigned char *cursor;
    size_t parsed = 0;
    const size_t max_size = (size_t) -1;

    if (value == NULL || value[0] == '\0' || count_out == NULL) {
        return FAILURE;
    }

    for (cursor = (const unsigned char *) value; *cursor != '\0'; ++cursor) {
        if (*cursor < '0' || *cursor > '9') {
            return FAILURE;
        }

        if (parsed > (max_size / 10)) {
            return FAILURE;
        }
        parsed *= 10;

        if ((size_t) (*cursor - '0') > (max_size - parsed)) {
            return FAILURE;
        }
        parsed += (size_t) (*cursor - '0');
    }

    *count_out = parsed;
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
    char line[KING_OBJECT_STORE_SNAPSHOT_MANIFEST_MAX_LINE_LENGTH];
    int saw_format = 0;
    int saw_kind = 0;
    int saw_consistency = 0;
    int saw_inventory_count = 0;
    int saw_upsert_count = 0;
    int saw_delete_count = 0;
    size_t expected_inventory_count = 0;
    size_t expected_upsert_count = 0;
    size_t expected_delete_count = 0;
    size_t actual_inventory_count = 0;
    size_t actual_upsert_count = 0;
    size_t actual_delete_count = 0;
    char pending_inventory_object_id[128] = {0};
    int status = FAILURE;

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
        size_t line_len = strlen(line);
        size_t value_len;

        if (line_len == sizeof(line) - 1 && line[line_len - 1] != '\n') {
            int ch;

            while ((ch = fgetc(fp)) != '\n' && ch != EOF) {
            }
            goto cleanup;
        }

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
                goto cleanup;
            }
            saw_format = 1;
            continue;
        }
        if (strcmp(line, "kind") == 0) {
            if (strcmp(value, KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND_FULL) != 0
                && strcmp(value, KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND_INCREMENTAL) != 0) {
                goto cleanup;
            }
            snprintf(kind_out, kind_out_size, "%s", value);
            saw_kind = 1;
            continue;
        }
        if (strcmp(line, "consistency") == 0) {
            if (strcmp(value, KING_OBJECT_STORE_SNAPSHOT_MANIFEST_CONSISTENCY) != 0) {
                goto cleanup;
            }
            saw_consistency = 1;
            continue;
        }
        if (strcmp(line, "inventory_count") == 0) {
            if (saw_inventory_count
                || king_object_store_snapshot_manifest_parse_count(value, &expected_inventory_count) != SUCCESS) {
                goto cleanup;
            }
            saw_inventory_count = 1;
            continue;
        }
        if (strcmp(line, "upsert_count") == 0) {
            if (saw_upsert_count
                || king_object_store_snapshot_manifest_parse_count(value, &expected_upsert_count) != SUCCESS) {
                goto cleanup;
            }
            saw_upsert_count = 1;
            continue;
        }
        if (strcmp(line, "delete_count") == 0) {
            if (saw_delete_count
                || king_object_store_snapshot_manifest_parse_count(value, &expected_delete_count) != SUCCESS) {
                goto cleanup;
            }
            saw_delete_count = 1;
            continue;
        }
        if (strcmp(line, "inventory_object_id") == 0) {
            if (pending_inventory_object_id[0] != '\0'
                || king_object_store_object_id_validate(value) != NULL) {
                goto cleanup;
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
                goto cleanup;
            }

            actual_inventory_count++;
            pending_inventory_object_id[0] = '\0';
            continue;
        }
        if (strcmp(line, "object_id") == 0 || strcmp(line, "upsert_object_id") == 0) {
            if (king_object_store_object_id_validate(value) != NULL
                || king_object_store_snapshot_string_list_push(upserts, value) != SUCCESS) {
                goto cleanup;
            }
            actual_upsert_count++;
            if (strcmp(line, "object_id") == 0
                && zend_hash_str_find(Z_ARRVAL_P(inventory), value, strlen(value)) == NULL
                && king_object_store_snapshot_inventory_set(inventory, value, "") != SUCCESS) {
                goto cleanup;
            }
            continue;
        }
        if (strcmp(line, "delete_object_id") == 0) {
            if (king_object_store_object_id_validate(value) != NULL
                || king_object_store_snapshot_string_list_push(deletes, value) != SUCCESS) {
                goto cleanup;
            }
            actual_delete_count++;
        }
    }

    if (pending_inventory_object_id[0] != '\0') {
        goto cleanup;
    }

    if (!saw_format || !saw_kind || !saw_consistency) {
        goto cleanup;
    }

    if (saw_inventory_count || saw_upsert_count || saw_delete_count) {
        if (!saw_inventory_count || !saw_upsert_count || !saw_delete_count) {
            goto cleanup;
        }
        if (expected_inventory_count != actual_inventory_count
            || expected_upsert_count != actual_upsert_count
            || expected_delete_count != actual_delete_count) {
            goto cleanup;
        }
    }

    status = SUCCESS;

cleanup:
    fclose(fp);
    return status;
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
