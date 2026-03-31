/* --- Replication (runtime: local_fs single file = replica 1) --- */

int king_object_store_replicate_object(const char *object_id, uint32_t replication_factor)
{
    king_object_metadata_t metadata;
    uint32_t achieved_real_copies;
    char replication_error[256];

    if (replication_factor == 0) {
        return SUCCESS;
    }

    if (object_id == NULL || object_id[0] == '\0') {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Object-store replication requires a valid object id."
        );
        return FAILURE;
    }

    if (king_object_store_meta_read(object_id, &metadata) != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Object-store replication could not load metadata for the stored object."
        );
        return FAILURE;
    }

    achieved_real_copies = king_object_store_count_achieved_real_copies(&metadata);

    if (achieved_real_copies >= replication_factor) {
        metadata.replication_status = 2; /* 2: Completed */
        (void) king_object_store_meta_write(object_id, &metadata);
        return SUCCESS;
    }

    metadata.replication_status = 3; /* 3: Failed */
    (void) king_object_store_meta_write(object_id, &metadata);

    snprintf(
        replication_error,
        sizeof(replication_error),
        "Object-store replication requested replication_factor %u but runtime achieved only %u real copies.",
        replication_factor,
        achieved_real_copies
    );
    king_object_store_set_backend_runtime_result(
        "primary",
        king_object_store_runtime.config.primary_backend,
        FAILURE,
        replication_error
    );
    return FAILURE;
}

static int king_object_store_backup_object_to_backend(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata,
    king_storage_backend_t backup_backend)
{
    int rc = FAILURE;
    king_object_metadata_t metadata_snapshot;
    char backup_error[512] = {0};

    if (object_id == NULL || object_id[0] == '\0' || data == NULL) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            "Missing object payload for backup operation."
        );
        return FAILURE;
    }

    if (!king_object_store_runtime.initialized) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            "Object store is not initialized."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend(
            "backup",
            backup_backend,
            "object backup") == FAILURE) {
        return FAILURE;
    }

    switch (backup_backend) {
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_write_internal(
                object_id,
                data,
                data_size,
                metadata,
                0,
                backup_error,
                sizeof(backup_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_write(
                object_id,
                data,
                data_size,
                metadata,
                backup_error,
                sizeof(backup_error),
                0
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_write(
                object_id,
                data,
                data_size,
                metadata,
                backup_error,
                sizeof(backup_error),
                0
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_write(
                object_id,
                data,
                data_size,
                metadata,
                backup_error,
                sizeof(backup_error),
                0
            );
            break;
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            king_object_store_set_backend_runtime_result(
                "backup",
                backup_backend,
                FAILURE,
                "Local backup backends are not implemented for a non-local primary object-store backend."
            );
            return FAILURE;
        default:
            king_object_store_set_backend_runtime_result(
                "backup",
                backup_backend,
                FAILURE,
                "Unsupported backup object-store backend."
            );
            return FAILURE;
    }

    if (rc != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            backup_error[0] == '\0' ? "Backup object-store backend write failed." : backup_error
        );
        return FAILURE;
    }

    if (king_object_store_backend_read_metadata(object_id, &metadata_snapshot) == SUCCESS) {
        if (metadata_snapshot.is_backed_up == 0) {
            metadata_snapshot.is_backed_up = 1;
        }
        king_object_store_metadata_mark_backend_present(
            &metadata_snapshot,
            king_object_store_runtime.config.primary_backend,
            1
        );
        king_object_store_metadata_mark_backend_present(
            &metadata_snapshot,
            backup_backend,
            1
        );
        king_object_store_meta_write(object_id, &metadata_snapshot);
    }

    king_object_store_set_backend_runtime_result(
        "backup",
        backup_backend,
        SUCCESS,
        NULL
    );
    return SUCCESS;
}

static int king_object_store_remove_backup_object_from_backend(
    const char *object_id,
    king_storage_backend_t backup_backend)
{
    int rc = FAILURE;
    char backup_error[512] = {0};
    zend_bool removed = 0;

    if (object_id == NULL || object_id[0] == '\0') {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            "Missing object id for backup delete operation."
        );
        return FAILURE;
    }

    if (!king_object_store_runtime.initialized) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            "Object store is not initialized."
        );
        return FAILURE;
    }

    if (backup_backend == king_object_store_runtime.config.primary_backend) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            SUCCESS,
            NULL
        );
        return SUCCESS;
    }

    if (king_object_store_require_honest_backend(
            "backup",
            backup_backend,
            "object delete"
        ) == FAILURE) {
        return FAILURE;
    }

    switch (backup_backend) {
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_remove_internal(
                object_id,
                0,
                0,
                backup_error,
                sizeof(backup_error)
            );
            if (rc == KING_OBJECT_STORE_RESULT_MISS) {
                rc = SUCCESS;
            }
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_delete_backup_copy(
                object_id,
                backup_error,
                sizeof(backup_error),
                &removed
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_delete_backup_copy(
                object_id,
                backup_error,
                sizeof(backup_error),
                &removed
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_delete_backup_copy(
                object_id,
                backup_error,
                sizeof(backup_error),
                &removed
            );
            break;
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            king_object_store_set_backend_runtime_result(
                "backup",
                backup_backend,
                FAILURE,
                "Local backup backends are not implemented for a non-local primary object-store backend."
            );
            return FAILURE;
        default:
            king_object_store_set_backend_runtime_result(
                "backup",
                backup_backend,
                FAILURE,
                "Unsupported backup object-store backend."
            );
            return FAILURE;
    }

    if (rc != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            backup_error[0] == '\0' ? "Backup object-store backend delete failed." : backup_error
        );
        return FAILURE;
    }

    king_object_store_set_backend_runtime_result(
        "backup",
        backup_backend,
        SUCCESS,
        NULL
    );
    return SUCCESS;
}

static int king_object_store_local_fs_remove_with_real_backup_semantics(const char *object_id)
{
    king_object_metadata_t metadata_snapshot;
    zend_bool has_metadata = 0;
    int payload_exists;
    king_storage_backend_t backup_backend;
    int logical_exists;
    char primary_error[512];

    if (object_id == NULL || object_id[0] == '\0') {
        return FAILURE;
    }

    backup_backend = king_object_store_normalize_backend(
        king_object_store_runtime.config.backup_backend
    );
    if (backup_backend == king_object_store_normalize_backend(king_object_store_runtime.config.primary_backend)
        || !king_object_store_backend_is_real(backup_backend)) {
        return king_object_store_local_fs_remove(object_id);
    }
    if (!king_object_store_local_fs_root_available("delete")) {
        return FAILURE;
    }

    payload_exists = king_object_store_local_fs_payload_exists(object_id);
    if (king_object_store_meta_read(object_id, &metadata_snapshot) == SUCCESS
        || king_object_store_runtime_metadata_cache_read(object_id, &metadata_snapshot) == SUCCESS) {
        has_metadata = 1;
    }

    logical_exists = payload_exists || has_metadata;
    if (!logical_exists) {
        return KING_OBJECT_STORE_RESULT_MISS;
    }

    if (king_object_store_remove_backup_object_from_backend(object_id, backup_backend) != SUCCESS) {
        snprintf(
            primary_error,
            sizeof(primary_error),
            "local_fs delete for '%s' could not complete because backup removal failed: %s",
            object_id,
            king_object_store_runtime.backup_adapter_error[0] != '\0'
                ? king_object_store_runtime.backup_adapter_error
                : "backup delete failed."
        );
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            primary_error
        );
        return FAILURE;
    }

    if (has_metadata) {
        if (backup_backend == KING_STORAGE_BACKEND_DISTRIBUTED
            || backup_backend == KING_STORAGE_BACKEND_CLOUD_S3
            || backup_backend == KING_STORAGE_BACKEND_CLOUD_GCS
            || backup_backend == KING_STORAGE_BACKEND_CLOUD_AZURE) {
            metadata_snapshot.is_backed_up = 0;
        }
        king_object_store_metadata_mark_backend_present(
            &metadata_snapshot,
            backup_backend,
            0
        );
        king_object_store_reconcile_replication_status(&metadata_snapshot);
        if (king_object_store_meta_write(object_id, &metadata_snapshot) != SUCCESS) {
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Primary object-store delete removed the backup copy but failed to update local metadata."
            );
            return FAILURE;
        }
    }

    if (payload_exists) {
        return king_object_store_local_fs_remove(object_id);
    }

    king_object_store_meta_remove(object_id);
    king_object_store_invalidate_cdn_cache_entry(object_id);
    return SUCCESS;
}

