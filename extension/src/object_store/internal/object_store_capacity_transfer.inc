static int king_object_store_is_meta_filename(const char *name)
{
    size_t name_len;

    if (name == NULL || name[0] == '\0' || name[0] == '.') {
        return 1;
    }

    name_len = strlen(name);
    if (name_len > 5 && strcmp(name + name_len - 5, ".meta") == 0) {
        return 1;
    }

    return 0;
}

static void king_object_store_fill_fallback_metadata(
    king_object_metadata_t *metadata,
    const char *object_id,
    uint64_t content_length,
    time_t fallback_timestamp)
{
    if (metadata == NULL || object_id == NULL) {
        return;
    }

    memset(metadata, 0, sizeof(*metadata));
    strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
    metadata->content_length = content_length;
    metadata->created_at = fallback_timestamp;
    metadata->modified_at = fallback_timestamp;
}

zend_bool king_object_store_runtime_capacity_is_enabled(void)
{
    return king_object_store_runtime.initialized
        && king_object_store_runtime.config.max_storage_size_bytes > 0;
}

const char *king_object_store_runtime_capacity_mode_to_string(void)
{
    return king_object_store_runtime_capacity_is_enabled()
        ? "logical_hard_limit"
        : "disabled";
}

uint64_t king_object_store_runtime_capacity_available_bytes(void)
{
    uint64_t limit_bytes;

    if (!king_object_store_runtime_capacity_is_enabled()) {
        return 0;
    }

    limit_bytes = king_object_store_runtime.config.max_storage_size_bytes;
    if (king_object_store_runtime.current_stored_bytes >= limit_bytes) {
        return 0;
    }

    return limit_bytes - king_object_store_runtime.current_stored_bytes;
}

static int king_object_store_runtime_capacity_resolve_existing_size(
    const char *object_id,
    uint64_t *old_size_out,
    zend_bool *had_existing_object_out
)
{
    char file_path[1024];
    struct stat st_old;
    king_object_metadata_t existing_metadata;
    king_storage_backend_t primary_backend;

    if (old_size_out == NULL || had_existing_object_out == NULL) {
        return FAILURE;
    }

    *old_size_out = 0;
    *had_existing_object_out = 0;

    if (object_id == NULL || king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }

    memset(&existing_metadata, 0, sizeof(existing_metadata));
    if (king_object_store_runtime_metadata_cache_read(object_id, &existing_metadata) == SUCCESS
        || king_object_store_meta_read(object_id, &existing_metadata) == SUCCESS) {
        *old_size_out = existing_metadata.content_length;
        *had_existing_object_out = 1;
        return SUCCESS;
    }

    primary_backend = king_object_store_normalize_backend(
        king_object_store_runtime.config.primary_backend
    );
    if (primary_backend == KING_STORAGE_BACKEND_DISTRIBUTED) {
        if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
            return FAILURE;
        }
    } else {
        king_object_store_build_path(file_path, sizeof(file_path), object_id);
    }
    if (stat(file_path, &st_old) == 0 && S_ISREG(st_old.st_mode)) {
        *old_size_out = (uint64_t) st_old.st_size;
        *had_existing_object_out = 1;
    }

    return SUCCESS;
}

static zend_bool king_object_store_runtime_capacity_allows_rewrite(
    uint64_t new_size,
    zend_bool had_existing_object,
    uint64_t old_size,
    uint64_t *projected_total_out
)
{
    uint64_t projected_total;

    if (!king_object_store_runtime_capacity_is_enabled()) {
        if (projected_total_out != NULL) {
            *projected_total_out = king_object_store_runtime.current_stored_bytes;
        }
        return 1;
    }

    projected_total = king_object_store_runtime.current_stored_bytes;
    if (had_existing_object) {
        if (projected_total >= old_size) {
            projected_total -= old_size;
        } else {
            projected_total = 0;
        }
    }

    if (UINT64_MAX - projected_total < new_size) {
        if (projected_total_out != NULL) {
            *projected_total_out = UINT64_MAX;
        }
        return 0;
    }

    projected_total += new_size;
    if (projected_total_out != NULL) {
        *projected_total_out = projected_total;
    }

    return projected_total <= king_object_store_runtime.config.max_storage_size_bytes;
}

int king_object_store_runtime_capacity_check_rewrite(
    uint64_t new_size,
    zend_bool had_existing_object,
    uint64_t old_size,
    char *error,
    size_t error_size
)
{
    if (!king_object_store_runtime_capacity_allows_rewrite(
            new_size,
            had_existing_object,
            old_size,
            NULL
        )) {
        if (error != NULL && error_size > 0) {
            snprintf(
                error,
                error_size,
                "Object-store operation would exceed the configured runtime capacity."
            );
        }
        return FAILURE;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

int king_object_store_runtime_capacity_check_object_size(
    const char *object_id,
    uint64_t new_size,
    char *error,
    size_t error_size
)
{
    uint64_t old_size = 0;
    zend_bool had_existing_object = 0;

    if (king_object_store_runtime_capacity_resolve_existing_size(
            object_id,
            &old_size,
            &had_existing_object
        ) != SUCCESS) {
        if (error != NULL && error_size > 0) {
            snprintf(
                error,
                error_size,
                "Object-store operation could not resolve the current runtime capacity baseline."
            );
        }
        return FAILURE;
    }

    return king_object_store_runtime_capacity_check_rewrite(
        new_size,
        had_existing_object,
        old_size,
        error,
        error_size
    );
}

static int king_object_store_apply_local_fs_counters_for_rewrite(
    const char *object_id,
    uint64_t new_size,
    int had_old,
    uint64_t old_size
)
{
    if (had_old) {
        if (king_object_store_runtime.current_stored_bytes >= old_size) {
            king_object_store_runtime.current_stored_bytes -= old_size;
        }
    } else {
        king_object_store_runtime.current_object_count++;
    }

    king_object_store_runtime.current_stored_bytes += new_size;
    king_object_store_runtime.latest_object_at = time(NULL);

    return SUCCESS;
}

static int king_object_store_export_object(const char *object_id, const char *destination_directory)
{
    char destination_path[1024];
    char destination_meta_path[1024];
    void *data = NULL;
    size_t data_size = 0;
    king_object_metadata_t metadata;

    if (!king_object_store_runtime.initialized) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL || destination_directory == NULL || destination_directory[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "object export") == FAILURE) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(destination_directory, 1) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_ensure_directory_recursive(destination_directory) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(destination_directory, 0) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_read_object(object_id, &data, &data_size, &metadata) != SUCCESS) {
        return FAILURE;
    }

    if (metadata.object_id[0] == '\0') {
        king_object_store_fill_fallback_metadata(&metadata, object_id, (uint64_t) data_size, time(NULL));
    }

    if (king_object_store_build_path_in_directory(destination_path, sizeof(destination_path), destination_directory, object_id, NULL) != SUCCESS
        || king_object_store_build_path_in_directory(
            destination_meta_path,
            sizeof(destination_meta_path),
            destination_directory,
            object_id,
            ".meta"
        ) != SUCCESS) {
        if (data != NULL) {
            pefree(data, 1);
        }
        return FAILURE;
    }

    if (king_object_store_mkdir_parents(destination_path) != SUCCESS
        || king_object_store_atomic_write_file(destination_path, data, data_size) != SUCCESS
        || king_object_store_meta_write_to_path(destination_meta_path, &metadata) != SUCCESS) {
        if (data != NULL) {
            pefree(data, 1);
        }
        unlink(destination_path);
        unlink(destination_meta_path);
        return FAILURE;
    }

    if (data != NULL) {
        pefree(data, 1);
    }

    return SUCCESS;
}

static int king_object_store_import_object(const char *object_id, const char *source_directory)
{
    char source_path[1024];
    char source_meta_path[1024];
    char capacity_error[256];
    struct stat source_stat;
    void *data = NULL;
    size_t data_size = 0;
    king_object_metadata_t metadata;

    if (!king_object_store_runtime.initialized) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL || source_directory == NULL || source_directory[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "object import") == FAILURE) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(source_directory, 0) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_build_path_in_directory(source_path, sizeof(source_path), source_directory, object_id, NULL) != SUCCESS) {
        return FAILURE;
    }
    if (stat(source_path, &source_stat) != 0 || !S_ISREG(source_stat.st_mode)) {
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

    if (king_object_store_build_path_in_directory(
            source_meta_path,
            sizeof(source_meta_path),
            source_directory,
            object_id,
            ".meta"
        ) != SUCCESS
        || king_object_store_read_file_contents(source_path, &data, &data_size) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_meta_read_from_path(source_meta_path, &metadata) != SUCCESS) {
        king_object_store_fill_fallback_metadata(&metadata, object_id, (uint64_t) source_stat.st_size, source_stat.st_mtime);
    }

    if (metadata.object_id[0] == '\0') {
        strncpy(metadata.object_id, object_id, sizeof(metadata.object_id) - 1);
    }
    if (metadata.content_length == 0) {
        metadata.content_length = (uint64_t) source_stat.st_size;
    }

    if (king_object_store_write_object(object_id, data, data_size, &metadata) != SUCCESS) {
        if (data != NULL) {
            pefree(data, 1);
        }
        return FAILURE;
    }

    if (data != NULL) {
        pefree(data, 1);
    }

    return SUCCESS;
}

int king_object_store_backup_object(const char *object_id, const char *destination_path)
{
    return king_object_store_export_object(object_id, destination_path);
}

int king_object_store_restore_object(const char *object_id, const char *source_path)
{
    return king_object_store_import_object(object_id, source_path);
}

int king_object_store_backup_all_objects(const char *destination_directory)
{
    zval objects;
    zval *entry;

    if (!king_object_store_runtime.initialized || destination_directory == NULL || destination_directory[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "batch object export") == FAILURE) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(destination_directory, 1) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_ensure_directory_recursive(destination_directory) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(destination_directory, 0) != SUCCESS) {
        return FAILURE;
    }

    array_init(&objects);
    if (king_object_store_list_object(&objects) != SUCCESS) {
        zval_ptr_dtor(&objects);
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL(objects), entry) {
        zval *object_id_zv;

        if (Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        object_id_zv = zend_hash_str_find(Z_ARRVAL_P(entry), "object_id", sizeof("object_id") - 1);
        if (object_id_zv == NULL || Z_TYPE_P(object_id_zv) != IS_STRING) {
            continue;
        }

        if (king_object_store_backup_object(Z_STRVAL_P(object_id_zv), destination_directory) != SUCCESS) {
            zval_ptr_dtor(&objects);
            return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    zval_ptr_dtor(&objects);
    return SUCCESS;
}

int king_object_store_restore_all_objects(const char *source_directory)
{
    DIR *dir;
    struct dirent *ent;
    char source_path[1024];
    struct stat st;

    if (!king_object_store_runtime.initialized || source_directory == NULL || source_directory[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_require_honest_backend(
            "primary",
            king_object_store_runtime.config.primary_backend,
            "batch object import") == FAILURE) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(source_directory, 0) != SUCCESS) {
        return FAILURE;
    }

    dir = opendir(source_directory);
    if (dir == NULL) {
        return FAILURE;
    }

    while ((ent = readdir(dir)) != NULL) {
        if (ent->d_name[0] == '.') {
            continue;
        }
        if (king_object_store_is_meta_filename(ent->d_name)) {
            continue;
        }
        if (king_object_store_object_id_validate(ent->d_name) != NULL) {
            closedir(dir);
            return FAILURE;
        }
        if (king_object_store_build_path_in_directory(source_path, sizeof(source_path), source_directory, ent->d_name, NULL) != SUCCESS) {
            closedir(dir);
            return FAILURE;
        }
        if (stat(source_path, &st) != 0) {
            closedir(dir);
            return FAILURE;
        }
        if (!S_ISREG(st.st_mode)) {
            continue;
        }
        if (king_object_store_restore_object(ent->d_name, source_directory) != SUCCESS) {
            closedir(dir);
            return FAILURE;
        }
    }

    closedir(dir);
    return SUCCESS;
}

