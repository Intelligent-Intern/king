int king_object_store_read_object_range(
    const char *object_id,
    size_t offset,
    size_t length,
    zend_bool has_length,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata
)
{
    int rc = FAILURE;
    king_object_metadata_t visibility_metadata;

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Invalid object ID for ranged object-store read."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "range get operations") == FAILURE) {
        return KING_OBJECT_STORE_RESULT_UNAVAILABLE;
    }

    if (king_object_store_read_visibility_metadata(object_id, &visibility_metadata) == SUCCESS
        && king_object_store_metadata_is_expired_now(&visibility_metadata)) {
        if (metadata != NULL) {
            *metadata = visibility_metadata;
        }
        return king_object_store_fail_expired_visibility("range read");
    }

    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_read_range(
                object_id,
                offset,
                length,
                has_length,
                data,
                data_size,
                metadata
            );
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_read_range_internal(
                object_id,
                offset,
                length,
                has_length,
                data,
                data_size,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_read_range(
                object_id,
                offset,
                length,
                has_length,
                data,
                data_size,
                metadata
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_read_range(
                object_id,
                offset,
                length,
                has_length,
                data,
                data_size,
                metadata
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_read_range(
                object_id,
                offset,
                length,
                has_length,
                data,
                data_size,
                metadata
            );
            break;
        default:
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Unsupported primary object-store backend for range reads."
            );
            return FAILURE;
    }

    king_object_store_finalize_backend_runtime_result(
        "primary",
        king_object_store_runtime.config.primary_backend,
        rc,
        "Primary object-store backend ranged read failed."
    );
    return rc;
}

