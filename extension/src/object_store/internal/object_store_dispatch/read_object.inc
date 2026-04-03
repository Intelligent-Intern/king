int king_object_store_read_object(
    const char *object_id,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata)
{
    int rc = FAILURE;
    king_object_metadata_t visibility_metadata;
    zend_bool visibility_metadata_loaded = 0;
    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Invalid object ID for object-store read."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "get operations") == FAILURE) {
        return KING_OBJECT_STORE_RESULT_UNAVAILABLE;
    }

    if (king_object_store_read_visibility_metadata(object_id, &visibility_metadata) == SUCCESS) {
        visibility_metadata_loaded = 1;
        if (king_object_store_metadata_is_expired_now(&visibility_metadata)) {
            if (metadata != NULL) {
                *metadata = visibility_metadata;
            }
            return king_object_store_fail_expired_visibility("read");
        }
    }

    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_read(object_id, data, data_size, metadata);
            if (rc != SUCCESS && king_object_store_local_fs_payload_is_missing(object_id)) {
                int fallback_rc = king_object_store_local_fs_read_fallback_from_cloud_backup(
                    object_id,
                    data,
                    data_size,
                    metadata
                );

                if (fallback_rc != KING_OBJECT_STORE_RESULT_MISS) {
                    rc = fallback_rc;
                }
            }
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_read_internal(
                object_id,
                data,
                data_size,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_read(object_id, data, data_size);
            if (rc == SUCCESS && metadata != NULL) {
                if (visibility_metadata_loaded) {
                    *metadata = visibility_metadata;
                } else {
                    rc = king_object_store_backend_read_metadata(object_id, metadata);
                }
            }
            if (rc == SUCCESS && metadata != NULL && !king_object_store_verify_integrity_buffer(*data, *data_size, metadata)) {
                king_object_store_release_read_buffer(data, data_size);
                king_object_store_set_backend_runtime_result(
                    "primary",
                    king_object_store_runtime.config.primary_backend,
                    FAILURE,
                    "Primary object-store backend integrity validation failed during read."
                );
                rc = FAILURE;
            }
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_read(object_id, data, data_size);
            if (rc == SUCCESS && metadata != NULL) {
                if (visibility_metadata_loaded) {
                    *metadata = visibility_metadata;
                } else {
                    rc = king_object_store_backend_read_metadata(object_id, metadata);
                }
            }
            if (rc == SUCCESS && metadata != NULL && !king_object_store_verify_integrity_buffer(*data, *data_size, metadata)) {
                king_object_store_release_read_buffer(data, data_size);
                king_object_store_set_backend_runtime_result(
                    "primary",
                    king_object_store_runtime.config.primary_backend,
                    FAILURE,
                    "Primary object-store backend integrity validation failed during read."
                );
                rc = FAILURE;
            }
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_read(object_id, data, data_size);
            if (rc == SUCCESS && metadata != NULL) {
                if (visibility_metadata_loaded) {
                    *metadata = visibility_metadata;
                } else {
                    rc = king_object_store_backend_read_metadata(object_id, metadata);
                }
            }
            if (rc == SUCCESS && metadata != NULL && !king_object_store_verify_integrity_buffer(*data, *data_size, metadata)) {
                king_object_store_release_read_buffer(data, data_size);
                king_object_store_set_backend_runtime_result(
                    "primary",
                    king_object_store_runtime.config.primary_backend,
                    FAILURE,
                    "Primary object-store backend integrity validation failed during read."
                );
                rc = FAILURE;
            }
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
    king_object_store_finalize_backend_runtime_result(
        "primary",
        king_object_store_runtime.config.primary_backend,
        rc,
        "Primary object-store backend read failed."
    );
    return rc;
}

