/* --- Backend-routing dispatch --- */

int king_object_store_write_object_from_file(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata
)
{
    int rc = FAILURE;
    struct stat source_stat;
    char capacity_error[256];

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Invalid object ID for object-store write."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "put operations") == FAILURE) {
        return KING_OBJECT_STORE_RESULT_UNAVAILABLE;
    }

    if (stat(source_path, &source_stat) != 0 || !S_ISREG(source_stat.st_mode)) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Primary object-store write source path is unavailable."
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
        return FAILURE;
    }

    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_write_from_file(object_id, source_path, metadata);
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_write_from_file_internal(
                object_id,
                source_path,
                metadata,
                1,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_write_from_file(
                object_id,
                source_path,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                1
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_write_from_file(
                object_id,
                source_path,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                1
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_write_from_file(
                object_id,
                source_path,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                1
            );
            break;
        default:
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Unsupported primary object-store backend."
            );
            return FAILURE;
    }

    king_object_store_set_backend_runtime_result(
        "primary",
        king_object_store_runtime.config.primary_backend,
        rc,
        rc == SUCCESS ? NULL : "Primary object-store backend write failed."
    );

    if (rc == SUCCESS &&
        king_object_store_runtime.config.backup_backend != king_object_store_runtime.config.primary_backend) {
        if (king_object_store_backup_object_from_file_to_backend(
                object_id,
                source_path,
                metadata,
                king_object_store_runtime.config.backup_backend
            ) != SUCCESS) {
            if (king_object_store_runtime.config.replication_factor > 0) {
                king_object_store_update_replication_status(object_id, 3);
            }
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Primary object-store write failed because backup operation failed."
            );
            return FAILURE;
        }
    }

    if (rc == SUCCESS && king_object_store_runtime.config.replication_factor > 0) {
        if (king_object_store_replicate_object(object_id, king_object_store_runtime.config.replication_factor) != SUCCESS) {
            if (king_object_store_runtime.primary_adapter_error[0] == '\0') {
                king_object_store_set_backend_runtime_result(
                    "primary",
                    king_object_store_runtime.config.primary_backend,
                    FAILURE,
                    "Primary object-store write failed because replication target was not reached."
                );
            }
            return FAILURE;
        }
    }

    return rc;
}

