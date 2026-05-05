static zval *king_orchestrator_prepare_persisted_options(zval *options, zval *sanitized_options)
{
    if (sanitized_options == NULL) {
        return options;
    }

    ZVAL_NULL(sanitized_options);
    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return options;
    }

    ZVAL_COPY(sanitized_options, options);
    SEPARATE_ARRAY(sanitized_options);
    zend_hash_str_del(
        Z_ARRVAL_P(sanitized_options),
        "cancel",
        sizeof("cancel") - 1
    );

    return sanitized_options;
}

static zend_result king_orchestrator_validate_non_negative_step_delay(
    zval *value,
    uint32_t index,
    zend_bool throw_on_error)
{
    char message[KING_ERR_LEN];

    if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
        return SUCCESS;
    }

    if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 0) {
        snprintf(
            message,
            sizeof(message),
            "king_pipeline_orchestrator_run() step %u option 'delay_ms' must be provided as an integer >= 0.",
            (unsigned) index
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_validation_exception,
            throw_on_error
        );
    }

    return SUCCESS;
}

static zend_result king_orchestrator_execute_step_delay(
    zval *step,
    uint32_t index,
    king_orchestrator_exec_control_t *control,
    const char *function_name,
    zend_bool throw_on_error,
    zend_bool *cancelled_out)
{
    zval *delay_value;
    zend_long delay_ms;
    uint64_t started_at_ms;

    if (step == NULL || Z_TYPE_P(step) != IS_ARRAY) {
        return SUCCESS;
    }

    delay_value = zend_hash_str_find(Z_ARRVAL_P(step), "delay_ms", sizeof("delay_ms") - 1);
    if (king_orchestrator_validate_non_negative_step_delay(delay_value, index, throw_on_error) != SUCCESS) {
        return FAILURE;
    }
    if (delay_value == NULL || Z_TYPE_P(delay_value) == IS_NULL || Z_LVAL_P(delay_value) == 0) {
        return SUCCESS;
    }

    delay_ms = Z_LVAL_P(delay_value);
    started_at_ms = king_orchestrator_monotonic_time_ms();

    while (king_orchestrator_monotonic_time_ms() - started_at_ms < (uint64_t) delay_ms) {
        uint64_t elapsed_ms = king_orchestrator_monotonic_time_ms() - started_at_ms;
        uint64_t remaining_ms = (uint64_t) delay_ms > elapsed_ms
            ? (uint64_t) delay_ms - elapsed_ms
            : 0;
        useconds_t sleep_chunk = (useconds_t) ((remaining_ms > 10 ? 10 : remaining_ms) * 1000U);

        if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, cancelled_out) != SUCCESS) {
            return FAILURE;
        }
        if (sleep_chunk == 0) {
            break;
        }
        usleep(sleep_chunk);
    }

    return king_orchestrator_exec_control_check(control, function_name, throw_on_error, cancelled_out);
}

static zend_result king_orchestrator_validate_pipeline_step(
    zval *step,
    uint32_t index,
    zend_bool throw_on_error,
    zend_long *failed_step_index_out)
{
    zval *tool;
    char message[256];

    if (Z_TYPE_P(step) != IS_ARRAY) {
        return SUCCESS;
    }

    if (failed_step_index_out != NULL) {
        *failed_step_index_out = (zend_long) index;
    }

    tool = zend_hash_str_find(Z_ARRVAL_P(step), "tool", sizeof("tool") - 1);
    if (tool == NULL || Z_TYPE_P(tool) != IS_STRING || Z_STRLEN_P(tool) == 0) {
        snprintf(
            message,
            sizeof(message),
            "king_pipeline_orchestrator_run() step %u requires a non-empty tool name.",
            (unsigned) index
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_validation_exception,
            throw_on_error
        );
    }

    if (king_orchestrator_lookup_tool(Z_STRVAL_P(tool), Z_STRLEN_P(tool)) == NULL) {
        snprintf(
            message,
            sizeof(message),
            "king_pipeline_orchestrator_run() references unknown tool '%s'.",
            Z_STRVAL_P(tool)
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    return king_orchestrator_validate_non_negative_step_delay(
        zend_hash_str_find(Z_ARRVAL_P(step), "delay_ms", sizeof("delay_ms") - 1),
        index,
        throw_on_error
    );
}

static zend_result king_orchestrator_validate_pipeline_definition(
    zval *pipeline_array,
    const char *function_name,
    zend_bool throw_on_error,
    zend_long *failed_step_index_out)
{
    HashTable *ht;
    zval *step;
    uint32_t index = 0;

    if (pipeline_array == NULL || Z_TYPE_P(pipeline_array) != IS_ARRAY) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() requires an array pipeline definition.",
            king_ce_validation_exception,
            throw_on_error
        );
    }

    ht = Z_ARRVAL_P(pipeline_array);
    ZEND_HASH_FOREACH_VAL(ht, step) {
        if (king_orchestrator_validate_pipeline_step(step, index, throw_on_error, failed_step_index_out) != SUCCESS) {
            return FAILURE;
        }
        index++;
    } ZEND_HASH_FOREACH_END();

    (void) function_name;
    return SUCCESS;
}

static zend_bool king_orchestrator_remote_handler_boundary_requires_process_registration(
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

static zend_result king_orchestrator_remote_build_tool_config_map(
    zval *handler_boundary,
    zval *return_value,
    const char *function_name
)
{
    zval *required_tools;
    zval *tool_name;
    char message[KING_ERR_LEN];

    if (return_value == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(return_value);
    if (!king_orchestrator_remote_handler_boundary_requires_process_registration(handler_boundary)) {
        return SUCCESS;
    }

    required_tools = zend_hash_str_find(
        Z_ARRVAL_P(handler_boundary),
        "required_tools",
        sizeof("required_tools") - 1
    );
    if (required_tools == NULL || Z_TYPE_P(required_tools) != IS_ARRAY) {
        snprintf(
            message,
            sizeof(message),
            "%s() could not load the durable remote-peer handler boundary tool list.",
            function_name != NULL ? function_name : "king_pipeline_orchestrator_run"
        );
        king_set_error(message);
        return FAILURE;
    }

    array_init(return_value);
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(required_tools), tool_name) {
        zval tool_config;

        if (tool_name == NULL || Z_TYPE_P(tool_name) != IS_STRING || Z_STRLEN_P(tool_name) == 0) {
            zval_ptr_dtor(return_value);
            ZVAL_NULL(return_value);
            snprintf(
                message,
                sizeof(message),
                "%s() encountered an invalid remote-peer handler tool name in the durable boundary snapshot.",
                function_name != NULL ? function_name : "king_pipeline_orchestrator_run"
            );
            king_set_error(message);
            return FAILURE;
        }

        ZVAL_NULL(&tool_config);
        if (
            king_orchestrator_load_tool_config(
                Z_STRVAL_P(tool_name),
                Z_STRLEN_P(tool_name),
                &tool_config
            ) != SUCCESS
        ) {
            zval_ptr_dtor(return_value);
            ZVAL_NULL(return_value);
            snprintf(
                message,
                sizeof(message),
                "%s() could not load the persisted tool config for remote-peer handler '%s'.",
                function_name != NULL ? function_name : "king_pipeline_orchestrator_run",
                Z_STRVAL_P(tool_name)
            );
            king_set_error(message);
            return FAILURE;
        }

        add_assoc_zval_ex(
            return_value,
            Z_STRVAL_P(tool_name),
            Z_STRLEN_P(tool_name),
            &tool_config
        );
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static zend_result king_orchestrator_remote_load_handler_contract(
    zend_string *run_id,
    zval *handler_boundary_out,
    zval *tool_configs_out,
    const char *function_name
)
{
    char message[KING_ERR_LEN];

    if (handler_boundary_out == NULL || tool_configs_out == NULL || run_id == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(handler_boundary_out);
    ZVAL_NULL(tool_configs_out);

    if (king_orchestrator_load_run_handler_boundary(run_id, handler_boundary_out) != SUCCESS) {
        snprintf(
            message,
            sizeof(message),
            "%s() could not load the persisted remote-peer handler boundary.",
            function_name != NULL ? function_name : "king_pipeline_orchestrator_run"
        );
        king_set_error(message);
        return FAILURE;
    }

    if (
        king_orchestrator_remote_build_tool_config_map(
            handler_boundary_out,
            tool_configs_out,
            function_name
        ) != SUCCESS
    ) {
        zval_ptr_dtor(handler_boundary_out);
        ZVAL_NULL(handler_boundary_out);
        return FAILURE;
    }

    return SUCCESS;
}

static int king_orchestrator_execute_remote_run(
    zend_string *run_id,
    zval *initial_data,
    zval *pipeline_array,
    zval *options,
    zval *return_value,
    king_orchestrator_exec_control_t *control,
    const char *function_name,
    zend_bool throw_on_error)
{
    php_stream *stream = NULL;
    zend_string *target = NULL;
    zend_string *encoded_run_id = NULL;
    zend_string *encoded_initial = NULL;
    zend_string *encoded_pipeline = NULL;
    zend_string *encoded_options = NULL;
    zend_string *encoded_handler_boundary = NULL;
    zend_string *encoded_tool_configs = NULL;
    smart_str command = {0};
    zend_long timeout_budget_ms;
    zend_long deadline_budget_ms;
    struct timeval timeout;
    zend_string *transport_error = NULL;
    int transport_error_code = 0;
    zend_bool cancelled = 0;
    const char *error_message;
    zend_long failed_step_index = -1;
    king_orchestrator_error_meta_t remote_error_meta;
    zval handler_boundary;
    zval remote_tool_configs;

    king_orchestrator_error_meta_init(&remote_error_meta);
    ZVAL_NULL(&handler_boundary);
    ZVAL_NULL(&remote_tool_configs);

    if (control != NULL) {
        control->run_id = run_id;
    }

    if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
        king_orchestrator_mark_interrupted_run(run_id, cancelled, -1, "remote_peer");
        ZVAL_FALSE(return_value);
        return FAILURE;
    }

    if (king_orchestrator_validate_pipeline_definition(
            pipeline_array,
            function_name,
            throw_on_error,
            &failed_step_index
        ) != SUCCESS) {
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

    if (
        king_orchestrator_remote_load_handler_contract(
            run_id,
            &handler_boundary,
            &remote_tool_configs,
            function_name
        ) != SUCCESS
    ) {
        ZVAL_FALSE(return_value);
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

    if (king_orchestrator_pipeline_run_note_remote_attempt(run_id) != SUCCESS) {
        zval_ptr_dtor(&handler_boundary);
        zval_ptr_dtor(&remote_tool_configs);
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            "king_pipeline_orchestrator_run() failed to persist remote-attempt observability.",
            "backend",
            "caller_managed_retry",
            -1,
            "controller"
        );
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed to persist remote-attempt observability.",
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    target = king_orchestrator_remote_build_target();
    if (target == NULL) {
        zval_ptr_dtor(&handler_boundary);
        zval_ptr_dtor(&remote_tool_configs);
        error_message = "king_pipeline_orchestrator_run() requires a non-empty orchestrator_remote_host when orchestrator_execution_backend=remote_peer.";
        king_set_error(error_message);
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            error_message,
            "backend",
            "caller_managed_retry",
            -1,
            "remote_peer"
        );
        return king_orchestrator_raise_error(
            error_message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    timeout_budget_ms = king_orchestrator_control_effective_budget_ms(control);
    if (timeout_budget_ms <= 0) {
        zend_string_release(target);
        zval_ptr_dtor(&handler_boundary);
        zval_ptr_dtor(&remote_tool_configs);
        ZVAL_FALSE(return_value);
        king_set_error("king_pipeline_orchestrator_run() exceeded the active orchestrator timeout budget.");
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            king_get_error(),
            "timeout",
            "caller_managed_retry",
            -1,
            "remote_peer"
        );
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() exceeded the active orchestrator timeout budget.",
            king_ce_timeout_exception,
            throw_on_error
        );
    }

    timeout.tv_sec = timeout_budget_ms / 1000;
    timeout.tv_usec = (timeout_budget_ms % 1000) * 1000;

    stream = php_stream_xport_create(
        ZSTR_VAL(target),
        ZSTR_LEN(target),
        0,
        STREAM_XPORT_CLIENT | STREAM_XPORT_CONNECT,
        NULL,
        &timeout,
        NULL,
        &transport_error,
        &transport_error_code
    );
    zend_string_release(target);
    target = NULL;

    if (stream == NULL) {
        zval_ptr_dtor(&handler_boundary);
        zval_ptr_dtor(&remote_tool_configs);
        if (transport_error != NULL) {
            king_set_error(ZSTR_VAL(transport_error));
            zend_string_release(transport_error);
        } else {
            char message[KING_ERR_LEN];

            snprintf(
                message,
                sizeof(message),
                "king_pipeline_orchestrator_run() failed to connect to the remote execution peer (code %d).",
                transport_error_code
            );
            king_set_error(message);
        }
        error_message = king_get_error();
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            error_message,
            "remote_transport",
            "caller_managed_retry",
            -1,
            "remote_peer"
        );
        return king_orchestrator_raise_error(
            error_message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    if (king_orchestrator_remote_prepare_stream(stream, timeout_budget_ms) != SUCCESS) {
        php_stream_close(stream);
        zval_ptr_dtor(&handler_boundary);
        zval_ptr_dtor(&remote_tool_configs);
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            "king_pipeline_orchestrator_run() could not configure the remote execution peer stream.",
            "remote_transport",
            "caller_managed_retry",
            -1,
            "remote_peer"
        );
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() could not configure the remote execution peer stream.",
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    encoded_run_id = php_base64_encode(
        (const unsigned char *) ZSTR_VAL(run_id),
        ZSTR_LEN(run_id)
    );
    encoded_initial = king_orchestrator_remote_encode_zval_base64(initial_data);
    encoded_pipeline = king_orchestrator_remote_encode_zval_base64(pipeline_array);
    encoded_options = king_orchestrator_remote_encode_zval_base64(options);
    encoded_handler_boundary = king_orchestrator_remote_encode_zval_base64(&handler_boundary);
    encoded_tool_configs = king_orchestrator_remote_encode_zval_base64(&remote_tool_configs);
    if (
        encoded_run_id == NULL
        || encoded_initial == NULL
        || encoded_pipeline == NULL
        || encoded_options == NULL
        || encoded_handler_boundary == NULL
        || encoded_tool_configs == NULL
    ) {
        php_stream_close(stream);
        if (encoded_run_id != NULL) {
            zend_string_release(encoded_run_id);
        }
        if (encoded_initial != NULL) {
            zend_string_release(encoded_initial);
        }
        if (encoded_pipeline != NULL) {
            zend_string_release(encoded_pipeline);
        }
        if (encoded_options != NULL) {
            zend_string_release(encoded_options);
        }
        if (encoded_handler_boundary != NULL) {
            zend_string_release(encoded_handler_boundary);
        }
        if (encoded_tool_configs != NULL) {
            zend_string_release(encoded_tool_configs);
        }
        zval_ptr_dtor(&handler_boundary);
        zval_ptr_dtor(&remote_tool_configs);
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            "king_pipeline_orchestrator_run() failed while encoding the remote execution payload.",
            "backend",
            "caller_managed_retry",
            -1,
            "controller"
        );
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed while encoding the remote execution payload.",
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    deadline_budget_ms = king_orchestrator_control_deadline_budget_ms(control);
    smart_str_appends(&command, "RUN\t");
    smart_str_append(&command, encoded_run_id);
    smart_str_appendc(&command, '\t');
    smart_str_append(&command, encoded_initial);
    smart_str_appendc(&command, '\t');
    smart_str_append(&command, encoded_pipeline);
    smart_str_appendc(&command, '\t');
    smart_str_append(&command, encoded_options);
    smart_str_appendc(&command, '\t');
    smart_str_append(&command, encoded_handler_boundary);
    smart_str_appendc(&command, '\t');
    smart_str_append(&command, encoded_tool_configs);
    smart_str_append_printf(&command, "\t" ZEND_LONG_FMT "\t" ZEND_LONG_FMT "\n", timeout_budget_ms, deadline_budget_ms);
    smart_str_0(&command);

    zend_string_release(encoded_run_id);
    zend_string_release(encoded_initial);
    zend_string_release(encoded_pipeline);
    zend_string_release(encoded_options);
    zend_string_release(encoded_handler_boundary);
    zend_string_release(encoded_tool_configs);
    zval_ptr_dtor(&handler_boundary);
    zval_ptr_dtor(&remote_tool_configs);

    if (command.s == NULL) {
        php_stream_close(stream);
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            "king_pipeline_orchestrator_run() failed while materializing the remote execution payload.",
            "backend",
            "caller_managed_retry",
            -1,
            "controller"
        );
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed while materializing the remote execution payload.",
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
        smart_str_free(&command);
        php_stream_close(stream);
        ZVAL_FALSE(return_value);
        king_orchestrator_mark_interrupted_run(run_id, cancelled, -1, "remote_peer");
        return FAILURE;
    }

    if (king_orchestrator_remote_write_all(stream, ZSTR_VAL(command.s), ZSTR_LEN(command.s), function_name) != SUCCESS) {
        smart_str_free(&command);
        php_stream_close(stream);
        error_message = king_get_error();
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            error_message,
            "remote_transport",
            "caller_managed_retry",
            -1,
            "remote_peer"
        );
        return king_orchestrator_raise_error(
            error_message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }
    smart_str_free(&command);

    if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
        php_stream_close(stream);
        ZVAL_FALSE(return_value);
        king_orchestrator_mark_interrupted_run(run_id, cancelled, -1, "remote_peer");
        return FAILURE;
    }

    if (king_orchestrator_remote_expect_result(stream, function_name, return_value, &remote_error_meta) != SUCCESS) {
        php_stream_close(stream);
        error_message = king_get_error();
        if (error_message == NULL || error_message[0] == '\0') {
            error_message = "king_pipeline_orchestrator_run() failed while waiting for the remote execution result.";
            king_set_error(error_message);
        }
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            error_message,
            remote_error_meta.has_classification ? remote_error_meta.category : "remote_transport",
            remote_error_meta.has_classification ? remote_error_meta.retry_disposition : "caller_managed_retry",
            remote_error_meta.has_classification ? remote_error_meta.step_index : -1,
            remote_error_meta.has_classification && remote_error_meta.backend[0] != '\0'
                ? remote_error_meta.backend
                : "remote_peer"
        );
        return king_orchestrator_raise_error(
            error_message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    php_stream_close(stream);

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
