/*
 * Distributed-backend slice. Manages the filesystem-backed distributed object
 * mirror and the bounded read/write/remove helpers behind distributed mode.
 */

static int king_object_store_distributed_root_available(
    const char *operation,
    char *error,
    size_t error_size
)
{
    struct stat root_stat;

    if (operation == NULL || operation[0] == '\0') {
        operation = "operation";
    }

    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            operation,
            NULL,
            "the configured storage_root_path is empty."
        );
        return 0;
    }

    if (stat(king_object_store_runtime.config.storage_root_path, &root_stat) != 0) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            operation,
            NULL,
            strerror(errno)
        );
        return 0;
    }

    if (!S_ISDIR(root_stat.st_mode)) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            operation,
            NULL,
            "the configured storage_root_path is not a directory."
        );
        return 0;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return 1;
}

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

static int king_object_store_distributed_write_from_file_internal(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata,
    zend_bool update_counters,
    char *error,
    size_t error_size
)
{
    char file_path[PATH_MAX];
    struct stat source_st;
    struct stat st_old;
    int is_overwrite = 0;
    zend_bool old_size_resolved = 0;
    uint64_t old_size = 0;
    king_object_metadata_t final_metadata;
    king_object_metadata_t existing_metadata;
    char computed_sha256[65];
    uint64_t source_size = 0;

    if (!king_object_store_runtime.initialized || object_id == NULL || source_path == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("write", error, error_size)) {
        return FAILURE;
    }
    if (stat(source_path, &source_st) != 0 || !S_ISREG(source_st.st_mode)) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "write",
            object_id,
            "the upload source path is unavailable."
        );
        return FAILURE;
    }
    if (king_object_store_compute_sha256_hex_for_path(source_path, computed_sha256, &source_size) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "write",
            object_id,
            "could not compute integrity_sha256 for the source payload."
        );
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

    if (king_object_store_copy_file_to_path(source_path, file_path) != SUCCESS) {
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
        king_object_store_runtime.current_stored_bytes += source_size;
        king_object_store_runtime.latest_object_at = time(NULL);
    }

    memset(&final_metadata, 0, sizeof(final_metadata));
    if (metadata != NULL) {
        final_metadata = *metadata;
    }
    king_object_store_finalize_metadata_for_write(
        object_id,
        source_size,
        computed_sha256,
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

static int king_object_store_distributed_read_internal(
    const char *object_id,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata,
    char *error,
    size_t error_size
)
{
    FILE *fp;
    char file_path[PATH_MAX];
    struct stat st;

    if (!king_object_store_runtime.initialized || object_id == NULL || data == NULL || data_size == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("read", error, error_size)) {
        return FAILURE;
    }
    if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            "could not build the distributed payload path."
        );
        return FAILURE;
    }

    if (king_object_store_open_regular_file_stream_readonly(file_path, &fp, &st) != SUCCESS) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            strerror(errno)
        );
        return FAILURE;
    }

    if (metadata != NULL) {
        if (king_object_store_meta_read(object_id, metadata) != SUCCESS) {
            memset(metadata, 0, sizeof(*metadata));
            strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
            metadata->content_length = (uint64_t) st.st_size;
            metadata->created_at = st.st_mtime;
            metadata->modified_at = st.st_mtime;
            metadata->version = 1;
            metadata->distributed_present = 1;
        } else if (king_object_store_metadata_is_expired_now(metadata)) {
            fclose(fp);
            return FAILURE;
        }
    }

    *data_size = (size_t) st.st_size;
    *data = pecalloc(1, *data_size + 1, 1);
    if (*data == NULL) {
        fclose(fp);
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            "could not allocate a read buffer."
        );
        return FAILURE;
    }

    if (fread(*data, 1, *data_size, fp) != *data_size) {
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        fclose(fp);
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            "could not read the committed payload bytes."
        );
        return FAILURE;
    }

    fclose(fp);
    if (metadata != NULL && !king_object_store_verify_integrity_buffer(*data, *data_size, metadata)) {
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        if (error != NULL && error_size > 0) {
            snprintf(
                error,
                error_size,
                "distributed read for '%s' failed: integrity validation failed.",
                object_id
            );
        }
        return FAILURE;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

static int king_object_store_distributed_read_range_internal(
    const char *object_id,
    size_t offset,
    size_t length,
    zend_bool has_length,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata,
    char *error,
    size_t error_size
)
{
    FILE *fp;
    char file_path[PATH_MAX];
    struct stat st;
    size_t bytes_to_read;
    king_object_metadata_t resolved_metadata;

    if (!king_object_store_runtime.initialized || object_id == NULL || data == NULL || data_size == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("range read", error, error_size)) {
        return FAILURE;
    }
    if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "could not build the distributed payload path."
        );
        return FAILURE;
    }

    *data = NULL;
    *data_size = 0;

    if (king_object_store_open_regular_file_stream_readonly(file_path, &fp, &st) != SUCCESS) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            strerror(errno)
        );
        return FAILURE;
    }

    if ((uint64_t) offset > (uint64_t) st.st_size) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "requested range starts past the end of the object."
        );
        fclose(fp);
        return KING_OBJECT_STORE_RESULT_VALIDATION;
    }

    if (metadata != NULL) {
        if (king_object_store_meta_read(object_id, metadata) != SUCCESS) {
            memset(metadata, 0, sizeof(*metadata));
            strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
            metadata->content_length = (uint64_t) st.st_size;
            metadata->created_at = st.st_mtime;
            metadata->modified_at = st.st_mtime;
            metadata->version = 1;
            metadata->distributed_present = 1;
        } else if (king_object_store_metadata_is_expired_now(metadata)) {
            fclose(fp);
            return FAILURE;
        }
    } else if (king_object_store_meta_read(object_id, &resolved_metadata) == SUCCESS
        && king_object_store_metadata_is_expired_now(&resolved_metadata)) {
        fclose(fp);
        return FAILURE;
    }

    bytes_to_read = (size_t) ((uint64_t) st.st_size - (uint64_t) offset);
    if (has_length && length < bytes_to_read) {
        bytes_to_read = length;
    }

    if (fseeko(fp, (off_t) offset, SEEK_SET) != 0) {
        fclose(fp);
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "could not seek to the requested offset."
        );
        return FAILURE;
    }

    *data = pecalloc(1, bytes_to_read + 1, 1);
    if (*data == NULL) {
        fclose(fp);
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "could not allocate a range-read buffer."
        );
        return FAILURE;
    }
    if (bytes_to_read > 0 && fread(*data, 1, bytes_to_read, fp) != bytes_to_read) {
        pefree(*data, 1);
        *data = NULL;
        fclose(fp);
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "could not read the requested range bytes."
        );
        return FAILURE;
    }

    fclose(fp);
    *data_size = bytes_to_read;
    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

static int king_object_store_distributed_read_to_stream_internal(
    const char *object_id,
    php_stream *destination_stream,
    size_t offset,
    size_t length,
    zend_bool has_length,
    king_object_metadata_t *metadata,
    char *error,
    size_t error_size
)
{
    char file_path[PATH_MAX];
    struct stat st;
    king_object_metadata_t resolved_metadata;
    king_object_metadata_t *metadata_ptr = metadata;
    char computed_sha256[65];
    uint64_t source_size = 0;

    if (!king_object_store_runtime.initialized || object_id == NULL || destination_stream == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("read", error, error_size)) {
        return FAILURE;
    }
    if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            "could not build the distributed payload path."
        );
        return FAILURE;
    }

    if (stat(file_path, &st) != 0 || !S_ISREG(st.st_mode)) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            strerror(errno)
        );
        return FAILURE;
    }
    if ((uint64_t) offset > (uint64_t) st.st_size) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "requested range starts past the end of the object."
        );
        return KING_OBJECT_STORE_RESULT_VALIDATION;
    }

    if (metadata_ptr == NULL) {
        metadata_ptr = &resolved_metadata;
    }
    if (king_object_store_meta_read(object_id, metadata_ptr) != SUCCESS) {
        memset(metadata_ptr, 0, sizeof(*metadata_ptr));
        strncpy(metadata_ptr->object_id, object_id, sizeof(metadata_ptr->object_id) - 1);
        metadata_ptr->content_length = (uint64_t) st.st_size;
        metadata_ptr->created_at = st.st_mtime;
        metadata_ptr->modified_at = st.st_mtime;
        metadata_ptr->version = 1;
        metadata_ptr->distributed_present = 1;
    } else if (king_object_store_metadata_is_expired_now(metadata_ptr)) {
        return FAILURE;
    }

    if (!has_length && offset == 0 && metadata_ptr->integrity_sha256[0] != '\0') {
        if (king_object_store_compute_sha256_hex_for_path(file_path, computed_sha256, &source_size) != SUCCESS) {
            king_object_store_set_backend_filesystem_error(
                error,
                error_size,
                "distributed",
                "read",
                object_id,
                "could not compute integrity_sha256 for the committed payload."
            );
            return FAILURE;
        }
        if (strcmp(computed_sha256, metadata_ptr->integrity_sha256) != 0
            || source_size != metadata_ptr->content_length) {
            king_object_store_set_backend_filesystem_error(
                error,
                error_size,
                "distributed",
                "read",
                object_id,
                "integrity validation failed."
            );
            return FAILURE;
        }
    }

    if (king_object_store_copy_path_to_stream(
            file_path,
            destination_stream,
            offset,
            length,
            has_length
        ) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            "could not stream the committed payload bytes."
        );
        return FAILURE;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

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
