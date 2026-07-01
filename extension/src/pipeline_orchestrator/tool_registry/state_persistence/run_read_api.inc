int king_orchestrator_load_run_payload(
    zend_string *run_id,
    zval *initial_data,
    zval *pipeline,
    zval *options,
    zval *telemetry_parent_context)
{
    king_orchestrator_run_state_t *run_state;
    int lock_fd = -1;

    if (
        initial_data == NULL
        || pipeline == NULL
        || options == NULL
        || telemetry_parent_context == NULL
    ) {
        return FAILURE;
    }

    ZVAL_NULL(initial_data);
    ZVAL_NULL(pipeline);
    ZVAL_NULL(options);
    ZVAL_NULL(telemetry_parent_context);

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (
        king_orchestrator_decode_base64_zval(run_state->initial_data_b64, initial_data) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->pipeline_b64, pipeline) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->options_b64, options) != SUCCESS
        || (
            run_state->telemetry_parent_context_b64 != NULL
            && king_orchestrator_decode_base64_zval(
                run_state->telemetry_parent_context_b64,
                telemetry_parent_context
            ) != SUCCESS
        )
    ) {
        zval_ptr_dtor(initial_data);
        zval_ptr_dtor(pipeline);
        zval_ptr_dtor(options);
        zval_ptr_dtor(telemetry_parent_context);
        ZVAL_NULL(initial_data);
        ZVAL_NULL(pipeline);
        ZVAL_NULL(options);
        ZVAL_NULL(telemetry_parent_context);
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    king_orchestrator_state_lock_release(lock_fd);
    return SUCCESS;
}

int king_orchestrator_load_run_progress(
    zend_string *run_id,
    zval *result,
    zend_long *completed_step_count_out
)
{
    king_orchestrator_run_state_t *run_state;
    int lock_fd = -1;

    if (result == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(result);
    if (completed_step_count_out != NULL) {
        *completed_step_count_out = 0;
    }

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (completed_step_count_out != NULL) {
        *completed_step_count_out = run_state->completed_step_count;
    }

    if (
        run_state->result_b64 != NULL
        && king_orchestrator_decode_base64_zval(run_state->result_b64, result) != SUCCESS
    ) {
        zval_ptr_dtor(result);
        ZVAL_NULL(result);
        if (completed_step_count_out != NULL) {
            *completed_step_count_out = 0;
        }
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    king_orchestrator_state_lock_release(lock_fd);
    return SUCCESS;
}

int king_orchestrator_load_run_handler_boundary(zend_string *run_id, zval *return_value)
{
    king_orchestrator_run_state_t *run_state;
    int lock_fd = -1;

    if (return_value == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(return_value);

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (run_state->handler_boundary_b64 == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return SUCCESS;
    }

    if (
        king_orchestrator_decode_base64_zval(
            run_state->handler_boundary_b64,
            return_value
        ) != SUCCESS
    ) {
        zval_ptr_dtor(return_value);
        ZVAL_NULL(return_value);
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    king_orchestrator_state_lock_release(lock_fd);
    return SUCCESS;
}
