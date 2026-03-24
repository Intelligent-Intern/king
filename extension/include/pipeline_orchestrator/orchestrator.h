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

/* Pipeline Execution */
int king_orchestrator_run(zval *initial_data, zval *pipeline, zval *options, zval *return_value);

#endif /* KING_PIPELINE_ORCHESTRATOR_H */
