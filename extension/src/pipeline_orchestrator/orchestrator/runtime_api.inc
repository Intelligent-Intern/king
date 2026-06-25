#include "runtime_api/pipeline_observability.inc"

#include "runtime_api/handler_invocation.inc"

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

#include "runtime_api/worker_runtime.inc"

#include "runtime_api/procedural_api.inc"
