static int king_object_store_distributed_remove_internal(
    const char *object_id,
    zend_bool update_counters,
    zend_bool remove_metadata,
    char *error,
    size_t error_size
)
{
    char file_path[PATH_MAX];
    struct stat st;
    king_object_metadata_t metadata;
    uint64_t removed_size = 0;
    zend_bool size_resolved = 0;

    if (!king_object_store_runtime.initialized || object_id == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("delete", error, error_size)) {
        return FAILURE;
    }
    if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "delete",
            object_id,
            "could not build the distributed payload path."
        );
        return FAILURE;
    }

    if (king_object_store_runtime_metadata_cache_read(object_id, &metadata) == SUCCESS
        || king_object_store_meta_read(object_id, &metadata) == SUCCESS) {
        removed_size = metadata.content_length;
        size_resolved = 1;
    } else if (stat(file_path, &st) == 0) {
        removed_size = (uint64_t) st.st_size;
        size_resolved = 1;
    }

    if (unlink(file_path) != 0) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "delete",
            object_id,
            strerror(errno)
        );
        return FAILURE;
    }

    if (update_counters) {
        if (size_resolved && king_object_store_runtime.current_stored_bytes >= removed_size) {
            king_object_store_runtime.current_stored_bytes -= removed_size;
        }
        if (king_object_store_runtime.current_object_count > 0) {
            king_object_store_runtime.current_object_count--;
        }
    }

    if (remove_metadata) {
        king_object_store_meta_remove(object_id);
        king_object_store_invalidate_cdn_cache_entry(object_id);
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

static int king_object_store_distributed_list_internal(
    zval *return_array,
    char *error,
    size_t error_size
)
{
    DIR *dir;
    struct dirent *ent;
    struct stat st;
    char dir_path[PATH_MAX];
    char file_path[PATH_MAX];
    zval exported_entry;
    king_object_metadata_t metadata;
    king_object_metadata_t *metadata_ptr;

    if (!king_object_store_runtime.initialized) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("list", error, error_size)) {
        return FAILURE;
    }

    king_object_store_build_distributed_objects_dir_path(dir_path, sizeof(dir_path));
    dir = opendir(dir_path);
    if (dir == NULL) {
        if (errno == ENOENT) {
            return SUCCESS;
        }
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "list",
            NULL,
            strerror(errno)
        );
        return FAILURE;
    }

    while ((ent = readdir(dir)) != NULL) {
        if (ent->d_name[0] == '.') {
            continue;
        }
        if (king_object_store_build_distributed_path(file_path, sizeof(file_path), ent->d_name) != SUCCESS) {
            continue;
        }
        if (stat(file_path, &st) == 0 && S_ISREG(st.st_mode)) {
            metadata_ptr = NULL;
            if (king_object_store_meta_read(ent->d_name, &metadata) == SUCCESS) {
                if (king_object_store_metadata_is_expired_now(&metadata)) {
                    continue;
                }
                metadata_ptr = &metadata;
            }
            king_object_store_list_entry_from_metadata(
                &exported_entry,
                ent->d_name,
                metadata_ptr,
                (uint64_t) st.st_size,
                st.st_mtime
            );
            add_next_index_zval(return_array, &exported_entry);
        }
    }

    closedir(dir);
    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

static int king_object_store_distributed_remove_with_real_backup_semantics(const char *object_id)
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
        return king_object_store_distributed_remove_internal(
            object_id,
            1,
            1,
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error)
        );
    }
    if (!king_object_store_distributed_root_available(
            "delete",
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error)
        )) {
        return FAILURE;
    }

    payload_exists = king_object_store_distributed_payload_exists(object_id);
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
            "distributed delete for '%s' could not complete because backup removal failed: %s",
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
                "Primary object-store delete removed the backup copy but failed to update distributed metadata."
            );
            return FAILURE;
        }
    }

    if (payload_exists) {
        return king_object_store_distributed_remove_internal(
            object_id,
            1,
            1,
            king_object_store_runtime.primary_adapter_error,
            sizeof(king_object_store_runtime.primary_adapter_error)
        );
    }

    king_object_store_meta_remove(object_id);
    king_object_store_invalidate_cdn_cache_entry(object_id);
    return SUCCESS;
}

/* --- CDN stubs --- */
