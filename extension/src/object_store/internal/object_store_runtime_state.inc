static zend_bool king_object_store_verify_integrity_buffer(
    const void *data,
    size_t data_size,
    const king_object_metadata_t *metadata
)
{
    char computed[65];

    if (metadata == NULL || metadata->integrity_sha256[0] == '\0') {
        return 1;
    }

    king_object_store_compute_sha256_hex(data, data_size, computed);
    return strcmp(computed, metadata->integrity_sha256) == 0;
}

static void king_object_store_runtime_metadata_cache_entry_free(king_object_metadata_t *metadata)
{
    if (metadata == NULL) {
        return;
    }

    pefree(metadata, 1);
}

static void king_object_store_runtime_metadata_cache_zval_dtor(zval *zv)
{
    if (Z_TYPE_P(zv) == IS_PTR) {
        king_object_store_runtime_metadata_cache_entry_free((king_object_metadata_t *) Z_PTR_P(zv));
    }
}

static int king_object_store_runtime_metadata_cache_ensure(void)
{
    if (king_object_store_runtime_metadata_cache_initialized) {
        return SUCCESS;
    }

    zend_hash_init(
        &king_object_store_runtime_metadata_cache,
        8,
        NULL,
        king_object_store_runtime_metadata_cache_zval_dtor,
        1
    );
    king_object_store_runtime_metadata_cache_initialized = true;
    return SUCCESS;
}

static void king_object_store_runtime_metadata_cache_reset(void)
{
    if (!king_object_store_runtime_metadata_cache_initialized) {
        return;
    }

    zend_hash_destroy(&king_object_store_runtime_metadata_cache);
    king_object_store_runtime_metadata_cache_initialized = false;
}

static void king_object_store_runtime_metadata_cache_clear(void)
{
    if (!king_object_store_runtime_metadata_cache_initialized) {
        return;
    }

    zend_hash_clean(&king_object_store_runtime_metadata_cache);
}

static int king_object_store_runtime_metadata_cache_store(
    const char *object_id,
    const king_object_metadata_t *metadata
)
{
    zend_string *persistent_key;
    king_object_metadata_t *copy;
    zval stored_metadata;

    if (object_id == NULL || object_id[0] == '\0' || metadata == NULL) {
        return FAILURE;
    }

    if (king_object_store_runtime_metadata_cache_ensure() != SUCCESS) {
        return FAILURE;
    }

    copy = pemalloc(sizeof(*copy), 1);
    if (copy == NULL) {
        return FAILURE;
    }
    *copy = *metadata;

    ZVAL_PTR(&stored_metadata, copy);
    persistent_key = zend_string_init(object_id, strlen(object_id), 1);
    zend_hash_update(&king_object_store_runtime_metadata_cache, persistent_key, &stored_metadata);
    zend_string_release_ex(persistent_key, 1);

    return SUCCESS;
}

int king_object_store_runtime_metadata_cache_read(
    const char *object_id,
    king_object_metadata_t *metadata
)
{
    zval *cached_entry;

    if (object_id == NULL || metadata == NULL || !king_object_store_runtime_metadata_cache_initialized) {
        return FAILURE;
    }

    cached_entry = zend_hash_str_find(
        &king_object_store_runtime_metadata_cache,
        object_id,
        strlen(object_id)
    );
    if (cached_entry == NULL || Z_TYPE_P(cached_entry) != IS_PTR || Z_PTR_P(cached_entry) == NULL) {
        return FAILURE;
    }

    *metadata = *((king_object_metadata_t *) Z_PTR_P(cached_entry));
    return SUCCESS;
}

static void king_object_store_runtime_metadata_cache_remove(const char *object_id)
{
    if (object_id == NULL || !king_object_store_runtime_metadata_cache_initialized) {
        return;
    }

    zend_hash_str_del(
        &king_object_store_runtime_metadata_cache,
        object_id,
        strlen(object_id)
    );
}

static zend_result king_object_store_fill_random_bytes(uint8_t *target, size_t target_len)
{
    int fd;
    size_t total = 0;

    if (target == NULL || target_len == 0) {
        return FAILURE;
    }

    fd = open("/dev/urandom", O_RDONLY | O_CLOEXEC);
    if (fd < 0) {
        return FAILURE;
    }

    while (total < target_len) {
        ssize_t chunk = read(fd, target + total, target_len - total);

        if (chunk <= 0) {
            close(fd);
            return FAILURE;
        }

        total += (size_t) chunk;
    }

    close(fd);
    return SUCCESS;
}

int king_object_store_acquire_object_lock(
    const char *object_id,
    int *lock_fd_out,
    char *error,
    size_t error_size
)
{
    char lock_path[1024];
    int fd;

    if (lock_fd_out != NULL) {
        *lock_fd_out = -1;
    }
    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }

    if (!king_object_store_runtime.initialized || object_id == NULL || object_id[0] == '\0') {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Object-store runtime is unavailable for mutation locking.");
        }
        return FAILURE;
    }
    if (king_object_store_build_lock_path(object_id, lock_path, sizeof(lock_path)) != SUCCESS) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to prepare the object-store mutation lock for '%s'.", object_id);
        }
        return FAILURE;
    }

    fd = open(lock_path, O_RDWR | O_CREAT | O_CLOEXEC, 0666);
    if (fd < 0) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to open the object-store mutation lock for '%s'.", object_id);
        }
        return FAILURE;
    }

    if (flock(fd, LOCK_EX | LOCK_NB) != 0) {
        if (error != NULL && error_size > 0) {
            if (errno == EWOULDBLOCK || errno == EAGAIN) {
                snprintf(error, error_size, "Object-store object '%s' already has an active mutation.", object_id);
            } else {
                snprintf(error, error_size, "Failed to acquire the object-store mutation lock for '%s'.", object_id);
            }
        }
        close(fd);
        return FAILURE;
    }

    if (lock_fd_out != NULL) {
        *lock_fd_out = fd;
    } else {
        close(fd);
        return FAILURE;
    }

    return SUCCESS;
}

void king_object_store_release_object_lock(int *lock_fd)
{
    if (lock_fd == NULL || *lock_fd < 0) {
        return;
    }

    (void) flock(*lock_fd, LOCK_UN);
    close(*lock_fd);
    *lock_fd = -1;
}

static void king_object_store_upload_session_destroy_ptr(king_object_store_upload_session_t *session)
{
    if (session == NULL) {
        return;
    }

    if (session->lock_fd >= 0) {
        king_object_store_release_object_lock(&session->lock_fd);
    }

    if (session->completed || session->aborted) {
        king_object_store_upload_session_remove_persisted_state(session);
    }

    zend_hash_destroy(&session->provider_parts);
    efree(session);
}

static void king_object_store_upload_session_zval_dtor(zval *zv)
{
    if (Z_TYPE_P(zv) == IS_PTR) {
        king_object_store_upload_session_destroy_ptr((king_object_store_upload_session_t *) Z_PTR_P(zv));
    }
}

static int king_object_store_upload_sessions_ensure(void)
{
    if (king_object_store_upload_sessions_initialized) {
        return SUCCESS;
    }

    zend_hash_init(
        &king_object_store_upload_sessions,
        8,
        NULL,
        king_object_store_upload_session_zval_dtor,
        0
    );
    king_object_store_upload_sessions_initialized = true;
    return SUCCESS;
}

static void king_object_store_upload_sessions_reset(void)
{
    if (!king_object_store_upload_sessions_initialized) {
        return;
    }

    zend_hash_destroy(&king_object_store_upload_sessions);
    king_object_store_upload_sessions_initialized = false;
}

static int king_object_store_upload_session_store(const king_object_store_upload_session_t *session)
{
    zval stored_session;

    if (session == NULL || session->upload_id[0] == '\0') {
        return FAILURE;
    }

    if (king_object_store_upload_sessions_ensure() != SUCCESS) {
        return FAILURE;
    }

    ZVAL_PTR(&stored_session, (void *) session);
    zend_hash_str_update(
        &king_object_store_upload_sessions,
        session->upload_id,
        strlen(session->upload_id),
        &stored_session
    );
    return SUCCESS;
}

static king_object_store_upload_session_t *king_object_store_upload_session_find(const char *upload_id)
{
    zval *entry;

    if (upload_id == NULL || upload_id[0] == '\0' || !king_object_store_upload_sessions_initialized) {
        return NULL;
    }

    entry = zend_hash_str_find(
        &king_object_store_upload_sessions,
        upload_id,
        strlen(upload_id)
    );
    if (entry == NULL || Z_TYPE_P(entry) != IS_PTR || Z_PTR_P(entry) == NULL) {
        return NULL;
    }

    return (king_object_store_upload_session_t *) Z_PTR_P(entry);
}

static int king_object_store_upload_session_persist(
    const king_object_store_upload_session_t *session,
    char *error,
    size_t error_size
)
{
    FILE *fp = NULL;
    char temp_path[PATH_MAX];
    int temp_fd = -1;
    zval *part_value;
    zend_ulong part_number;

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    if (session == NULL || session->state_path[0] == '\0') {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to persist object-store upload session state.");
        }
        return FAILURE;
    }

    if (snprintf(temp_path, sizeof(temp_path), "%s.XXXXXX", session->state_path) >= (int) sizeof(temp_path)) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to prepare a temporary object-store upload state file.");
        }
        return FAILURE;
    }

    temp_fd = mkstemp(temp_path);
    if (temp_fd < 0) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to create a temporary object-store upload state file.");
        }
        return FAILURE;
    }

    fp = fdopen(temp_fd, "wb");
    if (fp == NULL) {
        close(temp_fd);
        unlink(temp_path);
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to open a temporary object-store upload state file.");
        }
        return FAILURE;
    }
    temp_fd = -1;

    fprintf(fp,
        "upload_id=%s\n"
        "object_id=%s\n"
        "provider_token=%s\n"
        "expected_integrity_sha256=%s\n"
        "backend=%d\n"
        "protocol=%d\n"
        "uploaded_bytes=%" PRIu64 "\n"
        "next_offset=%" PRIu64 "\n"
        "old_size=%" PRIu64 "\n"
        "chunk_size_bytes=%" PRIu64 "\n"
        "next_part_number=%u\n"
        "created_at=%" PRId64 "\n"
        "updated_at=%" PRId64 "\n"
        "existed_before=%d\n"
        "sequential_chunks_required=%d\n"
        "final_chunk_may_be_shorter=%d\n"
        "final_chunk_received=%d\n"
        "remote_completed=%d\n"
        "recovered_after_restart=%d\n"
        "completed=%d\n"
        "aborted=%d\n",
        session->upload_id,
        session->object_id,
        session->provider_token,
        session->expected_integrity_sha256,
        (int) session->backend,
        (int) session->protocol,
        (uint64_t) session->uploaded_bytes,
        (uint64_t) session->next_offset,
        (uint64_t) session->old_size,
        (uint64_t) session->chunk_size_bytes,
        (unsigned) session->next_part_number,
        (int64_t) session->created_at,
        (int64_t) session->updated_at,
        (int) session->existed_before,
        (int) session->sequential_chunks_required,
        (int) session->final_chunk_may_be_shorter,
        (int) session->final_chunk_received,
        (int) session->remote_completed,
        (int) session->recovered_after_restart,
        (int) session->completed,
        (int) session->aborted
    );

    ZEND_HASH_FOREACH_NUM_KEY_VAL(&session->provider_parts, part_number, part_value) {
        if (Z_TYPE_P(part_value) != IS_STRING) {
            continue;
        }
        fprintf(fp, "part.%lu=%s\n", (unsigned long) part_number, Z_STRVAL_P(part_value));
    } ZEND_HASH_FOREACH_END();

    if (fclose(fp) != 0) {
        unlink(temp_path);
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to flush the object-store upload state file.");
        }
        return FAILURE;
    }

    if (rename(temp_path, session->state_path) != 0) {
        unlink(temp_path);
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to commit the object-store upload state file.");
        }
        return FAILURE;
    }

    if (king_object_store_meta_write_to_path(session->metadata_path, &session->metadata) != SUCCESS) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to persist object-store upload metadata state.");
        }
        return FAILURE;
    }
    if (session->existed_before) {
        if (king_object_store_meta_write_to_path(
                session->existing_metadata_path,
                &session->existing_metadata
            ) != SUCCESS) {
            if (error != NULL && error_size > 0) {
                snprintf(error, error_size, "Failed to persist object-store upload existing-object metadata.");
            }
            return FAILURE;
        }
    } else {
        unlink(session->existing_metadata_path);
    }

    return SUCCESS;
}

static void king_object_store_upload_session_remove_persisted_state(
    const king_object_store_upload_session_t *session
)
{
    if (session == NULL) {
        return;
    }

    if (session->state_path[0] != '\0') {
        unlink(session->state_path);
    }
    if (session->metadata_path[0] != '\0') {
        unlink(session->metadata_path);
    }
    if (session->existing_metadata_path[0] != '\0') {
        unlink(session->existing_metadata_path);
    }
    if (session->assembly_path[0] != '\0') {
        unlink(session->assembly_path);
    }
}

static void king_object_store_upload_session_export_status(
    const king_object_store_upload_session_t *session,
    king_object_store_upload_status_t *status_out
)
{
    if (status_out == NULL) {
        return;
    }

    memset(status_out, 0, sizeof(*status_out));
    if (session == NULL) {
        return;
    }

    snprintf(status_out->upload_id, sizeof(status_out->upload_id), "%s", session->upload_id);
    snprintf(status_out->object_id, sizeof(status_out->object_id), "%s", session->object_id);
    status_out->backend = session->backend;
    status_out->protocol = session->protocol;
    status_out->uploaded_bytes = session->uploaded_bytes;
    status_out->next_offset = session->next_offset;
    status_out->chunk_size_bytes = session->chunk_size_bytes;
    status_out->next_part_number = session->next_part_number;
    status_out->uploaded_part_count = session->next_part_number > 0 ? session->next_part_number - 1 : 0;
    status_out->created_at = session->created_at;
    status_out->updated_at = session->updated_at;
    status_out->sequential_chunks_required = session->sequential_chunks_required;
    status_out->final_chunk_may_be_shorter = session->final_chunk_may_be_shorter;
    status_out->final_chunk_received = session->final_chunk_received;
    status_out->remote_completed = session->remote_completed;
    status_out->recovered_after_restart = session->recovered_after_restart;
    status_out->completed = session->completed;
    status_out->aborted = session->aborted;
}

static int king_object_store_rehydrate_upload_sessions(char *error, size_t error_size)
{
    DIR *dir;
    struct dirent *ent;
    char session_directory[PATH_MAX];

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }

    if (king_object_store_upload_sessions_ensure() != SUCCESS) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to initialize the object-store upload-session registry.");
        }
        return FAILURE;
    }
    if (king_object_store_build_upload_session_directory(
            session_directory,
            sizeof(session_directory)
        ) != SUCCESS) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to prepare the object-store upload-session directory.");
        }
        return FAILURE;
    }

    dir = opendir(session_directory);
    if (dir == NULL) {
        return SUCCESS;
    }

    while ((ent = readdir(dir)) != NULL) {
        FILE *fp = NULL;
        size_t name_len;
        char state_path[PATH_MAX];
        char line[2048];
        king_object_store_upload_session_t *session = NULL;
        int session_lock_fd = -1;
        char lock_error[512];
        uint64_t assembly_size = 0;

        if (ent->d_name[0] == '.') {
            continue;
        }

        name_len = strlen(ent->d_name);
        if (name_len <= 6 || strcmp(ent->d_name + name_len - 6, ".state") != 0) {
            continue;
        }
        if (snprintf(state_path, sizeof(state_path), "%s/%s", session_directory, ent->d_name) >= (int) sizeof(state_path)) {
            continue;
        }

        fp = fopen(state_path, "r");
        if (fp == NULL) {
            continue;
        }

        session = ecalloc(1, sizeof(*session));
        session->lock_fd = -1;
        zend_hash_init(&session->provider_parts, 8, NULL, ZVAL_PTR_DTOR, 0);

        while (fgets(line, sizeof(line), fp) != NULL) {
            char *eq = strchr(line, '=');
            char *key;
            char *val;
            size_t vlen;

            if (eq == NULL) {
                continue;
            }

            *eq = '\0';
            key = line;
            val = eq + 1;
            vlen = strlen(val);
            if (vlen > 0 && val[vlen - 1] == '\n') {
                val[vlen - 1] = '\0';
            }

            if (strcmp(key, "upload_id") == 0) {
                snprintf(session->upload_id, sizeof(session->upload_id), "%s", val);
            } else if (strcmp(key, "object_id") == 0) {
                snprintf(session->object_id, sizeof(session->object_id), "%s", val);
            } else if (strcmp(key, "provider_token") == 0) {
                snprintf(session->provider_token, sizeof(session->provider_token), "%s", val);
            } else if (strcmp(key, "expected_integrity_sha256") == 0) {
                snprintf(session->expected_integrity_sha256, sizeof(session->expected_integrity_sha256), "%s", val);
            } else if (strcmp(key, "backend") == 0) {
                session->backend = (king_storage_backend_t) atoi(val);
            } else if (strcmp(key, "protocol") == 0) {
                session->protocol = (king_object_store_upload_protocol_t) atoi(val);
            } else if (strcmp(key, "uploaded_bytes") == 0) {
                session->uploaded_bytes = (uint64_t) strtoull(val, NULL, 10);
            } else if (strcmp(key, "next_offset") == 0) {
                session->next_offset = (uint64_t) strtoull(val, NULL, 10);
            } else if (strcmp(key, "old_size") == 0) {
                session->old_size = (uint64_t) strtoull(val, NULL, 10);
            } else if (strcmp(key, "chunk_size_bytes") == 0) {
                session->chunk_size_bytes = (uint64_t) strtoull(val, NULL, 10);
            } else if (strcmp(key, "next_part_number") == 0) {
                session->next_part_number = (uint32_t) strtoul(val, NULL, 10);
            } else if (strcmp(key, "created_at") == 0) {
                session->created_at = (time_t) strtoll(val, NULL, 10);
            } else if (strcmp(key, "updated_at") == 0) {
                session->updated_at = (time_t) strtoll(val, NULL, 10);
            } else if (strcmp(key, "existed_before") == 0) {
                session->existed_before = atoi(val) != 0;
            } else if (strcmp(key, "sequential_chunks_required") == 0) {
                session->sequential_chunks_required = atoi(val) != 0;
            } else if (strcmp(key, "final_chunk_may_be_shorter") == 0) {
                session->final_chunk_may_be_shorter = atoi(val) != 0;
            } else if (strcmp(key, "final_chunk_received") == 0) {
                session->final_chunk_received = atoi(val) != 0;
            } else if (strcmp(key, "remote_completed") == 0) {
                session->remote_completed = atoi(val) != 0;
            } else if (strcmp(key, "recovered_after_restart") == 0) {
                session->recovered_after_restart = atoi(val) != 0;
            } else if (strcmp(key, "completed") == 0) {
                session->completed = atoi(val) != 0;
            } else if (strcmp(key, "aborted") == 0) {
                session->aborted = atoi(val) != 0;
            } else if (strncmp(key, "part.", sizeof("part.") - 1) == 0) {
                zend_ulong part_number = (zend_ulong) strtoul(key + (sizeof("part.") - 1), NULL, 10);
                zval stored_part;

                ZVAL_STRING(&stored_part, val);
                zend_hash_index_update(&session->provider_parts, part_number, &stored_part);
            }
        }
        fclose(fp);
        fp = NULL;

        if (session->upload_id[0] == '\0' || session->object_id[0] == '\0'
            || king_object_store_upload_session_assign_paths(session) != SUCCESS) {
            king_object_store_upload_session_destroy_ptr(session);
            continue;
        }

        if (king_object_store_meta_read_from_path(session->metadata_path, &session->metadata) != SUCCESS) {
            king_object_store_upload_session_remove_persisted_state(session);
            king_object_store_upload_session_destroy_ptr(session);
            continue;
        }
        if (session->existed_before
            && king_object_store_meta_read_from_path(
                session->existing_metadata_path,
                &session->existing_metadata
            ) != SUCCESS) {
            king_object_store_upload_session_remove_persisted_state(session);
            king_object_store_upload_session_destroy_ptr(session);
            continue;
        }

        if (session->completed || session->aborted) {
            king_object_store_upload_session_remove_persisted_state(session);
            king_object_store_upload_session_destroy_ptr(session);
            continue;
        }

        if (king_object_store_seed_sha256_ctx_from_path(
                session->assembly_path,
                &session->sha256_ctx,
                &assembly_size
            ) != SUCCESS
            || assembly_size != session->uploaded_bytes) {
            king_object_store_upload_session_remove_persisted_state(session);
            king_object_store_upload_session_destroy_ptr(session);
            continue;
        }

        memset(lock_error, 0, sizeof(lock_error));
        if (king_object_store_acquire_object_lock(
                session->object_id,
                &session_lock_fd,
                lock_error,
                sizeof(lock_error)
            ) != SUCCESS) {
            if (error != NULL && error_size > 0 && error[0] == '\0') {
                snprintf(
                    error,
                    error_size,
                    "Failed to rehydrate object-store upload session '%s': %s",
                    session->upload_id,
                    lock_error[0] != '\0' ? lock_error : "object mutation lock unavailable"
                );
            }
            king_object_store_upload_session_remove_persisted_state(session);
            king_object_store_upload_session_destroy_ptr(session);
            continue;
        }

        session->lock_fd = session_lock_fd;
        session->recovered_after_restart = 1;
        if (king_object_store_upload_session_store(session) != SUCCESS) {
            king_object_store_upload_session_remove_persisted_state(session);
            king_object_store_upload_session_destroy_ptr(session);
            continue;
        }
    }

    closedir(dir);
    return SUCCESS;
}

static int king_object_store_append_path_to_file_and_hash(
    const char *source_path,
    const char *destination_path,
    PHP_SHA256_CTX *sha_ctx,
    uint64_t *bytes_appended_out
)
{
    FILE *source_fp = NULL;
    FILE *destination_fp = NULL;
    unsigned char *buffer = NULL;
    size_t chunk_bytes;
    uint64_t appended = 0;
    int rc = FAILURE;

    if (source_path == NULL || destination_path == NULL || sha_ctx == NULL) {
        return FAILURE;
    }

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        return FAILURE;
    }

    destination_fp = fopen(destination_path, "ab");
    if (destination_fp == NULL) {
        fclose(source_fp);
        return FAILURE;
    }

    chunk_bytes = king_object_store_streaming_chunk_bytes();
    buffer = emalloc(chunk_bytes);

    while (1) {
        size_t bytes_read = fread(buffer, 1, chunk_bytes, source_fp);

        if (bytes_read == 0) {
            if (feof(source_fp)) {
                rc = SUCCESS;
                break;
            }
            goto cleanup;
        }

        if (fwrite(buffer, 1, bytes_read, destination_fp) != bytes_read) {
            goto cleanup;
        }

        PHP_SHA256Update(sha_ctx, buffer, bytes_read);
        appended += (uint64_t) bytes_read;
    }

cleanup:
    if (buffer != NULL) {
        efree(buffer);
    }
    if (source_fp != NULL) {
        fclose(source_fp);
    }
    if (destination_fp != NULL) {
        fclose(destination_fp);
    }
    if (bytes_appended_out != NULL) {
        *bytes_appended_out = appended;
    }

    return rc;
}

static int king_object_store_finalize_completed_upload_session(
    king_object_store_upload_session_t *session,
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
)
{
    PHP_SHA256_CTX final_ctx;
    unsigned char digest[32];
    static const char hex[] = "0123456789abcdef";
    char computed_sha256[65];
    king_object_metadata_t final_metadata;
    size_t i;

    if (session == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (session->completed) {
        king_object_store_upload_session_export_status(session, status_out);
        return SUCCESS;
    }

    final_ctx = session->sha256_ctx;
    PHP_SHA256Final(digest, &final_ctx);
    for (i = 0; i < sizeof(digest); ++i) {
        computed_sha256[i * 2] = hex[(digest[i] >> 4) & 0x0f];
        computed_sha256[(i * 2) + 1] = hex[digest[i] & 0x0f];
    }
    computed_sha256[64] = '\0';

    if (session->expected_integrity_sha256[0] != '\0'
        && strcmp(session->expected_integrity_sha256, computed_sha256) != 0) {
        snprintf(
            error,
            error_size,
            "Resumable upload integrity validation failed for '%s'.",
            session->object_id
        );
        return FAILURE;
    }
    if (king_object_store_runtime_capacity_check_rewrite(
            session->uploaded_bytes,
            session->existed_before,
            session->old_size,
            error,
            error_size
        ) != SUCCESS) {
        snprintf(
            error,
            error_size,
            "Resumable upload for '%s' would exceed the configured object-store runtime capacity.",
            session->object_id
        );
        return FAILURE;
    }

    final_metadata = session->metadata;
    king_object_store_metadata_mark_backend_present(&final_metadata, session->backend, 1);
    king_object_store_finalize_metadata_for_write(
        session->object_id,
        session->uploaded_bytes,
        computed_sha256,
        &final_metadata,
        session->existed_before ? &session->existing_metadata : NULL
    );

    if (king_object_store_meta_write(session->object_id, &final_metadata) != SUCCESS) {
        snprintf(
            error,
            error_size,
            "Resumable upload for '%s' committed remotely but local metadata sidecar write failed.",
            session->object_id
        );
        return FAILURE;
    }

    king_object_store_apply_local_fs_counters_for_rewrite(
        session->object_id,
        session->uploaded_bytes,
        session->existed_before,
        session->old_size
    );

    if (king_object_store_runtime.config.backup_backend != session->backend) {
        if (king_object_store_backup_object_from_file_to_backend(
                session->object_id,
                session->assembly_path,
                &final_metadata,
                king_object_store_runtime.config.backup_backend
            ) != SUCCESS) {
            if (king_object_store_runtime.config.replication_factor > 0) {
                king_object_store_update_replication_status(session->object_id, 3);
            }
            snprintf(
                error,
                error_size,
                "Resumable upload for '%s' committed remotely but backup operation failed.",
                session->object_id
            );
            return FAILURE;
        }
    }

    if (king_object_store_runtime.config.replication_factor > 0) {
        if (king_object_store_replicate_object(
                session->object_id,
                king_object_store_runtime.config.replication_factor
            ) != SUCCESS) {
            snprintf(
                error,
                error_size,
                "Resumable upload for '%s' committed remotely but replication target was not reached.",
                session->object_id
            );
            return FAILURE;
        }
    }

    session->metadata = final_metadata;
    session->completed = 1;
    session->remote_completed = 1;
    session->updated_at = time(NULL);
    king_object_store_upload_session_export_status(session, status_out);
    return SUCCESS;
}

static void king_object_store_release_read_buffer(void **data, size_t *data_size)
{
    if (data == NULL || *data == NULL) {
        return;
    }

    pefree(*data, 1);
    *data = NULL;
    if (data_size != NULL) {
        *data_size = 0;
    }
}

