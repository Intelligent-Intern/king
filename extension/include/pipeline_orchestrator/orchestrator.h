/*
 * include/pipeline_orchestrator/orchestrator.h - Pipeline Orchestrator Core
 * =========================================================================
 *
 * This header defines the native tool registry and pipeline execution
 * entry points for the King orchestrator.
 */
#ifndef KING_PIPELINE_ORCHESTRATOR_H
#define KING_PIPELINE_ORCHESTRATOR_H

#include <php.h>

typedef struct _king_orchestrator_tool {
    zend_string *name;
    zval config;
    /* Handlers will be implemented as PHP callbacks or native hooks in later phases */
} king_orchestrator_tool_t;

/* Registry Management */
int king_orchestrator_registry_init(void);
void king_orchestrator_registry_shutdown(void);
int king_orchestrator_register_tool(const char *name, size_t name_len, zval *config);
zval *king_orchestrator_lookup_tool(const char *name, size_t name_len);
int king_orchestrator_configure_logging(zval *config);
size_t king_orchestrator_count_active_runs(void);
void king_orchestrator_append_component_info(zval *configuration);
int king_orchestrator_get_run_snapshot(zend_string *run_id, zval *return_value);
int king_orchestrator_load_run_payload(
    zend_string *run_id,
    zval *initial_data,
    zval *pipeline,
    zval *options
);
int king_orchestrator_enqueue_run(zend_string *run_id, zval *return_value);
int king_orchestrator_claim_next_run(zend_string **run_id_out, char *claimed_path, size_t claimed_path_len);

/* Pipeline Execution */
zend_string *king_orchestrator_pipeline_run_begin(
    zval *initial_data,
    zval *pipeline,
    zval *options
);
int king_orchestrator_pipeline_run_complete(zend_string *run_id, zval *result);
int king_orchestrator_pipeline_run_fail(zend_string *run_id, const char *error_message);
int king_orchestrator_run(zval *initial_data, zval *pipeline, zval *options, zval *return_value);
int king_orchestrator_dispatch(zval *initial_data, zval *pipeline, zval *options, zval *return_value);
int king_orchestrator_resume_run(zend_string *run_id, zval *return_value);
int king_orchestrator_worker_run_next(zval *return_value);

#endif /* KING_PIPELINE_ORCHESTRATOR_H */
