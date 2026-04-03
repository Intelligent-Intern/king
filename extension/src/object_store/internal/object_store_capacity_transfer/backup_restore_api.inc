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
            "Object-store archive integrity validation failed before the object became live."
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
