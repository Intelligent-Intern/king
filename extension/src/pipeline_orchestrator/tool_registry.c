/*
 * src/pipeline_orchestrator/tool_registry.c - Native Tool Handler Registry
 * =========================================================================
 *
 * This module manages the global registry of available orchestrator tools.
 * Tools are stored as metadata and configuration zvals for future execution.
 */
#include "php_king.h"
#include "include/pipeline_orchestrator/orchestrator.h"
#include <zend_hash.h>

static HashTable king_orchestrator_tool_registry;
static bool king_orchestrator_registry_initialized = false;

static void king_orchestrator_tool_dtor(zval *zv)
{
    king_orchestrator_tool_t *tool = Z_PTR_P(zv);
    if (tool) {
        if (tool->name) zend_string_release(tool->name);
        zval_ptr_dtor(&tool->config);
        efree(tool);
    }
}

int king_orchestrator_registry_init(void)
{
    if (king_orchestrator_registry_initialized) return SUCCESS;
    
    zend_hash_init(&king_orchestrator_tool_registry, 16, NULL, king_orchestrator_tool_dtor, 0);
    king_orchestrator_registry_initialized = true;
    return SUCCESS;
}

void king_orchestrator_registry_shutdown(void)
{
    if (!king_orchestrator_registry_initialized) return;
    
    zend_hash_destroy(&king_orchestrator_tool_registry);
    king_orchestrator_registry_initialized = false;
}

int king_orchestrator_register_tool(const char *name, size_t name_len, zval *config)
{
    if (!king_orchestrator_registry_initialized) {
        king_orchestrator_registry_init();
    }
    
    king_orchestrator_tool_t *tool = emalloc(sizeof(king_orchestrator_tool_t));
    tool->name = zend_string_init(name, name_len, 0);
    ZVAL_UNDEF(&tool->config);
    if (config) {
        ZVAL_COPY(&tool->config, config);
    }
    
    zval val;
    ZVAL_PTR(&val, tool);
    
    if (zend_hash_str_update(&king_orchestrator_tool_registry, name, name_len, &val) != NULL) {
        return SUCCESS;
    }
    
    return FAILURE;
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
        RETURN_FALSE;
    }

    if (king_orchestrator_register_tool(tool_name, tool_name_len, config) == SUCCESS) {
        RETURN_TRUE;
    }
    
    RETURN_FALSE;
}
