static int king_orchestrator_pipeline_run_finish_with_error(
    zend_string *run_id,
    const char *final_status,
    zend_bool cancel_requested,
    const char *error_message,
    const char *error_category,
    const char *retry_disposition,
    zend_long step_index,
    const char *backend
)
{
    king_orchestrator_run_state_t *run_state;
    zend_string *error_b64;
    int lock_fd = -1;
    int rc;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    error_b64 = king_orchestrator_encode_error_message_base64(error_message);

    king_orchestrator_replace_runtime_string(
        &run_state->status,
        zend_string_init(final_status, strlen(final_status), 1)
    );
    run_state->finished_at = time(NULL);
    run_state->cancel_requested = cancel_requested ? 1 : 0;
    if (strcmp(king_orchestrator_run_execution_backend(run_state), "file_worker") == 0) {
        king_orchestrator_run_state_replace_string_literal(&run_state->queue_phase, "dequeued");
    }
    if (error_b64 != NULL) {
        king_orchestrator_replace_runtime_string(&run_state->error_b64, zend_string_dup(error_b64, 1));
        zend_string_release(error_b64);
    }
    king_orchestrator_run_state_replace_optional_string(&run_state->error_category, error_category);
    king_orchestrator_run_state_replace_optional_string(&run_state->retry_disposition, retry_disposition);
    king_orchestrator_run_state_replace_optional_string(&run_state->error_backend, backend);
    run_state->error_step_index = step_index;
    king_orchestrator_set_last_run(run_state->run_id, final_status);

    rc = king_orchestrator_persist_state_locked();
    king_orchestrator_state_lock_release(lock_fd);
    if (rc != SUCCESS) {
        return FAILURE;
    }

    king_orchestrator_clear_run_cancel_request(run_state->run_id);
    return SUCCESS;
}

int king_orchestrator_pipeline_run_fail_classified(
    zend_string *run_id,
    const char *error_message,
    const char *error_category,
    const char *retry_disposition,
    zend_long step_index,
    const char *backend
)
{
    return king_orchestrator_pipeline_run_finish_with_error(
        run_id,
        "failed",
        0,
        error_message,
        error_category,
        retry_disposition,
        step_index,
        backend
    );
}

int king_orchestrator_pipeline_run_fail(zend_string *run_id, const char *error_message)
{
    return king_orchestrator_pipeline_run_fail_classified(
        run_id,
        error_message,
        NULL,
        NULL,
        -1,
        NULL
    );
}

int king_orchestrator_pipeline_run_cancelled(zend_string *run_id, const char *error_message)
{
    return king_orchestrator_pipeline_run_cancelled_classified(
        run_id,
        error_message,
        NULL,
        NULL,
        -1,
        NULL
    );
}

int king_orchestrator_pipeline_run_cancelled_classified(
    zend_string *run_id,
    const char *error_message,
    const char *error_category,
    const char *retry_disposition,
    zend_long step_index,
    const char *backend
)
{
    return king_orchestrator_pipeline_run_finish_with_error(
        run_id,
        "cancelled",
        1,
        error_message,
        error_category,
        retry_disposition,
        step_index,
        backend
    );
}

void king_orchestrator_append_component_info(zval *configuration)
{
    typedef struct _king_orchestrator_observability_summary {
        zend_long recovered_run_count;
        zend_long remote_attempted_run_count;
        zend_string *last_claimed_run_id;
        zend_string *last_recovered_run_id;
        zend_string *last_remote_attempt_run_id;
        const char *last_recovery_reason;
        time_t last_claimed_at;
        time_t last_recovered_at;
        time_t last_remote_attempt_at;
    } king_orchestrator_observability_summary_t;

    zval registered_tools;
    zval active_handler_contract;
    zval active_handler_tools;
    zval distributed_observability;
    zend_string *tool_name;
    zval *run_entry;
    zval *handler_entry;
    zend_string *handler_name;
    const char *execution_backend;
    const char *topology_scope = "local_in_process";
    const char *scheduler_policy = "in_process_linear";
    king_orchestrator_observability_summary_t summary;

    memset(&summary, 0, sizeof(summary));

    if (configuration == NULL || Z_TYPE_P(configuration) != IS_ARRAY) {
        return;
    }

    execution_backend = king_mcp_orchestrator_config.orchestrator_execution_backend != NULL
        ? king_mcp_orchestrator_config.orchestrator_execution_backend
        : "";
    if (strcmp(execution_backend, "file_worker") == 0) {
        topology_scope = "same_host_file_worker";
        scheduler_policy = "claimed_recovery_then_fifo_run_id";
    } else if (king_orchestrator_backend_is_remote_peer()) {
        topology_scope = "tcp_host_port_execution_peer";
        scheduler_policy = "controller_direct_remote_run";
    }

    add_assoc_string(
        configuration,
        "state_path",
        king_mcp_orchestrator_config.orchestrator_state_path != NULL
            ? king_mcp_orchestrator_config.orchestrator_state_path
            : ""
    );
    add_assoc_bool(
        configuration,
        "logging_configured",
        king_orchestrator_logging_config_b64 != NULL ? 1 : 0
    );
    add_assoc_string(
        configuration,
        "execution_backend",
        execution_backend
    );
    add_assoc_string(configuration, "topology_scope", topology_scope);
    add_assoc_string(configuration, "scheduler_policy", scheduler_policy);
    add_assoc_string(configuration, "retry_policy", "single_attempt");
    add_assoc_string(configuration, "idempotency_policy", "caller_managed");
    add_assoc_string(configuration, "compensation_policy", "caller_managed");
    add_assoc_string(
        configuration,
        "telemetry_adapter_contract",
        "run_partition_batch_retry_failure_identity"
    );
    add_assoc_string(
        configuration,
        "telemetry_identity_surface",
        "king_pipeline_orchestrator_get_run+otlp_spans+otlp_logs+telemetry_metrics"
    );
    add_assoc_string(
        configuration,
        "worker_queue_path",
        king_mcp_orchestrator_config.orchestrator_worker_queue_path != NULL
            ? king_mcp_orchestrator_config.orchestrator_worker_queue_path
            : ""
    );
    add_assoc_string(
        configuration,
        "remote_host",
        king_mcp_orchestrator_config.orchestrator_remote_host != NULL
            ? king_mcp_orchestrator_config.orchestrator_remote_host
            : ""
    );
    add_assoc_long(
        configuration,
        "remote_port",
        king_mcp_orchestrator_config.orchestrator_remote_port
    );
    add_assoc_bool(
        configuration,
        "recovered_from_state",
        king_orchestrator_recovered_from_state ? 1 : 0
    );
    add_assoc_long(
        configuration,
        "tool_count",
        (zend_long) zend_hash_num_elements(&king_orchestrator_tool_registry)
    );
    add_assoc_long(
        configuration,
        "run_history_count",
        (zend_long) zend_hash_num_elements(&king_orchestrator_pipeline_runs)
    );
    add_assoc_long(
        configuration,
        "active_run_count",
        (zend_long) king_orchestrator_count_active_runs()
    );
    add_assoc_long(
        configuration,
        "queued_run_count",
        (zend_long) king_orchestrator_count_queued_runs()
    );

    if (king_orchestrator_last_run_id != NULL) {
        add_assoc_stringl(
            configuration,
            "last_run_id",
            ZSTR_VAL(king_orchestrator_last_run_id),
            ZSTR_LEN(king_orchestrator_last_run_id)
        );
    } else {
        add_assoc_null(configuration, "last_run_id");
    }

    if (king_orchestrator_last_run_status != NULL) {
        add_assoc_stringl(
            configuration,
            "last_run_status",
            ZSTR_VAL(king_orchestrator_last_run_status),
            ZSTR_LEN(king_orchestrator_last_run_status)
        );
    } else {
        add_assoc_null(configuration, "last_run_status");
    }

    ZEND_HASH_FOREACH_VAL(&king_orchestrator_pipeline_runs, run_entry) {
        king_orchestrator_run_state_t *run_state;

        if (Z_TYPE_P(run_entry) != IS_PTR) {
            continue;
        }

        run_state = (king_orchestrator_run_state_t *) Z_PTR_P(run_entry);
        if (run_state == NULL || run_state->run_id == NULL) {
            continue;
        }

        if (run_state->recovery_count > 0) {
            summary.recovered_run_count++;
        }
        if (run_state->remote_attempt_count > 0) {
            summary.remote_attempted_run_count++;
        }

        if (run_state->last_claimed_at > summary.last_claimed_at) {
            summary.last_claimed_at = run_state->last_claimed_at;
            summary.last_claimed_run_id = run_state->run_id;
        }
        if (run_state->last_recovered_at > summary.last_recovered_at) {
            summary.last_recovered_at = run_state->last_recovered_at;
            summary.last_recovered_run_id = run_state->run_id;
            summary.last_recovery_reason = (
                run_state->last_recovery_reason != NULL
            ) ? ZSTR_VAL(run_state->last_recovery_reason) : NULL;
        }
        if (run_state->last_remote_attempt_at > summary.last_remote_attempt_at) {
            summary.last_remote_attempt_at = run_state->last_remote_attempt_at;
            summary.last_remote_attempt_run_id = run_state->run_id;
        }
    } ZEND_HASH_FOREACH_END();

    array_init(&registered_tools);
    ZEND_HASH_FOREACH_STR_KEY(&king_orchestrator_tool_registry, tool_name) {
        if (tool_name != NULL) {
            add_next_index_stringl(&registered_tools, ZSTR_VAL(tool_name), ZSTR_LEN(tool_name));
        }
    } ZEND_HASH_FOREACH_END();
    add_assoc_zval(configuration, "registered_tools", &registered_tools);

    array_init(&active_handler_tools);
    if (king_orchestrator_tool_handler_registry_initialized) {
        ZEND_HASH_FOREACH_STR_KEY_PTR(&king_orchestrator_tool_handler_registry, handler_name, handler_entry) {
            if (handler_name != NULL) {
                add_next_index_stringl(
                    &active_handler_tools,
                    ZSTR_VAL(handler_name),
                    ZSTR_LEN(handler_name)
                );
            }
        } ZEND_HASH_FOREACH_END();
    }
    array_init(&active_handler_contract);
    add_assoc_string(
        &active_handler_contract,
        "scope",
        (char *) topology_scope
    );
    add_assoc_long(
        &active_handler_contract,
        "registered_handler_count",
        king_orchestrator_tool_handler_registry_initialized
            ? (zend_long) zend_hash_num_elements(&king_orchestrator_tool_handler_registry)
            : 0
    );
    add_assoc_zval(&active_handler_contract, "registered_tools", &active_handler_tools);
    add_assoc_bool(
        &active_handler_contract,
        "requires_process_registration",
        king_orchestrator_tool_handler_registry_initialized
    );
    add_assoc_zval(configuration, "active_handler_contract", &active_handler_contract);

    array_init(&distributed_observability);
    add_assoc_long(
        &distributed_observability,
        "claimed_run_count",
        (zend_long) king_orchestrator_count_claimed_runs()
    );
    add_assoc_long(&distributed_observability, "recovered_run_count", summary.recovered_run_count);
    add_assoc_long(
        &distributed_observability,
        "remote_attempted_run_count",
        summary.remote_attempted_run_count
    );
    if (summary.last_claimed_run_id != NULL) {
        add_assoc_stringl(
            &distributed_observability,
            "last_claimed_run_id",
            ZSTR_VAL(summary.last_claimed_run_id),
            ZSTR_LEN(summary.last_claimed_run_id)
        );
    } else {
        add_assoc_null(&distributed_observability, "last_claimed_run_id");
    }
    if (summary.last_recovered_run_id != NULL) {
        add_assoc_stringl(
            &distributed_observability,
            "last_recovered_run_id",
            ZSTR_VAL(summary.last_recovered_run_id),
            ZSTR_LEN(summary.last_recovered_run_id)
        );
    } else {
        add_assoc_null(&distributed_observability, "last_recovered_run_id");
    }
    if (summary.last_recovery_reason != NULL) {
        add_assoc_string(&distributed_observability, "last_recovery_reason", (char *) summary.last_recovery_reason);
    } else {
        add_assoc_null(&distributed_observability, "last_recovery_reason");
    }
    if (summary.last_remote_attempt_run_id != NULL) {
        add_assoc_stringl(
            &distributed_observability,
            "last_remote_attempt_run_id",
            ZSTR_VAL(summary.last_remote_attempt_run_id),
            ZSTR_LEN(summary.last_remote_attempt_run_id)
        );
    } else {
        add_assoc_null(&distributed_observability, "last_remote_attempt_run_id");
    }
    add_assoc_zval(configuration, "distributed_observability", &distributed_observability);
}

PHP_FUNCTION(king_pipeline_orchestrator_register_tool)
{
    char *tool_name = NULL;
    size_t tool_name_len = 0;
    zval *config;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STRING(tool_name, tool_name_len)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    if (tool_name_len == 0) {
        king_set_error("king_pipeline_orchestrator_register_tool() requires a non-empty tool name.");
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_pipeline_orchestrator_register_tool() requires a non-empty tool name."
        );
        RETURN_THROWS();
    }

    if (king_orchestrator_register_tool(tool_name, tool_name_len, config) == SUCCESS) {
        RETURN_TRUE;
    }

    king_set_error("king_pipeline_orchestrator_register_tool() failed to persist the tool registry snapshot.");
    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "king_pipeline_orchestrator_register_tool() failed to persist the tool registry snapshot."
    );
    RETURN_THROWS();
}

PHP_FUNCTION(king_pipeline_orchestrator_register_handler)
{
    char *tool_name = NULL;
    size_t tool_name_len = 0;
    zval *handler;
    char message[256];
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STRING(tool_name, tool_name_len)
        Z_PARAM_ZVAL(handler)
    ZEND_PARSE_PARAMETERS_END();

    if (tool_name_len == 0) {
        king_set_error("king_pipeline_orchestrator_register_handler() requires a non-empty tool name.");
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_pipeline_orchestrator_register_handler() requires a non-empty tool name."
        );
        RETURN_THROWS();
    }

    if (zend_fcall_info_init(handler, 0, &fci, &fcc, NULL, NULL) != SUCCESS) {
        zend_argument_type_error(2, "must be a valid callback");
        RETURN_THROWS();
    }

    if (king_orchestrator_lookup_tool(tool_name, tool_name_len) == NULL) {
        snprintf(
            message,
            sizeof(message),
            "king_pipeline_orchestrator_register_handler() requires a previously registered tool '%s'.",
            tool_name
        );
        king_set_error(message);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s",
            message
        );
        RETURN_THROWS();
    }

    if (king_orchestrator_register_tool_handler(tool_name, tool_name_len, handler) == SUCCESS) {
        RETURN_TRUE;
    }

    king_set_error(
        "king_pipeline_orchestrator_register_handler() failed to bind the process-local handler registry."
    );
    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "king_pipeline_orchestrator_register_handler() failed to bind the process-local handler registry."
    );
    RETURN_THROWS();
}

PHP_FUNCTION(king_pipeline_orchestrator_get_run)
{
    zend_string *run_id;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(run_id)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(run_id) == 0) {
        king_set_error("king_pipeline_orchestrator_get_run() requires a non-empty run id.");
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_pipeline_orchestrator_get_run() requires a non-empty run id."
        );
        RETURN_THROWS();
    }

    if (king_orchestrator_get_run_snapshot(run_id, return_value) == SUCCESS) {
        return;
    }

    RETURN_FALSE;
}

PHP_FUNCTION(king_pipeline_orchestrator_cancel_run)
{
    zend_string *run_id;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(run_id)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(run_id) == 0) {
        king_set_error("king_pipeline_orchestrator_cancel_run() requires a non-empty run id.");
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_pipeline_orchestrator_cancel_run() requires a non-empty run id."
        );
        RETURN_THROWS();
    }

    if (!king_orchestrator_backend_is_file_worker()) {
        king_set_error("king_pipeline_orchestrator_cancel_run() requires orchestrator_execution_backend=file_worker.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_pipeline_orchestrator_cancel_run() requires orchestrator_execution_backend=file_worker."
        );
        RETURN_THROWS();
    }

    RETURN_BOOL(king_orchestrator_request_run_cancel(run_id) == SUCCESS);
}
