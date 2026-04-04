int king_orchestrator_registry_init(void)
{
    if (king_orchestrator_registry_initialized) {
        return SUCCESS;
    }

    zend_hash_init(
        &king_orchestrator_tool_registry,
        16,
        NULL,
        king_orchestrator_persistent_string_zval_dtor,
        1
    );
    zend_hash_init(
        &king_orchestrator_pipeline_runs,
        16,
        NULL,
        king_orchestrator_run_state_zval_dtor,
        1
    );
    king_orchestrator_logging_config_b64 = NULL;
    king_orchestrator_last_run_id = NULL;
    king_orchestrator_last_run_status = NULL;
    king_orchestrator_next_run_id = 1;
    king_orchestrator_recovered_from_state = false;
    king_orchestrator_registry_initialized = true;

    return king_orchestrator_load_state();
}

void king_orchestrator_registry_shutdown(void)
{
    if (!king_orchestrator_registry_initialized) {
        return;
    }

    zend_hash_destroy(&king_orchestrator_tool_registry);
    zend_hash_destroy(&king_orchestrator_pipeline_runs);

    if (king_orchestrator_logging_config_b64 != NULL) {
        zend_string_release_ex(king_orchestrator_logging_config_b64, 1);
        king_orchestrator_logging_config_b64 = NULL;
    }
    if (king_orchestrator_last_run_id != NULL) {
        zend_string_release_ex(king_orchestrator_last_run_id, 1);
        king_orchestrator_last_run_id = NULL;
    }
    if (king_orchestrator_last_run_status != NULL) {
        zend_string_release_ex(king_orchestrator_last_run_status, 1);
        king_orchestrator_last_run_status = NULL;
    }

    king_orchestrator_registry_initialized = false;
    king_orchestrator_recovered_from_state = false;
    king_orchestrator_next_run_id = 1;
}

int king_orchestrator_register_tool(const char *name, size_t name_len, zval *config)
{
    zend_string *config_b64;
    zend_string *persistent_name;
    zval stored_config;
    int lock_fd = -1;
    int rc;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    config_b64 = king_orchestrator_encode_zval_base64(config);
    if (config_b64 == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    ZVAL_STR(&stored_config, zend_string_dup(config_b64, 1));
    persistent_name = zend_string_init(name, name_len, 1);
    zend_hash_update(&king_orchestrator_tool_registry, persistent_name, &stored_config);
    zend_string_release_ex(persistent_name, 1);
    zend_string_release(config_b64);

    rc = king_orchestrator_persist_state_locked();
    king_orchestrator_state_lock_release(lock_fd);

    return rc;
}

zval *king_orchestrator_lookup_tool(const char *name, size_t name_len)
{
    int lock_fd = -1;
    zval *entry;

    if (!king_orchestrator_registry_initialized) {
        if (king_orchestrator_registry_init() != SUCCESS) {
            return NULL;
        }
    }

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return NULL;
    }

    entry = zend_hash_str_find(&king_orchestrator_tool_registry, name, name_len);
    king_orchestrator_state_lock_release(lock_fd);

    return entry;
}

int king_orchestrator_configure_logging(zval *config)
{
    zend_string *config_b64;
    int lock_fd = -1;
    int rc;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    config_b64 = king_orchestrator_encode_zval_base64(config);
    if (config_b64 == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    king_orchestrator_replace_runtime_string(
        &king_orchestrator_logging_config_b64,
        zend_string_dup(config_b64, 1)
    );
    zend_string_release(config_b64);

    rc = king_orchestrator_persist_state_locked();
    king_orchestrator_state_lock_release(lock_fd);

    return rc;
}

zend_string *king_orchestrator_pipeline_run_begin(
    zval *initial_data,
    zval *pipeline,
    zval *options,
    zval *telemetry_parent_context,
    const char *initial_status)
{
    king_orchestrator_run_state_t *run_state;
    zval stored_run;
    zend_string *initial_data_b64;
    zend_string *pipeline_b64;
    zend_string *options_b64;
    zend_string *telemetry_parent_context_b64;
    zend_string *result_b64;
    zend_string *error_b64;
    zend_string *run_id;
    int lock_fd = -1;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return NULL;
    }

    initial_data_b64 = king_orchestrator_encode_zval_base64(initial_data);
    pipeline_b64 = king_orchestrator_encode_zval_base64(pipeline);
    options_b64 = king_orchestrator_encode_zval_base64(options);
    telemetry_parent_context_b64 = telemetry_parent_context != NULL
        ? king_orchestrator_encode_zval_base64(telemetry_parent_context)
        : king_orchestrator_encode_zval_base64(NULL);

    {
        zval null_value;

        ZVAL_NULL(&null_value);
        result_b64 = king_orchestrator_encode_zval_base64(&null_value);
        error_b64 = king_orchestrator_encode_zval_base64(&null_value);
    }

    if (
        initial_data_b64 == NULL
        || pipeline_b64 == NULL
        || options_b64 == NULL
        || telemetry_parent_context_b64 == NULL
        || result_b64 == NULL
        || error_b64 == NULL
    ) {
        if (initial_data_b64 != NULL) {
            zend_string_release(initial_data_b64);
        }
        if (pipeline_b64 != NULL) {
            zend_string_release(pipeline_b64);
        }
        if (options_b64 != NULL) {
            zend_string_release(options_b64);
        }
        if (telemetry_parent_context_b64 != NULL) {
            zend_string_release(telemetry_parent_context_b64);
        }
        if (result_b64 != NULL) {
            zend_string_release(result_b64);
        }
        if (error_b64 != NULL) {
            zend_string_release(error_b64);
        }
        king_orchestrator_state_lock_release(lock_fd);
        return NULL;
    }

    if (initial_status == NULL || initial_status[0] == '\0') {
        initial_status = "running";
    }

    run_id = strpprintf(0, "run-%ld", king_orchestrator_next_run_id++);
    run_state = pemalloc(sizeof(*run_state), 1);
    memset(run_state, 0, sizeof(*run_state));

    run_state->run_id = zend_string_dup(run_id, 1);
    run_state->status = zend_string_init(initial_status, strlen(initial_status), 1);
    run_state->execution_backend = zend_string_init(
        king_orchestrator_current_execution_backend(),
        strlen(king_orchestrator_current_execution_backend()),
        1
    );
    run_state->queue_phase = zend_string_init("not_queued", sizeof("not_queued") - 1, 1);
    run_state->started_at = time(NULL);
    run_state->finished_at = 0;
    run_state->enqueued_at = 0;
    run_state->last_claimed_at = 0;
    run_state->last_recovered_at = 0;
    run_state->last_remote_attempt_at = 0;
    run_state->cancel_requested = 0;
    run_state->initial_data_b64 = zend_string_dup(initial_data_b64, 1);
    run_state->pipeline_b64 = zend_string_dup(pipeline_b64, 1);
    run_state->options_b64 = zend_string_dup(options_b64, 1);
    run_state->telemetry_parent_context_b64 = zend_string_dup(telemetry_parent_context_b64, 1);
    run_state->result_b64 = zend_string_dup(result_b64, 1);
    run_state->error_b64 = zend_string_dup(error_b64, 1);
    run_state->error_category = NULL;
    run_state->retry_disposition = NULL;
    run_state->error_backend = NULL;
    run_state->last_recovery_reason = NULL;
    run_state->error_step_index = -1;
    run_state->completed_step_count = 0;
    run_state->claim_count = 0;
    run_state->recovery_count = 0;
    run_state->remote_attempt_count = 0;
    run_state->last_claimed_by_pid = 0;

    zend_string_release(initial_data_b64);
    zend_string_release(pipeline_b64);
    zend_string_release(options_b64);
    zend_string_release(telemetry_parent_context_b64);
    zend_string_release(result_b64);
    zend_string_release(error_b64);

    ZVAL_PTR(&stored_run, run_state);
    {
        zend_string *persistent_key = zend_string_dup(run_state->run_id, 1);

        zend_hash_update(&king_orchestrator_pipeline_runs, persistent_key, &stored_run);
        zend_string_release_ex(persistent_key, 1);
    }
    king_orchestrator_set_last_run(run_state->run_id, initial_status);

    if (king_orchestrator_persist_state_locked() != SUCCESS) {
        zend_hash_del(&king_orchestrator_pipeline_runs, run_state->run_id);
        king_orchestrator_state_lock_release(lock_fd);
        zend_string_release(run_id);
        return NULL;
    }

    king_orchestrator_state_lock_release(lock_fd);
    return run_id;
}

int king_orchestrator_pipeline_run_mark_running(
    zend_string *run_id,
    zend_bool recovered_claim,
    zend_long claimed_by_pid
)
{
    king_orchestrator_run_state_t *run_state;
    time_t now;
    int lock_fd = -1;
    int rc;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL || run_state->status == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (king_orchestrator_run_state_is_terminal(run_state)) {
        king_orchestrator_state_lock_release(lock_fd);
        return SUCCESS;
    }

    if (!zend_string_equals_literal(run_state->status, "running")) {
        king_orchestrator_replace_runtime_string(
            &run_state->status,
            zend_string_init("running", sizeof("running") - 1, 1)
        );
        run_state->finished_at = 0;
        king_orchestrator_run_state_clear_error_classification(run_state);
        king_orchestrator_set_last_run(run_state->run_id, "running");
    }

    now = time(NULL);
    run_state->claim_count++;
    run_state->last_claimed_at = now;
    run_state->last_claimed_by_pid = claimed_by_pid;
    king_orchestrator_run_state_replace_string_literal(&run_state->queue_phase, "claimed");

    if (recovered_claim) {
        run_state->recovery_count++;
        run_state->last_recovered_at = now;
        king_orchestrator_run_state_replace_string_literal(
            &run_state->last_recovery_reason,
            "claimed_job_recovery"
        );
    }

    rc = king_orchestrator_persist_state_locked();
    king_orchestrator_state_lock_release(lock_fd);

    return rc;
}

int king_orchestrator_pipeline_run_is_terminal(zend_string *run_id)
{
    int lock_fd = -1;
    int is_terminal;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return 0;
    }

    is_terminal = king_orchestrator_run_state_is_terminal(king_orchestrator_find_run(run_id));
    king_orchestrator_state_lock_release(lock_fd);

    return is_terminal;
}

int king_orchestrator_pipeline_run_record_completed_steps(
    zend_string *run_id,
    zend_long completed_step_count
)
{
    king_orchestrator_run_state_t *run_state;
    int lock_fd = -1;
    int rc;

    if (completed_step_count < 0) {
        completed_step_count = 0;
    }

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (completed_step_count <= run_state->completed_step_count) {
        king_orchestrator_state_lock_release(lock_fd);
        return SUCCESS;
    }

    run_state->completed_step_count = completed_step_count;
    rc = king_orchestrator_persist_state_locked();
    king_orchestrator_state_lock_release(lock_fd);

    return rc;
}

int king_orchestrator_pipeline_run_complete(zend_string *run_id, zval *result)
{
    king_orchestrator_run_state_t *run_state;
    zend_string *error_b64;
    zend_string *result_b64;
    zval null_value;
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

    result_b64 = king_orchestrator_encode_zval_base64(result);
    if (result_b64 == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    ZVAL_NULL(&null_value);
    error_b64 = king_orchestrator_encode_zval_base64(&null_value);
    if (error_b64 == NULL) {
        zend_string_release(result_b64);
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    king_orchestrator_replace_runtime_string(
        &run_state->status,
        zend_string_init("completed", sizeof("completed") - 1, 1)
    );
    run_state->finished_at = time(NULL);
    run_state->cancel_requested = 0;
    if (strcmp(king_orchestrator_run_execution_backend(run_state), "file_worker") == 0) {
        king_orchestrator_run_state_replace_string_literal(&run_state->queue_phase, "dequeued");
    }
    king_orchestrator_replace_runtime_string(&run_state->result_b64, zend_string_dup(result_b64, 1));
    king_orchestrator_replace_runtime_string(&run_state->error_b64, zend_string_dup(error_b64, 1));
    king_orchestrator_run_state_clear_error_classification(run_state);
    zend_string_release(result_b64);
    zend_string_release(error_b64);
    king_orchestrator_set_last_run(run_state->run_id, "completed");

    rc = king_orchestrator_persist_state_locked();
    king_orchestrator_state_lock_release(lock_fd);
    if (rc != SUCCESS) {
        return FAILURE;
    }

    king_orchestrator_clear_run_cancel_request(run_state->run_id);
    return SUCCESS;
}
