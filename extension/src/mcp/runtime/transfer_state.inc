static int king_mcp_build_transfer_state_lock_path(
    const char *state_path,
    char *lock_path,
    size_t lock_path_len
)
{
    if (
        state_path == NULL
        || state_path[0] == '\0'
        || lock_path == NULL
        || lock_path_len == 0
    ) {
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

static int king_mcp_transfer_state_lock_acquire(
    king_mcp_state *state,
    int *lock_fd_out
)
{
    char lock_path[1024];
    const char *state_path = king_mcp_transfer_state_path();
    int flags = O_RDWR | O_CREAT;
    int fd;

    if (lock_fd_out == NULL) {
        return FAILURE;
    }

    *lock_fd_out = -1;

    if (!king_mcp_transfer_state_path_is_configured()) {
        return SUCCESS;
    }

    if (king_mcp_build_transfer_state_lock_path(state_path, lock_path, sizeof(lock_path)) != SUCCESS) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not build the local transfer state lock path."
        );
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
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not open the local transfer state lock file."
        );
        return FAILURE;
    }

    if (fchmod(fd, 0600) != 0) {
        close(fd);
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not secure the local transfer state lock file."
        );
        return FAILURE;
    }

    if (flock(fd, LOCK_EX) != 0) {
        close(fd);
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not acquire the local transfer state lock."
        );
        return FAILURE;
    }

    *lock_fd_out = fd;
    return SUCCESS;
}

static void king_mcp_transfer_state_lock_release(int lock_fd)
{
    if (lock_fd < 0) {
        return;
    }

    (void) flock(lock_fd, LOCK_UN);
    close(lock_fd);
}

static FILE *king_mcp_open_nofollow_stream(
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

static int king_mcp_transfer_state_path_validate_existing(const char *state_path)
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

static int king_mcp_build_transfer_state_tmp_template(
    const char *state_path,
    char *tmp_path,
    size_t tmp_path_len
)
{
    if (
        state_path == NULL
        || state_path[0] == '\0'
        || tmp_path == NULL
        || tmp_path_len == 0
    ) {
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

static zend_string *king_mcp_transfer_state_decode_payload(
    king_mcp_state *state,
    const char *encoded,
    size_t encoded_len
)
{
    zend_string *decoded = php_base64_decode((const unsigned char *) encoded, encoded_len);

    if (decoded == NULL) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime encountered invalid persisted transfer payload data."
        );
        return NULL;
    }

    return decoded;
}

static zend_result king_mcp_transfer_state_transaction_begin(
    king_mcp_state *state,
    HashTable *transfers,
    int *lock_fd_out
)
{
    const char *state_path = king_mcp_transfer_state_path();
    FILE *stream = NULL;
    char *line = NULL;
    size_t line_cap = 0;
    ssize_t line_len;

    if (transfers == NULL || lock_fd_out == NULL) {
        return FAILURE;
    }

    zend_hash_init(transfers, 8, NULL, ZVAL_PTR_DTOR, 0);
    *lock_fd_out = -1;

    if (king_mcp_transfer_state_lock_acquire(state, lock_fd_out) != SUCCESS) {
        zend_hash_destroy(transfers);
        return FAILURE;
    }

    if (!king_mcp_transfer_state_path_is_configured()) {
        return SUCCESS;
    }

    if (king_mcp_transfer_state_path_validate_existing(state_path) != SUCCESS) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime refused the configured local transfer state path."
        );
        goto failure;
    }

    stream = king_mcp_open_nofollow_stream(state_path, O_RDONLY, 0, "rb");
    if (stream == NULL) {
        if (errno == ENOENT) {
            return SUCCESS;
        }

        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not open the configured local transfer state file."
        );
        goto failure;
    }

    while ((line_len = getline(&line, &line_cap, stream)) != -1) {
        char *saveptr = NULL;
        char *kind;
        char *key;
        char *payload_b64;
        zval payload_zv;
        zend_string *payload;

        while (line_len > 0 && (line[line_len - 1] == '\n' || line[line_len - 1] == '\r')) {
            line[--line_len] = '\0';
        }

        if (line_len == 0 || line[0] == '#') {
            continue;
        }

        kind = strtok_r(line, "\t", &saveptr);
        if (kind == NULL) {
            continue;
        }

        if (strcmp(kind, "version") == 0) {
            char *version_text = strtok_r(NULL, "\t", &saveptr);
            zend_long version;

            if (version_text == NULL) {
                king_mcp_state_set_errorf(
                    state,
                    KING_MCP_ERROR_BACKEND,
                    "MCP runtime encountered a malformed local transfer state header."
                );
                goto failure;
            }

            version = ZEND_STRTOL(version_text, NULL, 10);
            if (version != KING_MCP_TRANSFER_STATE_VERSION) {
                king_mcp_state_set_errorf(
                    state,
                    KING_MCP_ERROR_BACKEND,
                    "MCP runtime encountered an unsupported local transfer state version."
                );
                goto failure;
            }
            continue;
        }

        if (strcmp(kind, "transfer") != 0) {
            continue;
        }

        key = strtok_r(NULL, "\t", &saveptr);
        payload_b64 = strtok_r(NULL, "\t", &saveptr);
        if (key == NULL || payload_b64 == NULL || key[0] == '\0') {
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_BACKEND,
                "MCP runtime encountered a malformed persisted transfer entry."
            );
            goto failure;
        }

        payload = king_mcp_transfer_state_decode_payload(state, payload_b64, strlen(payload_b64));
        if (payload == NULL) {
            goto failure;
        }

        ZVAL_STR(&payload_zv, payload);
        zend_hash_str_update(transfers, key, strlen(key), &payload_zv);
    }

    if (stream != NULL) {
        fclose(stream);
    }
    if (line != NULL) {
        free(line);
    }

    return SUCCESS;

failure:
    if (stream != NULL) {
        fclose(stream);
    }
    if (line != NULL) {
        free(line);
    }
    zend_hash_destroy(transfers);
    king_mcp_transfer_state_lock_release(*lock_fd_out);
    *lock_fd_out = -1;
    return FAILURE;
}

static void king_mcp_transfer_state_transaction_end(HashTable *transfers, int lock_fd)
{
    if (transfers != NULL) {
        zend_hash_destroy(transfers);
    }

    king_mcp_transfer_state_lock_release(lock_fd);
}

static zend_result king_mcp_transfer_state_persist_locked(
    king_mcp_state *state,
    HashTable *transfers
)
{
    const char *state_path = king_mcp_transfer_state_path();
    char tmp_path[1024];
    FILE *stream = NULL;
    zend_string *key;
    zval *payload_zv;

    if (!king_mcp_transfer_state_path_is_configured()) {
        return SUCCESS;
    }

    if (transfers == NULL) {
        return FAILURE;
    }

    if (zend_hash_num_elements(transfers) == 0) {
        if (king_mcp_transfer_state_path_validate_existing(state_path) != SUCCESS) {
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_BACKEND,
                "MCP runtime refused the configured local transfer state path."
            );
            return FAILURE;
        }

        if (unlink(state_path) != 0 && errno != ENOENT) {
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_BACKEND,
                "MCP runtime could not clean up the local transfer state file."
            );
            return FAILURE;
        }

        return SUCCESS;
    }

    if (king_mcp_transfer_state_path_validate_existing(state_path) != SUCCESS) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime refused the configured local transfer state path."
        );
        return FAILURE;
    }

    if (king_mcp_build_transfer_state_tmp_template(state_path, tmp_path, sizeof(tmp_path)) != SUCCESS) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not build the local transfer state staging path."
        );
        return FAILURE;
    }

    {
        int fd = mkstemp(tmp_path);
        if (fd < 0) {
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_BACKEND,
                "MCP runtime could not create the local transfer state staging file."
            );
            return FAILURE;
        }
        if (fchmod(fd, 0600) != 0) {
            close(fd);
            unlink(tmp_path);
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_BACKEND,
                "MCP runtime could not secure the local transfer state staging file."
            );
            return FAILURE;
        }
        stream = fdopen(fd, "wb");
        if (stream == NULL) {
            close(fd);
            unlink(tmp_path);
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_BACKEND,
                "MCP runtime could not open the local transfer state staging stream."
            );
            return FAILURE;
        }
    }

    fprintf(stream, "version\t%d\n", KING_MCP_TRANSFER_STATE_VERSION);

    ZEND_HASH_FOREACH_STR_KEY_VAL(transfers, key, payload_zv) {
        zend_string *encoded_payload;

        if (key == NULL || Z_TYPE_P(payload_zv) != IS_STRING) {
            continue;
        }

        encoded_payload = php_base64_encode(
            (const unsigned char *) Z_STRVAL_P(payload_zv),
            Z_STRLEN_P(payload_zv)
        );
        if (encoded_payload == NULL) {
            fclose(stream);
            unlink(tmp_path);
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_BACKEND,
                "MCP runtime could not encode the persisted transfer payload."
            );
            return FAILURE;
        }

        fprintf(stream, "transfer\t%s\t%s\n", ZSTR_VAL(key), ZSTR_VAL(encoded_payload));
        zend_string_release(encoded_payload);
    } ZEND_HASH_FOREACH_END();

    if (fclose(stream) != 0) {
        unlink(tmp_path);
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not flush the local transfer state staging file."
        );
        return FAILURE;
    }

    if (king_mcp_transfer_state_path_validate_existing(state_path) != SUCCESS) {
        unlink(tmp_path);
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime refused the configured local transfer state path."
        );
        return FAILURE;
    }

    if (rename(tmp_path, state_path) != 0) {
        unlink(tmp_path);
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not publish the local transfer state snapshot."
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_mcp_transfer_state_store_payload(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len,
    zend_string *payload
)
{
    HashTable transfers;
    int lock_fd = -1;
    zend_string *key;
    zval payload_zv;

    if (!king_mcp_transfer_state_path_is_configured()) {
        return SUCCESS;
    }

    if (payload == NULL) {
        return FAILURE;
    }

    key = king_mcp_transfer_key_create(service, service_len, method, method_len, id, id_len);
    if (key == NULL) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not materialize the persisted transfer key."
        );
        return FAILURE;
    }

    if (king_mcp_transfer_state_transaction_begin(state, &transfers, &lock_fd) != SUCCESS) {
        zend_string_release(key);
        return FAILURE;
    }

    ZVAL_STR_COPY(&payload_zv, payload);
    zend_hash_update(&transfers, key, &payload_zv);
    zend_string_release(key);

    if (king_mcp_transfer_state_persist_locked(state, &transfers) != SUCCESS) {
        king_mcp_transfer_state_transaction_end(&transfers, lock_fd);
        return FAILURE;
    }

    king_mcp_transfer_state_transaction_end(&transfers, lock_fd);
    return SUCCESS;
}

static zend_string *king_mcp_transfer_state_find_payload(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len
)
{
    HashTable transfers;
    int lock_fd = -1;
    zend_string *key;
    zend_string *payload = NULL;
    zval *payload_zv;

    if (!king_mcp_transfer_state_path_is_configured()) {
        return NULL;
    }

    key = king_mcp_transfer_key_create(service, service_len, method, method_len, id, id_len);
    if (key == NULL) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not materialize the persisted transfer key."
        );
        return NULL;
    }

    if (king_mcp_transfer_state_transaction_begin(state, &transfers, &lock_fd) != SUCCESS) {
        zend_string_release(key);
        return NULL;
    }

    payload_zv = zend_hash_find(&transfers, key);
    if (payload_zv != NULL && Z_TYPE_P(payload_zv) == IS_STRING) {
        payload = zend_string_copy(Z_STR_P(payload_zv));
    }

    king_mcp_transfer_state_transaction_end(&transfers, lock_fd);
    zend_string_release(key);

    return payload;
}

static zend_result king_mcp_transfer_state_delete_payload(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len
)
{
    HashTable transfers;
    int lock_fd = -1;
    zend_string *key;

    if (!king_mcp_transfer_state_path_is_configured()) {
        return SUCCESS;
    }

    key = king_mcp_transfer_key_create(service, service_len, method, method_len, id, id_len);
    if (key == NULL) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_BACKEND,
            "MCP runtime could not materialize the persisted transfer key."
        );
        return FAILURE;
    }

    if (king_mcp_transfer_state_transaction_begin(state, &transfers, &lock_fd) != SUCCESS) {
        zend_string_release(key);
        return FAILURE;
    }

    (void) zend_hash_del(&transfers, key);
    zend_string_release(key);

    if (king_mcp_transfer_state_persist_locked(state, &transfers) != SUCCESS) {
        king_mcp_transfer_state_transaction_end(&transfers, lock_fd);
        return FAILURE;
    }

    king_mcp_transfer_state_transaction_end(&transfers, lock_fd);
    return SUCCESS;
}

