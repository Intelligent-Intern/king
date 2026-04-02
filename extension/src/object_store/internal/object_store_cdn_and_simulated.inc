/*
 * CDN and simulated-backend helpers. Covers synthetic CDN distribution hooks
 * plus the lightweight simulated provider surfaces used for runtime reporting.
 */

int king_cdn_distribute_object(
    const char *object_id,
    const king_storage_node_t *edge_nodes,
    uint32_t node_count)
{
    if (object_id == NULL || node_count == 0) {
        return SUCCESS;
    }

    king_object_metadata_t metadata;
    if (king_object_store_meta_read(object_id, &metadata) == SUCCESS) {
        metadata.is_distributed = 1;
        metadata.distribution_peer_count = node_count;
        king_object_store_meta_write(object_id, &metadata);
    }

    return SUCCESS;
}

int king_cdn_find_optimal_edge_node(const char *client_ip, king_storage_node_t **optimal_node)
{
    (void) client_ip;
    if (optimal_node != NULL) {
        *optimal_node = NULL;
    }
    return FAILURE; /* runtime: no provisioned edge nodes */
}

int king_object_store_cleanup_expired_objects(
    uint64_t *scanned_out,
    uint64_t *removed_out,
    uint64_t *bytes_reclaimed_out,
    uint64_t *failures_out
)
{
    DIR *dir;
    struct dirent *ent;
    char meta_path[1024];
    char object_id[256];
    king_object_metadata_t metadata;
    uint64_t scanned = 0;
    uint64_t removed = 0;
    uint64_t bytes_reclaimed = 0;
    uint64_t failures = 0;

    if (scanned_out != NULL) {
        *scanned_out = 0;
    }
    if (removed_out != NULL) {
        *removed_out = 0;
    }
    if (bytes_reclaimed_out != NULL) {
        *bytes_reclaimed_out = 0;
    }
    if (failures_out != NULL) {
        *failures_out = 0;
    }

    king_cdn_sweep_expired();

    if (!king_object_store_runtime.initialized
        || king_object_store_runtime.config.storage_root_path[0] == '\0') {
        return SUCCESS;
    }

    dir = opendir(king_object_store_runtime.config.storage_root_path);
    if (dir == NULL) {
        return FAILURE;
    }

    while ((ent = readdir(dir)) != NULL) {
        size_t nlen;

        if (ent->d_name[0] == '.') {
            continue;
        }

        nlen = strlen(ent->d_name);
        if (nlen <= 5 || strcmp(ent->d_name + nlen - 5, ".meta") != 0) {
            continue;
        }

        if (snprintf(
                meta_path,
                sizeof(meta_path),
                "%s/%s",
                king_object_store_runtime.config.storage_root_path,
                ent->d_name
            ) >= (int) sizeof(meta_path)) {
            continue;
        }

        if (king_object_store_meta_read_from_path(meta_path, &metadata) != SUCCESS) {
            continue;
        }

        scanned++;
        if (!king_object_store_metadata_is_expired_now(&metadata)) {
            continue;
        }

        memset(object_id, 0, sizeof(object_id));
        if (metadata.object_id[0] != '\0') {
            strncpy(object_id, metadata.object_id, sizeof(object_id) - 1);
        } else {
            memcpy(object_id, ent->d_name, nlen - 5);
            object_id[nlen - 5] = '\0';
        }

        if (king_object_store_remove_object(object_id) == SUCCESS) {
            removed++;
            bytes_reclaimed += metadata.content_length;
        } else {
            failures++;
        }
    }

    closedir(dir);

    if (scanned_out != NULL) {
        *scanned_out = scanned;
    }
    if (removed_out != NULL) {
        *removed_out = removed;
    }
    if (bytes_reclaimed_out != NULL) {
        *bytes_reclaimed_out = bytes_reclaimed;
    }
    if (failures_out != NULL) {
        *failures_out = failures;
    }

    return SUCCESS;
}

/* --- Backend-specific simulated drivers (Runtime) --- */

static int king_object_store_simulated_backend_build_paths(
    const char *subdir,
    char *path,
    size_t path_len,
    const char *object_id
)
{
    char base_path[1024];
    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        return FAILURE;
    }

    if (object_id == NULL || object_id[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }

    int full_len = snprintf(base_path, sizeof(base_path), "%s/%s", king_object_store_runtime.config.storage_root_path, subdir);
    if (full_len < 0 || (size_t) full_len >= sizeof(base_path)) {
        return FAILURE;
    }
    mkdir(base_path, 0755);

    if (snprintf(path, path_len, "%s/%s", base_path, object_id) >= (int) path_len) {
        return FAILURE;
    }
    return SUCCESS;
}

static int king_object_store_simulated_backend_write(const char *subdir, const char *object_id, const void *data, size_t data_size)
{
    char file_path[2048];
    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_simulated_backend_build_paths(subdir, file_path, sizeof(file_path), object_id) != SUCCESS) {
        return FAILURE;
    }

    FILE *fp = fopen(file_path, "wb");
    if (!fp) {
        return FAILURE;
    }
    if (fwrite(data, 1, data_size, fp) != data_size) {
        fclose(fp);
        unlink(file_path);
        return FAILURE;
    }
    fclose(fp);
    return SUCCESS;
}

static int king_object_store_simulated_backend_read(const char *subdir, const char *object_id, void **data, size_t *data_size)
{
    char file_path[2048];
    int fd;
    FILE *fp = NULL;
    struct stat st;
    ssize_t bytes_read;
    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        return FAILURE;
    }

    if (king_object_store_simulated_backend_build_paths(subdir, file_path, sizeof(file_path), object_id) != SUCCESS) {
        return FAILURE;
    }

#ifdef O_CLOEXEC
    fd = open(file_path, O_RDONLY | O_CLOEXEC | O_NOFOLLOW);
#else
    fd = open(file_path, O_RDONLY | O_NOFOLLOW);
#endif
    if (fd < 0) {
        return FAILURE;
    }

    if (fstat(fd, &st) != 0 || !S_ISREG(st.st_mode)) {
        close(fd);
        return FAILURE;
    }

    *data_size = st.st_size;
    *data = pemalloc(*data_size, 1);
    if (*data == NULL) {
        close(fd);
        *data_size = 0;
        return FAILURE;
    }

    fp = fdopen(fd, "rb");
    if (!fp) {
        close(fd);
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        return FAILURE;
    }

    bytes_read = fread(*data, 1, *data_size, fp);
    if ((size_t) bytes_read != *data_size) {
        fclose(fp);
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        return FAILURE;
    }
    fclose(fp);
    return SUCCESS;
}

static int king_object_store_simulated_backend_remove(const char *subdir, const char *object_id)
{
    char file_path[2048];
    if (king_object_store_simulated_backend_build_paths(subdir, file_path, sizeof(file_path), object_id) != SUCCESS) {
        return FAILURE;
    }

    return (unlink(file_path) == 0) ? SUCCESS : FAILURE;
}

static int king_object_store_simulated_backend_list(const char *subdir, zval *return_array)
{
    if (!king_object_store_runtime.config.storage_root_path[0]) {
        return FAILURE;
    }

    char dir_path[1024];
    int dir_len = snprintf(dir_path, sizeof(dir_path), "%s/%s", king_object_store_runtime.config.storage_root_path, subdir);
    if (dir_len < 0 || (size_t) dir_len >= sizeof(dir_path)) {
        return FAILURE;
    }

    DIR *dir = opendir(dir_path);
    if (dir == NULL) {
        return FAILURE;
    }

    struct dirent *ent;
    struct stat st;
    char file_path[2048];
    zval exported_entry;
    while ((ent = readdir(dir)) != NULL) {
        if (ent->d_name[0] == '.') {
            continue;
        }
        size_t nlen = strlen(ent->d_name);
        if (nlen > 5 && strcmp(ent->d_name + nlen - 5, ".meta") == 0) {
            continue;
        }

        if (snprintf(file_path, sizeof(file_path), "%s/%s", dir_path, ent->d_name) >= (int) sizeof(file_path)) {
            continue;
        }
        if (stat(file_path, &st) == 0 && S_ISREG(st.st_mode)) {
            array_init(&exported_entry);
            add_assoc_string(&exported_entry, "object_id", ent->d_name);
            add_assoc_long(&exported_entry, "size_bytes", (zend_long) st.st_size);
            add_assoc_long(&exported_entry, "stored_at",  (zend_long) st.st_mtime);
            add_next_index_zval(return_array, &exported_entry);
        }
    }

    closedir(dir);
    return SUCCESS;
}

static int king_object_store_gcs_simulate_write(const char *object_id, const void *data, size_t data_size, const king_object_metadata_t *metadata)
{
    (void) metadata;
    return king_object_store_simulated_backend_write("gcs", object_id, data, data_size);
}

static int king_object_store_azure_simulate_write(const char *object_id, const void *data, size_t data_size, const king_object_metadata_t *metadata)
{
    (void) metadata;
    return king_object_store_simulated_backend_write("azure", object_id, data, data_size);
}

static int king_object_store_s3_simulate_write(const char *object_id, const void *data, size_t data_size, const king_object_metadata_t *metadata)
{
    (void) metadata;
    return king_object_store_simulated_backend_write("s3", object_id, data, data_size);
}

static int king_object_store_memcached_simulate_write(const char *object_id, const void *data, size_t data_size, const king_object_metadata_t *metadata)
{
    (void) metadata;
    return king_object_store_simulated_backend_write("memcached", object_id, data, data_size);
}

static int king_object_store_s3_simulate_read(const char *object_id, void **data, size_t *data_size)
{
    return king_object_store_simulated_backend_read("s3", object_id, data, data_size);
}

static int king_object_store_gcs_simulate_read(const char *object_id, void **data, size_t *data_size)
{
    return king_object_store_simulated_backend_read("gcs", object_id, data, data_size);
}

static int king_object_store_azure_simulate_read(const char *object_id, void **data, size_t *data_size)
{
    return king_object_store_simulated_backend_read("azure", object_id, data, data_size);
}

static int king_object_store_distributed_simulate_read(const char *object_id, void **data, size_t *data_size)
{
    return king_object_store_simulated_backend_read("memcached", object_id, data, data_size);
}

static int king_object_store_s3_simulate_remove(const char *object_id)
{
    return king_object_store_simulated_backend_remove("s3", object_id);
}

static int king_object_store_gcs_simulate_remove(const char *object_id)
{
    return king_object_store_simulated_backend_remove("gcs", object_id);
}

static int king_object_store_azure_simulate_remove(const char *object_id)
{
    return king_object_store_simulated_backend_remove("azure", object_id);
}

static int king_object_store_distributed_simulate_remove(const char *object_id)
{
    return king_object_store_simulated_backend_remove("memcached", object_id);
}

static int king_object_store_distributed_simulate_list(zval *return_array)
{
    return king_object_store_simulated_backend_list("memcached", return_array);
}

static int king_object_store_s3_simulate_list(zval *return_array)
{
    return king_object_store_simulated_backend_list("s3", return_array);
}

static int king_object_store_gcs_simulate_list(zval *return_array)
{
    return king_object_store_simulated_backend_list("gcs", return_array);
}

static int king_object_store_azure_simulate_list(zval *return_array)
{
    return king_object_store_simulated_backend_list("azure", return_array);
}

static void king_object_store_build_range_header(
    size_t offset,
    size_t length,
    zend_bool has_length,
    char *buffer,
    size_t buffer_size
)
{
    if (buffer == NULL || buffer_size == 0) {
        return;
    }

    if (has_length) {
        if (length == 0) {
            snprintf(buffer, buffer_size, "Range: bytes=%zu-%zu", offset, offset);
            return;
        }

        snprintf(
            buffer,
            buffer_size,
            "Range: bytes=%zu-%zu",
            offset,
            offset + length - 1
        );
        return;
    }

    snprintf(buffer, buffer_size, "Range: bytes=%zu-", offset);
}

static void king_object_store_list_entry_from_metadata(
    zval *entry,
    const char *object_id,
    const king_object_metadata_t *metadata,
    uint64_t fallback_size,
    time_t fallback_timestamp
)
{
    uint64_t size_bytes = fallback_size;
    time_t stored_at = fallback_timestamp;

    array_init(entry);
    add_assoc_string(entry, "object_id", (char *) object_id);

    if (metadata != NULL) {
        if (metadata->content_length > 0) {
            size_bytes = metadata->content_length;
        }
        if (metadata->modified_at > 0) {
            stored_at = metadata->modified_at;
        } else if (metadata->created_at > 0) {
            stored_at = metadata->created_at;
        }

        add_assoc_string(entry, "content_type", metadata->content_type[0] != '\0' ? metadata->content_type : "");
        add_assoc_string(entry, "content_encoding", metadata->content_encoding[0] != '\0' ? metadata->content_encoding : "");
        add_assoc_string(entry, "etag", metadata->etag[0] != '\0' ? metadata->etag : "");
        add_assoc_string(entry, "integrity_sha256", metadata->integrity_sha256[0] != '\0' ? metadata->integrity_sha256 : "");
        add_assoc_string(entry, "object_type_name", (char *) king_object_type_to_string(metadata->object_type));
        add_assoc_string(entry, "cache_policy_name", (char *) king_cache_policy_to_string(metadata->cache_policy));
        add_assoc_long(entry, "version", (zend_long) metadata->version);
        add_assoc_long(entry, "content_length", (zend_long) metadata->content_length);
        add_assoc_long(entry, "created_at", (zend_long) metadata->created_at);
        add_assoc_long(entry, "modified_at", (zend_long) metadata->modified_at);
        add_assoc_long(entry, "expires_at", (zend_long) metadata->expires_at);
        add_assoc_bool(entry, "is_expired", king_object_store_metadata_is_expired_now(metadata));
        add_assoc_long(entry, "object_type", (zend_long) metadata->object_type);
        add_assoc_long(entry, "cache_policy", (zend_long) metadata->cache_policy);
        add_assoc_long(entry, "cache_ttl_seconds", (zend_long) metadata->cache_ttl_seconds);
        add_assoc_long(entry, "local_fs_present", (zend_long) metadata->local_fs_present);
        add_assoc_long(entry, "distributed_present", (zend_long) metadata->distributed_present);
        add_assoc_long(entry, "cloud_s3_present", (zend_long) metadata->cloud_s3_present);
        add_assoc_long(entry, "cloud_gcs_present", (zend_long) metadata->cloud_gcs_present);
        add_assoc_long(entry, "cloud_azure_present", (zend_long) metadata->cloud_azure_present);
        add_assoc_long(entry, "is_backed_up", (zend_long) metadata->is_backed_up);
        add_assoc_long(entry, "replication_status", (zend_long) metadata->replication_status);
        add_assoc_long(entry, "is_distributed", (zend_long) metadata->is_distributed);
        add_assoc_long(entry, "distribution_peer_count", (zend_long) metadata->distribution_peer_count);
    }

    add_assoc_long(entry, "size_bytes", (zend_long) size_bytes);
    add_assoc_long(entry, "stored_at", (zend_long) stored_at);
}
