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
        "%s:attempt-" ZEND_LONG_FMT,
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
        "%s:%s:%s:" ZEND_LONG_FMT,
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

static void king_orchestrator_begin_pipeline_run_span(
    king_trace_context_t **run_span,
    zend_string *run_id,
    zend_string *attempt_identity,
    zend_long attempt_number,
    const char *execution_backend,
    const char *topology_scope
)
{
    zval run_attributes;

    if (run_span == NULL || !king_telemetry_is_capture_enabled() || attempt_identity == NULL) {
        return;
    }

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
        run_span,
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
