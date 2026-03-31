void king_object_store_rehydrate_stats(void)
{
    DIR *dir;
    struct dirent *ent;
    struct stat st;
    char file_path[1024];
    HashTable seen_object_ids;
    king_object_metadata_t metadata;
    zend_bool seen_initialized = 0;

    king_object_store_runtime.current_object_count = 0;
    king_object_store_runtime.current_stored_bytes  = 0;
    king_object_store_runtime.latest_object_at      = 0;

    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        return;
    }

    dir = opendir(king_object_store_runtime.config.storage_root_path);
    if (dir == NULL) {
        return;
    }

    zend_hash_init(&seen_object_ids, 8, NULL, NULL, 0);
    seen_initialized = 1;

    while ((ent = readdir(dir)) != NULL) {
        size_t name_len;
        char object_id[128];
        time_t latest_timestamp;

        if (ent->d_name[0] == '.') {
            continue;
        }

        name_len = strlen(ent->d_name);
        if (name_len <= 5 || strcmp(ent->d_name + name_len - 5, ".meta") != 0) {
            continue;
        }

        snprintf(
            file_path,
            sizeof(file_path),
            "%s/%s",
            king_object_store_runtime.config.storage_root_path,
            ent->d_name
        );

        if (stat(file_path, &st) != 0 || !S_ISREG(st.st_mode)) {
            continue;
        }
        if (name_len - 5 >= sizeof(object_id)) {
            continue;
        }

        memcpy(object_id, ent->d_name, name_len - 5);
        object_id[name_len - 5] = '\0';

        if (king_object_store_meta_read_from_path(file_path, &metadata) != SUCCESS) {
            continue;
        }

        zend_hash_str_add_empty_element(&seen_object_ids, object_id, name_len - 5);
        if (king_object_store_metadata_is_expired_now(&metadata)) {
            continue;
        }
        if (!king_object_store_metadata_counts_toward_primary_inventory(
                object_id,
                &metadata,
                king_object_store_runtime.config.primary_backend
            )) {
            continue;
        }

        king_object_store_runtime.current_object_count++;
        king_object_store_runtime.current_stored_bytes += metadata.content_length;
        latest_timestamp = metadata.modified_at > 0 ? metadata.modified_at : st.st_mtime;
        if (latest_timestamp > king_object_store_runtime.latest_object_at) {
            king_object_store_runtime.latest_object_at = latest_timestamp;
        }
    }

    rewinddir(dir);
    while ((ent = readdir(dir)) != NULL) {
        size_t nlen;
        if (ent->d_name[0] == '.') {
            continue;
        }

        nlen = strlen(ent->d_name);
        if (nlen > 5 && strcmp(ent->d_name + nlen - 5, ".meta") == 0) {
            continue;
        }
        if (seen_initialized && zend_hash_str_exists(&seen_object_ids, ent->d_name, nlen)) {
            continue;
        }

        snprintf(file_path, sizeof(file_path), "%s/%s",
            king_object_store_runtime.config.storage_root_path, ent->d_name);

        if (stat(file_path, &st) == 0 && S_ISREG(st.st_mode)) {
            king_object_store_runtime.current_object_count++;
            king_object_store_runtime.current_stored_bytes += (uint64_t) st.st_size;
            if (st.st_mtime > king_object_store_runtime.latest_object_at) {
                king_object_store_runtime.latest_object_at = st.st_mtime;
            }
        }
    }

    if (seen_initialized) {
        zend_hash_destroy(&seen_object_ids);
    }
    closedir(dir);
}

int king_object_store_local_fs_write(
    const char *object_id,
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata)
{
    char file_path[1024];
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

    memset(&existing_metadata, 0, sizeof(existing_metadata));

    king_object_store_build_path(file_path, sizeof(file_path), object_id);
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

    if (king_object_store_mkdir_parents(file_path) != SUCCESS
        || king_object_store_atomic_write_file(file_path, data, data_size) != SUCCESS) {
        return FAILURE;
    }

    if (is_overwrite) {
        if (old_size_resolved && king_object_store_runtime.current_stored_bytes >= old_size) {
            king_object_store_runtime.current_stored_bytes -= old_size;
        }
        /* Do NOT increment object count on overwrite */
    } else {
        king_object_store_runtime.current_object_count++;
    }
    king_object_store_runtime.current_stored_bytes += data_size;
    king_object_store_runtime.latest_object_at = time(NULL);

    /* Write durable metadata sidecar */
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
        KING_STORAGE_BACKEND_LOCAL_FS,
        1
    );

    king_object_store_meta_write(object_id, &final_metadata);

    return SUCCESS;
}

int king_object_store_local_fs_write_from_file(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata
)
{
    char file_path[1024];
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
    if (stat(source_path, &source_st) != 0 || !S_ISREG(source_st.st_mode)) {
        return FAILURE;
    }
    if (king_object_store_compute_sha256_hex_for_path(source_path, computed_sha256, &source_size) != SUCCESS) {
        return FAILURE;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    king_object_store_build_path(file_path, sizeof(file_path), object_id);
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
        return FAILURE;
    }

    if (is_overwrite) {
        if (old_size_resolved && king_object_store_runtime.current_stored_bytes >= old_size) {
            king_object_store_runtime.current_stored_bytes -= old_size;
        }
    } else {
        king_object_store_runtime.current_object_count++;
    }
    king_object_store_runtime.current_stored_bytes += source_size;
    king_object_store_runtime.latest_object_at = time(NULL);

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
    king_object_store_metadata_mark_backend_present(&final_metadata, KING_STORAGE_BACKEND_LOCAL_FS, 1);

    if (king_object_store_meta_write(object_id, &final_metadata) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

int king_object_store_local_fs_read(
    const char *object_id,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata)
{
    FILE *fp;
    char file_path[1024];
    struct stat st;

    if (!king_object_store_runtime.initialized || object_id == NULL ||
        data == NULL || data_size == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_local_fs_root_available("read")) {
        return FAILURE;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);

    if (stat(file_path, &st) != 0) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_local_failure_error("read", object_id, strerror(errno));
        return FAILURE;
    }

    if (metadata != NULL) {
        if (king_object_store_meta_read(object_id, metadata) != SUCCESS) {
            memset(metadata, 0, sizeof(*metadata));
            strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
            metadata->content_length = (uint64_t) st.st_size;
            metadata->created_at     = st.st_mtime;
            metadata->modified_at    = st.st_mtime;
            metadata->version        = 1;
        } else if (king_object_store_metadata_is_expired_now(metadata)) {
            return FAILURE;
        }
    }

    fp = fopen(file_path, "rb");
    if (fp == NULL) {
        king_object_store_set_local_failure_error("read", object_id, strerror(errno));
        return FAILURE;
    }

    *data_size = st.st_size;
    *data = pecalloc(1, *data_size + 1, 1); /* +1 for null-terminator safety */
    if (*data == NULL) {
        fclose(fp);
        king_object_store_set_local_failure_error("read", object_id, "could not allocate a read buffer.");
        return FAILURE;
    }

    if (fread(*data, 1, *data_size, fp) != *data_size) {
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        fclose(fp);
        king_object_store_set_local_failure_error("read", object_id, "could not read the committed payload bytes.");
        return FAILURE;
    }

    fclose(fp);
    if (metadata != NULL && !king_object_store_verify_integrity_buffer(*data, *data_size, metadata)) {
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        king_object_store_set_backend_runtime_result(
            "primary",
            king_object_store_runtime.config.primary_backend,
            FAILURE,
            "Primary object-store backend integrity validation failed during read."
        );
        return FAILURE;
    }

    return SUCCESS;
}

int king_object_store_local_fs_read_range(
    const char *object_id,
    size_t offset,
    size_t length,
    zend_bool has_length,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata
)
{
    FILE *fp;
    char file_path[1024];
    struct stat st;
    size_t bytes_to_read;
    king_object_metadata_t resolved_metadata;

    if (!king_object_store_runtime.initialized || object_id == NULL || data == NULL || data_size == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_local_fs_root_available("range read")) {
        return FAILURE;
    }

    *data = NULL;
    *data_size = 0;

    king_object_store_build_path(file_path, sizeof(file_path), object_id);
    if (stat(file_path, &st) != 0 || !S_ISREG(st.st_mode)) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_local_failure_error("range read", object_id, strerror(errno));
        return FAILURE;
    }

    if ((uint64_t) offset > (uint64_t) st.st_size) {
        king_object_store_set_local_failure_error(
            "range read",
            object_id,
            "requested range starts past the end of the object."
        );
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
        } else if (king_object_store_metadata_is_expired_now(metadata)) {
            return FAILURE;
        }
    } else if (king_object_store_meta_read(object_id, &resolved_metadata) == SUCCESS
        && king_object_store_metadata_is_expired_now(&resolved_metadata)) {
        return FAILURE;
    }

    bytes_to_read = (size_t) ((uint64_t) st.st_size - (uint64_t) offset);
    if (has_length && length < bytes_to_read) {
        bytes_to_read = length;
    }

    fp = fopen(file_path, "rb");
    if (fp == NULL) {
        king_object_store_set_local_failure_error("range read", object_id, strerror(errno));
        return FAILURE;
    }
    if (fseeko(fp, (off_t) offset, SEEK_SET) != 0) {
        fclose(fp);
        king_object_store_set_local_failure_error("range read", object_id, "could not seek to the requested offset.");
        return FAILURE;
    }

    *data = pecalloc(1, bytes_to_read + 1, 1);
    if (*data == NULL) {
        fclose(fp);
        king_object_store_set_local_failure_error("range read", object_id, "could not allocate a range-read buffer.");
        return FAILURE;
    }
    if (bytes_to_read > 0 && fread(*data, 1, bytes_to_read, fp) != bytes_to_read) {
        pefree(*data, 1);
        *data = NULL;
        fclose(fp);
        king_object_store_set_local_failure_error("range read", object_id, "could not read the requested range bytes.");
        return FAILURE;
    }

    fclose(fp);
    *data_size = bytes_to_read;
    return SUCCESS;
}

int king_object_store_local_fs_read_to_stream(
    const char *object_id,
    php_stream *destination_stream,
    size_t offset,
    size_t length,
    zend_bool has_length,
    king_object_metadata_t *metadata
)
{
    char file_path[1024];
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
    if (!king_object_store_local_fs_root_available("read")) {
        return FAILURE;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);
    if (stat(file_path, &st) != 0 || !S_ISREG(st.st_mode)) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_local_failure_error("read", object_id, strerror(errno));
        return FAILURE;
    }
    if ((uint64_t) offset > (uint64_t) st.st_size) {
        king_object_store_set_local_failure_error(
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
    } else if (king_object_store_metadata_is_expired_now(metadata_ptr)) {
        return FAILURE;
    }

    if (!has_length && offset == 0 && metadata_ptr->integrity_sha256[0] != '\0') {
        if (king_object_store_compute_sha256_hex_for_path(file_path, computed_sha256, &source_size) != SUCCESS) {
            king_object_store_set_local_failure_error("read", object_id, "could not compute integrity_sha256 for the committed payload.");
            return FAILURE;
        }
        if (strcmp(computed_sha256, metadata_ptr->integrity_sha256) != 0 || source_size != metadata_ptr->content_length) {
            king_object_store_set_local_failure_error("read", object_id, "integrity validation failed.");
            return FAILURE;
        }
    }

    return king_object_store_copy_path_to_stream(
        file_path,
        destination_stream,
        offset,
        length,
        has_length
    );
}

static int king_object_store_local_fs_payload_is_missing(const char *object_id)
{
    char file_path[1024];
    struct stat st;

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        return 0;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);
    if (stat(file_path, &st) == 0) {
        return 0;
    }

    return errno == ENOENT ? 1 : 0;
}

int king_object_store_local_fs_remove(const char *object_id)
{
    char file_path[1024];
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
    if (!king_object_store_local_fs_root_available("delete")) {
        return FAILURE;
    }

    king_object_store_build_path(file_path, sizeof(file_path), object_id);

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
        king_object_store_set_local_failure_error("delete", object_id, strerror(errno));
        return FAILURE;
    }

    if (size_resolved && king_object_store_runtime.current_stored_bytes >= removed_size) {
        king_object_store_runtime.current_stored_bytes -= removed_size;
    }
    if (king_object_store_runtime.current_object_count > 0) {
        king_object_store_runtime.current_object_count--;
    }

    /* Remove durable metadata sidecar */
    king_object_store_meta_remove(object_id);

    /* Auto-invalidate matching CDN cache entry */
    king_object_store_invalidate_cdn_cache_entry(object_id);

    return SUCCESS;
}

int king_object_store_local_fs_list(zval *return_array)
{
    DIR *dir;
    struct dirent *ent;
    struct stat st;
    char file_path[1024];
    zval exported_entry;
    king_object_metadata_t metadata;
    king_object_metadata_t *metadata_ptr;

    if (!king_object_store_runtime.initialized) {
        return FAILURE;
    }
    if (!king_object_store_local_fs_root_available("list")) {
        return FAILURE;
    }

    dir = opendir(king_object_store_runtime.config.storage_root_path);
    if (dir == NULL) {
        king_object_store_set_local_failure_error("list", NULL, strerror(errno));
        return FAILURE;
    }

    while ((ent = readdir(dir)) != NULL) {
        if (ent->d_name[0] == '.') {
            continue;
        }
        king_object_store_build_path(file_path, sizeof(file_path), ent->d_name);
        if (stat(file_path, &st) == 0 && S_ISREG(st.st_mode)) {
            /* Skip .meta sidecar files from the object listing */
            size_t nlen = strlen(ent->d_name);
            if (nlen > 5 && strcmp(ent->d_name + nlen - 5, ".meta") == 0) {
                continue;
            }
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
    return SUCCESS;
}

