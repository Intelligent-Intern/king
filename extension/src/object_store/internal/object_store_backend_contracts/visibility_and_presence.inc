/*
 * Backend-contract helpers for object-store metadata. Tracks per-backend
 * presence bits, adapter status and contract reporting across the mixed local,
 * distributed and cloud surfaces.
 */

static void king_object_store_metadata_mark_backend_present(
    king_object_metadata_t *metadata,
    king_storage_backend_t backend,
    uint8_t present
)
{
    if (metadata == NULL) {
        return;
    }

    backend = king_object_store_normalize_backend(backend);
    if (backend == KING_STORAGE_BACKEND_LOCAL_FS) {
        metadata->local_fs_present = present;
    } else if (backend == KING_STORAGE_BACKEND_DISTRIBUTED) {
        metadata->distributed_present = present;
    } else if (backend == KING_STORAGE_BACKEND_CLOUD_S3) {
        metadata->cloud_s3_present = present;
    } else if (backend == KING_STORAGE_BACKEND_CLOUD_GCS) {
        metadata->cloud_gcs_present = present;
    } else if (backend == KING_STORAGE_BACKEND_CLOUD_AZURE) {
        metadata->cloud_azure_present = present;
    }
}

zend_bool king_object_store_metadata_is_expired_at(
    const king_object_metadata_t *metadata,
    time_t now
)
{
    if (metadata == NULL || metadata->expires_at <= 0) {
        return 0;
    }

    return now >= metadata->expires_at ? 1 : 0;
}

zend_bool king_object_store_metadata_is_expired_now(
    const king_object_metadata_t *metadata
)
{
    return king_object_store_metadata_is_expired_at(metadata, time(NULL));
}

static int king_object_store_read_visibility_metadata(
    const char *object_id,
    king_object_metadata_t *metadata
)
{
    king_storage_backend_t primary_backend;
    struct stat root_stat;

    if (object_id == NULL || metadata == NULL) {
        return FAILURE;
    }

    primary_backend = king_object_store_normalize_backend(
        king_object_store_runtime.config.primary_backend
    );
    if (primary_backend == KING_STORAGE_BACKEND_LOCAL_FS
        || primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE) {
        if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
            return FAILURE;
        }
        if (stat(king_object_store_runtime.config.storage_root_path, &root_stat) != 0
            || !S_ISDIR(root_stat.st_mode)) {
            if (king_object_store_runtime_metadata_cache_read(object_id, metadata) == SUCCESS) {
                return SUCCESS;
            }
            return FAILURE;
        }
    }

    if (king_object_store_backend_read_metadata(object_id, metadata) == SUCCESS) {
        return SUCCESS;
    }

    if ((primary_backend == KING_STORAGE_BACKEND_LOCAL_FS
            || primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE)
        && king_object_store_runtime_metadata_cache_read(object_id, metadata) == SUCCESS) {
        return SUCCESS;
    }

    return FAILURE;
}

static int king_object_store_fail_expired_visibility(const char *operation)
{
    (void) operation;
    return KING_OBJECT_STORE_RESULT_MISS;
}

static void king_object_store_set_local_failure_error(
    const char *operation,
    const char *object_id,
    const char *detail
)
{
    if (operation == NULL || operation[0] == '\0') {
        operation = "operation";
    }

    if (object_id != NULL && object_id[0] != '\0') {
        snprintf(
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error),
            "local_fs %s for '%s' failed: %s",
            operation,
            object_id,
            detail != NULL && detail[0] != '\0' ? detail : "unknown local filesystem failure."
        );
        return;
    }

    snprintf(
        king_object_store_runtime.primary_adapter_error,
        sizeof(king_object_store_runtime.primary_adapter_error),
        "local_fs %s failed: %s",
        operation,
        detail != NULL && detail[0] != '\0' ? detail : "unknown local filesystem failure."
    );
}

static void king_object_store_set_backend_filesystem_error(
    char *destination,
    size_t destination_size,
    const char *backend_name,
    const char *operation,
    const char *object_id,
    const char *detail
)
{
    const char *safe_backend = backend_name != NULL && backend_name[0] != '\0'
        ? backend_name
        : "backend";
    const char *safe_operation = operation != NULL && operation[0] != '\0'
        ? operation
        : "operation";
    const char *safe_detail = detail != NULL && detail[0] != '\0'
        ? detail
        : "unknown filesystem failure.";

    if (destination == NULL || destination_size == 0) {
        return;
    }

    if (object_id != NULL && object_id[0] != '\0') {
        snprintf(
            destination,
            destination_size,
            "%s %s for '%s' failed: %s",
            safe_backend,
            safe_operation,
            object_id,
            safe_detail
        );
        return;
    }

    snprintf(
        destination,
        destination_size,
        "%s %s failed: %s",
        safe_backend,
        safe_operation,
        safe_detail
    );
}

static int king_object_store_local_fs_root_available(const char *operation)
{
    struct stat root_stat;

    if (operation == NULL || operation[0] == '\0') {
        operation = "operation";
    }

    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        king_object_store_set_local_failure_error(operation, NULL, "the configured storage_root_path is empty.");
        return 0;
    }

    if (stat(king_object_store_runtime.config.storage_root_path, &root_stat) != 0) {
        king_object_store_set_local_failure_error(operation, NULL, strerror(errno));
        return 0;
    }

    if (!S_ISDIR(root_stat.st_mode)) {
        king_object_store_set_local_failure_error(operation, NULL, "the configured storage_root_path is not a directory.");
        return 0;
    }

    return 1;
}

static int king_object_store_local_fs_payload_exists(const char *object_id)
{
    char file_path[1024];
    struct stat st;

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        return 0;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);
    if (stat(file_path, &st) != 0) {
        return 0;
    }

    return S_ISREG(st.st_mode) ? 1 : 0;
}

static int king_object_store_distributed_payload_exists(const char *object_id)
{
    char file_path[PATH_MAX];
    struct stat st;

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        return 0;
    }

    if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
        return 0;
    }
    if (stat(file_path, &st) != 0) {
        return 0;
    }

    return S_ISREG(st.st_mode) ? 1 : 0;
}

static int king_object_store_metadata_matches_backend(
    const char *object_id,
    const king_object_metadata_t *metadata,
    king_storage_backend_t backend
)
{
    if (metadata == NULL) {
        return 0;
    }

    backend = king_object_store_normalize_backend(backend);

    if (backend == KING_STORAGE_BACKEND_LOCAL_FS) {
        if (metadata->local_fs_present != 0) {
            return 1;
        }

        /* Legacy sidecars without explicit route markers stay readable when
         * the local payload still exists or when the object is known to have
         * a backed-up failover copy for the local_fs primary contract.
         */
        return king_object_store_local_fs_payload_exists(object_id)
            || metadata->is_backed_up != 0;
    }

    if (backend == KING_STORAGE_BACKEND_DISTRIBUTED) {
        return metadata->distributed_present != 0;
    }

    if (backend == KING_STORAGE_BACKEND_CLOUD_S3) {
        if (metadata->cloud_s3_present != 0) {
            return 1;
        }

        /* Legacy local_fs sidecars that were also backed up can still prove a
         * cloud-visible copy. Pure legacy cloud_s3 sidecars will miss here and
         * be rehydrated from a fresh HEAD request instead of leaking local
         * route state into the cloud path.
         */
        return metadata->is_backed_up != 0;
    }

    if (backend == KING_STORAGE_BACKEND_CLOUD_GCS) {
        return metadata->cloud_gcs_present != 0;
    }

    if (backend == KING_STORAGE_BACKEND_CLOUD_AZURE) {
        return metadata->cloud_azure_present != 0;
    }

    return 0;
}

static int king_object_store_metadata_counts_toward_primary_inventory(
    const char *object_id,
    const king_object_metadata_t *metadata,
    king_storage_backend_t backend
)
{
    backend = king_object_store_normalize_backend(backend);

    if (!king_object_store_metadata_matches_backend(object_id, metadata, backend)) {
        return 0;
    }

    if (backend == KING_STORAGE_BACKEND_LOCAL_FS) {
        return king_object_store_local_fs_payload_exists(object_id);
    }
    if (backend == KING_STORAGE_BACKEND_DISTRIBUTED) {
        return king_object_store_distributed_payload_exists(object_id);
    }

    return 1;
}

