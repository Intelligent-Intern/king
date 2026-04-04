static int king_orchestrator_queue_ensure_directory(void)
{
    const char *queue_path = king_mcp_orchestrator_config.orchestrator_worker_queue_path;

    if (!king_orchestrator_queue_path_is_configured()) {
        return FAILURE;
    }

    if (mkdir(queue_path, 0700) != 0 && errno != EEXIST) {
        return FAILURE;
    }

    if (king_orchestrator_queue_is_safe_directory() != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

static size_t king_orchestrator_count_runs_in_queue_phase(
    const char *queue_phase,
    size_t queue_phase_len
)
{
    zval *entry;
    size_t queue_phase_count = 0;

    if (!king_orchestrator_backend_is_file_worker() || queue_phase == NULL || queue_phase_len == 0) {
        return 0;
    }

    ZEND_HASH_FOREACH_VAL(&king_orchestrator_pipeline_runs, entry) {
        king_orchestrator_run_state_t *run_state;

        if (Z_TYPE_P(entry) != IS_PTR) {
            continue;
        }

        run_state = (king_orchestrator_run_state_t *) Z_PTR_P(entry);
        if (
            run_state == NULL
            || run_state->queue_phase == NULL
            || king_orchestrator_run_state_is_terminal(run_state)
            || strcmp(king_orchestrator_run_execution_backend(run_state), "file_worker") != 0
            || ZSTR_LEN(run_state->queue_phase) != queue_phase_len
            || memcmp(ZSTR_VAL(run_state->queue_phase), queue_phase, queue_phase_len) != 0
        ) {
            continue;
        }

        queue_phase_count++;
    } ZEND_HASH_FOREACH_END();

    return queue_phase_count;
}

static size_t king_orchestrator_count_queued_runs(void)
{
    return king_orchestrator_count_runs_in_queue_phase(
        "queued",
        sizeof("queued") - 1
    );
}

static size_t king_orchestrator_count_claimed_runs(void)
{
    return king_orchestrator_count_runs_in_queue_phase(
        "claimed",
        sizeof("claimed") - 1
    );
}

static void king_orchestrator_run_state_replace_string_literal(
    zend_string **target,
    const char *value
)
{
    king_orchestrator_replace_runtime_string(
        target,
        value != NULL ? zend_string_init(value, strlen(value), 1) : NULL
    );
}

static int king_orchestrator_pipeline_run_note_enqueued(zend_string *run_id, time_t enqueued_at)
{
    king_orchestrator_run_state_t *run_state;
    int lock_fd = -1;
    int rc;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    run_state->enqueued_at = enqueued_at;
    king_orchestrator_run_state_replace_string_literal(&run_state->queue_phase, "queued");

    rc = king_orchestrator_persist_state_locked();
    king_orchestrator_state_lock_release(lock_fd);

    return rc;
}

int king_orchestrator_pipeline_run_note_recovery(
    zend_string *run_id,
    const char *reason
)
{
    king_orchestrator_run_state_t *run_state;
    time_t now;
    int lock_fd = -1;
    int rc;

    if (reason == NULL || reason[0] == '\0') {
        return FAILURE;
    }

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    now = time(NULL);
    run_state->recovery_count++;
    run_state->last_recovered_at = now;
    king_orchestrator_run_state_replace_string_literal(&run_state->last_recovery_reason, reason);

    rc = king_orchestrator_persist_state_locked();
    king_orchestrator_state_lock_release(lock_fd);

    return rc;
}

int king_orchestrator_pipeline_run_note_remote_attempt(zend_string *run_id)
{
    king_orchestrator_run_state_t *run_state;
    int lock_fd = -1;
    int rc;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    run_state->remote_attempt_count++;
    run_state->last_remote_attempt_at = time(NULL);

    rc = king_orchestrator_persist_state_locked();
    king_orchestrator_state_lock_release(lock_fd);

    return rc;
}

static int king_orchestrator_persist_state_locked(void)
{
    char tmp_path[1024];
    FILE *stream;
    const char *state_path = king_mcp_orchestrator_config.orchestrator_state_path;
    zend_string *tool_name;
    zval *tool_config_b64;
    zval *run_entry;

    if (state_path == NULL || state_path[0] == '\0') {
        return SUCCESS;
    }

    snprintf(tmp_path, sizeof(tmp_path), "%s.tmp.%ld", state_path, (long) getpid());
    if (king_orchestrator_state_path_validate_existing(state_path) != SUCCESS) {
        return FAILURE;
    }

    if (king_orchestrator_build_state_tmp_template(state_path, tmp_path, sizeof(tmp_path)) != SUCCESS) {
        return FAILURE;
    }

    {
        int fd = mkstemp(tmp_path);
        if (fd < 0) {
            return FAILURE;
        }
        if (fchmod(fd, 0600) != 0) {
            close(fd);
            unlink(tmp_path);
            return FAILURE;
        }
        stream = fdopen(fd, "wb");
        if (stream == NULL) {
            close(fd);
            unlink(tmp_path);
            return FAILURE;
        }
    }

    if (stream == NULL) {
        return FAILURE;
    }

    fprintf(stream, "version\t%d\n", KING_ORCHESTRATOR_STATE_VERSION);
    if (king_orchestrator_logging_config_b64 != NULL) {
        fprintf(stream, "logging\t%s\n", ZSTR_VAL(king_orchestrator_logging_config_b64));
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(&king_orchestrator_tool_registry, tool_name, tool_config_b64) {
        zend_string *encoded_name;

        if (tool_name == NULL || Z_TYPE_P(tool_config_b64) != IS_STRING) {
            continue;
        }

        encoded_name = php_base64_encode(
            (const unsigned char *) ZSTR_VAL(tool_name),
            ZSTR_LEN(tool_name)
        );
        if (encoded_name == NULL) {
            fclose(stream);
            unlink(tmp_path);
            return FAILURE;
        }

        fprintf(
            stream,
            "tool\t%s\t%s\n",
            ZSTR_VAL(encoded_name),
            ZSTR_VAL(Z_STR_P(tool_config_b64))
        );
        zend_string_release(encoded_name);
    } ZEND_HASH_FOREACH_END();

    ZEND_HASH_FOREACH_VAL(&king_orchestrator_pipeline_runs, run_entry) {
        king_orchestrator_run_state_t *run_state;

        if (Z_TYPE_P(run_entry) != IS_PTR) {
            continue;
        }

        run_state = (king_orchestrator_run_state_t *) Z_PTR_P(run_entry);
        if (run_state == NULL || run_state->run_id == NULL || run_state->status == NULL) {
            continue;
        }

        fprintf(
            stream,
            "run\t%s\t%s\t%ld\t%ld\t%s\t%s\t%s\t%s\t%s\t%d\t%s\t%s\t%s\t%ld\t%ld\t%s\t%s\t%ld\t%ld\t%ld\t%ld\t%ld\t%ld\t%s\t%ld\t%ld\t%s\t%s\n",
            ZSTR_VAL(run_state->run_id),
            ZSTR_VAL(run_state->status),
            (long) run_state->started_at,
            (long) run_state->finished_at,
            run_state->initial_data_b64 != NULL ? ZSTR_VAL(run_state->initial_data_b64) : "",
            run_state->pipeline_b64 != NULL ? ZSTR_VAL(run_state->pipeline_b64) : "",
            run_state->options_b64 != NULL ? ZSTR_VAL(run_state->options_b64) : "",
            run_state->result_b64 != NULL ? ZSTR_VAL(run_state->result_b64) : "",
            run_state->error_b64 != NULL ? ZSTR_VAL(run_state->error_b64) : "",
            run_state->cancel_requested ? 1 : 0,
            run_state->error_category != NULL ? ZSTR_VAL(run_state->error_category) : "",
            run_state->retry_disposition != NULL ? ZSTR_VAL(run_state->retry_disposition) : "",
            run_state->error_backend != NULL ? ZSTR_VAL(run_state->error_backend) : "",
            (long) run_state->error_step_index,
            (long) run_state->completed_step_count,
            run_state->execution_backend != NULL ? ZSTR_VAL(run_state->execution_backend) : "",
            run_state->queue_phase != NULL ? ZSTR_VAL(run_state->queue_phase) : "",
            (long) run_state->enqueued_at,
            (long) run_state->last_claimed_at,
            (long) run_state->last_recovered_at,
            (long) run_state->last_remote_attempt_at,
            (long) run_state->claim_count,
            (long) run_state->recovery_count,
            run_state->last_recovery_reason != NULL ? ZSTR_VAL(run_state->last_recovery_reason) : "",
            (long) run_state->remote_attempt_count,
            (long) run_state->last_claimed_by_pid,
            run_state->telemetry_parent_context_b64 != NULL
                ? ZSTR_VAL(run_state->telemetry_parent_context_b64)
                : "",
            run_state->handler_boundary_b64 != NULL
                ? ZSTR_VAL(run_state->handler_boundary_b64)
                : ""
        );
    } ZEND_HASH_FOREACH_END();

    if (fclose(stream) != 0) {
        unlink(tmp_path);
        return FAILURE;
    }

    if (king_orchestrator_state_path_validate_existing(state_path) != SUCCESS) {
        unlink(tmp_path);
        return FAILURE;
    }

    if (rename(tmp_path, state_path) != 0) {
        unlink(tmp_path);
        return FAILURE;
    }

    return SUCCESS;
}

static size_t king_orchestrator_split_state_fields(
    char *line,
    char **fields,
    size_t max_fields
)
{
    char *cursor;
    char *field_start;
    size_t count = 0;

    if (line == NULL || fields == NULL || max_fields == 0) {
        return 0;
    }

    cursor = line;
    field_start = line;

    while (*cursor != '\0' && *cursor != '\n' && *cursor != '\r') {
        if (*cursor == '\t') {
            if (count < max_fields) {
                fields[count++] = field_start;
            }
            *cursor = '\0';
            field_start = cursor + 1;
        }
        cursor++;
    }

    if (*cursor == '\n' || *cursor == '\r') {
        *cursor = '\0';
    }

    if (count < max_fields) {
        fields[count++] = field_start;
    }

    return count;
}

static int king_orchestrator_load_state(void)
{
    FILE *stream;
    char line[16384];
    const char *state_path = king_mcp_orchestrator_config.orchestrator_state_path;
    struct stat st;

    king_orchestrator_runtime_clear_state();

    if (state_path == NULL || state_path[0] == '\0') {
        king_orchestrator_recovered_from_state = false;
        return SUCCESS;
    }

    if (php_check_open_basedir(state_path) != 0) {
        return FAILURE;
    }

    if (lstat(state_path, &st) != 0) {
        if (errno == ENOENT) {
            king_orchestrator_recovered_from_state = false;
            return SUCCESS;
        }

        return FAILURE;
    }

    if (!S_ISREG(st.st_mode)) {
        return FAILURE;
    }

    stream = king_orchestrator_open_nofollow_stream(
        state_path,
        O_RDONLY,
        0,
        "rb"
    );
    if (stream == NULL) {
        return FAILURE;
    }

    while (fgets(line, sizeof(line), stream) != NULL) {
        char *fields[32];
        char *kind;
        size_t field_count;

        if (line[0] == '\n' || line[0] == '\r' || line[0] == '#') {
            continue;
        }

        field_count = king_orchestrator_split_state_fields(
            line,
            fields,
            sizeof(fields) / sizeof(fields[0])
        );
        if (field_count == 0) {
            continue;
        }
        kind = fields[0];

        if (strcmp(kind, "version") == 0) {
            continue;
        }

        if (strcmp(kind, "logging") == 0) {
            char *logging_b64 = field_count > 1 ? fields[1] : NULL;
            if (logging_b64 != NULL && logging_b64[0] != '\0') {
                king_orchestrator_replace_runtime_string(
                    &king_orchestrator_logging_config_b64,
                    king_orchestrator_dup_state_field(logging_b64)
                );
                king_orchestrator_recovered_from_state = true;
            }
            continue;
        }

        if (strcmp(kind, "tool") == 0) {
            char *encoded_name = field_count > 1 ? fields[1] : NULL;
            char *config_b64 = field_count > 2 ? fields[2] : NULL;
            zend_string *decoded_name;
            zend_string *persistent_name;
            zval stored_config;

            if (
                encoded_name == NULL || encoded_name[0] == '\0'
                || config_b64 == NULL || config_b64[0] == '\0'
            ) {
                continue;
            }

            decoded_name = php_base64_decode(
                (const unsigned char *) encoded_name,
                strlen(encoded_name)
            );
            if (decoded_name == NULL) {
                continue;
            }

            persistent_name = zend_string_dup(decoded_name, 1);
            ZVAL_STR(&stored_config, king_orchestrator_dup_state_field(config_b64));
            zend_hash_update(&king_orchestrator_tool_registry, persistent_name, &stored_config);
            zend_string_release_ex(persistent_name, 1);
            zend_string_release(decoded_name);
            king_orchestrator_recovered_from_state = true;
            continue;
        }

        if (strcmp(kind, "run") == 0) {
            char *run_id;
            char *status;
            char *started_at;
            char *finished_at;
            char *initial_data_b64;
            char *pipeline_b64;
            char *options_b64;
            char *result_b64;
            char *error_b64;
            char *cancel_requested;
            char *error_category;
            char *retry_disposition;
            char *error_backend;
            char *error_step_index;
            char *completed_step_count;
            char *execution_backend = NULL;
            char *queue_phase = NULL;
            char *enqueued_at = NULL;
            char *last_claimed_at = NULL;
            char *last_recovered_at = NULL;
            char *last_remote_attempt_at = NULL;
            char *claim_count = NULL;
            char *recovery_count = NULL;
            char *last_recovery_reason = NULL;
            char *remote_attempt_count = NULL;
            char *last_claimed_by_pid = NULL;
            char *telemetry_parent_context_b64 = NULL;
            char *handler_boundary_b64 = NULL;
            king_orchestrator_run_state_t *run_state;
            zval stored_run;
            zend_string *persistent_key;
            zend_long sequence;

            if (field_count != 10 && field_count != 16 && field_count != 27 && field_count != 28 && field_count != 29) {
                continue;
            }

            run_id = fields[1];
            status = fields[2];
            started_at = fields[3];
            finished_at = fields[4];
            initial_data_b64 = fields[5];
            pipeline_b64 = fields[6];
            options_b64 = fields[7];
            result_b64 = fields[8];
            error_b64 = fields[9];
            if (field_count >= 16) {
                cancel_requested = fields[10];
                error_category = fields[11];
                retry_disposition = fields[12];
                error_backend = fields[13];
                error_step_index = fields[14];
                completed_step_count = fields[15];
            }
            if (field_count == 27 || field_count == 28 || field_count == 29) {
                execution_backend = fields[16];
                queue_phase = fields[17];
                enqueued_at = fields[18];
                last_claimed_at = fields[19];
                last_recovered_at = fields[20];
                last_remote_attempt_at = fields[21];
                claim_count = fields[22];
                recovery_count = fields[23];
                last_recovery_reason = fields[24];
                remote_attempt_count = fields[25];
                last_claimed_by_pid = fields[26];
            }
            if (field_count == 28) {
                telemetry_parent_context_b64 = fields[27];
            }
            if (field_count == 29) {
                telemetry_parent_context_b64 = fields[27];
                handler_boundary_b64 = fields[28];
            }

            if (
                run_id == NULL || run_id[0] == '\0'
                || status == NULL || status[0] == '\0'
                || started_at == NULL
                || finished_at == NULL
                || initial_data_b64 == NULL
                || pipeline_b64 == NULL
                || options_b64 == NULL
                || result_b64 == NULL
                || error_b64 == NULL
            ) {
                continue;
            }

            run_state = pemalloc(sizeof(*run_state), 1);
            memset(run_state, 0, sizeof(*run_state));
            run_state->run_id = king_orchestrator_dup_state_field(run_id);
            run_state->status = king_orchestrator_dup_state_field(status);
            run_state->execution_backend = (
                execution_backend != NULL && execution_backend[0] != '\0'
            ) ? king_orchestrator_dup_state_field(execution_backend) : king_orchestrator_dup_state_field(king_orchestrator_current_execution_backend());
            run_state->queue_phase = (
                queue_phase != NULL && queue_phase[0] != '\0'
            ) ? king_orchestrator_dup_state_field(queue_phase) : king_orchestrator_dup_state_field("not_queued");
            run_state->started_at = (time_t) ZEND_STRTOL(started_at, NULL, 10);
            run_state->finished_at = (time_t) ZEND_STRTOL(finished_at, NULL, 10);
            run_state->enqueued_at = (
                enqueued_at != NULL && enqueued_at[0] != '\0'
            ) ? (time_t) ZEND_STRTOL(enqueued_at, NULL, 10) : 0;
            run_state->last_claimed_at = (
                last_claimed_at != NULL && last_claimed_at[0] != '\0'
            ) ? (time_t) ZEND_STRTOL(last_claimed_at, NULL, 10) : 0;
            run_state->last_recovered_at = (
                last_recovered_at != NULL && last_recovered_at[0] != '\0'
            ) ? (time_t) ZEND_STRTOL(last_recovered_at, NULL, 10) : 0;
            run_state->last_remote_attempt_at = (
                last_remote_attempt_at != NULL && last_remote_attempt_at[0] != '\0'
            ) ? (time_t) ZEND_STRTOL(last_remote_attempt_at, NULL, 10) : 0;
            run_state->cancel_requested = (zend_bool) (
                cancel_requested != NULL && cancel_requested[0] != '\0'
                && ZEND_STRTOL(cancel_requested, NULL, 10) != 0
            );
            run_state->initial_data_b64 = king_orchestrator_dup_state_field(initial_data_b64);
            run_state->pipeline_b64 = king_orchestrator_dup_state_field(pipeline_b64);
            run_state->options_b64 = king_orchestrator_dup_state_field(options_b64);
            run_state->telemetry_parent_context_b64 = (
                telemetry_parent_context_b64 != NULL && telemetry_parent_context_b64[0] != '\0'
            ) ? king_orchestrator_dup_state_field(telemetry_parent_context_b64) : NULL;
            run_state->handler_boundary_b64 = (
                handler_boundary_b64 != NULL && handler_boundary_b64[0] != '\0'
            ) ? king_orchestrator_dup_state_field(handler_boundary_b64) : NULL;
            run_state->result_b64 = king_orchestrator_dup_state_field(result_b64);
            run_state->error_b64 = king_orchestrator_dup_state_field(error_b64);
            run_state->error_category = (
                error_category != NULL && error_category[0] != '\0'
            ) ? king_orchestrator_dup_state_field(error_category) : NULL;
            run_state->retry_disposition = (
                retry_disposition != NULL && retry_disposition[0] != '\0'
            ) ? king_orchestrator_dup_state_field(retry_disposition) : NULL;
            run_state->error_backend = (
                error_backend != NULL && error_backend[0] != '\0'
            ) ? king_orchestrator_dup_state_field(error_backend) : NULL;
            run_state->last_recovery_reason = (
                last_recovery_reason != NULL && last_recovery_reason[0] != '\0'
            ) ? king_orchestrator_dup_state_field(last_recovery_reason) : NULL;
            run_state->error_step_index = (
                error_step_index != NULL && error_step_index[0] != '\0'
            ) ? ZEND_STRTOL(error_step_index, NULL, 10) : -1;
            run_state->completed_step_count = (
                completed_step_count != NULL && completed_step_count[0] != '\0'
            ) ? ZEND_STRTOL(completed_step_count, NULL, 10) : 0;
            run_state->claim_count = (
                claim_count != NULL && claim_count[0] != '\0'
            ) ? ZEND_STRTOL(claim_count, NULL, 10) : 0;
            run_state->recovery_count = (
                recovery_count != NULL && recovery_count[0] != '\0'
            ) ? ZEND_STRTOL(recovery_count, NULL, 10) : 0;
            run_state->remote_attempt_count = (
                remote_attempt_count != NULL && remote_attempt_count[0] != '\0'
            ) ? ZEND_STRTOL(remote_attempt_count, NULL, 10) : 0;
            run_state->last_claimed_by_pid = (
                last_claimed_by_pid != NULL && last_claimed_by_pid[0] != '\0'
            ) ? ZEND_STRTOL(last_claimed_by_pid, NULL, 10) : 0;

            ZVAL_PTR(&stored_run, run_state);
            persistent_key = zend_string_dup(run_state->run_id, 1);
            zend_hash_update(&king_orchestrator_pipeline_runs, persistent_key, &stored_run);
            zend_string_release_ex(persistent_key, 1);
            king_orchestrator_set_last_run(run_state->run_id, ZSTR_VAL(run_state->status));

            sequence = king_orchestrator_extract_run_sequence(run_state->run_id);
            if (sequence >= king_orchestrator_next_run_id) {
                king_orchestrator_next_run_id = sequence + 1;
            }

            king_orchestrator_recovered_from_state = true;
        }
    }

    fclose(stream);
    return SUCCESS;
}

static king_orchestrator_run_state_t *king_orchestrator_find_run(zend_string *run_id)
{
    zval *entry;

    if (run_id == NULL) {
        return NULL;
    }

    entry = zend_hash_find(&king_orchestrator_pipeline_runs, run_id);
    if (entry == NULL || Z_TYPE_P(entry) != IS_PTR) {
        return NULL;
    }

    return (king_orchestrator_run_state_t *) Z_PTR_P(entry);
}

int king_orchestrator_load_run_payload(
    zend_string *run_id,
    zval *initial_data,
    zval *pipeline,
    zval *options,
    zval *telemetry_parent_context)
{
    king_orchestrator_run_state_t *run_state;
    int lock_fd = -1;

    if (
        initial_data == NULL
        || pipeline == NULL
        || options == NULL
        || telemetry_parent_context == NULL
    ) {
        return FAILURE;
    }

    ZVAL_NULL(initial_data);
    ZVAL_NULL(pipeline);
    ZVAL_NULL(options);
    ZVAL_NULL(telemetry_parent_context);

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (
        king_orchestrator_decode_base64_zval(run_state->initial_data_b64, initial_data) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->pipeline_b64, pipeline) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->options_b64, options) != SUCCESS
        || (
            run_state->telemetry_parent_context_b64 != NULL
            && king_orchestrator_decode_base64_zval(
                run_state->telemetry_parent_context_b64,
                telemetry_parent_context
            ) != SUCCESS
        )
    ) {
        zval_ptr_dtor(initial_data);
        zval_ptr_dtor(pipeline);
        zval_ptr_dtor(options);
        zval_ptr_dtor(telemetry_parent_context);
        ZVAL_NULL(initial_data);
        ZVAL_NULL(pipeline);
        ZVAL_NULL(options);
        ZVAL_NULL(telemetry_parent_context);
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    king_orchestrator_state_lock_release(lock_fd);
    return SUCCESS;
}

int king_orchestrator_load_run_progress(
    zend_string *run_id,
    zval *result,
    zend_long *completed_step_count_out
)
{
    king_orchestrator_run_state_t *run_state;
    int lock_fd = -1;

    if (result == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(result);
    if (completed_step_count_out != NULL) {
        *completed_step_count_out = 0;
    }

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (completed_step_count_out != NULL) {
        *completed_step_count_out = run_state->completed_step_count;
    }

    if (
        run_state->result_b64 != NULL
        && king_orchestrator_decode_base64_zval(run_state->result_b64, result) != SUCCESS
    ) {
        zval_ptr_dtor(result);
        ZVAL_NULL(result);
        if (completed_step_count_out != NULL) {
            *completed_step_count_out = 0;
        }
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    king_orchestrator_state_lock_release(lock_fd);
    return SUCCESS;
}

int king_orchestrator_load_run_handler_boundary(zend_string *run_id, zval *return_value)
{
    king_orchestrator_run_state_t *run_state;
    int lock_fd = -1;

    if (return_value == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(return_value);

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (run_state->handler_boundary_b64 == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return SUCCESS;
    }

    if (
        king_orchestrator_decode_base64_zval(
            run_state->handler_boundary_b64,
            return_value
        ) != SUCCESS
    ) {
        zval_ptr_dtor(return_value);
        ZVAL_NULL(return_value);
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    king_orchestrator_state_lock_release(lock_fd);
    return SUCCESS;
}
