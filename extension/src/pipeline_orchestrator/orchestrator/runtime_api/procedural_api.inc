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

PHP_FUNCTION(king_pipeline_orchestrator_run_async)
{
    zval *initial_data;
    zval *pipeline;
    zval *exec_options = NULL;
    zval params[3];
    uint32_t index;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(initial_data)
        Z_PARAM_ARRAY(pipeline)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(exec_options)
    ZEND_PARSE_PARAMETERS_END();

    ZVAL_COPY(&params[0], initial_data);
    ZVAL_COPY(&params[1], pipeline);
    if (exec_options != NULL) {
        ZVAL_COPY(&params[2], exec_options);
    } else {
        ZVAL_NULL(&params[2]);
    }

    if (king_awaitable_create_function_call(
            return_value,
            "king_pipeline_orchestrator_run_async",
            sizeof("king_pipeline_orchestrator_run_async") - 1,
            "king_pipeline_orchestrator_run",
            sizeof("king_pipeline_orchestrator_run") - 1,
            params,
            3,
            NULL
        ) != SUCCESS) {
        for (index = 0; index < 3; index++) {
            zval_ptr_dtor(&params[index]);
        }
        RETURN_FALSE;
    }

    for (index = 0; index < 3; index++) {
        zval_ptr_dtor(&params[index]);
    }
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

PHP_FUNCTION(king_pipeline_orchestrator_dispatch_async)
{
    zval *initial_data;
    zval *pipeline;
    zval *exec_options = NULL;
    zval params[3];
    uint32_t index;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(initial_data)
        Z_PARAM_ARRAY(pipeline)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(exec_options)
    ZEND_PARSE_PARAMETERS_END();

    ZVAL_COPY(&params[0], initial_data);
    ZVAL_COPY(&params[1], pipeline);
    if (exec_options != NULL) {
        ZVAL_COPY(&params[2], exec_options);
    } else {
        ZVAL_NULL(&params[2]);
    }

    if (king_awaitable_create_function_call(
            return_value,
            "king_pipeline_orchestrator_dispatch_async",
            sizeof("king_pipeline_orchestrator_dispatch_async") - 1,
            "king_pipeline_orchestrator_dispatch",
            sizeof("king_pipeline_orchestrator_dispatch") - 1,
            params,
            3,
            NULL
        ) != SUCCESS) {
        for (index = 0; index < 3; index++) {
            zval_ptr_dtor(&params[index]);
        }
        RETURN_FALSE;
    }

    for (index = 0; index < 3; index++) {
        zval_ptr_dtor(&params[index]);
    }
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

    if (
        king_orchestrator_backend_is_remote_peer()
        && king_system_require_admission(
            "king_pipeline_orchestrator_resume_run",
            "remote_peer_resumes"
        ) != SUCCESS
    ) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s",
            king_get_error()
        );
        RETURN_THROWS();
    }

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
