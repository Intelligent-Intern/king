static int king_orchestrator_execute_existing_run(
    zend_string *run_id,
    zval *initial_data,
    zval *pipeline_array,
    zval *return_value,
    king_orchestrator_exec_control_t *control,
    const char *function_name,
    zend_bool throw_on_error)
{
    HashTable *ht;
    zval *step;
    uint32_t index = 0;
    zend_bool cancelled = 0;
    zend_long failed_step_index = -1;

    if (initial_data == NULL || pipeline_array == NULL || Z_TYPE_P(pipeline_array) != IS_ARRAY) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() requires an array pipeline definition.",
            king_ce_validation_exception,
            throw_on_error
        );
    }

    if (control != NULL) {
        control->run_id = run_id;
    }

    if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
        king_orchestrator_mark_interrupted_run(run_id, cancelled, -1, NULL);
        ZVAL_FALSE(return_value);
        return FAILURE;
    }

    /* Clone initial data to return_value as the current placeholder result. */
    ZVAL_COPY(return_value, initial_data);

    ht = Z_ARRVAL_P(pipeline_array);
    ZEND_HASH_FOREACH_VAL(ht, step) {
        cancelled = 0;
        if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            king_orchestrator_mark_interrupted_run(run_id, cancelled, (zend_long) index, NULL);
            return FAILURE;
        }

        failed_step_index = (zend_long) index;
        if (king_orchestrator_validate_pipeline_step(step, index, throw_on_error, &failed_step_index) != SUCCESS) {
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            (void) king_orchestrator_pipeline_run_fail_classified(
                run_id,
                king_get_error(),
                "validation",
                "non_retryable",
                failed_step_index,
                NULL
            );
            return FAILURE;
        }
        cancelled = 0;
        if (king_orchestrator_execute_step_delay(
                step,
                index,
                control,
                function_name,
                throw_on_error,
                &cancelled
            ) != SUCCESS) {
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            king_orchestrator_mark_interrupted_run(run_id, cancelled, (zend_long) index, NULL);
            return FAILURE;
        }
        if (king_orchestrator_pipeline_run_record_completed_steps(run_id, (zend_long) index + 1) != SUCCESS) {
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            king_set_error("king_pipeline_orchestrator_run() failed to persist completed step progress.");
            (void) king_orchestrator_pipeline_run_fail_classified(
                run_id,
                king_get_error(),
                "backend",
                "caller_managed_retry",
                -1,
                "controller"
            );
            return king_orchestrator_raise_error(
                king_get_error(),
                king_ce_runtime_exception,
                throw_on_error
            );
        }
        index++;
    } ZEND_HASH_FOREACH_END();

    cancelled = 0;
    if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
        zval_ptr_dtor(return_value);
        ZVAL_FALSE(return_value);
        king_orchestrator_mark_interrupted_run(run_id, cancelled, -1, NULL);
        return FAILURE;
    }

    if (king_orchestrator_pipeline_run_complete(run_id, return_value) != SUCCESS) {
        zval_ptr_dtor(return_value);
        ZVAL_FALSE(return_value);
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed to persist the completed run snapshot.",
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    return SUCCESS;
}

int king_orchestrator_resume_run(
    zend_string *run_id,
    zval *return_value,
    const char *function_name,
    zend_bool throw_on_error
)
{
    zval initial_data;
    zval pipeline;
    zval options;
    king_orchestrator_exec_control_t control;
    int rc;

    if (run_id == NULL) {
        return FAILURE;
    }

    /* Each resumed run must start without stale pre-flush span/log residue. */
    king_telemetry_cleanup_scope_state();

    ZVAL_NULL(&initial_data);
    ZVAL_NULL(&pipeline);
    ZVAL_NULL(&options);
    ZVAL_UNDEF(&control.cancel_token);
    control.run_id = NULL;

    if (king_orchestrator_load_run_payload(run_id, &initial_data, &pipeline, &options) != SUCCESS) {
        char message[KING_ERR_LEN];

        snprintf(
            message,
            sizeof(message),
            "%s() could not load the persisted run payload.",
            function_name != NULL ? function_name : "king_pipeline_orchestrator_resume_run"
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    if (king_orchestrator_exec_control_parse(
            &options,
            function_name,
            throw_on_error,
            &control
        ) != SUCCESS) {
        zval_ptr_dtor(&initial_data);
        zval_ptr_dtor(&pipeline);
        zval_ptr_dtor(&options);
        king_orchestrator_exec_control_cleanup(&control);
        return FAILURE;
    }

    if (king_orchestrator_backend_is_remote_peer()) {
        rc = king_orchestrator_execute_remote_run(
            run_id,
            &initial_data,
            &pipeline,
            &options,
            return_value,
            &control,
            function_name,
            throw_on_error
        );
    } else {
        rc = king_orchestrator_execute_existing_run(
            run_id,
            &initial_data,
            &pipeline,
            return_value,
            &control,
            function_name,
            throw_on_error
        );
    }

    zval_ptr_dtor(&initial_data);
    zval_ptr_dtor(&pipeline);
    zval_ptr_dtor(&options);
    king_orchestrator_exec_control_cleanup(&control);

    return rc;
}

int king_orchestrator_run(zval *initial_data, zval *pipeline_array, zval *options, zval *return_value)
{
    zend_string *run_id;
    zval sanitized_options;
    zval *persisted_options;
    king_orchestrator_exec_control_t control;
    int rc = FAILURE;

    ZVAL_UNDEF(&sanitized_options);
    ZVAL_UNDEF(&control.cancel_token);
    control.run_id = NULL;

    /* A new orchestrator execution starts a fresh telemetry scope. */
    king_telemetry_cleanup_scope_state();

    if (king_orchestrator_backend_is_file_worker()) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() is unavailable when orchestrator_execution_backend=file_worker; use king_pipeline_orchestrator_dispatch().",
            king_ce_runtime_exception,
            1
        );
    }

    if (king_orchestrator_exec_control_parse(
            options,
            "king_pipeline_orchestrator_run",
            1,
            &control
        ) != SUCCESS) {
        goto cleanup;
    }

    if (king_orchestrator_exec_control_check(&control, "king_pipeline_orchestrator_run", 1, NULL) != SUCCESS) {
        goto cleanup;
    }

    if (king_orchestrator_enforce_max_concurrency(&control, "king_pipeline_orchestrator_run", 1) != SUCCESS) {
        goto cleanup;
    }

    persisted_options = king_orchestrator_prepare_persisted_options(options, &sanitized_options);
    run_id = king_orchestrator_pipeline_run_begin(initial_data, pipeline_array, persisted_options, "running");
    if (run_id == NULL) {
        rc = king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed to persist the initial run snapshot.",
            king_ce_runtime_exception,
            1
        );
        goto cleanup;
    }

    if (king_orchestrator_backend_is_remote_peer()) {
        rc = king_orchestrator_execute_remote_run(
            run_id,
            initial_data,
            pipeline_array,
            persisted_options,
            return_value,
            &control,
            "king_pipeline_orchestrator_run",
            1
        );
    } else {
        rc = king_orchestrator_execute_existing_run(
            run_id,
            initial_data,
            pipeline_array,
            return_value,
            &control,
            "king_pipeline_orchestrator_run",
            1
        );
    }
    zend_string_release(run_id);
cleanup:
    if (!Z_ISUNDEF(sanitized_options)) {
        zval_ptr_dtor(&sanitized_options);
    }
    king_orchestrator_exec_control_cleanup(&control);
    return rc;
}

int king_orchestrator_dispatch(zval *initial_data, zval *pipeline_array, zval *options, zval *return_value)
{
    zend_string *run_id;
    zval sanitized_options;
    zval *persisted_options;
    king_orchestrator_exec_control_t control;
    int rc = FAILURE;

    ZVAL_UNDEF(&sanitized_options);
    ZVAL_UNDEF(&control.cancel_token);
    control.run_id = NULL;

    if (!king_orchestrator_backend_is_file_worker()) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() requires orchestrator_execution_backend=file_worker.",
            king_ce_runtime_exception,
            1
        );
    }

    if (
        king_mcp_orchestrator_config.orchestrator_worker_queue_path == NULL
        || king_mcp_orchestrator_config.orchestrator_worker_queue_path[0] == '\0'
    ) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() requires a non-empty orchestrator_worker_queue_path.",
            king_ce_runtime_exception,
            1
        );
    }

    if (king_orchestrator_exec_control_parse(
            options,
            "king_pipeline_orchestrator_dispatch",
            1,
            &control
        ) != SUCCESS) {
        goto cleanup;
    }

    if (!Z_ISUNDEF(control.cancel_token)) {
        rc = king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() does not support live CancelToken propagation on the file_worker backend.",
            king_ce_runtime_exception,
            1
        );
        goto cleanup;
    }

    if (king_orchestrator_exec_control_check(&control, "king_pipeline_orchestrator_dispatch", 1, NULL) != SUCCESS) {
        goto cleanup;
    }

    if (king_orchestrator_enforce_max_concurrency(&control, "king_pipeline_orchestrator_dispatch", 1) != SUCCESS) {
        goto cleanup;
    }

    persisted_options = king_orchestrator_prepare_persisted_options(options, &sanitized_options);
    run_id = king_orchestrator_pipeline_run_begin(initial_data, pipeline_array, persisted_options, "queued");
    if (run_id == NULL) {
        rc = king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() failed to persist the initial run snapshot.",
            king_ce_runtime_exception,
            1
        );
        goto cleanup;
    }

    rc = king_orchestrator_enqueue_run(run_id, return_value);
    if (rc != SUCCESS) {
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            "king_pipeline_orchestrator_dispatch() failed to enqueue the run for the file-worker backend.",
            "backend",
            "caller_managed_retry",
            -1,
            "file_worker_queue"
        );
        zend_string_release(run_id);
        rc = king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() failed to enqueue the run for the file-worker backend.",
            king_ce_runtime_exception,
            1
        );
        goto cleanup;
    }

    zend_string_release(run_id);
    rc = SUCCESS;
cleanup:
    if (!Z_ISUNDEF(sanitized_options)) {
        zval_ptr_dtor(&sanitized_options);
    }
    king_orchestrator_exec_control_cleanup(&control);
    return rc;
}

int king_orchestrator_worker_run_next(zval *return_value)
{
    zend_string *run_id = NULL;
    char claimed_path[1024];
    int claimed_fd = -1;
    zend_bool recovered_claim = 0;
    int rc;

    if (!king_orchestrator_backend_is_file_worker()) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() requires orchestrator_execution_backend=file_worker.",
            king_ce_runtime_exception,
            1
        );
    }

    if (
        king_mcp_orchestrator_config.orchestrator_worker_queue_path == NULL
        || king_mcp_orchestrator_config.orchestrator_worker_queue_path[0] == '\0'
    ) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() requires a non-empty orchestrator_worker_queue_path.",
            king_ce_runtime_exception,
            1
        );
    }

    if (
        king_orchestrator_claim_next_run(
            &run_id,
            claimed_path,
            sizeof(claimed_path),
            &claimed_fd,
            &recovered_claim
        ) != SUCCESS
    ) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() could not claim a queued run.",
            king_ce_runtime_exception,
            1
        );
    }

    if (run_id == NULL) {
        if (claimed_fd >= 0) {
            close(claimed_fd);
        }
        ZVAL_FALSE(return_value);
        return SUCCESS;
    }

    if (king_orchestrator_pipeline_run_is_terminal(run_id)) {
        if (claimed_path[0] != '\0') {
            unlink(claimed_path);
        }
        if (claimed_fd >= 0) {
            close(claimed_fd);
        }
        if (king_orchestrator_get_run_snapshot(run_id, return_value) != SUCCESS) {
            zend_string_release(run_id);
            return king_orchestrator_raise_error(
                "king_pipeline_orchestrator_worker_run_next() could not read back the persisted terminal run snapshot.",
                king_ce_runtime_exception,
                1
            );
        }
        zend_string_release(run_id);
        return SUCCESS;
    }

    if (king_orchestrator_pipeline_run_mark_running(run_id, recovered_claim, (zend_long) getpid()) != SUCCESS) {
        if (claimed_path[0] != '\0') {
            unlink(claimed_path);
        }
        if (claimed_fd >= 0) {
            close(claimed_fd);
        }
        zend_string_release(run_id);
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() could not persist the claimed running snapshot.",
            king_ce_runtime_exception,
            1
        );
    }

    ZVAL_NULL(return_value);
    rc = king_orchestrator_resume_run(
        run_id,
        return_value,
        "king_pipeline_orchestrator_worker_run_next",
        1
    );
    zval_ptr_dtor(return_value);
    ZVAL_NULL(return_value);

    if (claimed_path[0] != '\0') {
        unlink(claimed_path);
    }
    if (claimed_fd >= 0) {
        close(claimed_fd);
        claimed_fd = -1;
    }

    if (rc != SUCCESS) {
        if (EG(exception) != NULL) {
            zval cancelled_snapshot;
            zval *status;
            const char *error_message = king_get_error();

            if (
                king_orchestrator_run_cancel_requested(run_id)
                || (
                    error_message != NULL
                    && strstr(error_message, "persisted file-worker cancel channel") != NULL
                )
                || king_orchestrator_exception_message_contains(
                    "persisted file-worker cancel channel"
                )
            ) {
                (void) king_orchestrator_pipeline_run_cancelled_classified(
                    run_id,
                    error_message,
                    "cancelled",
                    "not_applicable",
                    -1,
                    "file_worker"
                );
            }

            ZVAL_NULL(&cancelled_snapshot);
            if (king_orchestrator_get_run_snapshot(run_id, &cancelled_snapshot) == SUCCESS) {
                status = zend_hash_str_find(
                    Z_ARRVAL(cancelled_snapshot),
                    "status",
                    sizeof("status") - 1
                );
                if (
                    status != NULL
                    && Z_TYPE_P(status) == IS_STRING
                    && zend_string_equals_literal(Z_STR_P(status), "cancelled")
                ) {
                    zend_clear_exception();
                    zend_string_release(run_id);
                    ZVAL_COPY_VALUE(return_value, &cancelled_snapshot);
                    return SUCCESS;
                }
                zval_ptr_dtor(&cancelled_snapshot);
            }
            if (!king_orchestrator_pipeline_run_is_terminal(run_id)) {
                (void) king_orchestrator_pipeline_run_fail_classified(
                    run_id,
                    error_message,
                    "backend",
                    "caller_managed_retry",
                    -1,
                    "file_worker"
                );
            }
            zend_string_release(run_id);
            return FAILURE;
        }
        zend_string_release(run_id);
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() failed while executing the claimed run.",
            king_ce_runtime_exception,
            1
        );
    }

    if (king_orchestrator_get_run_snapshot(run_id, return_value) != SUCCESS) {
        zend_string_release(run_id);
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() could not read back the persisted run snapshot.",
            king_ce_runtime_exception,
            1
        );
    }

    zend_string_release(run_id);
    return SUCCESS;
}

PHP_FUNCTION(king_pipeline_orchestrator_run)
{
    zval *initial_data;
    zval *pipeline;
    zval *exec_options = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(initial_data)
        Z_PARAM_ARRAY(pipeline)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(exec_options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_orchestrator_run(initial_data, pipeline, exec_options, return_value) == SUCCESS) {
        return;
    }

    RETURN_THROWS();
}

PHP_FUNCTION(king_pipeline_orchestrator_dispatch)
{
    zval *initial_data;
    zval *pipeline;
    zval *exec_options = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(initial_data)
        Z_PARAM_ARRAY(pipeline)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(exec_options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_orchestrator_dispatch(initial_data, pipeline, exec_options, return_value) == SUCCESS) {
        return;
    }

    RETURN_THROWS();
}

PHP_FUNCTION(king_pipeline_orchestrator_worker_run_next)
{
    ZEND_PARSE_PARAMETERS_NONE();

    if (king_orchestrator_worker_run_next(return_value) == SUCCESS) {
        return;
    }

    RETURN_THROWS();
}

PHP_FUNCTION(king_pipeline_orchestrator_resume_run)
{
    zend_string *run_id;
    zval run_snapshot;
    zval *status;
    char message[KING_ERR_LEN];

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(run_id)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(run_id) == 0) {
        king_set_error("king_pipeline_orchestrator_resume_run() requires a non-empty run id.");
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_pipeline_orchestrator_resume_run() requires a non-empty run id."
        );
        RETURN_THROWS();
    }

    if (king_orchestrator_backend_is_file_worker()) {
        king_set_error(
            "king_pipeline_orchestrator_resume_run() is unavailable when orchestrator_execution_backend=file_worker; use king_pipeline_orchestrator_worker_run_next()."
        );
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_pipeline_orchestrator_resume_run() is unavailable when orchestrator_execution_backend=file_worker; use king_pipeline_orchestrator_worker_run_next()."
        );
        RETURN_THROWS();
    }

    ZVAL_NULL(&run_snapshot);
    if (king_orchestrator_get_run_snapshot(run_id, &run_snapshot) != SUCCESS) {
        king_set_error("king_pipeline_orchestrator_resume_run() could not read the persisted run snapshot.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_pipeline_orchestrator_resume_run() could not read the persisted run snapshot."
        );
        RETURN_THROWS();
    }

    status = zend_hash_str_find(
        Z_ARRVAL(run_snapshot),
        "status",
        sizeof("status") - 1
    );
    if (status == NULL || Z_TYPE_P(status) != IS_STRING) {
        zval_ptr_dtor(&run_snapshot);
        king_set_error("king_pipeline_orchestrator_resume_run() requires a persisted run snapshot with a valid status.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_pipeline_orchestrator_resume_run() requires a persisted run snapshot with a valid status."
        );
        RETURN_THROWS();
    }

    if (!zend_string_equals_literal(Z_STR_P(status), "running")) {
        snprintf(
            message,
            sizeof(message),
            "king_pipeline_orchestrator_resume_run() can only continue runs in 'running' state; '%s' is terminal or not resumable.",
            Z_STRVAL_P(status)
        );
        zval_ptr_dtor(&run_snapshot);
        king_set_error(message);
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s", message);
        RETURN_THROWS();
    }

    zval_ptr_dtor(&run_snapshot);

    if (king_orchestrator_pipeline_run_note_recovery(run_id, "resume_run") != SUCCESS) {
        king_set_error("king_pipeline_orchestrator_resume_run() failed to persist recovery observability.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_pipeline_orchestrator_resume_run() failed to persist recovery observability."
        );
        RETURN_THROWS();
    }

    if (
        king_orchestrator_resume_run(
            run_id,
            return_value,
            "king_pipeline_orchestrator_resume_run",
            1
        ) == SUCCESS
    ) {
        return;
    }

    RETURN_THROWS();
}

PHP_FUNCTION(king_pipeline_orchestrator_configure_logging)
{
    zval *config;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    if (king_orchestrator_configure_logging(config) == SUCCESS) {
        RETURN_TRUE;
    }

    king_set_error("king_pipeline_orchestrator_configure_logging() failed to persist the logging snapshot.");
    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "king_pipeline_orchestrator_configure_logging() failed to persist the logging snapshot."
    );
    RETURN_THROWS();
}
