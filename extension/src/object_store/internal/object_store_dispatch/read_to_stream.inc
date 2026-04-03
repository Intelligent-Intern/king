int king_object_store_read_object_to_stream(
    const char *object_id,
    php_stream *destination_stream,
    size_t offset,
    size_t length,
    zend_bool has_length,
    king_object_metadata_t *metadata
)
{
    int rc = FAILURE;
    char temp_path[1024];
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
            rc = king_object_store_local_fs_read_to_stream(
                object_id,
                destination_stream,
                offset,
                length,
                has_length,
                metadata
            );
            if (rc != SUCCESS && king_object_store_local_fs_payload_is_missing(object_id)) {
                int fallback_rc = king_object_store_local_fs_read_fallback_from_cloud_backup_to_stream(
                    object_id,
                    destination_stream,
                    offset,
                    length,
                    has_length,
                    metadata
                );

                if (fallback_rc != KING_OBJECT_STORE_RESULT_MISS) {
                    rc = fallback_rc;
                }
            }
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_read_to_stream_internal(
                object_id,
                destination_stream,
                offset,
                length,
                has_length,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            if (king_object_store_create_temp_file_path(temp_path, sizeof(temp_path)) != SUCCESS) {
                return FAILURE;
            }
            rc = king_object_store_s3_read_to_path_with_error(
                object_id,
                temp_path,
                offset,
                length,
                has_length,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            if (rc == SUCCESS && metadata != NULL && !has_length && offset == 0) {
                if (visibility_metadata_loaded) {
                    *metadata = visibility_metadata;
                } else if (king_object_store_backend_read_metadata(object_id, metadata) != SUCCESS) {
                    rc = FAILURE;
                }
                if (rc == SUCCESS && metadata->integrity_sha256[0] != '\0') {
                    char computed_sha256[65];
                    uint64_t source_size = 0;
                    if (king_object_store_compute_sha256_hex_for_path(temp_path, computed_sha256, &source_size) != SUCCESS
                        || strcmp(computed_sha256, metadata->integrity_sha256) != 0
                        || source_size != metadata->content_length) {
                        king_object_store_set_backend_runtime_result(
                            "primary",
                            king_object_store_runtime.config.primary_backend,
                            FAILURE,
                            "Primary object-store backend integrity validation failed during read."
                        );
                        rc = FAILURE;
                    }
                }
            }
            if (rc == SUCCESS) {
                rc = king_object_store_copy_path_to_stream(temp_path, destination_stream, 0, 0, 0);
            }
            unlink(temp_path);
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            if (king_object_store_create_temp_file_path(temp_path, sizeof(temp_path)) != SUCCESS) {
                return FAILURE;
            }
            rc = king_object_store_gcs_read_to_path_with_error(
                object_id,
                temp_path,
                offset,
                length,
                has_length,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            if (rc == SUCCESS && metadata != NULL && !has_length && offset == 0) {
                if (visibility_metadata_loaded) {
                    *metadata = visibility_metadata;
                } else if (king_object_store_backend_read_metadata(object_id, metadata) != SUCCESS) {
                    rc = FAILURE;
                }
                if (rc == SUCCESS && metadata->integrity_sha256[0] != '\0') {
                    char computed_sha256[65];
                    uint64_t source_size = 0;
                    if (king_object_store_compute_sha256_hex_for_path(temp_path, computed_sha256, &source_size) != SUCCESS
                        || strcmp(computed_sha256, metadata->integrity_sha256) != 0
                        || source_size != metadata->content_length) {
                        king_object_store_set_backend_runtime_result(
                            "primary",
                            king_object_store_runtime.config.primary_backend,
                            FAILURE,
                            "Primary object-store backend integrity validation failed during read."
                        );
                        rc = FAILURE;
                    }
                }
            }
            if (rc == SUCCESS) {
                rc = king_object_store_copy_path_to_stream(temp_path, destination_stream, 0, 0, 0);
            }
            unlink(temp_path);
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            if (king_object_store_create_temp_file_path(temp_path, sizeof(temp_path)) != SUCCESS) {
                return FAILURE;
            }
            rc = king_object_store_azure_read_to_path_with_error(
                object_id,
                temp_path,
                offset,
                length,
                has_length,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            if (rc == SUCCESS && metadata != NULL && !has_length && offset == 0) {
                if (visibility_metadata_loaded) {
                    *metadata = visibility_metadata;
                } else if (king_object_store_backend_read_metadata(object_id, metadata) != SUCCESS) {
                    rc = FAILURE;
                }
                if (rc == SUCCESS && metadata->integrity_sha256[0] != '\0') {
                    char computed_sha256[65];
                    uint64_t source_size = 0;
                    if (king_object_store_compute_sha256_hex_for_path(temp_path, computed_sha256, &source_size) != SUCCESS
                        || strcmp(computed_sha256, metadata->integrity_sha256) != 0
                        || source_size != metadata->content_length) {
                        king_object_store_set_backend_runtime_result(
                            "primary",
                            king_object_store_runtime.config.primary_backend,
                            FAILURE,
                            "Primary object-store backend integrity validation failed during read."
                        );
                        rc = FAILURE;
                    }
                }
            }
            if (rc == SUCCESS) {
                rc = king_object_store_copy_path_to_stream(temp_path, destination_stream, 0, 0, 0);
            }
            unlink(temp_path);
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

