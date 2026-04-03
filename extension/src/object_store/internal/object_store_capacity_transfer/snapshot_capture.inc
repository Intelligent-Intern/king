/* --- backup / restore / snapshot transfer helpers --- */

#define KING_OBJECT_STORE_SNAPSHOT_MANIFEST_FILENAME ".king_snapshot_manifest"
#define KING_OBJECT_STORE_SNAPSHOT_MANIFEST_FORMAT "king_object_store_snapshot_v1"
#define KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND_FULL "full_backup"
#define KING_OBJECT_STORE_SNAPSHOT_MANIFEST_KIND_INCREMENTAL "incremental_backup"
#define KING_OBJECT_STORE_SNAPSHOT_MANIFEST_CONSISTENCY "per_object_locked_commit"
#define KING_OBJECT_STORE_SNAPSHOT_MANIFEST_MAX_LINE_LENGTH 4096

#ifndef KING_PATH_MAX
# if defined(PATH_MAX)
#  define KING_PATH_MAX PATH_MAX
# else
#  define KING_PATH_MAX 1024
# endif
#endif

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

static int king_object_store_remove_tree_at(int parent_fd, const char *name);

static int king_object_store_remove_tree_directory_fd(int dir_fd)
{
    DIR *dir;
    struct dirent *entry;
    int scan_fd;
    int status = SUCCESS;

    if (dir_fd < 0) {
        return FAILURE;
    }

    scan_fd = dup(dir_fd);
    if (scan_fd < 0) {
        return FAILURE;
    }

    dir = fdopendir(scan_fd);
    if (dir == NULL) {
        close(scan_fd);
        return FAILURE;
    }

    errno = 0;
    while ((entry = readdir(dir)) != NULL) {
        if (strcmp(entry->d_name, ".") == 0 || strcmp(entry->d_name, "..") == 0) {
            continue;
        }

        if (king_object_store_remove_tree_at(dir_fd, entry->d_name) != SUCCESS) {
            status = FAILURE;
            break;
        }
    }

    if (entry == NULL && errno != 0) {
        status = FAILURE;
    }

    closedir(dir);
    return status;
}

static int king_object_store_remove_tree_at(int parent_fd, const char *name)
{
    int open_flags = O_RDONLY;
    int dir_fd;
    int status;

    if (parent_fd < 0 || name == NULL || name[0] == '\0') {
        return FAILURE;
    }

    if (unlinkat(parent_fd, name, 0) == 0) {
        return SUCCESS;
    }

    if (errno == ENOENT) {
        return SUCCESS;
    }

    if (errno != EISDIR && errno != EPERM && errno != EACCES) {
        return FAILURE;
    }

#ifdef O_CLOEXEC
    open_flags |= O_CLOEXEC;
#endif
#ifdef O_DIRECTORY
    open_flags |= O_DIRECTORY;
#endif
#ifdef O_NOFOLLOW
    open_flags |= O_NOFOLLOW;
#endif

    dir_fd = openat(parent_fd, name, open_flags);
    if (dir_fd < 0) {
        return errno == ENOENT ? SUCCESS : FAILURE;
    }

    status = king_object_store_remove_tree_directory_fd(dir_fd);
    close(dir_fd);
    if (status != SUCCESS) {
        return FAILURE;
    }

    if (unlinkat(parent_fd, name, AT_REMOVEDIR) != 0) {
        return errno == ENOENT ? SUCCESS : FAILURE;
    }

    return SUCCESS;
}

static int king_object_store_remove_tree(const char *path)
{
    char path_copy[KING_PATH_MAX];
    const char *parent_path = ".";
    char *name;
    size_t path_len;
    int open_flags = O_RDONLY;
    int parent_fd;
    int status;

    if (path == NULL || path[0] == '\0') {
        return FAILURE;
    }

    path_len = strlen(path);
    if (path_len == 0 || path_len >= sizeof(path_copy)) {
        return FAILURE;
    }

    memcpy(path_copy, path, path_len + 1);
    while (path_len > 1 && path_copy[path_len - 1] == '/') {
        path_copy[--path_len] = '\0';
    }

    name = strrchr(path_copy, '/');
    if (name == NULL) {
        name = path_copy;
    } else if (name == path_copy) {
        parent_path = "/";
        name++;
    } else {
        *name = '\0';
        parent_path = path_copy;
        name++;
    }

    if (name[0] == '\0') {
        return FAILURE;
    }

#ifdef O_CLOEXEC
    open_flags |= O_CLOEXEC;
#endif
#ifdef O_DIRECTORY
    open_flags |= O_DIRECTORY;
#endif

    parent_fd = open(parent_path, open_flags);
    if (parent_fd < 0) {
        return FAILURE;
    }

    status = king_object_store_remove_tree_at(parent_fd, name);
    close(parent_fd);
    return status;
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

    if (king_object_store_mkdir_parents(base_path) != SUCCESS) {
        return FAILURE;
    }

    return mkdtemp(destination) != NULL ? SUCCESS : FAILURE;
}

static void king_object_store_fill_fallback_metadata(
    king_object_metadata_t *metadata,
    const char *object_id,
    uint64_t content_length,
    time_t fallback_timestamp
);

static int king_object_store_snapshot_manifest_fingerprint_is_hex(const char *value)
{
    const unsigned char *cursor;

    if (value == NULL || strlen(value) != 64) {
        return 0;
    }

    for (cursor = (const unsigned char *) value; *cursor != '\0'; ++cursor) {
        if (!((*cursor >= '0' && *cursor <= '9')
                || (*cursor >= 'a' && *cursor <= 'f')
                || (*cursor >= 'A' && *cursor <= 'F'))) {
            return 0;
        }
    }

    return 1;
}

static int king_object_store_snapshot_inventory_set(
    zval *inventory,
    const char *object_id,
    const char *fingerprint
)
{
    zval stored;

    if (inventory == NULL || Z_TYPE_P(inventory) != IS_ARRAY
        || object_id == NULL || object_id[0] == '\0'
        || fingerprint == NULL) {
        return FAILURE;
    }

    ZVAL_STRING(&stored, fingerprint);
    return zend_hash_str_update(
               Z_ARRVAL_P(inventory),
               object_id,
               strlen(object_id),
               &stored
           ) != NULL
        ? SUCCESS
        : FAILURE;
}

static int king_object_store_snapshot_string_list_push(zval *entries, const char *value)
{
    if (entries == NULL || Z_TYPE_P(entries) != IS_ARRAY || value == NULL || value[0] == '\0') {
        return FAILURE;
    }

    add_next_index_string(entries, value);
    return SUCCESS;
}

static int king_object_store_snapshot_string_list_contains(zval *entries, const char *value)
{
    zval *entry;

    if (entries == NULL || Z_TYPE_P(entries) != IS_ARRAY || value == NULL || value[0] == '\0') {
        return 0;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(entries), entry) {
        if (Z_TYPE_P(entry) == IS_STRING && strcmp(Z_STRVAL_P(entry), value) == 0) {
            return 1;
        }
    } ZEND_HASH_FOREACH_END();

    return 0;
}

static int king_object_store_snapshot_metadata_fingerprint(
    const king_object_metadata_t *metadata,
    char output[65]
)
{
    char normalized[1024];
    int written;

    if (metadata == NULL || output == NULL) {
        return FAILURE;
    }

    written = snprintf(
        normalized,
        sizeof(normalized),
        "object_id=%s\n"
        "content_type=%s\n"
        "content_encoding=%s\n"
        "etag=%s\n"
        "integrity_sha256=%s\n"
        "content_length=%" PRIu64 "\n"
        "version=%" PRIu64 "\n"
        "created_at=%" PRId64 "\n"
        "modified_at=%" PRId64 "\n"
        "expires_at=%" PRId64 "\n"
        "object_type=%d\n"
        "cache_policy=%d\n"
        "cache_ttl_seconds=%u\n"
        "local_fs_present=%u\n"
        "distributed_present=%u\n"
        "cloud_s3_present=%u\n"
        "cloud_gcs_present=%u\n"
        "cloud_azure_present=%u\n"
        "is_backed_up=%u\n"
        "replication_status=%u\n"
        "is_distributed=%u\n"
        "distribution_peer_count=%u\n",
        metadata->object_id,
        metadata->content_type,
        metadata->content_encoding,
        metadata->etag,
        metadata->integrity_sha256,
        metadata->content_length,
        metadata->version,
        (int64_t) metadata->created_at,
        (int64_t) metadata->modified_at,
        (int64_t) metadata->expires_at,
        (int) metadata->object_type,
        (int) metadata->cache_policy,
        (unsigned) metadata->cache_ttl_seconds,
        (unsigned) metadata->local_fs_present,
        (unsigned) metadata->distributed_present,
        (unsigned) metadata->cloud_s3_present,
        (unsigned) metadata->cloud_gcs_present,
        (unsigned) metadata->cloud_azure_present,
        (unsigned) metadata->is_backed_up,
        (unsigned) metadata->replication_status,
        (unsigned) metadata->is_distributed,
        (unsigned) metadata->distribution_peer_count
    );
    if (written < 0 || (size_t) written >= sizeof(normalized)) {
        return FAILURE;
    }

    king_object_store_compute_sha256_hex(normalized, (size_t) written, output);
    return SUCCESS;
}

static int king_object_store_snapshot_capture_object(
    const char *object_id,
    const char *destination_directory,
    const char *base_fingerprint,
    char fingerprint_out[65],
    zend_bool *exported_out
)
{
    char destination_path[1024];
    char destination_meta_path[1024];
    char lock_error[256];
    void *data = NULL;
    size_t data_size = 0;
    king_object_metadata_t metadata;
    int mutation_lock_fd = -1;
    int rc = FAILURE;
    zend_bool should_export = 0;
    int metadata_rc;

    if (fingerprint_out != NULL) {
        fingerprint_out[0] = '\0';
    }
    if (exported_out != NULL) {
        *exported_out = 0;
    }
    if (!king_object_store_runtime.initialized
        || object_id == NULL
        || king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (destination_directory != NULL && destination_directory[0] != '\0') {
        if (king_object_store_ensure_directory_recursive(destination_directory) != SUCCESS) {
            return FAILURE;
        }
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
            lock_error[0] != '\0'
                ? lock_error
                : "Failed to acquire object-store mutation lock for snapshot export."
        );
        return FAILURE;
    }

    memset(&metadata, 0, sizeof(metadata));
    metadata_rc = king_object_store_backend_read_metadata(object_id, &metadata);
    if (metadata_rc != SUCCESS) {
        if (king_object_store_read_object(object_id, &data, &data_size, &metadata) != SUCCESS) {
            goto cleanup;
        }
    }

    if (metadata.object_id[0] == '\0') {
        if (data != NULL) {
            king_object_store_fill_fallback_metadata(&metadata, object_id, (uint64_t) data_size, time(NULL));
        } else {
            strncpy(metadata.object_id, object_id, sizeof(metadata.object_id) - 1);
        }
    }
    if (metadata.content_length == 0 && data != NULL) {
        metadata.content_length = (uint64_t) data_size;
    }
    if (king_object_store_snapshot_metadata_fingerprint(&metadata, fingerprint_out) != SUCCESS) {
        goto cleanup;
    }

    should_export =
        destination_directory != NULL
        && destination_directory[0] != '\0'
        && (base_fingerprint == NULL
            || base_fingerprint[0] == '\0'
            || strcmp(base_fingerprint, fingerprint_out) != 0);
    if (!should_export) {
        rc = SUCCESS;
        goto cleanup;
    }

    if (data == NULL && king_object_store_read_object(object_id, &data, &data_size, &metadata) != SUCCESS) {
        goto cleanup;
    }
    if (metadata.object_id[0] == '\0') {
        king_object_store_fill_fallback_metadata(&metadata, object_id, (uint64_t) data_size, time(NULL));
    }

    if (king_object_store_build_path_in_directory(
            destination_path,
            sizeof(destination_path),
            destination_directory,
            object_id,
            NULL
        ) != SUCCESS
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

    if (exported_out != NULL) {
        *exported_out = 1;
    }
    rc = SUCCESS;

cleanup:
    if (data != NULL) {
        pefree(data, 1);
    }
    king_object_store_release_object_lock(&mutation_lock_fd);
    return rc;
}

