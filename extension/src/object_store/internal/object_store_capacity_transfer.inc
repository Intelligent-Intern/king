/* --- backup / restore / snapshot transfer helpers --- */

#define KING_OBJECT_STORE_SNAPSHOT_MANIFEST_FILENAME ".king_snapshot_manifest"
#define KING_OBJECT_STORE_SNAPSHOT_MANIFEST_FORMAT "king_object_store_snapshot_v1"
#define KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND "full_backup"
#define KING_OBJECT_STORE_SNAPSHOT_MANIFEST_CONSISTENCY "per_object_locked_commit"

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

static int king_object_store_build_snapshot_manifest_path(
    char *dest,
    size_t dest_len,
    const char *directory
)
{
    int written;

    if (dest == NULL || dest_len == 0 || directory == NULL || directory[0] == '\0') {
        return FAILURE;
    }

    written = snprintf(
        dest,
        dest_len,
        "%s/%s",
        directory,
        KING_OBJECT_STORE_SNAPSHOT_MANIFEST_FILENAME
    );

    return (written >= 0 && (size_t) written < dest_len) ? SUCCESS : FAILURE;
}

static int king_object_store_remove_tree(const char *path)
{
    struct stat st;

    if (path == NULL || path[0] == '\0') {
        return FAILURE;
    }

    if (lstat(path, &st) != 0) {
        return errno == ENOENT ? SUCCESS : FAILURE;
    }

    if (!S_ISDIR(st.st_mode)) {
        return unlink(path) == 0 ? SUCCESS : FAILURE;
    }

    {
        DIR *dir = opendir(path);
        struct dirent *entry;

        if (dir == NULL) {
            return FAILURE;
        }

        while ((entry = readdir(dir)) != NULL) {
            char child_path[1024];

            if (strcmp(entry->d_name, ".") == 0 || strcmp(entry->d_name, "..") == 0) {
                continue;
            }

            if (snprintf(child_path, sizeof(child_path), "%s/%s", path, entry->d_name) >= (int) sizeof(child_path)) {
                closedir(dir);
                return FAILURE;
            }

            if (king_object_store_remove_tree(child_path) != SUCCESS) {
                closedir(dir);
                return FAILURE;
            }
        }

        closedir(dir);
    }

    return rmdir(path) == 0 ? SUCCESS : FAILURE;
}

static int king_object_store_create_unique_directory(
    const char *base_path,
    const char *label,
    char *destination,
    size_t destination_size
)
{
    if (base_path == NULL || base_path[0] == '\0'
        || label == NULL || label[0] == '\0'
        || destination == NULL || destination_size == 0) {
        return FAILURE;
    }

    if (snprintf(
            destination,
            destination_size,
            "%s.%s.XXXXXX",
            base_path,
            label
        ) >= (int) destination_size) {
        return FAILURE;
    }

    if (king_object_store_mkdir_parents(destination) != SUCCESS) {
        return FAILURE;
    }

    return mkdtemp(destination) != NULL ? SUCCESS : FAILURE;
}

static int king_object_store_snapshot_manifest_write(const char *directory, zval *objects)
{
    char manifest_path[1024];
    char temp_path[1024];
    FILE *fp = NULL;
    int temp_fd = -1;
    size_t object_count = 0;
    zval *entry;

    if (directory == NULL || directory[0] == '\0' || objects == NULL || Z_TYPE_P(objects) != IS_ARRAY) {
        return FAILURE;
    }

    if (king_object_store_build_snapshot_manifest_path(
            manifest_path,
            sizeof(manifest_path),
            directory
        ) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_mkdir_parents(manifest_path) != SUCCESS) {
        return FAILURE;
    }
    if (snprintf(temp_path, sizeof(temp_path), "%s.XXXXXX", manifest_path) >= (int) sizeof(temp_path)) {
        return FAILURE;
    }

    temp_fd = mkstemp(temp_path);
    if (temp_fd < 0) {
        return FAILURE;
    }

    fp = fdopen(temp_fd, "wb");
    if (fp == NULL) {
        close(temp_fd);
        unlink(temp_path);
        return FAILURE;
    }
    temp_fd = -1;

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(objects), entry) {
        zval *object_id_zv;

        if (Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        object_id_zv = zend_hash_str_find(Z_ARRVAL_P(entry), "object_id", sizeof("object_id") - 1);
        if (object_id_zv == NULL || Z_TYPE_P(object_id_zv) != IS_STRING) {
            continue;
        }
        object_count++;
    } ZEND_HASH_FOREACH_END();

    fprintf(
        fp,
        "format=%s\n"
        "kind=%s\n"
        "consistency=%s\n"
        "object_count=%zu\n",
        KING_OBJECT_STORE_SNAPSHOT_MANIFEST_FORMAT,
        KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND,
        KING_OBJECT_STORE_SNAPSHOT_MANIFEST_CONSISTENCY,
        object_count
    );

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(objects), entry) {
        zval *object_id_zv;

        if (Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }
        object_id_zv = zend_hash_str_find(Z_ARRVAL_P(entry), "object_id", sizeof("object_id") - 1);
        if (object_id_zv == NULL || Z_TYPE_P(object_id_zv) != IS_STRING) {
            continue;
        }

        fprintf(fp, "object_id=%s\n", Z_STRVAL_P(object_id_zv));
    } ZEND_HASH_FOREACH_END();

    if (fclose(fp) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    if (rename(temp_path, manifest_path) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    return SUCCESS;
}

static int king_object_store_restore_all_objects_from_manifest(
    const char *source_directory,
    const char *manifest_path
)
{
    FILE *fp;
    char line[640];
    int saw_format = 0;
    int saw_kind = 0;
    int saw_consistency = 0;

    if (source_directory == NULL || source_directory[0] == '\0'
        || manifest_path == NULL || manifest_path[0] == '\0') {
        return FAILURE;
    }

    fp = fopen(manifest_path, "rb");
    if (fp == NULL) {
        return FAILURE;
    }

    while (fgets(line, sizeof(line), fp) != NULL) {
        char *value = strchr(line, '=');
        size_t value_len;

        if (value == NULL) {
            continue;
        }

        *value = '\0';
        value++;
        value_len = strlen(value);
        while (value_len > 0 && (value[value_len - 1] == '\n' || value[value_len - 1] == '\r')) {
            value[--value_len] = '\0';
        }

        if (strcmp(line, "format") == 0) {
            if (strcmp(value, KING_OBJECT_STORE_SNAPSHOT_MANIFEST_FORMAT) != 0) {
                fclose(fp);
                return FAILURE;
            }
            saw_format = 1;
            continue;
        }
        if (strcmp(line, "kind") == 0) {
            if (strcmp(value, KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND) != 0) {
                fclose(fp);
                return FAILURE;
            }
            saw_kind = 1;
            continue;
        }
        if (strcmp(line, "consistency") == 0) {
            if (strcmp(value, KING_OBJECT_STORE_SNAPSHOT_MANIFEST_CONSISTENCY) != 0) {
                fclose(fp);
                return FAILURE;
            }
            saw_consistency = 1;
            continue;
        }
        if (strcmp(line, "object_id") != 0) {
            continue;
        }
        if (king_object_store_object_id_validate(value) != NULL) {
            fclose(fp);
            return FAILURE;
        }
        if (king_object_store_restore_object(value, source_directory) != SUCCESS) {
            fclose(fp);
            return FAILURE;
        }
    }

    fclose(fp);

    return (saw_format && saw_kind && saw_consistency) ? SUCCESS : FAILURE;
}

static int king_object_store_commit_snapshot_directory(
    const char *staging_directory,
    const char *destination_directory
)
{
    struct stat st;
    char previous_directory[1024];
    zend_bool had_existing_destination = 0;

    if (staging_directory == NULL || staging_directory[0] == '\0'
        || destination_directory == NULL || destination_directory[0] == '\0') {
        return FAILURE;
    }

    previous_directory[0] = '\0';

    if (stat(destination_directory, &st) == 0) {
        if (!S_ISDIR(st.st_mode)) {
            return FAILURE;
        }
        if (king_object_store_create_unique_directory(
                destination_directory,
                "previous",
                previous_directory,
                sizeof(previous_directory)
            ) != SUCCESS) {
            return FAILURE;
        }
        if (rmdir(previous_directory) != 0) {
            king_object_store_remove_tree(previous_directory);
            return FAILURE;
        }
        if (rename(destination_directory, previous_directory) != 0) {
            return FAILURE;
        }
        had_existing_destination = 1;
    } else if (errno != ENOENT) {
        return FAILURE;
    }

    if (rename(staging_directory, destination_directory) != 0) {
        if (had_existing_destination) {
            (void) rename(previous_directory, destination_directory);
        }
        return FAILURE;
    }

    if (had_existing_destination) {
        (void) king_object_store_remove_tree(previous_directory);
    }

    return SUCCESS;
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
    char lock_error[256];
    void *data = NULL;
    size_t data_size = 0;
    king_object_metadata_t metadata;
    int mutation_lock_fd = -1;
    int rc = FAILURE;

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
            lock_error[0] != '\0' ? lock_error : "Failed to acquire object-store mutation lock for export."
        );
        return FAILURE;
    }

    if (king_object_store_read_object(object_id, &data, &data_size, &metadata) != SUCCESS) {
        goto cleanup;
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
        goto cleanup;
    }

    if (king_object_store_mkdir_parents(destination_path) != SUCCESS
        || king_object_store_atomic_write_file(destination_path, data, data_size) != SUCCESS
        || king_object_store_meta_write_to_path(destination_meta_path, &metadata) != SUCCESS) {
        unlink(destination_path);
        unlink(destination_meta_path);
        goto cleanup;
    }

    rc = SUCCESS;

cleanup:
    if (data != NULL) {
        pefree(data, 1);
    }
    king_object_store_release_object_lock(&mutation_lock_fd);

    return rc;
}

static int king_object_store_import_object(const char *object_id, const char *source_directory)
{
    char source_path[1024];
    char source_meta_path[1024];
    char capacity_error[256];
    char lock_error[256];
    struct stat source_stat;
    void *data = NULL;
    size_t data_size = 0;
    king_object_metadata_t metadata;
    int mutation_lock_fd = -1;
    int rc = FAILURE;

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
            lock_error[0] != '\0' ? lock_error : "Failed to acquire object-store mutation lock for restore."
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
        goto cleanup;
    }

    if (king_object_store_build_path_in_directory(
            source_meta_path,
            sizeof(source_meta_path),
            source_directory,
            object_id,
            ".meta"
        ) != SUCCESS
        || king_object_store_read_file_contents(source_path, &data, &data_size) != SUCCESS) {
        goto cleanup;
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
        goto cleanup;
    }

    rc = SUCCESS;

cleanup:
    if (data != NULL) {
        pefree(data, 1);
    }
    king_object_store_release_object_lock(&mutation_lock_fd);

    return rc;
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
    char staging_directory[1024];
    int rc = FAILURE;

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
    if (king_object_store_create_unique_directory(
            destination_directory,
            "staging",
            staging_directory,
            sizeof(staging_directory)
        ) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_directory_is_within_storage_root(staging_directory, 0) != SUCCESS) {
        king_object_store_remove_tree(staging_directory);
        return FAILURE;
    }

    array_init(&objects);
    if (king_object_store_list_object(&objects) != SUCCESS) {
        zval_ptr_dtor(&objects);
        king_object_store_remove_tree(staging_directory);
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

        if (king_object_store_backup_object(Z_STRVAL_P(object_id_zv), staging_directory) != SUCCESS) {
            zval_ptr_dtor(&objects);
            king_object_store_remove_tree(staging_directory);
            return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    if (king_object_store_snapshot_manifest_write(staging_directory, &objects) != SUCCESS) {
        zval_ptr_dtor(&objects);
        king_object_store_remove_tree(staging_directory);
        return FAILURE;
    }

    zval_ptr_dtor(&objects);
    rc = king_object_store_commit_snapshot_directory(staging_directory, destination_directory);
    if (rc != SUCCESS) {
        king_object_store_remove_tree(staging_directory);
    }

    return rc;
}

int king_object_store_restore_all_objects(const char *source_directory)
{
    DIR *dir;
    struct dirent *ent;
    char source_path[1024];
    char manifest_path[1024];
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
    if (king_object_store_build_snapshot_manifest_path(
            manifest_path,
            sizeof(manifest_path),
            source_directory
        ) != SUCCESS) {
        return FAILURE;
    }
    if (stat(manifest_path, &st) == 0 && S_ISREG(st.st_mode)) {
        return king_object_store_restore_all_objects_from_manifest(source_directory, manifest_path);
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
