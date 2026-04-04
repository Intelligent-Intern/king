/*
 * include/pipeline_orchestrator/orchestrator.h - Pipeline Orchestrator Core
 * =========================================================================
 *
 * Active native orchestrator core for tool-registry persistence, run-state
 * persistence, and execution across the `local`, `file_worker`, and
 * `remote_peer` backends.
 */
#ifndef KING_PIPELINE_ORCHESTRATOR_CORE_H
#define KING_PIPELINE_ORCHESTRATOR_CORE_H

#include <php.h>

typedef struct _king_orchestrator_tool {
    zend_string *name;
    zval config;
    /* Persisted PHP tool configuration snapshot. */
} king_orchestrator_tool_t;

/* Registry and persisted run-state management */
int king_orchestrator_registry_init(void);
void king_orchestrator_registry_shutdown(void);
int king_orchestrator_runtime_handlers_init(void);
void king_orchestrator_runtime_handlers_shutdown(void);
int king_orchestrator_register_tool(const char *name, size_t name_len, zval *config);
zval *king_orchestrator_lookup_tool(const char *name, size_t name_len);
int king_orchestrator_register_tool_handler(const char *name, size_t name_len, zval *handler);
zval *king_orchestrator_lookup_tool_handler(const char *name, size_t name_len);
int king_orchestrator_configure_logging(zval *config);
size_t king_orchestrator_count_active_runs(void);
void king_orchestrator_append_component_info(zval *configuration);
int king_orchestrator_get_run_snapshot(zend_string *run_id, zval *return_value);
int king_orchestrator_load_run_payload(
    zend_string *run_id,
    zval *initial_data,
    zval *pipeline,
    zval *options,
    zval *telemetry_parent_context
);
int king_orchestrator_load_run_progress(
    zend_string *run_id,
    zval *result,
    zend_long *completed_step_count_out
);
int king_orchestrator_enqueue_run(zend_string *run_id, zval *return_value);
int king_orchestrator_claim_next_run(
    zend_string **run_id_out,
    char *claimed_path,
    size_t claimed_path_len,
    int *claimed_fd_out,
    zend_bool *recovered_claim_out
);
int king_orchestrator_request_run_cancel(zend_string *run_id);
int king_orchestrator_run_cancel_requested(zend_string *run_id);
void king_orchestrator_clear_run_cancel_request(zend_string *run_id);

/* Pipeline execution and persisted status transitions */
zend_string *king_orchestrator_pipeline_run_begin(
    zval *initial_data,
    zval *pipeline,
    zval *options,
    zval *telemetry_parent_context,
    const char *initial_status
);
int king_orchestrator_pipeline_run_mark_running(
    zend_string *run_id,
    zend_bool recovered_claim,
    zend_long claimed_by_pid
);
int king_orchestrator_pipeline_run_is_terminal(zend_string *run_id);
int king_orchestrator_pipeline_run_record_progress(
    zend_string *run_id,
    zend_long completed_step_count,
    zval *result
);
int king_orchestrator_pipeline_run_record_completed_steps(zend_string *run_id, zend_long completed_step_count);
int king_orchestrator_pipeline_run_note_recovery(zend_string *run_id, const char *reason);
int king_orchestrator_pipeline_run_note_remote_attempt(zend_string *run_id);
int king_orchestrator_pipeline_run_complete(zend_string *run_id, zval *result);
int king_orchestrator_pipeline_run_fail(zend_string *run_id, const char *error_message);
int king_orchestrator_pipeline_run_cancelled(zend_string *run_id, const char *error_message);
int king_orchestrator_pipeline_run_fail_classified(
    zend_string *run_id,
    const char *error_message,
    const char *error_category,
    const char *retry_disposition,
    zend_long step_index,
    const char *backend
);
int king_orchestrator_pipeline_run_cancelled_classified(
    zend_string *run_id,
    const char *error_message,
    const char *error_category,
    const char *retry_disposition,
    zend_long step_index,
    const char *backend
);
int king_orchestrator_run(zval *initial_data, zval *pipeline, zval *options, zval *return_value);
int king_orchestrator_dispatch(zval *initial_data, zval *pipeline, zval *options, zval *return_value);
int king_orchestrator_resume_run(
    zend_string *run_id,
    zval *return_value,
    const char *function_name,
    zend_bool throw_on_error
);
int king_orchestrator_worker_run_next(zval *return_value);

#endif /* KING_PIPELINE_ORCHESTRATOR_CORE_H */
