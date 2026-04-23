static zend_bool king_orchestrator_distributed_tracing_enabled(void)
{
    return king_mcp_orchestrator_config.orchestrator_enable_distributed_tracing ? 1 : 0;
}

static const char *king_orchestrator_boundary_backend_label(void)
{
    if (
        king_mcp_orchestrator_config.orchestrator_execution_backend == NULL
        || king_mcp_orchestrator_config.orchestrator_execution_backend[0] == '\0'
    ) {
        return "local";
    }

    return king_mcp_orchestrator_config.orchestrator_execution_backend;
}

static void king_orchestrator_capture_distributed_parent_context(zval *destination)
{
    if (destination == NULL) {
        return;
    }

    ZVAL_NULL(destination);
    if (!king_orchestrator_distributed_tracing_enabled()) {
        return;
    }

    if (!king_telemetry_build_distributed_parent_context(destination)) {
        ZVAL_NULL(destination);
    }
}

static const char *king_orchestrator_boundary_origin_label(const char *function_name)
{
    if (
        function_name != NULL
        && strcmp(function_name, "king_pipeline_orchestrator_worker_run_next") == 0
    ) {
        return "file_worker";
    }

    return "process_resume";
}

static zend_result king_orchestrator_begin_boundary_span(
    zend_string *run_id,
    const char *function_name,
    king_trace_context_t **span_out
)
{
    zval attributes;
    zend_result rc;

    if (span_out == NULL) {
        return FAILURE;
    }

    *span_out = NULL;
    if (!king_telemetry_has_incoming_parent_context()) {
        return SUCCESS;
    }

    array_init(&attributes);
    add_assoc_string(&attributes, "component", "pipeline_orchestrator");
    add_assoc_string(
        &attributes,
        "boundary_origin",
        (char *) king_orchestrator_boundary_origin_label(function_name)
    );
    add_assoc_string(
        &attributes,
        "execution_backend",
        (char *) king_orchestrator_boundary_backend_label()
    );
    add_assoc_string(&attributes, "hierarchy_source", "distributed_parent_context");
    if (run_id != NULL) {
        add_assoc_stringl(&attributes, "run_id", ZSTR_VAL(run_id), ZSTR_LEN(run_id));
    }

    rc = king_telemetry_start_internal_span(
        span_out,
        "pipeline-orchestrator-boundary",
        KING_SPAN_KIND_CONSUMER,
        &attributes,
        NULL
    );
    zval_ptr_dtor(&attributes);

    return rc;
}

static void king_orchestrator_finish_boundary_span(king_trace_context_t *span, int rc)
{
    zval final_attributes;

    if (span == NULL) {
        return;
    }

    array_init(&final_attributes);
    add_assoc_string(&final_attributes, "outcome", rc == SUCCESS ? "completed" : "failed");
    (void) king_telemetry_end_internal_span(span, &final_attributes);
    zval_ptr_dtor(&final_attributes);
}

static const char *king_orchestrator_runtime_topology_scope(void)
{
    if (king_orchestrator_backend_is_file_worker()) {
        return "same_host_file_worker";
    }

    if (king_orchestrator_backend_is_remote_peer()) {
        return "tcp_host_port_execution_peer";
    }

    return "local_in_process";
}

static zval *king_orchestrator_find_step_telemetry_id(
    zval *step,
    const char *field_name,
    size_t field_name_len
)
{
    zval *value;

    if (step == NULL || Z_TYPE_P(step) != IS_ARRAY) {
        return NULL;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(step), field_name, field_name_len);
    if (value == NULL || Z_TYPE_P(value) != IS_STRING || Z_STRLEN_P(value) == 0) {
        return NULL;
    }

    return value;
}

static zend_long king_orchestrator_run_attempt_number(zend_string *run_id)
{
    zval snapshot;
    zval *observability;
    zval *recovery_count;
    zend_long attempt_number = 1;

    if (run_id == NULL) {
        return 1;
    }

    ZVAL_NULL(&snapshot);
    if (king_orchestrator_get_run_snapshot(run_id, &snapshot) != SUCCESS) {
        return 1;
    }

    observability = zend_hash_str_find(
        Z_ARRVAL(snapshot),
        "distributed_observability",
        sizeof("distributed_observability") - 1
    );
    if (observability != NULL && Z_TYPE_P(observability) == IS_ARRAY) {
        recovery_count = zend_hash_str_find(
            Z_ARRVAL_P(observability),
            "recovery_count",
            sizeof("recovery_count") - 1
        );
        if (recovery_count != NULL && Z_TYPE_P(recovery_count) == IS_LONG && Z_LVAL_P(recovery_count) >= 0) {
            attempt_number = Z_LVAL_P(recovery_count) + 1;
        }
    }

    zval_ptr_dtor(&snapshot);
    return attempt_number > 0 ? attempt_number : 1;
}

static zend_string *king_orchestrator_build_attempt_identity(zend_string *run_id)
{
    return strpprintf(
        0,
        "%s:attempt-%ld",
        run_id != NULL ? ZSTR_VAL(run_id) : "run-unknown",
        king_orchestrator_run_attempt_number(run_id)
    );
}

static zend_string *king_orchestrator_build_failure_identity(
    zend_string *run_id,
    zend_string *attempt_identity,
    const char *category,
    zend_long step_index,
    const char *final_status
)
{
    return strpprintf(
        0,
        "%s:%s:%s:%ld",
        attempt_identity != NULL
            ? ZSTR_VAL(attempt_identity)
            : (run_id != NULL ? ZSTR_VAL(run_id) : "run-unknown"),
        final_status != NULL && final_status[0] != '\0' ? final_status : "failed",
        category != NULL && category[0] != '\0' ? category : "backend",
        step_index
    );
}

static void king_orchestrator_record_pipeline_counter(
    const char *metric_name,
    const char *execution_backend,
    const char *topology_scope,
    const char *outcome,
    const char *category,
    const char *retry_disposition
)
{
    zval labels;

    if (!king_telemetry_is_capture_enabled() || metric_name == NULL || metric_name[0] == '\0') {
        return;
    }

    array_init(&labels);
    add_assoc_string(&labels, "component", "pipeline_orchestrator");
    add_assoc_string(&labels, "execution_backend", (char *) execution_backend);
    add_assoc_string(&labels, "topology_scope", (char *) topology_scope);
    if (outcome != NULL && outcome[0] != '\0') {
        add_assoc_string(&labels, "outcome", (char *) outcome);
    }
    if (category != NULL && category[0] != '\0') {
        add_assoc_string(&labels, "category", (char *) category);
    }
    if (retry_disposition != NULL && retry_disposition[0] != '\0') {
        add_assoc_string(&labels, "retry_disposition", (char *) retry_disposition);
    }
    (void) king_telemetry_record_metric_internal(metric_name, KING_METRIC_TYPE_COUNTER, 1.0, &labels);
    zval_ptr_dtor(&labels);
}

static void king_orchestrator_emit_pipeline_failure_log(
    zend_string *run_id,
    zend_string *attempt_identity,
    zval *step,
    zend_long step_index,
    const char *execution_backend,
    const char *category,
    const char *retry_disposition,
    const char *message,
    const char *final_status
)
{
    zval attributes;
    zval *partition_id;
    zval *batch_id;
    zend_string *failure_identity;

    if (!king_telemetry_is_capture_enabled()) {
        return;
    }

    partition_id = king_orchestrator_find_step_telemetry_id(step, "partition_id", sizeof("partition_id") - 1);
    batch_id = king_orchestrator_find_step_telemetry_id(step, "batch_id", sizeof("batch_id") - 1);
    failure_identity = king_orchestrator_build_failure_identity(
        run_id,
        attempt_identity,
        category,
        step_index,
        final_status
    );

    array_init(&attributes);
    add_assoc_string(&attributes, "component", "pipeline_orchestrator");
    add_assoc_stringl(&attributes, "run_id", ZSTR_VAL(run_id), ZSTR_LEN(run_id));
    if (attempt_identity != NULL) {
        add_assoc_stringl(
            &attributes,
            "attempt_identity",
            ZSTR_VAL(attempt_identity),
            ZSTR_LEN(attempt_identity)
        );
    } else {
        add_assoc_null(&attributes, "attempt_identity");
    }
    add_assoc_long(&attributes, "step_index", step_index);
    add_assoc_string(&attributes, "execution_backend", (char *) execution_backend);
    add_assoc_string(&attributes, "topology_scope", (char *) king_orchestrator_runtime_topology_scope());
    add_assoc_string(&attributes, "failure_status", (char *) final_status);
    add_assoc_string(&attributes, "failure_category", (char *) category);
    add_assoc_string(&attributes, "retry_disposition", (char *) retry_disposition);
    add_assoc_str(&attributes, "failure_identity", failure_identity);
    if (partition_id != NULL) {
        add_assoc_stringl(&attributes, "partition_id", Z_STRVAL_P(partition_id), Z_STRLEN_P(partition_id));
    }
    if (batch_id != NULL) {
        add_assoc_stringl(&attributes, "batch_id", Z_STRVAL_P(batch_id), Z_STRLEN_P(batch_id));
    }

    (void) king_telemetry_log_internal(
        strcmp(final_status, "cancelled") == 0 ? KING_TELEMETRY_LEVEL_WARN : KING_TELEMETRY_LEVEL_ERROR,
        "pipeline_orchestrator",
        message != NULL && message[0] != '\0' ? message : "pipeline run failed",
        &attributes
    );
    zval_ptr_dtor(&attributes);
}

static void king_orchestrator_append_pipeline_span_attributes(
    zval *attributes,
    zend_string *run_id,
    zend_string *attempt_identity,
    zval *step,
    zend_long step_index
)
{
    zval *partition_id;
    zval *batch_id;
    zval *tool;

    if (attributes == NULL) {
        return;
    }

    add_assoc_string(attributes, "component", "pipeline_orchestrator");
    add_assoc_string(attributes, "telemetry_adapter_contract", "run_partition_batch_retry_failure_identity");
    add_assoc_stringl(attributes, "run_id", ZSTR_VAL(run_id), ZSTR_LEN(run_id));
    if (attempt_identity != NULL) {
        add_assoc_stringl(
            attributes,
            "attempt_identity",
            ZSTR_VAL(attempt_identity),
            ZSTR_LEN(attempt_identity)
        );
    } else {
        add_assoc_null(attributes, "attempt_identity");
    }
    add_assoc_string(attributes, "execution_backend", (char *) king_orchestrator_boundary_backend_label());
    add_assoc_string(attributes, "topology_scope", (char *) king_orchestrator_runtime_topology_scope());

    if (step_index >= 0) {
        add_assoc_long(attributes, "step_index", step_index);
    }

    if (step != NULL && Z_TYPE_P(step) == IS_ARRAY) {
        tool = zend_hash_str_find(Z_ARRVAL_P(step), "tool", sizeof("tool") - 1);
        if (tool != NULL && Z_TYPE_P(tool) == IS_STRING && Z_STRLEN_P(tool) > 0) {
            add_assoc_stringl(attributes, "step_tool", Z_STRVAL_P(tool), Z_STRLEN_P(tool));
        }
    }

    partition_id = king_orchestrator_find_step_telemetry_id(step, "partition_id", sizeof("partition_id") - 1);
    if (partition_id != NULL) {
        add_assoc_stringl(attributes, "partition_id", Z_STRVAL_P(partition_id), Z_STRLEN_P(partition_id));
    }

    batch_id = king_orchestrator_find_step_telemetry_id(step, "batch_id", sizeof("batch_id") - 1);
    if (batch_id != NULL) {
        add_assoc_stringl(attributes, "batch_id", Z_STRVAL_P(batch_id), Z_STRLEN_P(batch_id));
    }
}

static void king_orchestrator_finish_pipeline_span(
    king_trace_context_t *span,
    const char *outcome,
    const char *category,
    const char *retry_disposition,
    zend_string *failure_identity
)
{
    zval final_attributes;

    if (span == NULL) {
        return;
    }

    array_init(&final_attributes);
    add_assoc_string(
        &final_attributes,
        "outcome",
        (char *) (
            outcome != NULL && outcome[0] != '\0'
                ? outcome
                : "completed"
        )
    );
    if (category != NULL && category[0] != '\0') {
        add_assoc_string(&final_attributes, "failure_category", (char *) category);
    }
    if (retry_disposition != NULL && retry_disposition[0] != '\0') {
        add_assoc_string(&final_attributes, "retry_disposition", (char *) retry_disposition);
    }
    if (failure_identity != NULL) {
        add_assoc_stringl(
            &final_attributes,
            "failure_identity",
            ZSTR_VAL(failure_identity),
            ZSTR_LEN(failure_identity)
        );
    }

    (void) king_telemetry_end_internal_span(span, &final_attributes);
    zval_ptr_dtor(&final_attributes);
}

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
    const char *execution_backend = king_orchestrator_boundary_backend_label();
    const char *topology_scope = king_orchestrator_runtime_topology_scope();
    zend_long attempt_number;
    zend_long persisted_completed_step_count = 0;
    zend_string *attempt_identity = NULL;
    king_trace_context_t *run_span = NULL;
    zval handler_boundary;
    zval current_payload;
    zend_bool use_registered_tool_handlers = king_orchestrator_executes_userland_handlers_locally();
    zend_bool use_handler_boundary = 0;

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

    ZVAL_NULL(&handler_boundary);
    ZVAL_UNDEF(&current_payload);
    attempt_number = king_orchestrator_run_attempt_number(run_id);
    attempt_identity = king_orchestrator_build_attempt_identity(run_id);

    if (king_orchestrator_backend_is_file_worker()) {
        if (run_id == NULL || king_orchestrator_load_run_handler_boundary(run_id, &handler_boundary) != SUCCESS) {
            if (attempt_identity != NULL) {
                zend_string_release(attempt_identity);
            }
            zval_ptr_dtor(&handler_boundary);
            return king_orchestrator_raise_error(
                "king_pipeline_orchestrator_run() could not load the persisted file-worker handler boundary.",
                king_ce_runtime_exception,
                throw_on_error
            );
        }

        if (king_orchestrator_handler_boundary_requires_process_registration(&handler_boundary)) {
            use_registered_tool_handlers = 1;
            use_handler_boundary = 1;
        }
    }

    if (use_registered_tool_handlers) {
        if (
            run_id == NULL
            || king_orchestrator_load_run_progress(
                run_id,
                &current_payload,
                &persisted_completed_step_count
            ) != SUCCESS
        ) {
            if (attempt_identity != NULL) {
                zend_string_release(attempt_identity);
            }
            zval_ptr_dtor(&handler_boundary);
            return king_orchestrator_raise_error(
                "king_pipeline_orchestrator_run() could not load persisted registered-handler progress.",
                king_ce_runtime_exception,
                throw_on_error
            );
        }

        if (persisted_completed_step_count <= 0) {
            zval_ptr_dtor(&current_payload);
            ZVAL_COPY(&current_payload, initial_data);
        }
    }

    if (king_telemetry_is_capture_enabled() && attempt_identity != NULL) {
        zval run_attributes;

        array_init(&run_attributes);
        king_orchestrator_append_pipeline_span_attributes(
            &run_attributes,
            run_id,
            attempt_identity,
            NULL,
            -1
        );
        add_assoc_string(&run_attributes, "identity_scope", "run");
        if (attempt_number > 1) {
            add_assoc_stringl(
                &run_attributes,
                "retry_identity",
                ZSTR_VAL(attempt_identity),
                ZSTR_LEN(attempt_identity)
            );
        }
        (void) king_telemetry_start_internal_span(
            &run_span,
            "pipeline-orchestrator-run",
            KING_SPAN_KIND_CONSUMER,
            &run_attributes,
            NULL
        );
        zval_ptr_dtor(&run_attributes);

        king_orchestrator_record_pipeline_counter(
            "pipeline.run.count",
            execution_backend,
            topology_scope,
            "attempt",
            NULL,
            NULL
        );
        if (attempt_number > 1) {
            king_orchestrator_record_pipeline_counter(
                "pipeline.retry.count",
                execution_backend,
                topology_scope,
                "recovered_attempt",
                NULL,
                "caller_managed_retry"
            );
        }
    }

    if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
        zend_string *failure_identity = NULL;
        const char *category = cancelled ? "cancelled" : "backend";
        const char *retry_disposition = cancelled ? "not_applicable" : "caller_managed_retry";

        if (!cancelled
            && EG(exception) != NULL
            && instanceof_function(EG(exception)->ce, king_ce_timeout_exception)) {
            category = "timeout";
        }
        if (attempt_identity != NULL) {
            failure_identity = king_orchestrator_build_failure_identity(
                run_id,
                attempt_identity,
                category,
                -1,
                cancelled ? "cancelled" : "failed"
            );
        }
        king_orchestrator_finish_pipeline_span(
            run_span,
            cancelled ? "cancelled" : "failed",
            category,
            retry_disposition,
            failure_identity
        );
        if (failure_identity != NULL) {
            zend_string_release(failure_identity);
        }
        king_orchestrator_mark_interrupted_run(run_id, cancelled, -1, NULL);
        if (use_registered_tool_handlers) {
            zval_ptr_dtor(&current_payload);
        }
        zval_ptr_dtor(&handler_boundary);
        ZVAL_FALSE(return_value);
        if (attempt_identity != NULL) {
            zend_string_release(attempt_identity);
        }
        return FAILURE;
    }

    if (!use_registered_tool_handlers) {
        /* Runs without a registered-handler execution boundary still use the placeholder path. */
        ZVAL_COPY(return_value, initial_data);
    }

    ht = Z_ARRVAL_P(pipeline_array);
    ZEND_HASH_FOREACH_VAL(ht, step) {
        king_trace_context_t *step_span = NULL;
        zend_string *failure_identity = NULL;
        zend_bool step_uses_registered_handler = use_registered_tool_handlers && !use_handler_boundary;
        zval step_output;

        if (use_registered_tool_handlers && (zend_long) index < persisted_completed_step_count) {
            index++;
            continue;
        }

        if (use_handler_boundary) {
            step_uses_registered_handler = king_orchestrator_handler_boundary_step_uses_registered_handler(
                &handler_boundary,
                step,
                (zend_long) index
            );
        }

        if (king_telemetry_is_capture_enabled() && attempt_identity != NULL) {
            zval step_attributes;

            array_init(&step_attributes);
            king_orchestrator_append_pipeline_span_attributes(
                &step_attributes,
                run_id,
                attempt_identity,
                step,
                (zend_long) index
            );
            add_assoc_string(&step_attributes, "identity_scope", "step");
            if (attempt_number > 1) {
                add_assoc_stringl(
                    &step_attributes,
                    "retry_identity",
                    ZSTR_VAL(attempt_identity),
                    ZSTR_LEN(attempt_identity)
                );
            }
            (void) king_telemetry_start_internal_span(
                &step_span,
                "pipeline-orchestrator-step",
                KING_SPAN_KIND_INTERNAL,
                &step_attributes,
                NULL
            );
            zval_ptr_dtor(&step_attributes);

            if (king_orchestrator_find_step_telemetry_id(step, "partition_id", sizeof("partition_id") - 1) != NULL) {
                king_orchestrator_record_pipeline_counter(
                    "pipeline.partition.count",
                    execution_backend,
                    topology_scope,
                    "observed",
                    NULL,
                    NULL
                );
            }
            if (king_orchestrator_find_step_telemetry_id(step, "batch_id", sizeof("batch_id") - 1) != NULL) {
                king_orchestrator_record_pipeline_counter(
                    "pipeline.batch.count",
                    execution_backend,
                    topology_scope,
                    "observed",
                    NULL,
                    NULL
                );
            }
        }

        cancelled = 0;
        if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
            const char *category = cancelled ? "cancelled" : "backend";
            const char *retry_disposition = cancelled ? "not_applicable" : "caller_managed_retry";

            if (!cancelled
                && EG(exception) != NULL
                && instanceof_function(EG(exception)->ce, king_ce_timeout_exception)) {
                category = "timeout";
            }
            if (attempt_identity != NULL) {
                failure_identity = king_orchestrator_build_failure_identity(
                    run_id,
                    attempt_identity,
                    category,
                    (zend_long) index,
                    cancelled ? "cancelled" : "failed"
                );
            }
            king_orchestrator_finish_pipeline_span(
                step_span,
                cancelled ? "cancelled" : "failed",
                category,
                retry_disposition,
                failure_identity
            );
            king_orchestrator_finish_pipeline_span(
                run_span,
                cancelled ? "cancelled" : "failed",
                category,
                retry_disposition,
                failure_identity
            );
            king_orchestrator_emit_pipeline_failure_log(
                run_id,
                attempt_identity,
                step,
                (zend_long) index,
                execution_backend,
                category,
                retry_disposition,
                king_get_error(),
                cancelled ? "cancelled" : "failed"
            );
            king_orchestrator_record_pipeline_counter(
                "pipeline.failure.count",
                execution_backend,
                topology_scope,
                cancelled ? "cancelled" : "failed",
                category,
                retry_disposition
            );
            if (failure_identity != NULL) {
                zend_string_release(failure_identity);
            }
            if (use_registered_tool_handlers) {
                zval_ptr_dtor(&current_payload);
            } else {
                zval_ptr_dtor(return_value);
                ZVAL_FALSE(return_value);
            }
            king_orchestrator_mark_interrupted_run(run_id, cancelled, (zend_long) index, NULL);
            if (attempt_identity != NULL) {
                zend_string_release(attempt_identity);
            }
            zval_ptr_dtor(&handler_boundary);
            return FAILURE;
        }

        failed_step_index = (zend_long) index;
        if (king_orchestrator_validate_pipeline_step(step, index, throw_on_error, &failed_step_index) != SUCCESS) {
            if (attempt_identity != NULL) {
                failure_identity = king_orchestrator_build_failure_identity(
                    run_id,
                    attempt_identity,
                    "validation",
                    failed_step_index,
                    "failed"
                );
            }
            king_orchestrator_finish_pipeline_span(
                step_span,
                "failed",
                "validation",
                "non_retryable",
                failure_identity
            );
            king_orchestrator_finish_pipeline_span(
                run_span,
                "failed",
                "validation",
                "non_retryable",
                failure_identity
            );
            king_orchestrator_emit_pipeline_failure_log(
                run_id,
                attempt_identity,
                step,
                failed_step_index,
                execution_backend,
                "validation",
                "non_retryable",
                king_get_error(),
                "failed"
            );
            king_orchestrator_record_pipeline_counter(
                "pipeline.failure.count",
                execution_backend,
                topology_scope,
                "failed",
                "validation",
                "non_retryable"
            );
            if (failure_identity != NULL) {
                zend_string_release(failure_identity);
            }
            if (use_registered_tool_handlers) {
                zval_ptr_dtor(&current_payload);
            } else {
                zval_ptr_dtor(return_value);
                ZVAL_FALSE(return_value);
            }
            (void) king_orchestrator_pipeline_run_fail_classified(
                run_id,
                king_get_error(),
                "validation",
                "non_retryable",
                failed_step_index,
                NULL
            );
            if (attempt_identity != NULL) {
                zend_string_release(attempt_identity);
            }
            zval_ptr_dtor(&handler_boundary);
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
            const char *category = cancelled ? "cancelled" : "backend";
            const char *retry_disposition = cancelled ? "not_applicable" : "caller_managed_retry";

            if (!cancelled
                && EG(exception) != NULL
                && instanceof_function(EG(exception)->ce, king_ce_timeout_exception)) {
                category = "timeout";
            }
            if (attempt_identity != NULL) {
                failure_identity = king_orchestrator_build_failure_identity(
                    run_id,
                    attempt_identity,
                    category,
                    (zend_long) index,
                    cancelled ? "cancelled" : "failed"
                );
            }
            king_orchestrator_finish_pipeline_span(
                step_span,
                cancelled ? "cancelled" : "failed",
                category,
                retry_disposition,
                failure_identity
            );
            king_orchestrator_finish_pipeline_span(
                run_span,
                cancelled ? "cancelled" : "failed",
                category,
                retry_disposition,
                failure_identity
            );
            king_orchestrator_emit_pipeline_failure_log(
                run_id,
                attempt_identity,
                step,
                (zend_long) index,
                execution_backend,
                category,
                retry_disposition,
                king_get_error(),
                cancelled ? "cancelled" : "failed"
            );
            king_orchestrator_record_pipeline_counter(
                "pipeline.failure.count",
                execution_backend,
                topology_scope,
                cancelled ? "cancelled" : "failed",
                category,
                retry_disposition
            );
            if (failure_identity != NULL) {
                zend_string_release(failure_identity);
            }
            if (use_registered_tool_handlers) {
                zval_ptr_dtor(&current_payload);
            } else {
                zval_ptr_dtor(return_value);
                ZVAL_FALSE(return_value);
            }
            king_orchestrator_mark_interrupted_run(run_id, cancelled, (zend_long) index, NULL);
            if (attempt_identity != NULL) {
                zend_string_release(attempt_identity);
            }
            zval_ptr_dtor(&handler_boundary);
            return FAILURE;
        }

        ZVAL_UNDEF(&step_output);
        if (step_uses_registered_handler) {
            const char *handler_failure_category = "backend";
            const char *handler_retry_disposition = "caller_managed_retry";
            const char *handler_error_message;

            if (
                king_orchestrator_invoke_registered_tool_handler(
                    run_id,
                    step,
                    index,
                    &current_payload,
                    &step_output,
                    execution_backend,
                    topology_scope,
                    attempt_number,
                    control,
                    function_name,
                    throw_on_error,
                    &handler_failure_category,
                    &handler_retry_disposition
                ) != SUCCESS
            ) {
                handler_error_message = king_orchestrator_exception_message_or_last_error();
                if (handler_error_message == NULL || handler_error_message[0] == '\0') {
                    handler_error_message =
                        "king_pipeline_orchestrator_run() failed during registered userland handler execution.";
                    king_set_error(handler_error_message);
                }
                if (attempt_identity != NULL) {
                    failure_identity = king_orchestrator_build_failure_identity(
                        run_id,
                        attempt_identity,
                        handler_failure_category,
                        (zend_long) index,
                        "failed"
                    );
                }
                king_orchestrator_finish_pipeline_span(
                    step_span,
                    "failed",
                    handler_failure_category,
                    handler_retry_disposition,
                    failure_identity
                );
                king_orchestrator_finish_pipeline_span(
                    run_span,
                    "failed",
                    handler_failure_category,
                    handler_retry_disposition,
                    failure_identity
                );
                king_orchestrator_emit_pipeline_failure_log(
                    run_id,
                    attempt_identity,
                    step,
                    (zend_long) index,
                    execution_backend,
                    handler_failure_category,
                    handler_retry_disposition,
                    handler_error_message,
                    "failed"
                );
                king_orchestrator_record_pipeline_counter(
                    "pipeline.failure.count",
                    execution_backend,
                    topology_scope,
                    "failed",
                    handler_failure_category,
                    handler_retry_disposition
                );
                if (failure_identity != NULL) {
                    zend_string_release(failure_identity);
                }
                zval_ptr_dtor(&current_payload);
                ZVAL_FALSE(return_value);
                if (!king_orchestrator_pipeline_run_is_terminal(run_id)) {
                    (void) king_orchestrator_pipeline_run_fail_classified(
                        run_id,
                        handler_error_message,
                        handler_failure_category,
                        handler_retry_disposition,
                        (zend_long) index,
                        execution_backend
                    );
                }
                if (attempt_identity != NULL) {
                    zend_string_release(attempt_identity);
                }
                zval_ptr_dtor(&handler_boundary);
                return FAILURE;
            }

            zval_ptr_dtor(&current_payload);
            ZVAL_COPY_VALUE(&current_payload, &step_output);
        }

        if (use_registered_tool_handlers) {
            if (
                king_orchestrator_pipeline_run_record_progress(
                    run_id,
                    (zend_long) index + 1,
                    &current_payload
                ) != SUCCESS
            ) {
                if (attempt_identity != NULL) {
                    failure_identity = king_orchestrator_build_failure_identity(
                        run_id,
                        attempt_identity,
                        "backend",
                        -1,
                        "failed"
                    );
                }
                king_orchestrator_finish_pipeline_span(
                    step_span,
                    "failed",
                    "backend",
                    "caller_managed_retry",
                    failure_identity
                );
                king_orchestrator_finish_pipeline_span(
                    run_span,
                    "failed",
                    "backend",
                    "caller_managed_retry",
                    failure_identity
                );
                king_orchestrator_emit_pipeline_failure_log(
                    run_id,
                    attempt_identity,
                    step,
                    -1,
                    execution_backend,
                    "backend",
                    "caller_managed_retry",
                    "king_pipeline_orchestrator_run() failed to persist registered-handler progress.",
                    "failed"
                );
                king_orchestrator_record_pipeline_counter(
                    "pipeline.failure.count",
                    execution_backend,
                    topology_scope,
                    "failed",
                    "backend",
                    "caller_managed_retry"
                );
                if (failure_identity != NULL) {
                    zend_string_release(failure_identity);
                }
                zval_ptr_dtor(&current_payload);
                ZVAL_FALSE(return_value);
                king_set_error(
                    "king_pipeline_orchestrator_run() failed to persist registered-handler progress."
                );
                (void) king_orchestrator_pipeline_run_fail_classified(
                    run_id,
                    king_get_error(),
                    "backend",
                    "caller_managed_retry",
                    -1,
                    "controller"
                );
                if (attempt_identity != NULL) {
                    zend_string_release(attempt_identity);
                }
                zval_ptr_dtor(&handler_boundary);
                return king_orchestrator_raise_error(
                    king_get_error(),
                    king_ce_runtime_exception,
                    throw_on_error
                );
            }
        } else if (king_orchestrator_pipeline_run_record_completed_steps(run_id, (zend_long) index + 1) != SUCCESS) {
            if (attempt_identity != NULL) {
                failure_identity = king_orchestrator_build_failure_identity(
                    run_id,
                    attempt_identity,
                    "backend",
                    -1,
                    "failed"
                );
            }
            king_orchestrator_finish_pipeline_span(
                run_span,
                "failed",
                "backend",
                "caller_managed_retry",
                failure_identity
            );
            king_orchestrator_emit_pipeline_failure_log(
                run_id,
                attempt_identity,
                step,
                -1,
                execution_backend,
                "backend",
                "caller_managed_retry",
                "king_pipeline_orchestrator_run() failed to persist completed step progress.",
                "failed"
            );
            king_orchestrator_record_pipeline_counter(
                "pipeline.failure.count",
                execution_backend,
                topology_scope,
                "failed",
                "backend",
                "caller_managed_retry"
            );
            if (failure_identity != NULL) {
                zend_string_release(failure_identity);
            }
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
            if (attempt_identity != NULL) {
                zend_string_release(attempt_identity);
            }
            zval_ptr_dtor(&handler_boundary);
            return king_orchestrator_raise_error(
                king_get_error(),
                king_ce_runtime_exception,
                throw_on_error
            );
        }
        king_orchestrator_finish_pipeline_span(
            step_span,
            "completed",
            NULL,
            NULL,
            NULL
        );
        index++;
    } ZEND_HASH_FOREACH_END();

    cancelled = 0;
    if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
        zend_string *failure_identity = NULL;
        const char *category = cancelled ? "cancelled" : "backend";
        const char *retry_disposition = cancelled ? "not_applicable" : "caller_managed_retry";

        if (!cancelled
            && EG(exception) != NULL
            && instanceof_function(EG(exception)->ce, king_ce_timeout_exception)) {
            category = "timeout";
        }
        if (attempt_identity != NULL) {
            failure_identity = king_orchestrator_build_failure_identity(
                run_id,
                attempt_identity,
                category,
                -1,
                cancelled ? "cancelled" : "failed"
            );
        }
        king_orchestrator_finish_pipeline_span(
            run_span,
            cancelled ? "cancelled" : "failed",
            category,
            retry_disposition,
            failure_identity
        );
        king_orchestrator_emit_pipeline_failure_log(
            run_id,
            attempt_identity,
            NULL,
            -1,
            execution_backend,
            category,
            retry_disposition,
            king_get_error(),
            cancelled ? "cancelled" : "failed"
        );
        king_orchestrator_record_pipeline_counter(
            "pipeline.failure.count",
            execution_backend,
            topology_scope,
            cancelled ? "cancelled" : "failed",
            category,
            retry_disposition
        );
        if (failure_identity != NULL) {
            zend_string_release(failure_identity);
        }
        if (use_registered_tool_handlers) {
            zval_ptr_dtor(&current_payload);
        } else {
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
        }
        king_orchestrator_mark_interrupted_run(run_id, cancelled, -1, NULL);
        if (attempt_identity != NULL) {
            zend_string_release(attempt_identity);
        }
        zval_ptr_dtor(&handler_boundary);
        return FAILURE;
    }

    if (use_registered_tool_handlers) {
        ZVAL_COPY(return_value, &current_payload);
        zval_ptr_dtor(&current_payload);
    }

    if (king_orchestrator_pipeline_run_complete(run_id, return_value) != SUCCESS) {
        zend_string *failure_identity = NULL;

        if (attempt_identity != NULL) {
            failure_identity = king_orchestrator_build_failure_identity(
                run_id,
                attempt_identity,
                "backend",
                -1,
                "failed"
            );
        }
        king_orchestrator_finish_pipeline_span(
            run_span,
            "failed",
            "backend",
            "caller_managed_retry",
            failure_identity
        );
        king_orchestrator_emit_pipeline_failure_log(
            run_id,
            attempt_identity,
            NULL,
            -1,
            execution_backend,
            "backend",
            "caller_managed_retry",
            "king_pipeline_orchestrator_run() failed to persist the completed run snapshot.",
            "failed"
        );
        king_orchestrator_record_pipeline_counter(
            "pipeline.failure.count",
            execution_backend,
            topology_scope,
            "failed",
            "backend",
            "caller_managed_retry"
        );
        if (failure_identity != NULL) {
            zend_string_release(failure_identity);
        }
        zval_ptr_dtor(return_value);
        ZVAL_FALSE(return_value);
        if (attempt_identity != NULL) {
            zend_string_release(attempt_identity);
        }
        zval_ptr_dtor(&handler_boundary);
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed to persist the completed run snapshot.",
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    king_orchestrator_finish_pipeline_span(run_span, "completed", NULL, NULL, NULL);
    if (attempt_identity != NULL) {
        zend_string_release(attempt_identity);
    }
    zval_ptr_dtor(&handler_boundary);
    return SUCCESS;
}

static zend_result king_orchestrator_prepare_execution_pipeline(
    zval *pipeline_input,
    zval *normalized_pipeline_out,
    const char *function_name,
    zend_bool throw_on_error
)
{
    const char *fn = function_name != NULL ? function_name : "king_pipeline_orchestrator_run";
    zval *steps_value;
    HashTable *steps_ht;
    zval *step;
    size_t step_count;
    zend_bool explicit_graph = 0;
    zend_bool has_graph_markers = 0;
    zval *step_defs = NULL;
    zend_string **step_ids = NULL;
    zval *dependents = NULL;
    zend_long *indegree = NULL;
    zend_long *queue = NULL;
    HashTable id_to_index;
    zend_bool id_to_index_initialized = 0;
    size_t index = 0;
    zend_long queue_head = 0;
    zend_long queue_tail = 0;
    zend_long processed_count = 0;
    char message[KING_ERR_LEN];
    zend_result rc = FAILURE;

    ZVAL_UNDEF(normalized_pipeline_out);

    if (pipeline_input == NULL || Z_TYPE_P(pipeline_input) != IS_ARRAY) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() requires an array pipeline definition.",
            king_ce_validation_exception,
            throw_on_error
        );
    }

    steps_value = zend_hash_str_find(
        Z_ARRVAL_P(pipeline_input),
        "steps",
        sizeof("steps") - 1
    );
    if (steps_value != NULL) {
        explicit_graph = 1;
        if (Z_TYPE_P(steps_value) != IS_ARRAY) {
            snprintf(
                message,
                sizeof(message),
                "%s() graph pipeline key 'steps' must be an array.",
                fn
            );
            return king_orchestrator_raise_error(
                message,
                king_ce_validation_exception,
                throw_on_error
            );
        }
        steps_ht = Z_ARRVAL_P(steps_value);
    } else {
        steps_ht = Z_ARRVAL_P(pipeline_input);
    }

    if (zend_hash_num_elements(steps_ht) == 0) {
        array_init(normalized_pipeline_out);
        return SUCCESS;
    }

    ZEND_HASH_FOREACH_VAL(steps_ht, step) {
        if (step == NULL || Z_TYPE_P(step) != IS_ARRAY) {
            break;
        }
        if (
            zend_hash_str_find(Z_ARRVAL_P(step), "id", sizeof("id") - 1) != NULL
            || zend_hash_str_find(Z_ARRVAL_P(step), "deps", sizeof("deps") - 1) != NULL
        ) {
            has_graph_markers = 1;
            break;
        }
    } ZEND_HASH_FOREACH_END();

    if (!explicit_graph && !has_graph_markers) {
        if (steps_value != NULL) {
            ZVAL_COPY(normalized_pipeline_out, steps_value);
        } else {
            ZVAL_COPY(normalized_pipeline_out, pipeline_input);
        }
        return SUCCESS;
    }

    step_count = zend_hash_num_elements(steps_ht);
    step_defs = safe_emalloc(step_count, sizeof(zval), 0);
    step_ids = safe_emalloc(step_count, sizeof(zend_string *), 0);
    dependents = safe_emalloc(step_count, sizeof(zval), 0);
    indegree = safe_emalloc(step_count, sizeof(zend_long), 0);
    queue = safe_emalloc(step_count, sizeof(zend_long), 0);

    for (index = 0; index < step_count; index++) {
        ZVAL_UNDEF(&step_defs[index]);
        step_ids[index] = NULL;
        ZVAL_UNDEF(&dependents[index]);
        indegree[index] = 0;
        queue[index] = 0;
    }

    zend_hash_init(&id_to_index, (uint32_t) step_count, NULL, ZVAL_PTR_DTOR, 0);
    id_to_index_initialized = 1;

    index = 0;
    ZEND_HASH_FOREACH_VAL(steps_ht, step) {
        zval *id_value;
        zval idx;

        if (step == NULL || Z_TYPE_P(step) != IS_ARRAY) {
            snprintf(
                message,
                sizeof(message),
                "%s() graph step %zu must be an array.",
                fn,
                index
            );
            goto cleanup;
        }

        ZVAL_COPY(&step_defs[index], step);
        SEPARATE_ARRAY(&step_defs[index]);
        array_init(&dependents[index]);

        id_value = zend_hash_str_find(
            Z_ARRVAL(step_defs[index]),
            "id",
            sizeof("id") - 1
        );
        if (id_value != NULL && Z_TYPE_P(id_value) != IS_NULL) {
            if (Z_TYPE_P(id_value) != IS_STRING || Z_STRLEN_P(id_value) == 0) {
                snprintf(
                    message,
                    sizeof(message),
                    "%s() graph step %zu requires a non-empty string 'id' when provided.",
                    fn,
                    index
                );
                goto cleanup;
            }
            step_ids[index] = zend_string_copy(Z_STR_P(id_value));
        } else {
            step_ids[index] = strpprintf(0, "step-%zu", index);
            add_assoc_stringl(
                &step_defs[index],
                "id",
                ZSTR_VAL(step_ids[index]),
                ZSTR_LEN(step_ids[index])
            );
        }

        if (zend_hash_exists(&id_to_index, step_ids[index])) {
            snprintf(
                message,
                sizeof(message),
                "%s() graph pipeline contains a duplicate step id '%s'.",
                fn,
                ZSTR_VAL(step_ids[index])
            );
            goto cleanup;
        }

        ZVAL_LONG(&idx, (zend_long) index);
        zend_hash_update(&id_to_index, step_ids[index], &idx);
        index++;
    } ZEND_HASH_FOREACH_END();

    for (index = 0; index < step_count; index++) {
        zval *deps_value = zend_hash_str_find(
            Z_ARRVAL(step_defs[index]),
            "deps",
            sizeof("deps") - 1
        );

        if (deps_value == NULL || Z_TYPE_P(deps_value) == IS_NULL) {
            continue;
        }

        if (Z_TYPE_P(deps_value) != IS_ARRAY) {
            snprintf(
                message,
                sizeof(message),
                "%s() graph step '%s' key 'deps' must be an array when provided.",
                fn,
                step_ids[index] != NULL ? ZSTR_VAL(step_ids[index]) : "unknown"
            );
            goto cleanup;
        }

        {
            HashTable seen_dependencies;
            zval *dep_entry;

            zend_hash_init(&seen_dependencies, 0, NULL, NULL, 0);
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(deps_value), dep_entry) {
                zval *dep_index_zv;
                zend_long dep_index;

                if (
                    dep_entry == NULL
                    || Z_TYPE_P(dep_entry) != IS_STRING
                    || Z_STRLEN_P(dep_entry) == 0
                ) {
                    zend_hash_destroy(&seen_dependencies);
                    snprintf(
                        message,
                        sizeof(message),
                        "%s() graph step '%s' contains a non-string dependency id.",
                        fn,
                        step_ids[index] != NULL ? ZSTR_VAL(step_ids[index]) : "unknown"
                    );
                    goto cleanup;
                }

                dep_index_zv = zend_hash_find(&id_to_index, Z_STR_P(dep_entry));
                if (dep_index_zv == NULL || Z_TYPE_P(dep_index_zv) != IS_LONG) {
                    zend_hash_destroy(&seen_dependencies);
                    snprintf(
                        message,
                        sizeof(message),
                        "%s() graph step '%s' references unknown dependency '%s'.",
                        fn,
                        step_ids[index] != NULL ? ZSTR_VAL(step_ids[index]) : "unknown",
                        Z_STRVAL_P(dep_entry)
                    );
                    goto cleanup;
                }

                dep_index = Z_LVAL_P(dep_index_zv);
                if (dep_index == (zend_long) index) {
                    zend_hash_destroy(&seen_dependencies);
                    snprintf(
                        message,
                        sizeof(message),
                        "%s() graph step '%s' cannot depend on itself.",
                        fn,
                        step_ids[index] != NULL ? ZSTR_VAL(step_ids[index]) : "unknown"
                    );
                    goto cleanup;
                }

                if (zend_hash_index_exists(&seen_dependencies, (zend_ulong) dep_index)) {
                    continue;
                }

                zend_hash_index_add_empty_element(&seen_dependencies, (zend_ulong) dep_index);
                indegree[index]++;
                add_next_index_long(&dependents[dep_index], (zend_long) index);
            } ZEND_HASH_FOREACH_END();
            zend_hash_destroy(&seen_dependencies);
        }
    }

    for (index = 0; index < step_count; index++) {
        if (indegree[index] == 0) {
            queue[queue_tail++] = (zend_long) index;
        }
    }

    array_init(normalized_pipeline_out);
    while (queue_head < queue_tail) {
        zend_long current_index = queue[queue_head++];
        zval *dependent_index_value;

        add_next_index_zval(normalized_pipeline_out, &step_defs[current_index]);
        ZVAL_UNDEF(&step_defs[current_index]);
        processed_count++;

        ZEND_HASH_FOREACH_VAL(Z_ARRVAL(dependents[current_index]), dependent_index_value) {
            zend_long dependent_index;

            if (
                dependent_index_value == NULL
                || Z_TYPE_P(dependent_index_value) != IS_LONG
            ) {
                continue;
            }

            dependent_index = Z_LVAL_P(dependent_index_value);
            if (dependent_index < 0 || dependent_index >= (zend_long) step_count) {
                continue;
            }

            indegree[dependent_index]--;
            if (indegree[dependent_index] == 0) {
                queue[queue_tail++] = dependent_index;
            }
        } ZEND_HASH_FOREACH_END();
    }

    if (processed_count != (zend_long) step_count) {
        snprintf(
            message,
            sizeof(message),
            "%s() graph pipeline contains a cycle or unresolved dependency chain.",
            fn
        );
        goto cleanup;
    }

    rc = SUCCESS;

cleanup:
    if (rc != SUCCESS && !Z_ISUNDEF_P(normalized_pipeline_out)) {
        zval_ptr_dtor(normalized_pipeline_out);
        ZVAL_UNDEF(normalized_pipeline_out);
    }

    if (id_to_index_initialized) {
        zend_hash_destroy(&id_to_index);
    }

    if (step_defs != NULL) {
        for (index = 0; index < step_count; index++) {
            if (!Z_ISUNDEF(step_defs[index])) {
                zval_ptr_dtor(&step_defs[index]);
            }
        }
        efree(step_defs);
    }

    if (step_ids != NULL) {
        for (index = 0; index < step_count; index++) {
            if (step_ids[index] != NULL) {
                zend_string_release(step_ids[index]);
            }
        }
        efree(step_ids);
    }

    if (dependents != NULL) {
        for (index = 0; index < step_count; index++) {
            if (!Z_ISUNDEF(dependents[index])) {
                zval_ptr_dtor(&dependents[index]);
            }
        }
        efree(dependents);
    }

    if (indegree != NULL) {
        efree(indegree);
    }

    if (queue != NULL) {
        efree(queue);
    }

    if (rc == SUCCESS) {
        return SUCCESS;
    }

    return king_orchestrator_raise_error(
        message,
        king_ce_validation_exception,
        throw_on_error
    );
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
    zval normalized_pipeline;
    zval telemetry_parent_context;
    zval *persisted_options;
    king_orchestrator_exec_control_t control;
    zend_bool boundary_seeded = 0;
    int rc = FAILURE;

    ZVAL_UNDEF(&sanitized_options);
    ZVAL_UNDEF(&normalized_pipeline);
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

    if (
        king_orchestrator_prepare_execution_pipeline(
            pipeline_array,
            &normalized_pipeline,
            "king_pipeline_orchestrator_run",
            1
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    persisted_options = king_orchestrator_prepare_persisted_options(options, &sanitized_options);
    run_id = king_orchestrator_pipeline_run_begin(
        initial_data,
        &normalized_pipeline,
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
            &normalized_pipeline,
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
            &normalized_pipeline,
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
    if (!Z_ISUNDEF(normalized_pipeline)) {
        zval_ptr_dtor(&normalized_pipeline);
    }
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
    zval normalized_pipeline;
    zval telemetry_parent_context;
    zval *persisted_options;
    king_orchestrator_exec_control_t control;
    int rc = FAILURE;

    ZVAL_UNDEF(&sanitized_options);
    ZVAL_UNDEF(&normalized_pipeline);
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

    if (
        king_orchestrator_prepare_execution_pipeline(
            pipeline_array,
            &normalized_pipeline,
            "king_pipeline_orchestrator_dispatch",
            1
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    king_orchestrator_capture_distributed_parent_context(&telemetry_parent_context);
    persisted_options = king_orchestrator_prepare_persisted_options(options, &sanitized_options);
    run_id = king_orchestrator_pipeline_run_begin(
        initial_data,
        &normalized_pipeline,
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
    if (!Z_ISUNDEF(normalized_pipeline)) {
        zval_ptr_dtor(&normalized_pipeline);
    }
    if (!Z_ISUNDEF(sanitized_options)) {
        zval_ptr_dtor(&sanitized_options);
    }
    zval_ptr_dtor(&telemetry_parent_context);
    king_orchestrator_exec_control_cleanup(&control);
    return rc;
}

static int king_orchestrator_worker_release_unadmitted_claim(
    zend_string *run_id,
    char *claimed_path,
    int *claimed_fd,
    zend_bool recovered_claim
)
{
    char queued_path[1024];
    int rc = SUCCESS;

    if (!recovered_claim) {
        if (
            run_id == NULL
            || !king_orchestrator_run_id_is_valid(run_id)
            || claimed_path == NULL
            || claimed_path[0] == '\0'
            || king_mcp_orchestrator_config.orchestrator_worker_queue_path == NULL
            || king_mcp_orchestrator_config.orchestrator_worker_queue_path[0] == '\0'
            || snprintf(
                queued_path,
                sizeof(queued_path),
                "%s/queued-%s.job",
                king_mcp_orchestrator_config.orchestrator_worker_queue_path,
                ZSTR_VAL(run_id)
            ) >= (int) sizeof(queued_path)
            || rename(claimed_path, queued_path) != 0
        ) {
            rc = FAILURE;
        } else {
            claimed_path[0] = '\0';
        }
    }

    if (claimed_fd != NULL && *claimed_fd >= 0) {
        close(*claimed_fd);
        *claimed_fd = -1;
    }

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

    if (
        king_system_require_admission(
            "king_pipeline_orchestrator_worker_run_next",
            recovered_claim ? "file_worker_resumes" : "file_worker_claims"
        ) != SUCCESS
    ) {
        if (
            king_orchestrator_worker_release_unadmitted_claim(
                run_id,
                claimed_path,
                &claimed_fd,
                recovered_claim
            ) != SUCCESS
        ) {
            zend_string_release(run_id);
            return king_orchestrator_raise_error(
                "king_pipeline_orchestrator_worker_run_next() could not restore the unadmitted file-worker claim after readiness gating.",
                king_ce_runtime_exception,
                1
            );
        }
        zend_string_release(run_id);
        return king_orchestrator_raise_error(
            king_get_error(),
            king_ce_runtime_exception,
            1
        );
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
