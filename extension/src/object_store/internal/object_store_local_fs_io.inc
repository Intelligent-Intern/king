/* --- local_fs backend --- */

void king_object_store_build_path(char *dest, size_t dest_len, const char *object_id)
{
    snprintf(dest, dest_len, "%s/%s",
        king_object_store_runtime.config.storage_root_path, object_id);
}

static int king_object_store_build_path_in_directory(
    char *dest,
    size_t dest_len,
    const char *directory,
    const char *object_id,
    const char *suffix
)
{
    int written;

    if (dest == NULL || directory == NULL || object_id == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (suffix == NULL) {
        suffix = "";
    }

    written = snprintf(dest, dest_len, "%s/%s%s", directory, object_id, suffix);
    return (written >= 0 && (size_t) written < dest_len) ? SUCCESS : FAILURE;
}

static int king_object_store_is_directory(const char *path)
{
    struct stat st;
    if (path == NULL || path[0] == '\0') {
        return 0;
    }
    if (stat(path, &st) != 0) {
        return 0;
    }
    return S_ISDIR(st.st_mode) ? 1 : 0;
}

static int king_object_store_ensure_directory(const char *path)
{
    if (path == NULL || path[0] == '\0') {
        return FAILURE;
    }

    if (mkdir(path, 0755) == 0 || errno == EEXIST) {
        return king_object_store_is_directory(path) ? SUCCESS : FAILURE;
    }

    return FAILURE;
}

static int king_object_store_ensure_directory_recursive(const char *path)
{
    if (path == NULL || path[0] == '\0') {
        return FAILURE;
    }

    if (king_object_store_mkdir_parents(path) != SUCCESS) {
        return FAILURE;
    }

    return king_object_store_ensure_directory(path);
}

static int king_object_store_atomic_write_file(const char *target_path, const void *data, size_t data_size)
{
    FILE *fp;
    int temp_fd;
    char temp_path[1024];

    if (target_path == NULL || data == NULL) {
        return FAILURE;
    }

    if (snprintf(temp_path, sizeof(temp_path), "%s.XXXXXX", target_path) >= (int) sizeof(temp_path)) {
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

    if (data_size > 0) {
        if (fwrite(data, 1, data_size, fp) != data_size) {
            fclose(fp);
            unlink(temp_path);
            return FAILURE;
        }
    }

    if (fclose(fp) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    if (rename(temp_path, target_path) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    return SUCCESS;
}

static int king_object_store_copy_file_to_path(
    const char *source_path,
    const char *target_path
)
{
    FILE *source_fp = NULL;
    FILE *destination_fp = NULL;
    unsigned char *buffer = NULL;
    size_t chunk_bytes;
    char temp_path[1024];
    int temp_fd = -1;
    int rc = FAILURE;

    if (source_path == NULL || target_path == NULL) {
        return FAILURE;
    }
    if (king_object_store_mkdir_parents(target_path) != SUCCESS) {
        return FAILURE;
    }
    if (snprintf(temp_path, sizeof(temp_path), "%s.XXXXXX", target_path) >= (int) sizeof(temp_path)) {
        return FAILURE;
    }

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        goto cleanup;
    }

    temp_fd = mkstemp(temp_path);
    if (temp_fd < 0) {
        goto cleanup;
    }

    destination_fp = fdopen(temp_fd, "wb");
    if (destination_fp == NULL) {
        close(temp_fd);
        temp_fd = -1;
        goto cleanup;
    }
    temp_fd = -1;

    chunk_bytes = king_object_store_streaming_chunk_bytes();
    buffer = emalloc(chunk_bytes);

    while (1) {
        size_t bytes_read = fread(buffer, 1, chunk_bytes, source_fp);

        if (bytes_read == 0) {
            if (feof(source_fp)) {
                break;
            }
            goto cleanup;
        }

        if (fwrite(buffer, 1, bytes_read, destination_fp) != bytes_read) {
            goto cleanup;
        }
    }

    if (fclose(destination_fp) != 0) {
        destination_fp = NULL;
        goto cleanup;
    }
    destination_fp = NULL;

    if (rename(temp_path, target_path) != 0) {
        goto cleanup;
    }

    rc = SUCCESS;

cleanup:
    if (buffer != NULL) {
        efree(buffer);
    }
    if (source_fp != NULL) {
        fclose(source_fp);
    }
    if (destination_fp != NULL) {
        fclose(destination_fp);
    } else if (temp_fd >= 0) {
        close(temp_fd);
    }
    if (rc != SUCCESS && temp_path[0] != '\0') {
        unlink(temp_path);
    }

    return rc;
}

static int king_object_store_mkdir_parents(const char *path)
{
    size_t len;
    size_t i;
    size_t slash_count = 0;
    char current[1024];

    if (path == NULL || path[0] == '\0') {
        return FAILURE;
    }
    if (strlen(path) >= sizeof(current)) {
        return FAILURE;
    }
    memcpy(current, path, strlen(path) + 1);

    len = strlen(current);
    if (len == 0) {
        return FAILURE;
    }
    if (current[len - 1] == '/' && len > 1) {
        current[len - 1] = '\0';
        len--;
    }
    if (len == 0) {
        return FAILURE;
    }

    for (i = 0; i < len; i++) {
        if (current[i] == '/') {
            slash_count++;
        }
    }
    if (slash_count == 0) {
        return SUCCESS;
    }

    for (i = 1; i < len; i++) {
        if (current[i] == '/') {
            char old = current[i];
            current[i] = '\0';
            if (mkdir(current, 0755) != 0 && errno != EEXIST) {
                if (!king_object_store_is_directory(current)) {
                    current[i] = old;
                    return FAILURE;
                }
            }
            current[i] = old;
        }
    }

    return SUCCESS;
}

static int king_object_store_read_file_contents(
    const char *source_path,
    void **data,
    size_t *data_size
)
{
    FILE *fp;
    struct stat st;

    if (source_path == NULL || data == NULL || data_size == NULL) {
        return FAILURE;
    }

    if (stat(source_path, &st) != 0 || !S_ISREG(st.st_mode)) {
        return FAILURE;
    }

    *data_size = (size_t) st.st_size;
    *data = pemalloc(*data_size + 1, 1);
    if (*data == NULL) {
        *data_size = 0;
        return FAILURE;
    }

    fp = fopen(source_path, "rb");
    if (fp == NULL) {
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        return FAILURE;
    }

    if (*data_size > 0 && fread(*data, 1, *data_size, fp) != *data_size) {
        fclose(fp);
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        return FAILURE;
    }

    ((char *) *data)[*data_size] = '\0';
    fclose(fp);
    return SUCCESS;
}

static int king_object_store_metadata_parse_stream(FILE *fp, king_object_metadata_t *metadata)
{
    char line[640];

    if (fp == NULL || metadata == NULL) {
        return FAILURE;
    }

    memset(metadata, 0, sizeof(*metadata));

    while (fgets(line, sizeof(line), fp) != NULL) {
        char *eq = strchr(line, '=');
        if (eq == NULL) {
            continue;
        }

        *eq = '\0';
        char *key = line;
        char *val = eq + 1;
        size_t vlen;

        vlen = strlen(val);
        if (vlen > 0 && val[vlen - 1] == '\n') {
            val[vlen - 1] = '\0';
        }

        if (strcmp(key, "object_id") == 0) {
            strncpy(metadata->object_id, val, sizeof(metadata->object_id) - 1);
        } else if (strcmp(key, "content_type") == 0) {
            strncpy(metadata->content_type, val, sizeof(metadata->content_type) - 1);
        } else if (strcmp(key, "content_encoding") == 0) {
            strncpy(metadata->content_encoding, val, sizeof(metadata->content_encoding) - 1);
        } else if (strcmp(key, "etag") == 0) {
            strncpy(metadata->etag, val, sizeof(metadata->etag) - 1);
        } else if (strcmp(key, "integrity_sha256") == 0) {
            strncpy(metadata->integrity_sha256, val, sizeof(metadata->integrity_sha256) - 1);
        } else if (strcmp(key, "content_length") == 0) {
            metadata->content_length = (uint64_t) strtoull(val, NULL, 10);
        } else if (strcmp(key, "version") == 0) {
            metadata->version = (uint64_t) strtoull(val, NULL, 10);
        } else if (strcmp(key, "created_at") == 0) {
            metadata->created_at = (time_t) strtoll(val, NULL, 10);
        } else if (strcmp(key, "modified_at") == 0) {
            metadata->modified_at = (time_t) strtoll(val, NULL, 10);
        } else if (strcmp(key, "expires_at") == 0) {
            metadata->expires_at = (time_t) strtoll(val, NULL, 10);
        } else if (strcmp(key, "object_type") == 0) {
            metadata->object_type = (king_object_type_t) atoi(val);
        } else if (strcmp(key, "cache_policy") == 0) {
            metadata->cache_policy = (king_cache_policy_t) atoi(val);
        } else if (strcmp(key, "cache_ttl_seconds") == 0) {
            metadata->cache_ttl_seconds = (uint32_t) strtoul(val, NULL, 10);
        } else if (strcmp(key, "local_fs_present") == 0) {
            metadata->local_fs_present = (uint8_t) atoi(val);
        } else if (strcmp(key, "distributed_present") == 0) {
            metadata->distributed_present = (uint8_t) atoi(val);
        } else if (strcmp(key, "cloud_s3_present") == 0) {
            metadata->cloud_s3_present = (uint8_t) atoi(val);
        } else if (strcmp(key, "cloud_gcs_present") == 0) {
            metadata->cloud_gcs_present = (uint8_t) atoi(val);
        } else if (strcmp(key, "cloud_azure_present") == 0) {
            metadata->cloud_azure_present = (uint8_t) atoi(val);
        } else if (strcmp(key, "is_backed_up") == 0) {
            metadata->is_backed_up = (uint8_t) atoi(val);
        } else if (strcmp(key, "replication_status") == 0) {
            metadata->replication_status = (uint8_t) atoi(val);
        } else if (strcmp(key, "is_distributed") == 0) {
            metadata->is_distributed = (uint8_t) atoi(val);
        } else if (strcmp(key, "distribution_peer_count") == 0) {
            metadata->distribution_peer_count = (uint32_t) strtoul(val, NULL, 10);
        }
    }

    return SUCCESS;
}

static int king_object_store_meta_write_to_path(const char *path, const king_object_metadata_t *metadata)
{
    FILE *fp = NULL;
    king_object_metadata_t scratch;
    char temp_path[1024];
    int temp_fd = -1;

    if (path == NULL || path[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_mkdir_parents(path) != SUCCESS) {
        return FAILURE;
    }
    if (snprintf(temp_path, sizeof(temp_path), "%s.XXXXXX", path) >= (int) sizeof(temp_path)) {
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

    if (metadata == NULL) {
        memset(&scratch, 0, sizeof(scratch));
        scratch.created_at = time(NULL);
        metadata = &scratch;
    }

    fprintf(fp,
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
        "local_fs_present=%d\n"
        "distributed_present=%d\n"
        "cloud_s3_present=%d\n"
        "cloud_gcs_present=%d\n"
        "cloud_azure_present=%d\n"
        "is_backed_up=%d\n"
        "replication_status=%d\n"
        "is_distributed=%d\n"
        "distribution_peer_count=%u\n",
        metadata->object_id,
        metadata->content_type,
        metadata->content_encoding,
        metadata->etag,
        metadata->integrity_sha256,
        (uint64_t) metadata->content_length,
        (uint64_t) metadata->version,
        (int64_t)  metadata->created_at,
        (int64_t)  metadata->modified_at,
        (int64_t)  metadata->expires_at,
        (int)      metadata->object_type,
        (int)      metadata->cache_policy,
        (unsigned) metadata->cache_ttl_seconds,
        (int)      metadata->local_fs_present,
        (int)      metadata->distributed_present,
        (int)      metadata->cloud_s3_present,
        (int)      metadata->cloud_gcs_present,
        (int)      metadata->cloud_azure_present,
        (int)      metadata->is_backed_up,
        (int)      metadata->replication_status,
        (int)      metadata->is_distributed,
        (unsigned) metadata->distribution_peer_count
    );

    if (fclose(fp) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    if (rename(temp_path, path) != 0) {
        unlink(temp_path);
        return FAILURE;
    }

    return SUCCESS;
}

static int king_object_store_meta_read_from_path(const char *path, king_object_metadata_t *metadata)
{
    FILE *fp;

    if (path == NULL || metadata == NULL) {
        return FAILURE;
    }

    fp = fopen(path, "r");
    if (fp == NULL) {
        return FAILURE;
    }

    if (king_object_store_metadata_parse_stream(fp, metadata) != SUCCESS) {
        fclose(fp);
        return FAILURE;
    }

    fclose(fp);
    return SUCCESS;
}

/* --- Durable metadata sidecar helpers --- */

void king_object_store_build_meta_path(char *dest, size_t dest_len, const char *object_id)
{
    snprintf(dest, dest_len, "%s/%s.meta",
        king_object_store_runtime.config.storage_root_path, object_id);
}

/*
 * Simple text key=value format, one entry per line:
 *   object_id=<str>\n
 *   content_type=<str>\n
 *   content_encoding=<str>\n
 *   etag=<str>\n
 *   integrity_sha256=<hex>\n
 *   content_length=<uint64>\n
 *   version=<uint64>\n
 *   created_at=<int64>\n
 *   modified_at=<int64>\n
 *   expires_at=<int64>\n
 *   object_type=<int>\n
 *   cache_policy=<int>\n
 *   cache_ttl_seconds=<uint32>\n
 *   is_backed_up=<bool/uint8>\n
 *   replication_status=<uint8>\n
 */
int king_object_store_meta_write(const char *object_id, const king_object_metadata_t *metadata)
{
    char meta_path[1024];
    king_object_metadata_t scratch;
    king_object_metadata_t final_metadata = {0};
    const king_object_metadata_t *metadata_source = NULL;

    if (metadata == NULL) {
        memset(&scratch, 0, sizeof(scratch));
        metadata_source = &scratch;
    } else {
        final_metadata = *metadata;
        metadata_source = &final_metadata;
    }
    if (metadata_source->object_id[0] == '\0' && object_id != NULL) {
        strncpy(final_metadata.object_id, object_id, sizeof(final_metadata.object_id) - 1);
        metadata_source = &final_metadata;
    }
    if (object_id == NULL) {
        return FAILURE;
    }

    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }

    king_object_store_build_meta_path(meta_path, sizeof(meta_path), object_id);
    if (king_object_store_meta_write_to_path(meta_path, metadata_source) != SUCCESS) {
        return FAILURE;
    }

    (void) king_object_store_runtime_metadata_cache_store(object_id, metadata_source);
    return SUCCESS;
}

int king_object_store_meta_read(const char *object_id, king_object_metadata_t *metadata)
{
    char meta_path[1024];

    if (king_object_store_object_id_validate(object_id) != NULL || metadata == NULL) {
        return FAILURE;
    }

    king_object_store_build_meta_path(meta_path, sizeof(meta_path), object_id);
    if (king_object_store_meta_read_from_path(meta_path, metadata) != SUCCESS) {
        return FAILURE;
    }

    (void) king_object_store_runtime_metadata_cache_store(object_id, metadata);
    return SUCCESS;
}

void king_object_store_meta_remove(const char *object_id)
{
    char meta_path[1024];
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return;
    }
    king_object_store_build_meta_path(meta_path, sizeof(meta_path), object_id);
    unlink(meta_path);
    king_object_store_runtime_metadata_cache_remove(object_id);
}

/*
 * Rehydrate live stats from disk by scanning the storage root.
 * Called after init_system so the runtime counters match the actual
 * on-disk state even across process restarts.
 */
