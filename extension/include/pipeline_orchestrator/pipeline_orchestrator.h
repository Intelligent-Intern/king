/*
 * include/pipeline_orchestrator/pipeline_orchestrator.h - Pipeline orchestrator C API
 * ====================================================================================
 *
 * Thin PHP-surface anchor for the pipeline orchestrator entry points.
 * The active runtime and persisted run-state helpers live in
 * `orchestrator.h`; this header only keeps the high-level surface grouped
 * together for extension-facing includes.
 */

#ifndef KING_PIPELINE_ORCHESTRATOR_SURFACE_H
#define KING_PIPELINE_ORCHESTRATOR_SURFACE_H

#include <php.h>
#include "tool_handler_registry.h"

/* Legacy planning structs kept as schema notes; the active build consumes
 * PHP arrays directly instead of materializing these C-side shapes. */

typedef struct _king_pipeline_step_def_c {
    char *step_id_or_tool_name;
    char *tool_name;
    zval params_php_array;
    zval input_map_php_array;
    zend_bool condition_true_only;
} king_pipeline_step_def_c;

typedef struct _king_pipeline_def_c {
    king_pipeline_step_def_c *steps;
    size_t num_steps;
} king_pipeline_def_c;

typedef struct _king_pipeline_exec_options_c {
    zend_long overall_timeout_ms;
    zend_bool fail_fast;
} king_pipeline_exec_options_c;

/* --- Exported PHP Entry Points --- */

/* Runs one pipeline immediately on the configured backend. */
PHP_FUNCTION(king_pipeline_orchestrator_run);

/* Queues one pipeline run for the file-worker backend. */
PHP_FUNCTION(king_pipeline_orchestrator_dispatch);

/* Registers or replaces one persisted tool configuration. */
PHP_FUNCTION(king_pipeline_orchestrator_register_tool);

/* Registers or replaces one process-local executable handler binding. */
PHP_FUNCTION(king_pipeline_orchestrator_register_handler);

/* Persists the active orchestrator logging snapshot. */
PHP_FUNCTION(king_pipeline_orchestrator_configure_logging);

/* Claims and runs the next queued file-worker job. */
PHP_FUNCTION(king_pipeline_orchestrator_worker_run_next);

/* Resumes one persisted running run on local or remote-peer backends. */
PHP_FUNCTION(king_pipeline_orchestrator_resume_run);

/* Reads one persisted run snapshot. */
PHP_FUNCTION(king_pipeline_orchestrator_get_run);

/* Requests cancellation for one persisted file-worker run. */
PHP_FUNCTION(king_pipeline_orchestrator_cancel_run);

#endif /* KING_PIPELINE_ORCHESTRATOR_SURFACE_H */
