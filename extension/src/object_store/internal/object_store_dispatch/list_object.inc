int king_object_store_list_object(zval *return_array)
{
    int rc = FAILURE;

    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "list operations") == FAILURE) {
        return KING_OBJECT_STORE_RESULT_UNAVAILABLE;
    }

    switch (king_object_store_runtime.config.primary_backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            rc = king_object_store_local_fs_list(return_array);
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_list_internal(
                return_array,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_list(return_array);
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_list(return_array);
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_list(return_array);
            break;
        default:
            king_object_store_set_backend_runtime_result(
                "primary",
                king_object_store_runtime.config.primary_backend,
                FAILURE,
                "Unsupported primary object-store backend."
            );
            rc = FAILURE;
            break;
    }

    king_object_store_finalize_backend_runtime_result(
        "primary",
        king_object_store_runtime.config.primary_backend,
        rc,
        "Primary object-store backend list failed."
    );
    return rc;
}
