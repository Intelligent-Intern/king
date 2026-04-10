static int king_orchestrator_state_path_is_configured(void)
{
    return king_mcp_orchestrator_config.orchestrator_state_path != NULL
        && king_mcp_orchestrator_config.orchestrator_state_path[0] != '\0';
}

static int king_orchestrator_queue_path_is_configured(void)
{
    return king_mcp_orchestrator_config.orchestrator_worker_queue_path != NULL
        && king_mcp_orchestrator_config.orchestrator_worker_queue_path[0] != '\0';
}

static void king_orchestrator_runtime_clear_state(void)
{
    zend_hash_clean(&king_orchestrator_tool_registry);
    zend_hash_clean(&king_orchestrator_pipeline_runs);

    if (king_orchestrator_logging_config_b64 != NULL) {
        zend_string_release_ex(king_orchestrator_logging_config_b64, 1);
        king_orchestrator_logging_config_b64 = NULL;
    }
    if (king_orchestrator_last_run_id != NULL) {
        zend_string_release_ex(king_orchestrator_last_run_id, 1);
        king_orchestrator_last_run_id = NULL;
    }
    if (king_orchestrator_last_run_status != NULL) {
        zend_string_release_ex(king_orchestrator_last_run_status, 1);
        king_orchestrator_last_run_status = NULL;
    }

    king_orchestrator_recovered_from_state = false;
    king_orchestrator_next_run_id = 1;
}

static int king_orchestrator_build_state_lock_path(
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

static int king_orchestrator_state_lock_acquire(int *lock_fd_out)
{
    char lock_path[1024];
    int flags = O_RDWR | O_CREAT;
    int fd;

    if (lock_fd_out == NULL) {
        return FAILURE;
    }

    *lock_fd_out = -1;

    if (!king_orchestrator_state_path_is_configured()) {
        return SUCCESS;
    }

    if (king_orchestrator_build_state_lock_path(
            king_mcp_orchestrator_config.orchestrator_state_path,
            lock_path,
            sizeof(lock_path)
        ) != SUCCESS) {
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

static void king_orchestrator_state_lock_release(int lock_fd)
{
    if (lock_fd < 0) {
        return;
    }

    (void) flock(lock_fd, LOCK_UN);
    close(lock_fd);
}

static int king_orchestrator_state_refresh_locked(void)
{
    if (!king_orchestrator_state_path_is_configured()) {
        return SUCCESS;
    }

    return king_orchestrator_load_state();
}

static int king_orchestrator_state_transaction_begin(int *lock_fd_out)
{
    if (lock_fd_out == NULL) {
        return FAILURE;
    }

    *lock_fd_out = -1;

    if (!king_orchestrator_registry_initialized) {
        if (king_orchestrator_registry_init() != SUCCESS) {
            return FAILURE;
        }
    }

    if (king_orchestrator_state_lock_acquire(lock_fd_out) != SUCCESS) {
        return FAILURE;
    }

    if (king_orchestrator_state_refresh_locked() != SUCCESS) {
        king_orchestrator_state_lock_release(*lock_fd_out);
        *lock_fd_out = -1;
        return FAILURE;
    }

    return SUCCESS;
}

static int king_orchestrator_build_queue_entry_path(
    const char *entry_name,
    char *path,
    size_t path_len
)
{
    if (
        entry_name == NULL
        || path == NULL
        || path_len == 0
        || !king_orchestrator_queue_path_is_configured()
    ) {
        return FAILURE;
    }

    if (snprintf(
            path,
            path_len,
            "%s/%s",
            king_mcp_orchestrator_config.orchestrator_worker_queue_path,
            entry_name
        ) >= (int) path_len) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_orchestrator_queue_entry_is_regular_file(const char *path)
{
    struct stat st;

    if (path == NULL) {
        return 0;
    }

    return lstat(path, &st) == 0 && S_ISREG(st.st_mode);
}

static int king_orchestrator_queue_is_safe_directory(void)
{
    struct stat st;
    const char *queue_path = king_mcp_orchestrator_config.orchestrator_worker_queue_path;

    if (!king_orchestrator_queue_path_is_configured()) {
        return FAILURE;
    }

    if (lstat(queue_path, &st) != 0) {
        return FAILURE;
    }

    if (!S_ISDIR(st.st_mode)) {
        return FAILURE;
    }

    if ((st.st_mode & (S_IWGRP | S_IWOTH)) != 0) {
        return FAILURE;
    }

    return SUCCESS;
}

static FILE *king_orchestrator_open_nofollow_stream(
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

static int king_orchestrator_open_claimed_job_fd(
    const char *path,
    int *fd_out,
    bool *discard_path_out
)
{
    int fd;
    int open_flags = O_RDONLY;
    struct stat st;

    if (fd_out == NULL) {
        return FAILURE;
    }

    *fd_out = -1;
    if (discard_path_out != NULL) {
        *discard_path_out = false;
    }

    if (path == NULL || path[0] == '\0') {
        errno = EINVAL;
        return FAILURE;
    }

#ifdef O_CLOEXEC
    open_flags |= O_CLOEXEC;
#endif
#ifdef O_NONBLOCK
    open_flags |= O_NONBLOCK;
#endif
#ifdef O_NOFOLLOW
    open_flags |= O_NOFOLLOW;
#endif

    fd = open(path, open_flags);
    if (fd < 0) {
        if (
            discard_path_out != NULL
            && (errno == ELOOP || errno == ENOTDIR)
        ) {
            *discard_path_out = true;
        }
        return FAILURE;
    }

    if (fstat(fd, &st) != 0) {
        close(fd);
        return FAILURE;
    }

    if (!S_ISREG(st.st_mode)) {
        close(fd);
        if (discard_path_out != NULL) {
            *discard_path_out = true;
        }
        errno = EINVAL;
        return FAILURE;
    }

#ifdef O_NONBLOCK
    {
        int current_flags = fcntl(fd, F_GETFL);

        if (current_flags >= 0 && (current_flags & O_NONBLOCK) != 0) {
            (void) fcntl(fd, F_SETFL, current_flags & ~O_NONBLOCK);
        }
    }
#endif

    *fd_out = fd;
    return SUCCESS;
}

static int king_orchestrator_state_path_validate_existing(const char *state_path)
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

static int king_orchestrator_build_state_tmp_template(
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

static int king_orchestrator_queue_name_is_job(const char *name)
{
    size_t name_len;

    if (name == NULL || strncmp(name, "queued-", sizeof("queued-") - 1) != 0) {
        return 0;
    }

    name_len = strlen(name);
    return name_len > sizeof("queued-.job") - 1
        && strcmp(name + name_len - (sizeof(".job") - 1), ".job") == 0;
}

static int king_orchestrator_queue_name_is_claimed_job(const char *name)
{
    size_t name_len;

    if (name == NULL || strncmp(name, "claimed-", sizeof("claimed-") - 1) != 0) {
        return 0;
    }

    name_len = strlen(name);
    return name_len > sizeof("claimed-.job") - 1
        && strcmp(name + name_len - (sizeof(".job") - 1), ".job") == 0;
}

static zend_string *king_orchestrator_queue_entry_run_id(const char *name)
{
    const char *run_start;
    const char *suffix;
    size_t run_id_len;

    if (name == NULL) {
        return NULL;
    }

    run_start = strstr(name, "run-");
    if (run_start == NULL) {
        return NULL;
    }

    suffix = strstr(run_start, ".job");
    if (suffix == NULL || suffix <= run_start) {
        return NULL;
    }

    run_id_len = (size_t) (suffix - run_start);
    if (run_id_len == 0) {
        return NULL;
    }

    return zend_string_init(run_start, run_id_len, 0);
}

static zend_long king_orchestrator_queue_entry_sequence(const char *name)
{
    const char *run_start;
    char *endptr;
    zend_long sequence;

    if (name == NULL) {
        return 0;
    }

    run_start = strstr(name, "run-");
    if (run_start == NULL) {
        return 0;
    }

    run_start += sizeof("run-") - 1;
    if (*run_start == '\0') {
        return 0;
    }

    errno = 0;
    sequence = ZEND_STRTOL(run_start, &endptr, 10);
    if (errno != 0 || endptr == run_start || sequence <= 0) {
        return 0;
    }

    if (strcmp(endptr, ".job") != 0) {
        return 0;
    }

    return sequence;
}

static int king_orchestrator_queue_entry_is_better_candidate(
    const char *candidate_name,
    zend_long candidate_sequence,
    const char *selected_name,
    zend_long selected_sequence
)
{
    if (candidate_name == NULL || candidate_name[0] == '\0') {
        return 0;
    }

    if (selected_name == NULL || selected_name[0] == '\0') {
        return 1;
    }

    if (candidate_sequence > 0) {
        if (selected_sequence <= 0) {
            return 1;
        }
        if (candidate_sequence < selected_sequence) {
            return 1;
        }
        if (candidate_sequence > selected_sequence) {
            return 0;
        }
    } else if (selected_sequence > 0) {
        return 0;
    }

    return strcmp(candidate_name, selected_name) < 0;
}

static int king_orchestrator_build_cancel_marker_path(
    const zend_string *run_id,
    char *path,
    size_t path_len
)
{
    if (
        run_id == NULL
        || path == NULL
        || path_len == 0
        || !king_orchestrator_run_id_is_valid_literal(ZSTR_VAL(run_id), ZSTR_LEN(run_id))
        || !king_orchestrator_queue_path_is_configured()
    ) {
        return FAILURE;
    }

    if (snprintf(
            path,
            path_len,
            "%s/cancel-%s.sig",
            king_mcp_orchestrator_config.orchestrator_worker_queue_path,
            ZSTR_VAL(run_id)
        ) >= (int) path_len) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_orchestrator_build_run_queue_path(
    const zend_string *run_id,
    char *path,
    size_t path_len
)
{
    if (
        run_id == NULL
        || path == NULL
        || path_len == 0
        || !king_orchestrator_run_id_is_valid_literal(ZSTR_VAL(run_id), ZSTR_LEN(run_id))
        || !king_orchestrator_queue_path_is_configured()
    ) {
        return FAILURE;
    }

    if (snprintf(
            path,
            path_len,
            "%s/queued-%s.job",
            king_mcp_orchestrator_config.orchestrator_worker_queue_path,
            ZSTR_VAL(run_id)
        ) >= (int) path_len) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_orchestrator_run_state_is_terminal(const king_orchestrator_run_state_t *run_state)
{
    if (run_state == NULL || run_state->status == NULL) {
        return 0;
    }

    return zend_string_equals_literal(run_state->status, "completed")
        || zend_string_equals_literal(run_state->status, "failed")
        || zend_string_equals_literal(run_state->status, "cancelled");
}

static int king_orchestrator_decode_base64_zval(zend_string *encoded_value, zval *return_value)
{
    zend_string *decoded_payload;
    const unsigned char *cursor;
    php_unserialize_data_t var_hash;

    if (encoded_value == NULL) {
        ZVAL_NULL(return_value);
        return SUCCESS;
    }

    decoded_payload = php_base64_decode(
        (const unsigned char *) ZSTR_VAL(encoded_value),
        ZSTR_LEN(encoded_value)
    );
    if (decoded_payload == NULL) {
        return FAILURE;
    }

    cursor = (const unsigned char *) ZSTR_VAL(decoded_payload);
    ZVAL_NULL(return_value);

    PHP_VAR_UNSERIALIZE_INIT(var_hash);
    if (!php_var_unserialize(
            return_value,
            &cursor,
            cursor + ZSTR_LEN(decoded_payload),
            &var_hash)) {
        PHP_VAR_UNSERIALIZE_DESTROY(var_hash);
        zend_string_release(decoded_payload);
        zval_ptr_dtor(return_value);
        ZVAL_NULL(return_value);
        return FAILURE;
    }
    PHP_VAR_UNSERIALIZE_DESTROY(var_hash);
    zend_string_release(decoded_payload);

    return SUCCESS;
}
