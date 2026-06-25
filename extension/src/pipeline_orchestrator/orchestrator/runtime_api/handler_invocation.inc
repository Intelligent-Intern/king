static zend_bool king_orchestrator_executes_userland_handlers_locally(void)
{
    return !king_orchestrator_backend_is_file_worker() && !king_orchestrator_backend_is_remote_peer();
}

static zend_bool king_orchestrator_handler_boundary_requires_process_registration(
    zval *handler_boundary
)
{
    zval *requires_process_registration;

    if (handler_boundary == NULL || Z_TYPE_P(handler_boundary) != IS_ARRAY) {
        return 0;
    }

    requires_process_registration = zend_hash_str_find(
        Z_ARRVAL_P(handler_boundary),
        "requires_process_registration",
        sizeof("requires_process_registration") - 1
    );

    return requires_process_registration != NULL && zend_is_true(requires_process_registration);
}

static zend_bool king_orchestrator_handler_boundary_step_uses_registered_handler(
    zval *handler_boundary,
    zval *step,
    zend_long step_index
)
{
    zval *required_step_refs;
    zval *tool;
    zval *entry;

    if (
        handler_boundary == NULL
        || Z_TYPE_P(handler_boundary) != IS_ARRAY
        || step == NULL
        || Z_TYPE_P(step) != IS_ARRAY
    ) {
        return 0;
    }

    tool = zend_hash_str_find(Z_ARRVAL_P(step), "tool", sizeof("tool") - 1);
    if (tool == NULL || Z_TYPE_P(tool) != IS_STRING || Z_STRLEN_P(tool) == 0) {
        return 0;
    }

    required_step_refs = zend_hash_str_find(
        Z_ARRVAL_P(handler_boundary),
        "required_step_refs",
        sizeof("required_step_refs") - 1
    );
    if (required_step_refs == NULL || Z_TYPE_P(required_step_refs) != IS_ARRAY) {
        return 0;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(required_step_refs), entry) {
        zval *entry_index;
        zval *tool_name;

        if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }

        entry_index = zend_hash_str_find(Z_ARRVAL_P(entry), "index", sizeof("index") - 1);
        tool_name = zend_hash_str_find(
            Z_ARRVAL_P(entry),
            "tool_name",
            sizeof("tool_name") - 1
        );
        if (
            entry_index == NULL
            || Z_TYPE_P(entry_index) != IS_LONG
            || Z_LVAL_P(entry_index) != step_index
            || tool_name == NULL
            || Z_TYPE_P(tool_name) != IS_STRING
            || !zend_string_equals(Z_STR_P(tool_name), Z_STR_P(tool))
        ) {
            continue;
        }

        return 1;
    } ZEND_HASH_FOREACH_END();

    return 0;
}

static const char *king_orchestrator_failure_retry_disposition_for_category(
    const char *category
)
{
    if (category == NULL || category[0] == '\0') {
        return "caller_managed_retry";
    }

    if (strcmp(category, "validation") == 0) {
        return "non_retryable";
    }

    if (strcmp(category, "cancelled") == 0) {
        return "not_applicable";
    }

    return "caller_managed_retry";
}

static const char *king_orchestrator_exception_message_or_last_error(void)
{
    zval rv;
    zval *message;
    const char *last_error = king_get_error();

    if (EG(exception) == NULL) {
        return last_error;
    }

    message = zend_read_property(
        zend_ce_exception,
        EG(exception),
        "message",
        sizeof("message") - 1,
        1,
        &rv
    );
    if (message == NULL || Z_TYPE_P(message) != IS_STRING || Z_STRLEN_P(message) == 0) {
        return last_error;
    }

    king_set_error(Z_STRVAL_P(message));
    return king_get_error();
}

static void king_orchestrator_classify_handler_throwable_failure(
    const char **category_out,
    const char **retry_disposition_out
)
{
    const char *category = "runtime";

    if (
        EG(exception) != NULL
        && instanceof_function(EG(exception)->ce, king_ce_validation_exception)
    ) {
        category = "validation";
    } else if (
        EG(exception) != NULL
        && instanceof_function(EG(exception)->ce, king_ce_timeout_exception)
    ) {
        category = "timeout";
    }

    if (category_out != NULL) {
        *category_out = category;
    }
    if (retry_disposition_out != NULL) {
        *retry_disposition_out = king_orchestrator_failure_retry_disposition_for_category(category);
    }
}

static zend_result king_orchestrator_prepare_registered_handler_execution(
    zend_string *run_id,
    zval *initial_data,
    zval *handler_boundary,
    zval *current_payload,
    zend_long *persisted_completed_step_count,
    zend_bool *use_registered_tool_handlers,
    zend_bool *use_handler_boundary,
    zend_bool throw_on_error
)
{
    if (
        handler_boundary == NULL
        || current_payload == NULL
        || persisted_completed_step_count == NULL
        || use_registered_tool_handlers == NULL
        || use_handler_boundary == NULL
    ) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() could not prepare registered-handler execution state.",
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    if (king_orchestrator_backend_is_file_worker()) {
        if (run_id == NULL || king_orchestrator_load_run_handler_boundary(run_id, handler_boundary) != SUCCESS) {
            return king_orchestrator_raise_error(
                "king_pipeline_orchestrator_run() could not load the persisted file-worker handler boundary.",
                king_ce_runtime_exception,
                throw_on_error
            );
        }

        if (king_orchestrator_handler_boundary_requires_process_registration(handler_boundary)) {
            *use_registered_tool_handlers = 1;
            *use_handler_boundary = 1;
        }
    }

    if (*use_registered_tool_handlers) {
        if (
            run_id == NULL
            || king_orchestrator_load_run_progress(
                run_id,
                current_payload,
                persisted_completed_step_count
            ) != SUCCESS
        ) {
            return king_orchestrator_raise_error(
                "king_pipeline_orchestrator_run() could not load persisted registered-handler progress.",
                king_ce_runtime_exception,
                throw_on_error
            );
        }

        if (*persisted_completed_step_count <= 0) {
            zval_ptr_dtor(current_payload);
            ZVAL_COPY(current_payload, initial_data);
        }
    }

    return SUCCESS;
}

static zend_result king_orchestrator_invoke_registered_tool_handler(
    zend_string *run_id,
    zval *step,
    uint32_t step_index,
    zval *current_payload,
    zval *step_output,
    const char *execution_backend,
    const char *topology_scope,
    zend_long attempt_number,
    king_orchestrator_exec_control_t *control,
    const char *function_name,
    zend_bool throw_on_error,
    const char **failure_category_out,
    const char **retry_disposition_out
)
{
    zval *tool;
    zval *handler;
    zval context;
    zval context_input;
    zval tool_metadata;
    zval tool_config;
    zval run_metadata;
    zval step_metadata;
    zval step_definition;
    zval cancel;
    zval handler_result;
    zval *result_output;
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;
    char message[KING_ERR_LEN];

    if (failure_category_out != NULL) {
        *failure_category_out = "backend";
    }
    if (retry_disposition_out != NULL) {
        *retry_disposition_out = "caller_managed_retry";
    }

    if (
        step == NULL
        || Z_TYPE_P(step) != IS_ARRAY
        || current_payload == NULL
        || step_output == NULL
    ) {
        if (failure_category_out != NULL) {
            *failure_category_out = "backend";
        }
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() could not prepare the registered userland handler invocation.",
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    ZVAL_NULL(step_output);
    tool = zend_hash_str_find(Z_ARRVAL_P(step), "tool", sizeof("tool") - 1);
    if (tool == NULL || Z_TYPE_P(tool) != IS_STRING || Z_STRLEN_P(tool) == 0) {
        if (failure_category_out != NULL) {
            *failure_category_out = "validation";
        }
        if (retry_disposition_out != NULL) {
            *retry_disposition_out = "non_retryable";
        }
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() step requires a non-empty tool name before registered handler execution.",
            king_ce_validation_exception,
            throw_on_error
        );
    }

    handler = king_orchestrator_lookup_tool_handler(Z_STRVAL_P(tool), Z_STRLEN_P(tool));
    if (handler == NULL) {
        snprintf(
            message,
            sizeof(message),
            "%s() has no registered handler for tool '%s'.",
            function_name != NULL ? function_name : "king_pipeline_orchestrator_run",
            Z_STRVAL_P(tool)
        );
        if (failure_category_out != NULL) {
            *failure_category_out = "missing_handler";
        }
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    ZVAL_NULL(&tool_config);
    if (
        king_orchestrator_load_tool_config(
            Z_STRVAL_P(tool),
            Z_STRLEN_P(tool),
            &tool_config
        ) != SUCCESS
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s() could not load the persisted tool config for registered handler '%s'.",
            function_name != NULL ? function_name : "king_pipeline_orchestrator_run",
            Z_STRVAL_P(tool)
        );
        if (failure_category_out != NULL) {
            *failure_category_out = "backend";
        }
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    if (zend_fcall_info_init(handler, 0, &fci, &fcc, NULL, NULL) != SUCCESS) {
        zval_ptr_dtor(&tool_config);
        snprintf(
            message,
            sizeof(message),
            "%s() found an invalid registered handler for tool '%s'.",
            function_name != NULL ? function_name : "king_pipeline_orchestrator_run",
            Z_STRVAL_P(tool)
        );
        if (failure_category_out != NULL) {
            *failure_category_out = "missing_handler";
        }
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    array_init(&context);
    ZVAL_COPY(&context_input, current_payload);
    add_assoc_zval(&context, "input", &context_input);

    array_init(&tool_metadata);
    add_assoc_stringl(&tool_metadata, "name", Z_STRVAL_P(tool), Z_STRLEN_P(tool));
    add_assoc_zval(&tool_metadata, "config", &tool_config);
    add_assoc_zval(&context, "tool", &tool_metadata);

    array_init(&run_metadata);
    if (run_id != NULL) {
        add_assoc_stringl(&context, "run_id", ZSTR_VAL(run_id), ZSTR_LEN(run_id));
        add_assoc_stringl(&run_metadata, "run_id", ZSTR_VAL(run_id), ZSTR_LEN(run_id));
    } else {
        add_assoc_null(&context, "run_id");
        add_assoc_null(&run_metadata, "run_id");
    }
    add_assoc_long(&run_metadata, "attempt_number", attempt_number > 0 ? attempt_number : 1);
    add_assoc_string(
        &run_metadata,
        "execution_backend",
        (char *) (execution_backend != NULL ? execution_backend : "local")
    );
    add_assoc_string(
        &run_metadata,
        "topology_scope",
        (char *) (topology_scope != NULL ? topology_scope : "local_in_process")
    );
    add_assoc_zval(&context, "run", &run_metadata);

    array_init(&step_metadata);
    add_assoc_long(&step_metadata, "index", (zend_long) step_index);
    add_assoc_stringl(&step_metadata, "tool_name", Z_STRVAL_P(tool), Z_STRLEN_P(tool));
    ZVAL_COPY(&step_definition, step);
    add_assoc_zval(&step_metadata, "definition", &step_definition);
    add_assoc_zval(&context, "step", &step_metadata);

    if (control != NULL && !Z_ISUNDEF(control->cancel_token)) {
        ZVAL_COPY(&cancel, &control->cancel_token);
    } else {
        ZVAL_NULL(&cancel);
    }
    add_assoc_zval(&context, "cancel", &cancel);

    add_assoc_long(
        &context,
        "timeout_budget_ms",
        king_orchestrator_control_timeout_budget_ms(control)
    );
    add_assoc_long(
        &context,
        "deadline_budget_ms",
        king_orchestrator_control_deadline_budget_ms(control)
    );

    ZVAL_NULL(&handler_result);
    fci.retval = &handler_result;
    fci.param_count = 1;
    fci.params = &context;

    if (zend_call_function(&fci, &fcc) != SUCCESS) {
        zval_ptr_dtor(&context);
        if (EG(exception) == NULL) {
            snprintf(
                message,
                sizeof(message),
                    "%s() failed while invoking the registered handler for tool '%s'.",
                    function_name != NULL ? function_name : "king_pipeline_orchestrator_run",
                    Z_STRVAL_P(tool)
                );
            if (failure_category_out != NULL) {
                *failure_category_out = "runtime";
            }
            return king_orchestrator_raise_error(
                message,
                king_ce_runtime_exception,
                throw_on_error
            );
        }
        king_orchestrator_classify_handler_throwable_failure(
            failure_category_out,
            retry_disposition_out
        );
        (void) king_orchestrator_exception_message_or_last_error();
        return FAILURE;
    }

    zval_ptr_dtor(&context);
    if (EG(exception) != NULL) {
        zval_ptr_dtor(&handler_result);
        king_orchestrator_classify_handler_throwable_failure(
            failure_category_out,
            retry_disposition_out
        );
        (void) king_orchestrator_exception_message_or_last_error();
        return FAILURE;
    }

    if (Z_TYPE(handler_result) != IS_ARRAY) {
        zval_ptr_dtor(&handler_result);
        snprintf(
            message,
            sizeof(message),
            "%s() registered handler for tool '%s' must return an array result contract.",
            function_name != NULL ? function_name : "king_pipeline_orchestrator_run",
            Z_STRVAL_P(tool)
        );
        if (failure_category_out != NULL) {
            *failure_category_out = "runtime";
        }
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    result_output = zend_hash_str_find(
        Z_ARRVAL(handler_result),
        "output",
        sizeof("output") - 1
    );
    if (result_output == NULL || Z_TYPE_P(result_output) != IS_ARRAY) {
        zval_ptr_dtor(&handler_result);
        snprintf(
            message,
            sizeof(message),
            "%s() registered handler for tool '%s' must return an array result contract with key 'output'.",
            function_name != NULL ? function_name : "king_pipeline_orchestrator_run",
            Z_STRVAL_P(tool)
        );
        if (failure_category_out != NULL) {
            *failure_category_out = "runtime";
        }
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    ZVAL_COPY(step_output, result_output);
    zval_ptr_dtor(&handler_result);

    return SUCCESS;
}
