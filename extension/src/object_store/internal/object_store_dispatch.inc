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

int king_object_store_write_object(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata)
{
    int rc = FAILURE;
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

    capacity_error[0] = '\0';
    if (king_object_store_runtime_capacity_check_object_size(
            object_id,
            (uint64_t) data_size,
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
            rc = king_object_store_local_fs_write(object_id, data, data_size, metadata);
            break;
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_write_internal(
                object_id,
                data,
                data_size,
                metadata,
                1,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_write(
                object_id,
                data,
                data_size,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                1
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_write(
                object_id,
                data,
                data_size,
                metadata,
                king_object_store_runtime.primary_adapter_error,
                sizeof(king_object_store_runtime.primary_adapter_error),
                1
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_write(
                object_id,
                data,
                data_size,
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
        if (king_object_store_backup_object_to_backend(
                object_id,
                data,
                data_size,
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
