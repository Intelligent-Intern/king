#include "queue_control/snapshot_api.inc"

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
    zval handler_boundary;
    zval *requires_process_registration;
    zval *required_step_refs;
    zval *entry;

    if (ready_out == NULL) {
        return FAILURE;
    }

    *ready_out = 1;
    ZVAL_NULL(&handler_boundary);

    if (king_orchestrator_load_run_handler_boundary(run_id, &handler_boundary) != SUCCESS) {
        return FAILURE;
    }

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
    size_t run_id_len;
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
                "%s/claimed-" ZEND_LONG_FMT "-%s",
                king_mcp_orchestrator_config.orchestrator_worker_queue_path,
                (zend_long) getpid(),
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

        run_id_len = strlen(run_id_buffer);
        if (!king_orchestrator_run_id_is_valid_literal(run_id_buffer, run_id_len)) {
            close(claimed_fd);
            claimed_fd = -1;
            unlink(claimed_path);
            claimed_path[0] = '\0';
            return FAILURE;
        }

        *run_id_out = zend_string_init(run_id_buffer, run_id_len, 0);
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
