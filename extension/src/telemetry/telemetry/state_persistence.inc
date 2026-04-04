static const char *king_telemetry_queue_state_path(void)
{
    return (
        king_open_telemetry_config.queue_state_path != NULL
        && king_open_telemetry_config.queue_state_path[0] != '\0'
    ) ? king_open_telemetry_config.queue_state_path : NULL;
}

static zend_bool king_telemetry_queue_state_path_is_configured(void)
{
    return king_telemetry_queue_state_path() != NULL;
}

static void king_telemetry_refresh_queue_accounting(void)
{
    king_telemetry_batch_t *cursor = king_telemetry_export_queue_head;
    uint32_t queue_size = 0;
    uint64_t queue_bytes = 0;

    while (cursor != NULL) {
        queue_size++;
        queue_bytes = king_telemetry_saturated_add_u64(queue_bytes, cursor->estimated_bytes);
        cursor = cursor->next;
    }

    king_telemetry_queue_size = queue_size;
    king_telemetry_queue_bytes = queue_bytes;
    king_telemetry_note_runtime_watermarks();
}

static void king_telemetry_append_batch_in_memory(king_telemetry_batch_t *batch)
{
    if (batch == NULL) {
        return;
    }

    batch->next = NULL;
    if (king_telemetry_export_queue_tail == NULL) {
        king_telemetry_export_queue_head = batch;
        king_telemetry_export_queue_tail = batch;
    } else {
        king_telemetry_export_queue_tail->next = batch;
        king_telemetry_export_queue_tail = batch;
    }

    king_telemetry_refresh_queue_accounting();
}

static king_telemetry_batch_t *king_telemetry_peek_batch(void)
{
    return king_telemetry_export_queue_head;
}

static king_telemetry_batch_t *king_telemetry_remove_head_batch_in_memory(void)
{
    king_telemetry_batch_t *batch = king_telemetry_export_queue_head;

    if (batch == NULL) {
        return NULL;
    }

    king_telemetry_export_queue_head = batch->next;
    batch->next = NULL;
    if (king_telemetry_export_queue_head == NULL) {
        king_telemetry_export_queue_tail = NULL;
    }

    king_telemetry_refresh_queue_accounting();
    return batch;
}

static void king_telemetry_free_export_queue_in_memory(void)
{
    king_telemetry_batch_t *batch = king_telemetry_export_queue_head;

    while (batch != NULL) {
        king_telemetry_batch_t *next = batch->next;
        batch->next = NULL;
        king_telemetry_free_batch(batch);
        batch = next;
    }

    king_telemetry_export_queue_head = NULL;
    king_telemetry_export_queue_tail = NULL;
    king_telemetry_refresh_queue_accounting();
}

static int king_telemetry_build_queue_state_lock_path(
    const char *state_path,
    char *lock_path,
    size_t lock_path_len
)
{
    if (state_path == NULL || state_path[0] == '\0' || lock_path == NULL || lock_path_len == 0) {
        return FAILURE;
    }

    if (snprintf(lock_path, lock_path_len, "%s.lock", state_path) >= (int) lock_path_len) {
        return FAILURE;
    }

    if (php_check_open_basedir(lock_path) != 0) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_telemetry_queue_state_lock_acquire(int *lock_fd_out)
{
    const char *state_path = king_telemetry_queue_state_path();
    char lock_path[PATH_MAX];
    int flags = O_RDWR | O_CREAT;
    int fd;
    struct stat lock_stat;

    if (lock_fd_out == NULL) {
        return FAILURE;
    }

    *lock_fd_out = -1;

    if (!king_telemetry_queue_state_path_is_configured()) {
        return SUCCESS;
    }

    if (king_telemetry_build_queue_state_lock_path(state_path, lock_path, sizeof(lock_path)) != SUCCESS) {
        return FAILURE;
    }

#ifdef O_CLOEXEC
    flags |= O_CLOEXEC;
#endif
#ifdef O_NOFOLLOW
    flags |= O_NOFOLLOW;
#endif

    fd = open(lock_path, flags, 0600);
    if (fd < 0) {
        return FAILURE;
    }

    if (fstat(fd, &lock_stat) != 0 || !S_ISREG(lock_stat.st_mode)) {
        close(fd);
        return FAILURE;
    }

    if (fchmod(fd, 0600) != 0) {
        close(fd);
        return FAILURE;
    }

    if (flock(fd, LOCK_EX) != 0) {
        close(fd);
        return FAILURE;
    }

    *lock_fd_out = fd;
    return SUCCESS;
}

static void king_telemetry_queue_state_lock_release(int lock_fd)
{
    if (lock_fd < 0) {
        return;
    }

    (void) flock(lock_fd, LOCK_UN);
    close(lock_fd);
}

static int king_telemetry_queue_state_path_validate_existing(const char *state_path)
{
    struct stat st;

    if (state_path == NULL || state_path[0] == '\0') {
        return FAILURE;
    }

    if (php_check_open_basedir(state_path) != 0) {
        return FAILURE;
    }

    if (lstat(state_path, &st) != 0) {
        return errno == ENOENT ? SUCCESS : FAILURE;
    }

    return S_ISREG(st.st_mode) ? SUCCESS : FAILURE;
}

static FILE *king_telemetry_open_nofollow_stream(
    const char *path,
    int flags,
    mode_t mode,
    const char *stream_mode
)
{
    int fd;
    FILE *stream;

    if (path == NULL || stream_mode == NULL) {
        return NULL;
    }

#ifdef O_CLOEXEC
    flags |= O_CLOEXEC;
#endif
#ifdef O_NOFOLLOW
    flags |= O_NOFOLLOW;
#endif

    fd = open(path, flags, mode);
    if (fd < 0) {
        return NULL;
    }

    stream = fdopen(fd, stream_mode);
    if (stream == NULL) {
        close(fd);
        return NULL;
    }

    return stream;
}

static int king_telemetry_build_queue_state_tmp_template(
    const char *state_path,
    char *tmp_path,
    size_t tmp_path_len
)
{
    if (state_path == NULL || state_path[0] == '\0' || tmp_path == NULL || tmp_path_len == 0) {
        return FAILURE;
    }

    if (snprintf(tmp_path, tmp_path_len, "%s.tmp.XXXXXX", state_path) >= (int) tmp_path_len) {
        return FAILURE;
    }

    if (php_check_open_basedir(tmp_path) != 0) {
        return FAILURE;
    }

    return SUCCESS;
}

static zend_string *king_telemetry_state_serialize_zval(zval *value)
{
    smart_str buffer = {0};
    php_serialize_data_t var_hash;

    if (value == NULL) {
        return NULL;
    }

    PHP_VAR_SERIALIZE_INIT(var_hash);
    php_var_serialize(&buffer, value, &var_hash);
    PHP_VAR_SERIALIZE_DESTROY(var_hash);
    smart_str_0(&buffer);

    return buffer.s;
}

static zend_bool king_telemetry_state_value_tree_is_safe(
    zval *value,
    HashTable *seen_arrays
)
{
    zval *entry;
    zend_ulong array_key;

    if (value == NULL) {
        return 0;
    }

    switch (Z_TYPE_P(value)) {
        case IS_NULL:
        case IS_FALSE:
        case IS_TRUE:
        case IS_LONG:
        case IS_DOUBLE:
        case IS_STRING:
            return 1;
        case IS_ARRAY:
            array_key = (zend_ulong) (uintptr_t) Z_ARR_P(value);
            if (zend_hash_index_exists(seen_arrays, array_key)) {
                return 1;
            }
            zend_hash_index_add_empty_element(seen_arrays, array_key);
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(value), entry) {
                if (!king_telemetry_state_value_tree_is_safe(entry, seen_arrays)) {
                    return 0;
                }
            } ZEND_HASH_FOREACH_END();
            return 1;
        case IS_REFERENCE:
        case IS_OBJECT:
        case IS_RESOURCE:
        default:
            return 0;
    }
}

static int king_telemetry_state_unserialize_zval(
    const unsigned char *buffer,
    size_t buffer_len,
    zval *return_value
)
{
    const unsigned char *cursor = buffer;
    const unsigned char *end = buffer + buffer_len;
    php_unserialize_data_t var_hash;
    HashTable allowed_classes;
    HashTable seen_arrays;
    zend_result result = FAILURE;

    if (buffer == NULL || return_value == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(return_value);
    PHP_VAR_UNSERIALIZE_INIT(var_hash);
    zend_hash_init(&allowed_classes, 0, NULL, NULL, 0);
    php_var_unserialize_set_allowed_classes(var_hash, &allowed_classes);
    if (!php_var_unserialize(return_value, &cursor, end, &var_hash)) {
        goto cleanup;
    }
    if (cursor != end) {
        goto cleanup;
    }

    zend_hash_init(&seen_arrays, 0, NULL, NULL, 0);
    if (!king_telemetry_state_value_tree_is_safe(return_value, &seen_arrays)) {
        zend_hash_destroy(&seen_arrays);
        goto cleanup;
    }
    zend_hash_destroy(&seen_arrays);
    result = SUCCESS;

cleanup:
    PHP_VAR_UNSERIALIZE_DESTROY(var_hash);
    zend_hash_destroy(&allowed_classes);
    if (result != SUCCESS) {
        zval_ptr_dtor(return_value);
        ZVAL_NULL(return_value);
    }

    return result;
}

static zend_result king_telemetry_build_queue_state_snapshot(zval *snapshot)
{
    king_telemetry_batch_t *cursor;
    zval queue;

    if (snapshot == NULL) {
        return FAILURE;
    }

    array_init(snapshot);
    add_assoc_long(snapshot, "version", 1);

    array_init(&queue);
    cursor = king_telemetry_export_queue_head;
    while (cursor != NULL) {
        zval batch_state;
        zval metrics_copy;
        zval spans_copy;
        zval logs_copy;

        array_init(&batch_state);

        ZVAL_COPY(&metrics_copy, &cursor->metrics);
        add_assoc_zval(&batch_state, "metrics", &metrics_copy);

        ZVAL_COPY(&spans_copy, &cursor->spans);
        add_assoc_zval(&batch_state, "spans", &spans_copy);

        ZVAL_COPY(&logs_copy, &cursor->logs);
        add_assoc_zval(&batch_state, "logs", &logs_copy);

        add_assoc_long(&batch_state, "created_at", (zend_long) cursor->created_at);
        add_next_index_zval(&queue, &batch_state);

        cursor = cursor->next;
    }

    add_assoc_zval(snapshot, "queue", &queue);
    return SUCCESS;
}

static zend_result king_telemetry_persist_queue_state(void)
{
    const char *state_path = king_telemetry_queue_state_path();
    int lock_fd = -1;
    zval snapshot;
    zend_string *serialized = NULL;
    char tmp_path[PATH_MAX] = {0};
    int tmp_fd = -1;
    FILE *stream = NULL;
    zend_result result = FAILURE;

    if (!king_telemetry_queue_state_path_is_configured()) {
        return SUCCESS;
    }

    if (king_telemetry_queue_state_lock_acquire(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    if (king_telemetry_queue_state_path_validate_existing(state_path) != SUCCESS) {
        goto cleanup;
    }

    if (king_telemetry_export_queue_head == NULL) {
        if (unlink(state_path) != 0 && errno != ENOENT) {
            goto cleanup;
        }
        result = SUCCESS;
        goto cleanup;
    }

    if (king_telemetry_build_queue_state_snapshot(&snapshot) != SUCCESS) {
        goto cleanup;
    }

    serialized = king_telemetry_state_serialize_zval(&snapshot);
    zval_ptr_dtor(&snapshot);
    if (serialized == NULL) {
        goto cleanup;
    }

    if (king_telemetry_build_queue_state_tmp_template(state_path, tmp_path, sizeof(tmp_path)) != SUCCESS) {
        goto cleanup;
    }

    tmp_fd = mkstemp(tmp_path);
    if (tmp_fd < 0) {
        goto cleanup;
    }

    if (fchmod(tmp_fd, 0600) != 0) {
        goto cleanup;
    }

    stream = fdopen(tmp_fd, "wb");
    if (stream == NULL) {
        goto cleanup;
    }
    tmp_fd = -1;

    if (fwrite(ZSTR_VAL(serialized), 1, ZSTR_LEN(serialized), stream) != ZSTR_LEN(serialized)) {
        goto cleanup;
    }

    if (fflush(stream) != 0 || fsync(fileno(stream)) != 0) {
        goto cleanup;
    }

    if (fclose(stream) != 0) {
        stream = NULL;
        goto cleanup;
    }
    stream = NULL;

    if (rename(tmp_path, state_path) != 0) {
        goto cleanup;
    }

    result = SUCCESS;

cleanup:
    if (stream != NULL) {
        fclose(stream);
    } else if (tmp_fd >= 0) {
        close(tmp_fd);
    }
    if (result != SUCCESS && tmp_path[0] != '\0') {
        (void) unlink(tmp_path);
    }
    if (serialized != NULL) {
        zend_string_release(serialized);
    }
    king_telemetry_queue_state_lock_release(lock_fd);
    return result;
}

static int king_telemetry_queue_batch_internal(king_telemetry_batch_t *batch, zend_bool persist_state)
{
    uint32_t max_queue_size;
    uint64_t memory_limit;

    if (batch == NULL) {
        return FAILURE;
    }

    batch->next = NULL;
    king_telemetry_batch_refresh_estimated_bytes(batch);

    max_queue_size = king_telemetry_runtime_max_queue_size();
    if (max_queue_size > 0 && king_telemetry_queue_size >= max_queue_size) {
        king_telemetry_queue_drop_count++;
        return FAILURE;
    }

    memory_limit = king_telemetry_runtime_memory_byte_limit_internal();
    if (memory_limit > 0
        && king_telemetry_saturated_add_u64(king_telemetry_queue_bytes, batch->estimated_bytes) > memory_limit) {
        king_telemetry_queue_drop_count++;
        return FAILURE;
    }

    king_telemetry_append_batch_in_memory(batch);
    if (persist_state) {
        (void) king_telemetry_persist_queue_state();
    }

    return SUCCESS;
}

static zend_result king_telemetry_rehydrate_queue_state(void)
{
    const char *state_path = king_telemetry_queue_state_path();
    int lock_fd = -1;
    FILE *stream = NULL;
    smart_str state_buffer = {0};
    char chunk[4096];
    size_t read_bytes;
    zval snapshot;
    zval *queue;
    zval *batch_state;
    zend_result result = SUCCESS;
    zend_bool normalized = 0;

    if (!king_telemetry_queue_state_path_is_configured() || king_telemetry_export_queue_head != NULL) {
        return SUCCESS;
    }

    if (king_telemetry_queue_state_lock_acquire(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    if (king_telemetry_queue_state_path_validate_existing(state_path) != SUCCESS) {
        result = FAILURE;
        goto cleanup;
    }

    stream = king_telemetry_open_nofollow_stream(state_path, O_RDONLY, 0, "rb");
    if (stream == NULL) {
        if (errno == ENOENT) {
            result = SUCCESS;
            goto cleanup;
        }
        result = FAILURE;
        goto cleanup;
    }

    while ((read_bytes = fread(chunk, 1, sizeof(chunk), stream)) > 0) {
        smart_str_appendl(&state_buffer, chunk, read_bytes);
    }
    if (ferror(stream)) {
        result = FAILURE;
        goto cleanup;
    }
    fclose(stream);
    stream = NULL;

    smart_str_0(&state_buffer);
    if (state_buffer.s == NULL || ZSTR_LEN(state_buffer.s) == 0) {
        result = SUCCESS;
        goto cleanup;
    }

    if (king_telemetry_state_unserialize_zval(
            (const unsigned char *) ZSTR_VAL(state_buffer.s),
            ZSTR_LEN(state_buffer.s),
            &snapshot
        ) != SUCCESS) {
        result = FAILURE;
        goto cleanup;
    }

    queue = zend_hash_str_find(Z_ARRVAL(snapshot), "queue", sizeof("queue") - 1);
    if (queue == NULL || Z_TYPE_P(queue) != IS_ARRAY) {
        zval_ptr_dtor(&snapshot);
        result = FAILURE;
        goto cleanup;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(queue), batch_state) {
        zval *metrics;
        zval *spans;
        zval *logs;
        zval *created_at;
        king_telemetry_batch_t *batch;

        if (batch_state == NULL || Z_TYPE_P(batch_state) != IS_ARRAY) {
            normalized = 1;
            continue;
        }

        metrics = zend_hash_str_find(Z_ARRVAL_P(batch_state), "metrics", sizeof("metrics") - 1);
        spans = zend_hash_str_find(Z_ARRVAL_P(batch_state), "spans", sizeof("spans") - 1);
        logs = zend_hash_str_find(Z_ARRVAL_P(batch_state), "logs", sizeof("logs") - 1);
        if ((metrics != NULL && Z_TYPE_P(metrics) != IS_ARRAY)
            || (spans != NULL && Z_TYPE_P(spans) != IS_ARRAY)
            || (logs != NULL && Z_TYPE_P(logs) != IS_ARRAY)) {
            normalized = 1;
            continue;
        }

        batch = king_telemetry_create_batch();

        if (metrics != NULL) {
            zval_ptr_dtor(&batch->metrics);
            ZVAL_COPY(&batch->metrics, metrics);
        }
        if (spans != NULL) {
            zval_ptr_dtor(&batch->spans);
            ZVAL_COPY(&batch->spans, spans);
        }
        if (logs != NULL) {
            zval_ptr_dtor(&batch->logs);
            ZVAL_COPY(&batch->logs, logs);
        }

        created_at = zend_hash_str_find(Z_ARRVAL_P(batch_state), "created_at", sizeof("created_at") - 1);
        if (created_at != NULL) {
            batch->created_at = (time_t) zval_get_long(created_at);
        }

        if (king_telemetry_queue_batch_internal(batch, 0) != SUCCESS) {
            king_telemetry_free_batch(batch);
            normalized = 1;
        }
    } ZEND_HASH_FOREACH_END();

    zval_ptr_dtor(&snapshot);

    if (normalized) {
        (void) king_telemetry_persist_queue_state();
    }

cleanup:
    if (stream != NULL) {
        fclose(stream);
    }
    smart_str_free(&state_buffer);
    king_telemetry_queue_state_lock_release(lock_fd);
    return result;
}
