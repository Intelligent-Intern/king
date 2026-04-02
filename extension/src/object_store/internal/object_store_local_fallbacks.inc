/*
 * Local-fallback slice. Restores or synthesizes local payloads and metadata
 * when cloud/distributed flows need a fail-closed filesystem fallback.
 */

static int king_object_store_local_fs_restore_payload_from_backup(
    const char *object_id,
    const void *data,
    size_t data_size
)
{
    char file_path[1024];

    if (object_id == NULL || data == NULL) {
        return FAILURE;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);
    if (king_object_store_mkdir_parents(file_path) != SUCCESS) {
        return FAILURE;
    }

    return king_object_store_atomic_write_file(file_path, data, data_size);
}

static int king_object_store_local_fs_restore_payload_from_path(
    const char *object_id,
    const char *source_path
)
{
    char file_path[1024];

    if (object_id == NULL || source_path == NULL) {
        return FAILURE;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);
    if (king_object_store_mkdir_parents(file_path) != SUCCESS) {
        return FAILURE;
    }

    return king_object_store_copy_file_to_path(source_path, file_path);
}

static int king_object_store_local_fs_read_fallback_from_cloud_backup(
    const char *object_id,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata
)
{
    king_object_metadata_t sidecar_metadata;
    char backup_error[512] = {0};
    int rc;
    int mutation_lock_fd = -1;
    char lock_error[512] = {0};

    if (king_object_store_runtime.config.backup_backend != KING_STORAGE_BACKEND_CLOUD_S3) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }

    /*
     * Only heal from backup when the local metadata sidecar still says the
     * object logically exists. A true delete removes the sidecar, so stale
     * backup copies must not resurrect removed objects.
     */
    if (king_object_store_meta_read(object_id, &sidecar_metadata) != SUCCESS
        && king_object_store_runtime_metadata_cache_read(object_id, &sidecar_metadata) != SUCCESS) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }
    if (!king_object_store_metadata_matches_backend(
            object_id,
            &sidecar_metadata,
            KING_STORAGE_BACKEND_LOCAL_FS
        )) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }

    rc = king_object_store_s3_read_with_error(
        object_id,
        data,
        data_size,
        0,
        0,
        0,
        backup_error,
        sizeof(backup_error)
    );
    king_object_store_set_backend_runtime_result(
        "backup",
        king_object_store_runtime.config.backup_backend,
        rc,
        rc == SUCCESS ? NULL : backup_error
    );
    if (rc != SUCCESS) {
        return FAILURE;
    }

    if (sidecar_metadata.object_id[0] == '\0') {
        strncpy(sidecar_metadata.object_id, object_id, sizeof(sidecar_metadata.object_id) - 1);
    }
    sidecar_metadata.content_length = (uint64_t) *data_size;
    king_object_store_metadata_mark_backend_present(
        &sidecar_metadata,
        KING_STORAGE_BACKEND_LOCAL_FS,
        1
    );
    king_object_store_metadata_mark_backend_present(
        &sidecar_metadata,
        KING_STORAGE_BACKEND_CLOUD_S3,
        1
    );
    sidecar_metadata.is_backed_up = 1;

    if (metadata != NULL) {
        *metadata = sidecar_metadata;
    }

    if (king_object_store_acquire_object_lock(
            object_id,
            &mutation_lock_fd,
            lock_error,
            sizeof(lock_error)
        ) != SUCCESS) {
        return SUCCESS;
    }

    if (king_object_store_local_fs_restore_payload_from_backup(object_id, *data, *data_size) != SUCCESS) {
        king_object_store_append_adapter_error(
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error),
            "Primary object-store backend read fallback succeeded but local payload rehydration failed."
        );
        king_object_store_release_object_lock(&mutation_lock_fd);
        king_object_store_release_read_buffer(data, data_size);
        return FAILURE;
    }

    if (king_object_store_meta_write(object_id, &sidecar_metadata) != SUCCESS) {
        king_object_store_append_adapter_error(
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error),
            "Primary object-store backend read fallback succeeded but local metadata sidecar rehydration failed."
        );
        king_object_store_release_object_lock(&mutation_lock_fd);
        king_object_store_release_read_buffer(data, data_size);
        return FAILURE;
    }
    king_object_store_release_object_lock(&mutation_lock_fd);
    king_object_store_rehydrate_stats();
    return SUCCESS;
}

static int king_object_store_local_fs_read_fallback_from_cloud_backup_to_stream(
    const char *object_id,
    php_stream *destination_stream,
    size_t offset,
    size_t length,
    zend_bool has_length,
    king_object_metadata_t *metadata
)
{
    king_object_metadata_t sidecar_metadata;
    char backup_error[512] = {0};
    char temp_path[1024];
    int rc = FAILURE;
    int mutation_lock_fd = -1;
    char lock_error[512] = {0};

    if (destination_stream == NULL) {
        return FAILURE;
    }
    if (king_object_store_runtime.config.backup_backend != KING_STORAGE_BACKEND_CLOUD_S3) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }
    if (king_object_store_meta_read(object_id, &sidecar_metadata) != SUCCESS
        && king_object_store_runtime_metadata_cache_read(object_id, &sidecar_metadata) != SUCCESS) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }
    if (!king_object_store_metadata_matches_backend(
            object_id,
            &sidecar_metadata,
            KING_STORAGE_BACKEND_LOCAL_FS
        )) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }
    if (king_object_store_create_temp_file_path(temp_path, sizeof(temp_path)) != SUCCESS) {
        return FAILURE;
    }

    rc = king_object_store_s3_read_to_path_with_error(
        object_id,
        temp_path,
        offset,
        length,
        has_length,
        backup_error,
        sizeof(backup_error)
    );
    king_object_store_set_backend_runtime_result(
        "backup",
        king_object_store_runtime.config.backup_backend,
        rc,
        rc == SUCCESS ? NULL : backup_error
    );
    if (rc != SUCCESS) {
        unlink(temp_path);
        return FAILURE;
    }

    if (!has_length && offset == 0 && sidecar_metadata.integrity_sha256[0] != '\0') {
        char computed_sha256[65];
        uint64_t source_size = 0;

        if (king_object_store_compute_sha256_hex_for_path(temp_path, computed_sha256, &source_size) != SUCCESS
            || strcmp(computed_sha256, sidecar_metadata.integrity_sha256) != 0
            || source_size != sidecar_metadata.content_length) {
            unlink(temp_path);
            king_object_store_append_adapter_error(
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                "Primary object-store backend read fallback failed integrity validation."
            );
            return FAILURE;
        }

        king_object_store_metadata_mark_backend_present(&sidecar_metadata, KING_STORAGE_BACKEND_LOCAL_FS, 1);
        king_object_store_metadata_mark_backend_present(&sidecar_metadata, KING_STORAGE_BACKEND_CLOUD_S3, 1);
        sidecar_metadata.is_backed_up = 1;
        if (metadata != NULL) {
            *metadata = sidecar_metadata;
        }
        if (king_object_store_acquire_object_lock(
                object_id,
                &mutation_lock_fd,
                lock_error,
                sizeof(lock_error)
            ) == SUCCESS) {
            if (king_object_store_local_fs_restore_payload_from_path(object_id, temp_path) != SUCCESS) {
                unlink(temp_path);
                king_object_store_append_adapter_error(
                    king_object_store_runtime.primary_adapter_error,
                    sizeof(king_object_store_runtime.primary_adapter_error),
                    "Primary object-store backend read fallback succeeded but local payload rehydration failed."
                );
                king_object_store_release_object_lock(&mutation_lock_fd);
                return FAILURE;
            }
            if (king_object_store_meta_write(object_id, &sidecar_metadata) != SUCCESS) {
                unlink(temp_path);
                king_object_store_append_adapter_error(
                    king_object_store_runtime.primary_adapter_error,
                    sizeof(king_object_store_runtime.primary_adapter_error),
                    "Primary object-store backend read fallback succeeded but local metadata sidecar rehydration failed."
                );
                king_object_store_release_object_lock(&mutation_lock_fd);
                return FAILURE;
            }
            king_object_store_release_object_lock(&mutation_lock_fd);
            king_object_store_rehydrate_stats();
        }
    }

    rc = king_object_store_copy_path_to_stream(temp_path, destination_stream, 0, 0, 0);
    unlink(temp_path);
    return rc;
}
