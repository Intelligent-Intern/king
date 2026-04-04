static void king_orchestrator_append_step_snapshots(
    zval *target,
    zval *pipeline,
    const king_orchestrator_run_state_t *run_state
)
{
    zval steps;
    zval *step;
    zval *tool;
    zval entry;
    uint32_t index = 0;
    const char *execution_backend = king_orchestrator_run_execution_backend(run_state);
    const char *topology_scope = king_orchestrator_topology_scope_for_backend(execution_backend);

    if (target == NULL) {
        return;
    }

    array_init(&steps);
    if (pipeline == NULL || Z_TYPE_P(pipeline) != IS_ARRAY) {
        add_assoc_zval(target, "steps", &steps);
        return;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(pipeline), step) {
        array_init(&entry);
        add_assoc_long(&entry, "index", (zend_long) index);

        tool = NULL;
        if (step != NULL && Z_TYPE_P(step) == IS_ARRAY) {
            tool = zend_hash_str_find(Z_ARRVAL_P(step), "tool", sizeof("tool") - 1);
        }

        if (tool != NULL && Z_TYPE_P(tool) == IS_STRING) {
            add_assoc_stringl(&entry, "tool", Z_STRVAL_P(tool), Z_STRLEN_P(tool));
        } else {
            add_assoc_null(&entry, "tool");
        }

        add_assoc_string(
            &entry,
            "status",
            king_orchestrator_step_status_for_snapshot(run_state, (zend_long) index)
        );
        add_assoc_string(
            &entry,
            "compensation_status",
            king_orchestrator_step_compensation_status_for_snapshot(run_state, (zend_long) index)
        );
        add_assoc_string(&entry, "execution_backend", (char *) execution_backend);
        add_assoc_string(&entry, "topology_scope", (char *) topology_scope);

        if (
            run_state != NULL
            && run_state->error_step_index == (zend_long) index
            && (
                run_state->error_category != NULL
                || run_state->retry_disposition != NULL
                || run_state->error_backend != NULL
            )
        ) {
            king_orchestrator_append_error_classification(&entry, run_state, pipeline);
        } else {
            add_assoc_null(&entry, "error_classification");
        }

        if (king_orchestrator_find_pipeline_step_string(
                pipeline,
                (zend_long) index,
                "partition_id",
                sizeof("partition_id") - 1
            ) != NULL
            || king_orchestrator_find_pipeline_step_string(
                pipeline,
                (zend_long) index,
                "batch_id",
                sizeof("batch_id") - 1
            ) != NULL) {
            zval step_adapter;
            zend_string *attempt_identity;
            zend_string *failure_identity;
            zval *partition_id;
            zval *batch_id;

            array_init(&step_adapter);
            attempt_identity = king_orchestrator_build_attempt_identity_for_snapshot(run_state);
            add_assoc_stringl(&step_adapter, "attempt_identity", ZSTR_VAL(attempt_identity), ZSTR_LEN(attempt_identity));
            if (run_state != NULL && run_state->recovery_count > 0) {
                add_assoc_stringl(&step_adapter, "retry_identity", ZSTR_VAL(attempt_identity), ZSTR_LEN(attempt_identity));
            } else {
                add_assoc_null(&step_adapter, "retry_identity");
            }
            zend_string_release(attempt_identity);

            partition_id = king_orchestrator_find_pipeline_step_string(
                pipeline,
                (zend_long) index,
                "partition_id",
                sizeof("partition_id") - 1
            );
            if (partition_id != NULL) {
                add_assoc_stringl(&step_adapter, "partition_id", Z_STRVAL_P(partition_id), Z_STRLEN_P(partition_id));
            } else {
                add_assoc_null(&step_adapter, "partition_id");
            }

            batch_id = king_orchestrator_find_pipeline_step_string(
                pipeline,
                (zend_long) index,
                "batch_id",
                sizeof("batch_id") - 1
            );
            if (batch_id != NULL) {
                add_assoc_stringl(&step_adapter, "batch_id", Z_STRVAL_P(batch_id), Z_STRLEN_P(batch_id));
            } else {
                add_assoc_null(&step_adapter, "batch_id");
            }

            if (
                run_state != NULL
                && run_state->error_step_index == (zend_long) index
                && run_state->status != NULL
                && (
                    zend_string_equals_literal(run_state->status, "failed")
                    || zend_string_equals_literal(run_state->status, "cancelled")
                )
            ) {
                failure_identity = king_orchestrator_build_failure_identity_for_snapshot(run_state);
                if (failure_identity != NULL) {
                    add_assoc_stringl(&step_adapter, "failure_identity", ZSTR_VAL(failure_identity), ZSTR_LEN(failure_identity));
                    zend_string_release(failure_identity);
                } else {
                    add_assoc_null(&step_adapter, "failure_identity");
                }
            } else {
                add_assoc_null(&step_adapter, "failure_identity");
            }

            add_assoc_zval(&entry, "telemetry_adapter", &step_adapter);
        } else {
            add_assoc_null(&entry, "telemetry_adapter");
        }

        add_next_index_zval(&steps, &entry);
        index++;
    } ZEND_HASH_FOREACH_END();

    add_assoc_zval(target, "steps", &steps);
}

int king_orchestrator_get_run_snapshot(zend_string *run_id, zval *return_value)
{
    king_orchestrator_run_state_t *run_state;
    zval initial_data;
    zval pipeline;
    zval options;
    zval handler_boundary;
    zval result;
    zval error;
    int lock_fd = -1;

    ZVAL_NULL(&initial_data);
    ZVAL_NULL(&pipeline);
    ZVAL_NULL(&options);
    ZVAL_NULL(&handler_boundary);
    ZVAL_NULL(&result);
    ZVAL_NULL(&error);

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (
        king_orchestrator_decode_base64_zval(run_state->initial_data_b64, &initial_data) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->pipeline_b64, &pipeline) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->options_b64, &options) != SUCCESS
        || (
            run_state->handler_boundary_b64 != NULL
            && king_orchestrator_decode_base64_zval(run_state->handler_boundary_b64, &handler_boundary) != SUCCESS
        )
        || king_orchestrator_decode_base64_zval(run_state->result_b64, &result) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->error_b64, &error) != SUCCESS
    ) {
        zval_ptr_dtor(&initial_data);
        zval_ptr_dtor(&pipeline);
        zval_ptr_dtor(&options);
        zval_ptr_dtor(&handler_boundary);
        zval_ptr_dtor(&result);
        zval_ptr_dtor(&error);
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    array_init(return_value);
    add_assoc_stringl(return_value, "run_id", ZSTR_VAL(run_state->run_id), ZSTR_LEN(run_state->run_id));
    add_assoc_stringl(return_value, "status", ZSTR_VAL(run_state->status), ZSTR_LEN(run_state->status));
    add_assoc_string(return_value, "execution_backend", (char *) king_orchestrator_run_execution_backend(run_state));
    add_assoc_string(
        return_value,
        "topology_scope",
        (char *) king_orchestrator_topology_scope_for_backend(king_orchestrator_run_execution_backend(run_state))
    );
    add_assoc_string(return_value, "retry_policy", "single_attempt");
    add_assoc_string(return_value, "idempotency_policy", "caller_managed");
    add_assoc_string(return_value, "compensation_policy", "caller_managed");
    add_assoc_long(return_value, "started_at", (zend_long) run_state->started_at);
    add_assoc_long(return_value, "finished_at", (zend_long) run_state->finished_at);
    add_assoc_bool(return_value, "cancel_requested", run_state->cancel_requested ? 1 : 0);
    add_assoc_long(return_value, "step_count", king_orchestrator_pipeline_step_count(&pipeline));
    add_assoc_long(return_value, "completed_step_count", run_state->completed_step_count);
    add_assoc_zval(return_value, "initial_data", &initial_data);
    add_assoc_zval(return_value, "pipeline", &pipeline);
    add_assoc_zval(return_value, "options", &options);
    add_assoc_zval(return_value, "handler_boundary", &handler_boundary);
    add_assoc_zval(return_value, "result", &result);
    add_assoc_zval(return_value, "error", &error);
    king_orchestrator_append_error_classification(return_value, run_state, &pipeline);
    king_orchestrator_append_run_telemetry_adapter_snapshot(return_value, &pipeline, run_state);
    king_orchestrator_append_compensation_snapshot(return_value, &pipeline, run_state);
    king_orchestrator_append_run_observability(return_value, run_state, &pipeline);
    king_orchestrator_append_step_snapshots(return_value, &pipeline, run_state);

    king_orchestrator_state_lock_release(lock_fd);
    return SUCCESS;
}

int king_orchestrator_enqueue_run(zend_string *run_id, zval *return_value)
{
    char queue_file_path[1024];
    FILE *stream;
    time_t enqueued_at;

    if (!king_orchestrator_backend_is_file_worker() || !king_orchestrator_queue_path_is_configured()) {
        return FAILURE;
    }

    if (king_orchestrator_queue_ensure_directory() != SUCCESS) {
        return FAILURE;
    }

    snprintf(
        queue_file_path,
        sizeof(queue_file_path),
        "%s/queued-%s.job",
        king_mcp_orchestrator_config.orchestrator_worker_queue_path,
        ZSTR_VAL(run_id)
    );

    stream = king_orchestrator_open_nofollow_stream(
        queue_file_path,
        O_WRONLY | O_CREAT | O_EXCL,
        0600,
        "wb"
    );
    if (stream == NULL) {
        return FAILURE;
    }

    if (fprintf(stream, "%s\n", ZSTR_VAL(run_id)) < 0) {
        fclose(stream);
        unlink(queue_file_path);
        return FAILURE;
    }

    if (fclose(stream) != 0) {
        unlink(queue_file_path);
        return FAILURE;
    }

    enqueued_at = time(NULL);
    if (king_orchestrator_pipeline_run_note_enqueued(run_id, enqueued_at) != SUCCESS) {
        unlink(queue_file_path);
        return FAILURE;
    }

    array_init(return_value);
    add_assoc_stringl(return_value, "run_id", ZSTR_VAL(run_id), ZSTR_LEN(run_id));
    add_assoc_string(return_value, "backend", "file_worker");
    add_assoc_string(return_value, "status", "queued");
    add_assoc_long(return_value, "enqueued_at", (zend_long) enqueued_at);

    return SUCCESS;
}

int king_orchestrator_run_cancel_requested(zend_string *run_id)
{
    char marker_path[1024];

    if (king_orchestrator_build_cancel_marker_path(run_id, marker_path, sizeof(marker_path)) != SUCCESS) {
        return 0;
    }

    return king_orchestrator_queue_entry_is_regular_file(marker_path);
}

void king_orchestrator_clear_run_cancel_request(zend_string *run_id)
{
    char marker_path[1024];

    if (king_orchestrator_build_cancel_marker_path(run_id, marker_path, sizeof(marker_path)) != SUCCESS) {
        return;
    }

    unlink(marker_path);
}

static zend_result king_orchestrator_worker_handler_boundary_is_ready(
    zend_string *run_id,
    zend_bool *ready_out
)
{
    king_orchestrator_run_state_t *run_state;
    zval handler_boundary;
    zval *requires_process_registration;
    zval *required_step_refs;
    zval *entry;
    int lock_fd = -1;

    if (ready_out == NULL) {
        return FAILURE;
    }

    *ready_out = 1;
    ZVAL_NULL(&handler_boundary);

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
            &handler_boundary
        ) != SUCCESS
    ) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    king_orchestrator_state_lock_release(lock_fd);

    if (Z_TYPE(handler_boundary) != IS_ARRAY) {
        zval_ptr_dtor(&handler_boundary);
        return SUCCESS;
    }

    requires_process_registration = zend_hash_str_find(
        Z_ARRVAL(handler_boundary),
        "requires_process_registration",
        sizeof("requires_process_registration") - 1
    );
    if (
        requires_process_registration == NULL
        || !zend_is_true(requires_process_registration)
    ) {
        zval_ptr_dtor(&handler_boundary);
        return SUCCESS;
    }

    required_step_refs = zend_hash_str_find(
        Z_ARRVAL(handler_boundary),
        "required_step_refs",
        sizeof("required_step_refs") - 1
    );
    if (required_step_refs == NULL || Z_TYPE_P(required_step_refs) != IS_ARRAY) {
        zval_ptr_dtor(&handler_boundary);
        return FAILURE;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(required_step_refs), entry) {
        zval *tool_name;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            zval_ptr_dtor(&handler_boundary);
            return FAILURE;
        }

        tool_name = zend_hash_str_find(
            Z_ARRVAL_P(entry),
            "tool_name",
            sizeof("tool_name") - 1
        );
        if (tool_name == NULL || Z_TYPE_P(tool_name) != IS_STRING || Z_STRLEN_P(tool_name) == 0) {
            zval_ptr_dtor(&handler_boundary);
            return FAILURE;
        }

        if (
            king_orchestrator_lookup_tool_handler(
                Z_STRVAL_P(tool_name),
                Z_STRLEN_P(tool_name)
            ) == NULL
        ) {
            *ready_out = 0;
            zval_ptr_dtor(&handler_boundary);
            return SUCCESS;
        }
    } ZEND_HASH_FOREACH_END();

    zval_ptr_dtor(&handler_boundary);
    return SUCCESS;
}

int king_orchestrator_request_run_cancel(zend_string *run_id)
{
    king_orchestrator_run_state_t *run_state;
    char marker_path[1024];
    char queued_path[1024];
    FILE *stream;
    zend_bool previous_cancel_requested;
    int lock_fd = -1;
    int rc;

    if (
        run_id == NULL
        || !king_orchestrator_backend_is_file_worker()
        || !king_orchestrator_queue_path_is_configured()
    ) {
        return FAILURE;
    }

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL || king_orchestrator_run_state_is_terminal(run_state)) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (run_state->cancel_requested) {
        king_orchestrator_state_lock_release(lock_fd);
        return SUCCESS;
    }

    if (
        run_state->status != NULL
        && zend_string_equals_literal(run_state->status, "queued")
        && king_orchestrator_build_run_queue_path(run_id, queued_path, sizeof(queued_path)) == SUCCESS
        && king_orchestrator_queue_entry_is_regular_file(queued_path)
    ) {
        zend_string *error_b64;

        if (unlink(queued_path) != 0) {
            king_orchestrator_state_lock_release(lock_fd);
            return FAILURE;
        }

        error_b64 = king_orchestrator_encode_error_message_base64(
            "king_pipeline_orchestrator_cancel_run() cancelled the queued run before worker claim."
        );
        king_orchestrator_replace_runtime_string(
            &run_state->status,
            zend_string_init("cancelled", sizeof("cancelled") - 1, 1)
        );
        run_state->finished_at = time(NULL);
        run_state->cancel_requested = 1;
        if (strcmp(king_orchestrator_run_execution_backend(run_state), "file_worker") == 0) {
            king_orchestrator_run_state_replace_string_literal(&run_state->queue_phase, "dequeued");
        }
        if (error_b64 != NULL) {
            king_orchestrator_replace_runtime_string(&run_state->error_b64, zend_string_dup(error_b64, 1));
            zend_string_release(error_b64);
        }
        king_orchestrator_set_last_run(run_state->run_id, "cancelled");

        rc = king_orchestrator_persist_state_locked();
        king_orchestrator_state_lock_release(lock_fd);
        return rc;
    }

    if (king_orchestrator_queue_ensure_directory() != SUCCESS) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }
    if (king_orchestrator_build_cancel_marker_path(run_id, marker_path, sizeof(marker_path)) != SUCCESS) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    previous_cancel_requested = run_state->cancel_requested;
    run_state->cancel_requested = 1;
    if (king_orchestrator_persist_state_locked() != SUCCESS) {
        run_state->cancel_requested = previous_cancel_requested;
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    stream = king_orchestrator_open_nofollow_stream(
        marker_path,
        O_WRONLY | O_CREAT | O_TRUNC,
        0600,
        "wb"
    );
    if (stream == NULL) {
        run_state->cancel_requested = previous_cancel_requested;
        (void) king_orchestrator_persist_state_locked();
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (fprintf(stream, "%s\n", ZSTR_VAL(run_id)) < 0) {
        fclose(stream);
        unlink(marker_path);
        run_state->cancel_requested = previous_cancel_requested;
        (void) king_orchestrator_persist_state_locked();
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (fclose(stream) != 0) {
        unlink(marker_path);
        run_state->cancel_requested = previous_cancel_requested;
        (void) king_orchestrator_persist_state_locked();
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    king_orchestrator_state_lock_release(lock_fd);
    return SUCCESS;
}

int king_orchestrator_claim_next_run(
    zend_string **run_id_out,
    char *claimed_path,
    size_t claimed_path_len,
    int *claimed_fd_out,
    zend_bool *recovered_claim_out
)
{
    int claimed_fd = -1;
    char run_id_buffer[256];
    char *newline;
    unsigned int attempt = 0;
    char busy_names[64][256];
    size_t busy_count = 0;
    bool discard_claimed_path = false;

    if (run_id_out == NULL || claimed_path == NULL || claimed_path_len == 0) {
        return FAILURE;
    }

    *run_id_out = NULL;
    claimed_path[0] = '\0';

    if (claimed_fd_out != NULL) {
        *claimed_fd_out = -1;
    }
    if (recovered_claim_out != NULL) {
        *recovered_claim_out = 0;
    }

    if (!king_orchestrator_backend_is_file_worker() || !king_orchestrator_queue_path_is_configured()) {
        return FAILURE;
    }

    for (;;) {
        DIR *dir;
        struct dirent *entry;
        char queued_path[1024];
        char selected_name[256];
        zend_long selected_sequence = 0;
        int selected_is_claimed = 0;

        dir = opendir(king_mcp_orchestrator_config.orchestrator_worker_queue_path);
        if (dir == NULL) {
            if (errno == ENOENT) {
                return SUCCESS;
            }
            return FAILURE;
        }

        selected_name[0] = '\0';
        while ((entry = readdir(dir)) != NULL) {
            if (king_orchestrator_queue_name_is_claimed_job(entry->d_name)) {
                char entry_path[1024];
                zend_long entry_sequence;
                size_t busy_idx;
                int skip_entry = 0;

                for (busy_idx = 0; busy_idx < busy_count; busy_idx++) {
                    if (strcmp(entry->d_name, busy_names[busy_idx]) == 0) {
                        skip_entry = 1;
                        break;
                    }
                }
                if (skip_entry) {
                    continue;
                }

                if (
                    king_orchestrator_build_queue_entry_path(
                        entry->d_name,
                        entry_path,
                        sizeof(entry_path)
                    ) != SUCCESS
                    || !king_orchestrator_queue_entry_is_regular_file(entry_path)
                ) {
                    continue;
                }

                entry_sequence = king_orchestrator_queue_entry_sequence(entry->d_name);
                if (!king_orchestrator_queue_entry_is_better_candidate(
                        entry->d_name,
                        entry_sequence,
                        selected_name,
                        selected_sequence
                    )) {
                    continue;
                }

                strncpy(selected_name, entry->d_name, sizeof(selected_name) - 1);
                selected_name[sizeof(selected_name) - 1] = '\0';
                selected_sequence = entry_sequence;
                selected_is_claimed = 1;
            }
        }

        if (selected_name[0] == '\0') {
            rewinddir(dir);

            while ((entry = readdir(dir)) != NULL) {
                if (king_orchestrator_queue_name_is_job(entry->d_name)) {
                    char entry_path[1024];
                    zend_long entry_sequence;

                    if (
                        king_orchestrator_build_queue_entry_path(
                            entry->d_name,
                            entry_path,
                            sizeof(entry_path)
                        ) != SUCCESS
                        || !king_orchestrator_queue_entry_is_regular_file(entry_path)
                    ) {
                        continue;
                    }

                    entry_sequence = king_orchestrator_queue_entry_sequence(entry->d_name);
                    if (!king_orchestrator_queue_entry_is_better_candidate(
                            entry->d_name,
                            entry_sequence,
                            selected_name,
                            selected_sequence
                        )) {
                        continue;
                    }

                    strncpy(selected_name, entry->d_name, sizeof(selected_name) - 1);
                    selected_name[sizeof(selected_name) - 1] = '\0';
                    selected_sequence = entry_sequence;
                }
            }
        }

        closedir(dir);

        if (selected_name[0] == '\0') {
            return SUCCESS;
        }

        {
            zend_string *candidate_run_id;
            zend_bool handler_ready = 1;

            candidate_run_id = king_orchestrator_queue_entry_run_id(selected_name);
            if (candidate_run_id == NULL) {
                attempt++;
                if (attempt >= 64) {
                    return SUCCESS;
                }
                continue;
            }

            if (
                king_orchestrator_worker_handler_boundary_is_ready(
                    candidate_run_id,
                    &handler_ready
                ) != SUCCESS
            ) {
                zend_string_release(candidate_run_id);
                return FAILURE;
            }
            zend_string_release(candidate_run_id);

            if (!handler_ready) {
                if (busy_count < (sizeof(busy_names) / sizeof(busy_names[0]))) {
                    strncpy(busy_names[busy_count], selected_name, sizeof(busy_names[busy_count]) - 1);
                    busy_names[busy_count][sizeof(busy_names[busy_count]) - 1] = '\0';
                    busy_count++;
                }
                attempt++;
                if (attempt >= 64) {
                    return SUCCESS;
                }
                continue;
            }
        }

        if (selected_is_claimed) {
            snprintf(
                claimed_path,
                claimed_path_len,
                "%s/%s",
                king_mcp_orchestrator_config.orchestrator_worker_queue_path,
                selected_name
            );
        } else {
            snprintf(
                queued_path,
                sizeof(queued_path),
                "%s/%s",
                king_mcp_orchestrator_config.orchestrator_worker_queue_path,
                selected_name
            );
            snprintf(
                claimed_path,
                claimed_path_len,
                "%s/claimed-%ld-%s",
                king_mcp_orchestrator_config.orchestrator_worker_queue_path,
                (long) getpid(),
                selected_name
            );

            if (rename(queued_path, claimed_path) != 0) {
                claimed_path[0] = '\0';
                if (errno != ENOENT && errno != EEXIST) {
                    return FAILURE;
                }

                attempt++;
                if (attempt >= 64) {
                    return SUCCESS;
                }
                continue;
            }
        }

        discard_claimed_path = false;
        if (
            king_orchestrator_open_claimed_job_fd(
                claimed_path,
                &claimed_fd,
                &discard_claimed_path
            ) != SUCCESS
        ) {
            int open_errno = errno;

            if (discard_claimed_path || !selected_is_claimed) {
                unlink(claimed_path);
            }
            claimed_fd = -1;
            claimed_path[0] = '\0';
            if (
                open_errno != ENOENT
                && open_errno != ELOOP
                && open_errno != ENOTDIR
                && open_errno != EINVAL
            ) {
                return FAILURE;
            }
            attempt++;
            if (attempt >= 64) {
                return SUCCESS;
            }
            continue;
        }

        if (flock(claimed_fd, LOCK_EX | LOCK_NB) != 0) {
            int lock_errno = errno;

            close(claimed_fd);
            claimed_fd = -1;
            claimed_path[0] = '\0';

            if (lock_errno != EWOULDBLOCK && lock_errno != EAGAIN) {
                return FAILURE;
            }

            if (busy_count < (sizeof(busy_names) / sizeof(busy_names[0]))) {
                strncpy(busy_names[busy_count], selected_name, sizeof(busy_names[busy_count]) - 1);
                busy_names[busy_count][sizeof(busy_names[busy_count]) - 1] = '\0';
                busy_count++;
            }

            attempt++;
            if (attempt >= 64) {
                return SUCCESS;
            }
            continue;
        }

        if (lseek(claimed_fd, 0, SEEK_SET) < 0) {
            close(claimed_fd);
            claimed_fd = -1;
            if (!selected_is_claimed) {
                unlink(claimed_path);
            }
            claimed_path[0] = '\0';
            return FAILURE;
        }

        {
            ssize_t bytes_read = read(claimed_fd, run_id_buffer, sizeof(run_id_buffer) - 1);

            if (bytes_read <= 0) {
                close(claimed_fd);
                claimed_fd = -1;
                unlink(claimed_path);
                claimed_path[0] = '\0';
                return FAILURE;
            }

            run_id_buffer[bytes_read] = '\0';
        }

        newline = strpbrk(run_id_buffer, "\r\n");
        if (newline != NULL) {
            *newline = '\0';
        }

        if (run_id_buffer[0] == '\0') {
            close(claimed_fd);
            claimed_fd = -1;
            unlink(claimed_path);
            claimed_path[0] = '\0';
            return FAILURE;
        }

        *run_id_out = zend_string_init(run_id_buffer, strlen(run_id_buffer), 0);
        if (claimed_fd_out != NULL) {
            *claimed_fd_out = claimed_fd;
        } else {
            close(claimed_fd);
        }
        if (recovered_claim_out != NULL) {
            *recovered_claim_out = selected_is_claimed ? 1 : 0;
        }
        return SUCCESS;
    }
}
