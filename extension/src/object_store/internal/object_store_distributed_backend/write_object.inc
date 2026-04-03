static int king_object_store_distributed_write_internal(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata,
    zend_bool update_counters,
    char *error,
    size_t error_size
)
{
    char file_path[PATH_MAX];
    struct stat st_old;
    int is_overwrite = 0;
    zend_bool old_size_resolved = 0;
    uint64_t old_size = 0;
    king_object_metadata_t final_metadata;
    king_object_metadata_t existing_metadata;

    if (!king_object_store_runtime.initialized || object_id == NULL || data == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("write", error, error_size)) {
        return FAILURE;
    }
    if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "write",
            object_id,
            "could not build the distributed payload path."
        );
        return FAILURE;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_runtime_metadata_cache_read(object_id, &existing_metadata) == SUCCESS
        || king_object_store_meta_read(object_id, &existing_metadata) == SUCCESS) {
        is_overwrite = 1;
        old_size = existing_metadata.content_length;
        old_size_resolved = 1;
    } else if (stat(file_path, &st_old) == 0 && S_ISREG(st_old.st_mode)) {
        is_overwrite = 1;
        old_size = (uint64_t) st_old.st_size;
        old_size_resolved = 1;
        strncpy(existing_metadata.object_id, object_id, sizeof(existing_metadata.object_id) - 1);
        existing_metadata.content_length = old_size;
        existing_metadata.created_at = st_old.st_mtime;
        existing_metadata.modified_at = st_old.st_mtime;
        existing_metadata.version = 1;
    }

    if (king_object_store_mkdir_parents(file_path) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "write",
            object_id,
            "could not create the distributed payload directory."
        );
        return FAILURE;
    }
    if (king_object_store_atomic_write_file(file_path, data, data_size) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "write",
            object_id,
            strerror(errno)
        );
        return FAILURE;
    }

    if (update_counters) {
        if (is_overwrite) {
            if (old_size_resolved && king_object_store_runtime.current_stored_bytes >= old_size) {
                king_object_store_runtime.current_stored_bytes -= old_size;
            }
        } else {
            king_object_store_runtime.current_object_count++;
        }
        king_object_store_runtime.current_stored_bytes += data_size;
        king_object_store_runtime.latest_object_at = time(NULL);
    }

    memset(&final_metadata, 0, sizeof(final_metadata));
    if (metadata != NULL) {
        final_metadata = *metadata;
    }
    if (final_metadata.object_id[0] == '\0') {
        strncpy(final_metadata.object_id, object_id, sizeof(final_metadata.object_id) - 1);
    }
    if (final_metadata.content_length == 0) {
        final_metadata.content_length = data_size;
    }
    king_object_store_prepare_metadata_for_write(
        object_id,
        data,
        data_size,
        &final_metadata,
        is_overwrite ? &existing_metadata : NULL
    );
    king_object_store_metadata_mark_backend_present(
        &final_metadata,
        KING_STORAGE_BACKEND_DISTRIBUTED,
        1
    );

    if (king_object_store_meta_write(object_id, &final_metadata) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "write",
            object_id,
            "could not persist the shared object metadata sidecar."
        );
        return FAILURE;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

