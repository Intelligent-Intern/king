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
    zval telemetry_parent_context;
    king_orchestrator_exec_control_t control;
    king_trace_context_t *boundary_span = NULL;
    zend_bool boundary_seeded = 0;
    int rc;

    if (run_id == NULL) {
        return FAILURE;
    }

    /* Each resumed run must start without stale pre-flush span/log residue. */
    king_telemetry_cleanup_scope_state();

    ZVAL_NULL(&initial_data);
    ZVAL_NULL(&pipeline);
    ZVAL_NULL(&options);
    ZVAL_NULL(&telemetry_parent_context);
    ZVAL_UNDEF(&control.cancel_token);
    control.run_id = NULL;

    if (
        king_orchestrator_load_run_payload(
            run_id,
            &initial_data,
            &pipeline,
            &options,
            &telemetry_parent_context
        ) != SUCCESS
    ) {
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
        zval_ptr_dtor(&telemetry_parent_context);
        king_orchestrator_exec_control_cleanup(&control);
        return FAILURE;
    }

    if (
        king_orchestrator_distributed_tracing_enabled()
        && Z_TYPE(telemetry_parent_context) == IS_ARRAY
        && king_telemetry_set_incoming_parent_context_from_array(&telemetry_parent_context) == SUCCESS
    ) {
        boundary_seeded = 1;
        (void) king_orchestrator_begin_boundary_span(run_id, function_name, &boundary_span);
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

    king_orchestrator_finish_boundary_span(boundary_span, rc);
    if (boundary_seeded) {
        king_telemetry_clear_incoming_parent_context();
    }

    zval_ptr_dtor(&initial_data);
    zval_ptr_dtor(&pipeline);
    zval_ptr_dtor(&options);
    zval_ptr_dtor(&telemetry_parent_context);
    king_orchestrator_exec_control_cleanup(&control);

    return rc;
}

int king_orchestrator_run(zval *initial_data, zval *pipeline_array, zval *options, zval *return_value)
{
    zend_string *run_id;
    zval sanitized_options;
    zval telemetry_parent_context;
    zval *persisted_options;
    king_orchestrator_exec_control_t control;
    zend_bool boundary_seeded = 0;
    int rc = FAILURE;

    ZVAL_UNDEF(&sanitized_options);
    ZVAL_NULL(&telemetry_parent_context);
    ZVAL_UNDEF(&control.cancel_token);
    control.run_id = NULL;

    /*
     * Capture the current caller span before the fresh-run cleanup so resumed
     * work can rehydrate the original parent trace relationship.
     */
    king_orchestrator_capture_distributed_parent_context(&telemetry_parent_context);

    /* A new orchestrator execution starts a fresh telemetry scope. */
    king_telemetry_cleanup_scope_state();

    if (king_orchestrator_backend_is_file_worker()) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() is unavailable when orchestrator_execution_backend=file_worker; use king_pipeline_orchestrator_dispatch().",
            king_ce_runtime_exception,
            1
        );
    }

    if (
        king_orchestrator_backend_is_remote_peer()
        && king_system_require_admission(
            "king_pipeline_orchestrator_run",
            "remote_peer_dispatches"
        ) != SUCCESS
    ) {
        return king_orchestrator_raise_error(
            king_get_error(),
            king_ce_runtime_exception,
            1
        );
    }

    if (
        king_orchestrator_executes_userland_handlers_locally()
        && king_system_require_admission(
            "king_pipeline_orchestrator_run",
            "orchestrator_submissions"
        ) != SUCCESS
    ) {
        return king_orchestrator_raise_error(
            king_get_error(),
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
    run_id = king_orchestrator_pipeline_run_begin(
        initial_data,
        pipeline_array,
        persisted_options,
        &telemetry_parent_context,
        "running"
    );
    if (run_id == NULL) {
        rc = king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed to persist the initial run snapshot.",
            king_ce_runtime_exception,
            1
        );
        goto cleanup;
    }

    if (
        king_orchestrator_distributed_tracing_enabled()
        && Z_TYPE(telemetry_parent_context) == IS_ARRAY
        && king_telemetry_set_incoming_parent_context_from_array(&telemetry_parent_context) == SUCCESS
    ) {
        boundary_seeded = 1;
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
    if (boundary_seeded) {
        king_telemetry_clear_incoming_parent_context();
    }
    zend_string_release(run_id);
cleanup:
    if (!Z_ISUNDEF(sanitized_options)) {
        zval_ptr_dtor(&sanitized_options);
    }
    zval_ptr_dtor(&telemetry_parent_context);
    king_orchestrator_exec_control_cleanup(&control);
    return rc;
}

int king_orchestrator_dispatch(zval *initial_data, zval *pipeline_array, zval *options, zval *return_value)
{
    zend_string *run_id;
    zval sanitized_options;
    zval telemetry_parent_context;
    zval *persisted_options;
    king_orchestrator_exec_control_t control;
    int rc = FAILURE;

    ZVAL_UNDEF(&sanitized_options);
    ZVAL_NULL(&telemetry_parent_context);
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

    king_orchestrator_capture_distributed_parent_context(&telemetry_parent_context);
    persisted_options = king_orchestrator_prepare_persisted_options(options, &sanitized_options);
    run_id = king_orchestrator_pipeline_run_begin(
        initial_data,
        pipeline_array,
        persisted_options,
        &telemetry_parent_context,
        "queued"
    );
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
    zval_ptr_dtor(&telemetry_parent_context);
    king_orchestrator_exec_control_cleanup(&control);
    return rc;
}
