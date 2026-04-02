/*
 * Streaming helper slice for the object store. Provides SHA-256 and stream
 * transfer utilities reused by local and provider-backed object flows.
 */

void king_object_store_compute_sha256_hex(const void *data, size_t data_size, char output[65])
{
    PHP_SHA256_CTX ctx;
    unsigned char digest[32];
    static const char hex[] = "0123456789abcdef";
    size_t i;

    if (output == NULL) {
        return;
    }

    PHP_SHA256Init(&ctx);
    if (data_size > 0 && data != NULL) {
        PHP_SHA256Update(&ctx, (const unsigned char *) data, data_size);
    }
    PHP_SHA256Final(digest, &ctx);

    for (i = 0; i < sizeof(digest); ++i) {
        output[(i * 2)] = hex[(digest[i] >> 4) & 0x0f];
        output[(i * 2) + 1] = hex[digest[i] & 0x0f];
    }
    output[64] = '\0';
}

static void king_object_store_prepare_metadata_for_write(
    const char *object_id,
    const void *data,
    size_t data_size,
    king_object_metadata_t *metadata,
    const king_object_metadata_t *existing_metadata
)
{
    char computed_sha256[65];

    king_object_store_compute_sha256_hex(data, data_size, computed_sha256);
    king_object_store_finalize_metadata_for_write(
        object_id,
        (uint64_t) data_size,
        computed_sha256,
        metadata,
        existing_metadata
    );
}

static void king_object_store_finalize_metadata_for_write(
    const char *object_id,
    uint64_t data_size,
    const char *integrity_sha256,
    king_object_metadata_t *metadata,
    const king_object_metadata_t *existing_metadata
)
{
    time_t now;

    if (metadata == NULL || object_id == NULL) {
        return;
    }

    now = time(NULL);

    if (metadata->object_id[0] == '\0') {
        strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
    }
    if (metadata->content_length == 0) {
        metadata->content_length = data_size;
    }
    if (metadata->created_at == 0) {
        metadata->created_at = existing_metadata != NULL && existing_metadata->created_at > 0
            ? existing_metadata->created_at
            : now;
    }
    metadata->modified_at = now;
    if (metadata->version == 0) {
        if (existing_metadata != NULL) {
            metadata->version = existing_metadata->version > 0
                ? existing_metadata->version + 1
                : 2;
        } else {
            metadata->version = 1;
        }
    }
    if (metadata->integrity_sha256[0] == '\0' && integrity_sha256 != NULL && integrity_sha256[0] != '\0') {
        strncpy(metadata->integrity_sha256, integrity_sha256, sizeof(metadata->integrity_sha256) - 1);
    }
    if (metadata->etag[0] == '\0') {
        strncpy(metadata->etag, metadata->integrity_sha256, sizeof(metadata->etag) - 1);
    }
    if (metadata->content_type[0] == '\0') {
        strncpy(metadata->content_type, "application/octet-stream", sizeof(metadata->content_type) - 1);
    }
}

static size_t king_object_store_streaming_chunk_bytes(void)
{
    size_t chunk_bytes = (size_t) king_object_store_runtime.config.chunk_size_kb * 1024U;

    if (chunk_bytes == 0) {
        chunk_bytes = 64 * 1024;
    }

    return chunk_bytes;
}

static int king_object_store_copy_path_to_stream(
    const char *source_path,
    php_stream *destination_stream,
    size_t offset,
    size_t length,
    zend_bool has_length
)
{
    FILE *source_fp;
    unsigned char *buffer;
    size_t chunk_bytes;
    uint64_t remaining;

    if (source_path == NULL || destination_stream == NULL) {
        return FAILURE;
    }

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        return FAILURE;
    }
    if (fseeko(source_fp, (off_t) offset, SEEK_SET) != 0) {
        fclose(source_fp);
        return FAILURE;
    }

    chunk_bytes = king_object_store_streaming_chunk_bytes();
    buffer = emalloc(chunk_bytes);
    remaining = has_length ? (uint64_t) length : UINT64_MAX;

    while (remaining > 0) {
        size_t want = chunk_bytes;
        size_t bytes_read;
        size_t bytes_written;

        if (has_length && remaining < (uint64_t) want) {
            want = (size_t) remaining;
        }

        bytes_read = fread(buffer, 1, want, source_fp);
        if (bytes_read == 0) {
            if (feof(source_fp)) {
                break;
            }
            efree(buffer);
            fclose(source_fp);
            return FAILURE;
        }

        bytes_written = php_stream_write(destination_stream, (char *) buffer, bytes_read);
        if (bytes_written != bytes_read) {
            efree(buffer);
            fclose(source_fp);
            return FAILURE;
        }

        if (has_length) {
            remaining -= (uint64_t) bytes_read;
        }
    }

    efree(buffer);
    fclose(source_fp);
    return SUCCESS;
}

static int king_object_store_backup_object_from_file_to_backend(
    const char *object_id,
    const char *source_path,
    const king_object_metadata_t *metadata,
    king_storage_backend_t backup_backend
)
{
    int rc = FAILURE;
    king_object_metadata_t metadata_snapshot;
    char backup_error[512] = {0};

    if (object_id == NULL || object_id[0] == '\0' || source_path == NULL) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            "Missing object payload for backup operation."
        );
        return FAILURE;
    }

    if (!king_object_store_runtime.initialized) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            "Object store is not initialized."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend(
            "backup",
            backup_backend,
            "object backup") == FAILURE) {
        return FAILURE;
    }

    switch (backup_backend) {
        case KING_STORAGE_BACKEND_DISTRIBUTED:
            rc = king_object_store_distributed_write_from_file_internal(
                object_id,
                source_path,
                metadata,
                0,
                backup_error,
                sizeof(backup_error)
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_S3:
            rc = king_object_store_s3_write_from_file(
                object_id,
                source_path,
                metadata,
                backup_error,
                sizeof(backup_error),
                0
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            rc = king_object_store_gcs_write_from_file(
                object_id,
                source_path,
                metadata,
                backup_error,
                sizeof(backup_error),
                0
            );
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            rc = king_object_store_azure_write_from_file(
                object_id,
                source_path,
                metadata,
                backup_error,
                sizeof(backup_error),
                0
            );
            break;
        case KING_STORAGE_BACKEND_LOCAL_FS:
        case KING_STORAGE_BACKEND_MEMORY_CACHE:
            king_object_store_set_backend_runtime_result(
                "backup",
                backup_backend,
                FAILURE,
                "Local backup backends are not implemented for a non-local primary object-store backend."
            );
            return FAILURE;
        default:
            king_object_store_set_backend_runtime_result(
                "backup",
                backup_backend,
                FAILURE,
                "Unsupported backup object-store backend."
            );
            return FAILURE;
    }

    if (rc != SUCCESS) {
        king_object_store_set_backend_runtime_result(
            "backup",
            backup_backend,
            FAILURE,
            backup_error[0] == '\0' ? "Backup object-store backend write failed." : backup_error
        );
        return FAILURE;
    }

    if (king_object_store_backend_read_metadata(object_id, &metadata_snapshot) == SUCCESS) {
        if (metadata_snapshot.is_backed_up == 0) {
            metadata_snapshot.is_backed_up = 1;
        }
        king_object_store_metadata_mark_backend_present(
            &metadata_snapshot,
            king_object_store_runtime.config.primary_backend,
            1
        );
        king_object_store_metadata_mark_backend_present(
            &metadata_snapshot,
            backup_backend,
            1
        );
        king_object_store_meta_write(object_id, &metadata_snapshot);
    }

    king_object_store_set_backend_runtime_result(
        "backup",
        backup_backend,
        SUCCESS,
        NULL
    );
    return SUCCESS;
}

static int king_object_store_compute_sha256_hex_for_path(
    const char *source_path,
    char output[65],
    uint64_t *size_out
)
{
    FILE *source_fp;
    unsigned char *buffer;
    unsigned char digest[32];
    PHP_SHA256_CTX ctx;
    uint64_t total = 0;
    size_t chunk_bytes;
    static const char hex[] = "0123456789abcdef";
    size_t i;

    if (source_path == NULL || output == NULL) {
        return FAILURE;
    }

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        return FAILURE;
    }

    chunk_bytes = king_object_store_streaming_chunk_bytes();
    buffer = emalloc(chunk_bytes);
    PHP_SHA256Init(&ctx);

    while (1) {
        size_t bytes_read = fread(buffer, 1, chunk_bytes, source_fp);

        if (bytes_read == 0) {
            if (feof(source_fp)) {
                break;
            }
            efree(buffer);
            fclose(source_fp);
            return FAILURE;
        }

        PHP_SHA256Update(&ctx, buffer, bytes_read);
        total += (uint64_t) bytes_read;
    }

    PHP_SHA256Final(digest, &ctx);
    efree(buffer);
    fclose(source_fp);

    for (i = 0; i < sizeof(digest); ++i) {
        output[i * 2] = hex[(digest[i] >> 4) & 0x0f];
        output[(i * 2) + 1] = hex[digest[i] & 0x0f];
    }
    output[64] = '\0';

    if (size_out != NULL) {
        *size_out = total;
    }
    return SUCCESS;
}

static int king_object_store_seed_sha256_ctx_from_path(
    const char *source_path,
    PHP_SHA256_CTX *ctx,
    uint64_t *size_out
)
{
    FILE *source_fp;
    unsigned char *buffer;
    uint64_t total = 0;
    size_t chunk_bytes;

    if (source_path == NULL || ctx == NULL) {
        return FAILURE;
    }

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        return FAILURE;
    }

    chunk_bytes = king_object_store_streaming_chunk_bytes();
    buffer = emalloc(chunk_bytes);
    PHP_SHA256Init(ctx);

    while (1) {
        size_t bytes_read = fread(buffer, 1, chunk_bytes, source_fp);

        if (bytes_read == 0) {
            if (feof(source_fp)) {
                break;
            }
            efree(buffer);
            fclose(source_fp);
            return FAILURE;
        }

        PHP_SHA256Update(ctx, buffer, bytes_read);
        total += (uint64_t) bytes_read;
    }

    efree(buffer);
    fclose(source_fp);

    if (size_out != NULL) {
        *size_out = total;
    }
    return SUCCESS;
}

static int king_object_store_build_upload_session_directory(
    char *destination,
    size_t destination_size
)
{
    const char *base_directory;

    if (destination == NULL || destination_size == 0) {
        return FAILURE;
    }

    base_directory = king_object_store_runtime.config.storage_root_path[0] != '\0'
        ? king_object_store_runtime.config.storage_root_path
        : "/tmp";
    if (snprintf(destination, destination_size, "%s/.king_upload_sessions", base_directory) >= (int) destination_size) {
        return FAILURE;
    }
    return SUCCESS;
}

static int king_object_store_build_upload_session_path(
    const char *upload_id,
    const char *suffix,
    char *destination,
    size_t destination_size
)
{
    char directory[PATH_MAX];

    if (upload_id == NULL || upload_id[0] == '\0' || suffix == NULL
        || destination == NULL || destination_size == 0) {
        return FAILURE;
    }
    if (king_object_store_build_upload_session_directory(directory, sizeof(directory)) != SUCCESS) {
        return FAILURE;
    }
    if (snprintf(destination, destination_size, "%s/%s%s", directory, upload_id, suffix) >= (int) destination_size) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_object_store_upload_session_assign_paths(king_object_store_upload_session_t *session)
{
    char directory[PATH_MAX];

    if (session == NULL || session->upload_id[0] == '\0') {
        return FAILURE;
    }
    if (king_object_store_build_upload_session_directory(directory, sizeof(directory)) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_ensure_directory_recursive(directory) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_build_upload_session_path(
            session->upload_id,
            ".assembly",
            session->assembly_path,
            sizeof(session->assembly_path)
        ) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_build_upload_session_path(
            session->upload_id,
            ".state",
            session->state_path,
            sizeof(session->state_path)
        ) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_build_upload_session_path(
            session->upload_id,
            ".metadata",
            session->metadata_path,
            sizeof(session->metadata_path)
        ) != SUCCESS) {
        return FAILURE;
    }
    if (king_object_store_build_upload_session_path(
            session->upload_id,
            ".existing",
            session->existing_metadata_path,
            sizeof(session->existing_metadata_path)
        ) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_object_store_create_temp_file_path(char *destination, size_t destination_size)
{
    const char *base_directory;
    int temp_fd;

    if (destination == NULL || destination_size == 0) {
        return FAILURE;
    }

    base_directory = king_object_store_runtime.config.storage_root_path[0] != '\0'
        ? king_object_store_runtime.config.storage_root_path
        : "/tmp";
    if (snprintf(destination, destination_size, "%s/.king_object_tmp_XXXXXX", base_directory) >= (int) destination_size) {
        return FAILURE;
    }

    temp_fd = mkstemp(destination);
    if (temp_fd < 0) {
        destination[0] = '\0';
        return FAILURE;
    }
    close(temp_fd);
    return SUCCESS;
}

static int king_object_store_build_lock_path(
    const char *object_id,
    char *destination,
    size_t destination_size
)
{
    char lock_directory[1024];

    if (object_id == NULL || destination == NULL || destination_size == 0) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }

    if (snprintf(
            lock_directory,
            sizeof(lock_directory),
            "%s/.king_object_locks",
            king_object_store_runtime.config.storage_root_path[0] != '\0'
                ? king_object_store_runtime.config.storage_root_path
                : "/tmp"
        ) >= (int) sizeof(lock_directory)) {
        return FAILURE;
    }
    if (king_object_store_ensure_directory_recursive(lock_directory) != SUCCESS) {
        return FAILURE;
    }
    if (snprintf(
            destination,
            destination_size,
            "%s/%s.lock",
            lock_directory,
            object_id
        ) >= (int) destination_size) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_object_store_build_mutation_barrier_path(
    char *destination,
    size_t destination_size
)
{
    if (destination == NULL || destination_size == 0) {
        return FAILURE;
    }

    if (king_object_store_runtime.config.storage_root_path[0] != '\0'
        && king_object_store_ensure_directory_recursive(
            king_object_store_runtime.config.storage_root_path
        ) != SUCCESS) {
        return FAILURE;
    }

    if (snprintf(
            destination,
            destination_size,
            "%s/.king_object_mutation_barrier.lock",
            king_object_store_runtime.config.storage_root_path[0] != '\0'
                ? king_object_store_runtime.config.storage_root_path
                : "/tmp"
        ) >= (int) destination_size) {
        return FAILURE;
    }

    return SUCCESS;
}
