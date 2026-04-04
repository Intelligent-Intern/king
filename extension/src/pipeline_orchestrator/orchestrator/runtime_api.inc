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
    zend_string *attempt_identity = NULL;
    king_trace_context_t *run_span = NULL;

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

    attempt_number = king_orchestrator_run_attempt_number(run_id);
    attempt_identity = king_orchestrator_build_attempt_identity(run_id);

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
        ZVAL_FALSE(return_value);
        if (attempt_identity != NULL) {
            zend_string_release(attempt_identity);
        }
        return FAILURE;
    }

    /* Clone initial data to return_value as the current placeholder result. */
    ZVAL_COPY(return_value, initial_data);

    ht = Z_ARRVAL_P(pipeline_array);
    ZEND_HASH_FOREACH_VAL(ht, step) {
        king_trace_context_t *step_span = NULL;
        zend_string *failure_identity = NULL;

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
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            king_orchestrator_mark_interrupted_run(run_id, cancelled, (zend_long) index, NULL);
            if (attempt_identity != NULL) {
                zend_string_release(attempt_identity);
            }
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
            if (attempt_identity != NULL) {
                zend_string_release(attempt_identity);
            }
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
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            king_orchestrator_mark_interrupted_run(run_id, cancelled, (zend_long) index, NULL);
            if (attempt_identity != NULL) {
                zend_string_release(attempt_identity);
            }
            return FAILURE;
        }

        king_orchestrator_finish_pipeline_span(
            step_span,
            "completed",
            NULL,
            NULL,
            NULL
        );
        if (king_orchestrator_pipeline_run_record_completed_steps(run_id, (zend_long) index + 1) != SUCCESS) {
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
        zval_ptr_dtor(return_value);
        ZVAL_FALSE(return_value);
        king_orchestrator_mark_interrupted_run(run_id, cancelled, -1, NULL);
        if (attempt_identity != NULL) {
            zend_string_release(attempt_identity);
        }
        return FAILURE;
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
