/*
 * include/pipeline_orchestrator/pipeline_orchestrator.h - Pipeline orchestrator C API
 * ====================================================================================
 *
 * This header defines the C-side data structures and entry points used by
 * the pipeline orchestrator implementation.
 */

#ifndef KING_PIPELINE_ORCHESTRATOR_H
#define KING_PIPELINE_ORCHESTRATOR_H

#include <php.h>
#include "tool_handler_registry.h"

/* --- Pipeline Definition Types --- */

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

/* --- Orchestrator Lifecycle --- */

int king_pipeline_orchestrator_init_settings(void);

void king_pipeline_orchestrator_shutdown_settings(void);

/* Configures automatic logging from a PHP array. */
int king_pipeline_orchestrator_configure_auto_logging_from_php(zval *logger_config_php_array);

/* Registers a tool handler from PHP. */
int king_pipeline_orchestrator_register_tool_handler_from_php(const char *tool_name, zval *handler_config_php_array);

/* Runs a pipeline definition from PHP. */
PHP_FUNCTION(king_pipeline_orchestrator_run);

/* Registers a tool handler from PHP. */
PHP_FUNCTION(king_pipeline_orchestrator_register_tool);

/* Configures orchestrator logging from PHP. */
PHP_FUNCTION(king_pipeline_orchestrator_configure_logging);


#endif /* KING_PIPELINE_ORCHESTRATOR_H */
