static void king_orchestrator_append_step_snapshots(
    zval *target,
    zval *pipeline,
    const king_orchestrator_run_state_t *run_state
)
{
    zval steps;
    zval *step;
    zval *tool;
    zval entry;
    uint32_t index = 0;
    const char *execution_backend = king_orchestrator_run_execution_backend(run_state);
    const char *topology_scope = king_orchestrator_topology_scope_for_backend(execution_backend);

    if (target == NULL) {
        return;
    }

    array_init(&steps);
    if (pipeline == NULL || Z_TYPE_P(pipeline) != IS_ARRAY) {
        add_assoc_zval(target, "steps", &steps);
        return;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(pipeline), step) {
        array_init(&entry);
        add_assoc_long(&entry, "index", (zend_long) index);

        tool = NULL;
        if (step != NULL && Z_TYPE_P(step) == IS_ARRAY) {
            tool = zend_hash_str_find(Z_ARRVAL_P(step), "tool", sizeof("tool") - 1);
        }

        if (tool != NULL && Z_TYPE_P(tool) == IS_STRING) {
            add_assoc_stringl(&entry, "tool", Z_STRVAL_P(tool), Z_STRLEN_P(tool));
        } else {
            add_assoc_null(&entry, "tool");
        }

        add_assoc_string(
            &entry,
            "status",
            king_orchestrator_step_status_for_snapshot(run_state, (zend_long) index)
        );
        add_assoc_string(
            &entry,
            "compensation_status",
            king_orchestrator_step_compensation_status_for_snapshot(run_state, (zend_long) index)
        );
        add_assoc_string(&entry, "execution_backend", (char *) execution_backend);
        add_assoc_string(&entry, "topology_scope", (char *) topology_scope);

        if (
            run_state != NULL
            && run_state->error_step_index == (zend_long) index
            && (
                run_state->error_category != NULL
                || run_state->retry_disposition != NULL
                || run_state->error_backend != NULL
            )
        ) {
            king_orchestrator_append_error_classification(&entry, run_state, pipeline);
        } else {
            add_assoc_null(&entry, "error_classification");
        }

        if (king_orchestrator_find_pipeline_step_string(
                pipeline,
                (zend_long) index,
                "partition_id",
                sizeof("partition_id") - 1
            ) != NULL
            || king_orchestrator_find_pipeline_step_string(
                pipeline,
                (zend_long) index,
                "batch_id",
                sizeof("batch_id") - 1
            ) != NULL) {
            zval step_adapter;
            zend_string *attempt_identity;
            zend_string *failure_identity;
            zval *partition_id;
            zval *batch_id;

            array_init(&step_adapter);
            attempt_identity = king_orchestrator_build_attempt_identity_for_snapshot(run_state);
            add_assoc_stringl(&step_adapter, "attempt_identity", ZSTR_VAL(attempt_identity), ZSTR_LEN(attempt_identity));
            if (run_state != NULL && run_state->recovery_count > 0) {
                add_assoc_stringl(&step_adapter, "retry_identity", ZSTR_VAL(attempt_identity), ZSTR_LEN(attempt_identity));
            } else {
                add_assoc_null(&step_adapter, "retry_identity");
            }
            zend_string_release(attempt_identity);

            partition_id = king_orchestrator_find_pipeline_step_string(
                pipeline,
                (zend_long) index,
                "partition_id",
                sizeof("partition_id") - 1
            );
            if (partition_id != NULL) {
                add_assoc_stringl(&step_adapter, "partition_id", Z_STRVAL_P(partition_id), Z_STRLEN_P(partition_id));
            } else {
                add_assoc_null(&step_adapter, "partition_id");
            }

            batch_id = king_orchestrator_find_pipeline_step_string(
                pipeline,
                (zend_long) index,
                "batch_id",
                sizeof("batch_id") - 1
            );
            if (batch_id != NULL) {
                add_assoc_stringl(&step_adapter, "batch_id", Z_STRVAL_P(batch_id), Z_STRLEN_P(batch_id));
            } else {
                add_assoc_null(&step_adapter, "batch_id");
            }

            if (
                run_state != NULL
                && run_state->error_step_index == (zend_long) index
                && run_state->status != NULL
                && (
                    zend_string_equals_literal(run_state->status, "failed")
                    || zend_string_equals_literal(run_state->status, "cancelled")
                )
            ) {
                failure_identity = king_orchestrator_build_failure_identity_for_snapshot(run_state);
                if (failure_identity != NULL) {
                    add_assoc_stringl(&step_adapter, "failure_identity", ZSTR_VAL(failure_identity), ZSTR_LEN(failure_identity));
                    zend_string_release(failure_identity);
                } else {
                    add_assoc_null(&step_adapter, "failure_identity");
                }
            } else {
                add_assoc_null(&step_adapter, "failure_identity");
            }

            add_assoc_zval(&entry, "telemetry_adapter", &step_adapter);
        } else {
            add_assoc_null(&entry, "telemetry_adapter");
        }

        add_next_index_zval(&steps, &entry);
        index++;
    } ZEND_HASH_FOREACH_END();

    add_assoc_zval(target, "steps", &steps);
}

int king_orchestrator_get_run_snapshot(zend_string *run_id, zval *return_value)
{
    king_orchestrator_run_state_t *run_state;
    zval initial_data;
    zval pipeline;
    zval options;
    zval handler_boundary;
    zval result;
    zval error;
    zval handler_readiness;
    int lock_fd = -1;

    ZVAL_NULL(&initial_data);
    ZVAL_NULL(&pipeline);
    ZVAL_NULL(&options);
    ZVAL_NULL(&handler_boundary);
    ZVAL_NULL(&result);
    ZVAL_NULL(&error);
    ZVAL_NULL(&handler_readiness);

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (
        king_orchestrator_decode_base64_zval(run_state->initial_data_b64, &initial_data) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->pipeline_b64, &pipeline) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->options_b64, &options) != SUCCESS
        || (
            run_state->handler_boundary_b64 != NULL
            && king_orchestrator_decode_base64_zval(run_state->handler_boundary_b64, &handler_boundary) != SUCCESS
        )
        || king_orchestrator_decode_base64_zval(run_state->result_b64, &result) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->error_b64, &error) != SUCCESS
    ) {
        zval_ptr_dtor(&initial_data);
        zval_ptr_dtor(&pipeline);
        zval_ptr_dtor(&options);
        zval_ptr_dtor(&handler_boundary);
        zval_ptr_dtor(&result);
        zval_ptr_dtor(&error);
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    array_init(return_value);
    add_assoc_stringl(return_value, "run_id", ZSTR_VAL(run_state->run_id), ZSTR_LEN(run_state->run_id));
    add_assoc_stringl(return_value, "status", ZSTR_VAL(run_state->status), ZSTR_LEN(run_state->status));
    add_assoc_string(return_value, "execution_backend", (char *) king_orchestrator_run_execution_backend(run_state));
    add_assoc_string(
        return_value,
        "topology_scope",
        (char *) king_orchestrator_topology_scope_for_backend(king_orchestrator_run_execution_backend(run_state))
    );
    add_assoc_string(return_value, "retry_policy", "single_attempt");
    add_assoc_string(return_value, "idempotency_policy", "caller_managed");
    add_assoc_string(return_value, "compensation_policy", "caller_managed");
    add_assoc_long(return_value, "started_at", (zend_long) run_state->started_at);
    add_assoc_long(return_value, "finished_at", (zend_long) run_state->finished_at);
    add_assoc_bool(return_value, "cancel_requested", run_state->cancel_requested ? 1 : 0);
    add_assoc_long(return_value, "step_count", king_orchestrator_pipeline_step_count(&pipeline));
    add_assoc_long(return_value, "completed_step_count", run_state->completed_step_count);
    add_assoc_zval(return_value, "initial_data", &initial_data);
    add_assoc_zval(return_value, "pipeline", &pipeline);
    add_assoc_zval(return_value, "options", &options);
    add_assoc_zval(return_value, "handler_boundary", &handler_boundary);
    king_orchestrator_append_handler_readiness(
        Z_TYPE(handler_boundary) == IS_ARRAY
            ? &handler_boundary
            : NULL,
        &handler_readiness
    );
    add_assoc_zval(return_value, "handler_readiness", &handler_readiness);
    add_assoc_zval(return_value, "result", &result);
    add_assoc_zval(return_value, "error", &error);
    king_orchestrator_append_error_classification(return_value, run_state, &pipeline);
    king_orchestrator_append_run_telemetry_adapter_snapshot(return_value, &pipeline, run_state);
    king_orchestrator_append_compensation_snapshot(return_value, &pipeline, run_state);
    king_orchestrator_append_run_observability(return_value, run_state, &pipeline);
    king_orchestrator_append_step_snapshots(return_value, &pipeline, run_state);

    king_orchestrator_state_lock_release(lock_fd);
    return SUCCESS;
}
