/*
 * Backend-metadata bridge. Reads and writes metadata across the currently
 * active backend and reconciles provider payload paths with local metadata
 * snapshots.
 */

int king_object_store_backend_read_metadata(
    const char *object_id,
    king_object_metadata_t *metadata)
{
    char error[256];
    zend_bool exists = 0;
    king_storage_backend_t active_backend;
    char payload_path[1024];
    struct stat payload_stat;
    struct stat root_stat;

    if (metadata == NULL || object_id == NULL) {
        return FAILURE;
    }

    error[0] = '\0';

    active_backend = king_object_store_runtime.config.primary_backend;
    if (active_backend == KING_STORAGE_BACKEND_MEMORY_CACHE) {
        active_backend = KING_STORAGE_BACKEND_LOCAL_FS;
    }

    if ((active_backend == KING_STORAGE_BACKEND_LOCAL_FS
            || active_backend == KING_STORAGE_BACKEND_MEMORY_CACHE)
        && (king_object_store_runtime.config.storage_root_path[0] == '\0'
            || stat(king_object_store_runtime.config.storage_root_path, &root_stat) != 0
            || !S_ISDIR(root_stat.st_mode))) {
        snprintf(
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error),
            "local_fs metadata read failed: %s",
            king_object_store_runtime.config.storage_root_path[0] == '\0'
                ? "the configured storage_root_path is empty."
                : (
                    stat(king_object_store_runtime.config.storage_root_path, &root_stat) != 0
                        ? strerror(errno)
                        : "the configured storage_root_path is not a directory."
                )
        );
        return FAILURE;
    }
    if (active_backend == KING_STORAGE_BACKEND_DISTRIBUTED
        && !king_object_store_distributed_root_available(
            "metadata read",
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error)
        )) {
        return FAILURE;
    }

    if (king_object_store_meta_read(object_id, metadata) == SUCCESS) {
        if (king_object_store_metadata_matches_backend(object_id, metadata, active_backend)) {
            return SUCCESS;
        }

        if (active_backend != KING_STORAGE_BACKEND_CLOUD_S3
            && active_backend != KING_STORAGE_BACKEND_CLOUD_GCS
            && active_backend != KING_STORAGE_BACKEND_CLOUD_AZURE) {
            if (active_backend == KING_STORAGE_BACKEND_DISTRIBUTED) {
                if (king_object_store_build_distributed_path(payload_path, sizeof(payload_path), object_id) != SUCCESS) {
                    return FAILURE;
                }
            } else {
                king_object_store_build_path(payload_path, sizeof(payload_path), object_id);
            }
            if (stat(payload_path, &payload_stat) == 0 && S_ISREG(payload_stat.st_mode)) {
                snprintf(
                    king_object_store_runtime.primary_adapter_error,
                    sizeof(king_object_store_runtime.primary_adapter_error),
                    "%s metadata for '%s' is unavailable even though the payload still exists.",
                    active_backend == KING_STORAGE_BACKEND_DISTRIBUTED ? "distributed" : "local_fs",
                    object_id
                );
                return FAILURE;
            }
            return KING_OBJECT_STORE_RESULT_MISS;
        }
    }

    /*
     * For local_fs primary plus cloud_s3 backup, the process-local metadata
     * cache is the live bridge during a temporary primary-root outage. It is
     * not a restart contract; init_system repopulates it only from real
     * durable sidecars.
     */
    if ((king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_LOCAL_FS
            || king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE)
        && king_object_store_runtime.config.backup_backend == KING_STORAGE_BACKEND_CLOUD_S3
        && king_object_store_runtime_metadata_cache_read(object_id, metadata) == SUCCESS
        && king_object_store_metadata_matches_backend(
            object_id,
            metadata,
            KING_STORAGE_BACKEND_LOCAL_FS
        )) {
        return SUCCESS;
    }

    if (active_backend == KING_STORAGE_BACKEND_CLOUD_S3) {
        if (king_object_store_s3_head_object(object_id, &exists, metadata, error, sizeof(error)) != SUCCESS) {
            snprintf(
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                "%s",
                error
            );
            return FAILURE;
        }
        if (!exists) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }

        king_object_store_metadata_mark_backend_present(
            metadata,
            KING_STORAGE_BACKEND_CLOUD_S3,
            1
        );
        (void) king_object_store_meta_write(object_id, metadata);
        return SUCCESS;
    }

    if (active_backend == KING_STORAGE_BACKEND_CLOUD_GCS) {
        if (king_object_store_gcs_head_object(object_id, &exists, metadata, error, sizeof(error)) != SUCCESS) {
            snprintf(
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                "%s",
                error
            );
            return FAILURE;
        }
        if (!exists) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }

        king_object_store_metadata_mark_backend_present(
            metadata,
            KING_STORAGE_BACKEND_CLOUD_GCS,
            1
        );
        (void) king_object_store_meta_write(object_id, metadata);
        return SUCCESS;
    }

    if (active_backend == KING_STORAGE_BACKEND_CLOUD_AZURE) {
        if (king_object_store_azure_head_object(object_id, &exists, metadata, error, sizeof(error)) != SUCCESS) {
            snprintf(
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                "%s",
                error
            );
            return FAILURE;
        }
        if (!exists) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }

        king_object_store_metadata_mark_backend_present(
            metadata,
            KING_STORAGE_BACKEND_CLOUD_AZURE,
            1
        );
        (void) king_object_store_meta_write(object_id, metadata);
        return SUCCESS;
    }

    if (active_backend != KING_STORAGE_BACKEND_CLOUD_S3
        && active_backend != KING_STORAGE_BACKEND_CLOUD_GCS
        && active_backend != KING_STORAGE_BACKEND_CLOUD_AZURE) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }

    return FAILURE;
}
