int king_object_store_remove_object(const char *object_id)
{
    int rc = FAILURE;
    int mutation_lock_fd = -1;
    char lock_error[512] = {0};

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Invalid object ID for object-store remove."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "delete operations") == FAILURE) {
        return KING_OBJECT_STORE_RESULT_UNAVAILABLE;
    }
    if ((king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_LOCAL_FS
            || king_object_store_runtime.config.primary_backend == KING_STORAGE_BACKEND_MEMORY_CACHE)
        && !king_object_store_local_fs_root_available("delete")) {
        king_object_store_finalize_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Primary object-store backend remove failed."
        );
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
            lock_error[0] != '\0' ? lock_error : "Failed to acquire object-store mutation lock for delete."
        );
        if (strstr(lock_error, "active mutation") != NULL) {
            return KING_OBJECT_STORE_RESULT_CONFLICT;
        }
        return FAILURE;
    }

    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_remove_with_real_backup_semantics(object_id);
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_remove_with_real_backup_semantics(object_id);
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_remove(object_id);
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_remove(object_id);
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_remove(object_id);
            break;
        default:
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Unsupported primary object-store backend."
            );
            king_object_store_release_object_lock(&mutation_lock_fd);
            return FAILURE;
    }
    king_object_store_finalize_backend_runtime_result(
        "primary",
        king_object_store_runtime.config.primary_backend,
        rc,
        "Primary object-store backend remove failed."
    );
    king_object_store_release_object_lock(&mutation_lock_fd);
    return rc;
}

