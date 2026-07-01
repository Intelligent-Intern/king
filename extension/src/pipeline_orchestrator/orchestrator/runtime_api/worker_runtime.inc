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
