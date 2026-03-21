/*
 * include/pipeline_orchestrator/tool_handler_registry.h - Tool handler registry API
 * =================================================================================
 *
 * This header defines the C-level types and functions used to register and
 * look up pipeline tool handlers.
 */

#ifndef KING_TOOL_HANDLER_REGISTRY_H
#define KING_TOOL_HANDLER_REGISTRY_H

#include <php.h>
#include "mcp.h"

/* --- MCP Target Configuration --- */
typedef struct _king_mcp_target_config_t {
    char *host;
    zend_long port;
    char *service_name;
    char *method_name;
    /* PHP array of MCP client options for this target. */
    zval mcp_client_options_php_array;
} king_mcp_target_config_t;

/* --- Parameter and Output Mapping --- */
typedef HashTable king_field_map_t; /* PHP: ['generic_param' => 'proto_field_name'] */

/* --- RAG Configuration --- */
typedef struct _king_rag_config_t {
    zend_bool enabled_by_default;
    char *enabled_param_key;
    king_mcp_target_config_t rag_agent_target;
    char *rag_request_proto_schema;
    char *rag_response_proto_schema;
    char *context_field_in_rag_response;
    char *target_context_field_in_llm_request;
    char *topics_from_param_key;
    struct {
        char *source_tool_name_or_id;
        char *source_field_name;
    } topics_from_previous_step;
    king_field_map_t *rag_param_map;
} king_rag_config_t;

/* --- Tool Handler Configuration --- */
typedef struct _king_tool_handler_config_t {
    char *tool_name;
    king_mcp_target_config_t mcp_target;
    char *input_proto_schema;
    char *output_proto_schema;
    king_field_map_t *param_map;
    king_field_map_t *output_map;
    king_rag_config_t *rag_config;
} king_tool_handler_config_t;

/* --- Registry API --- */
int king_tool_handler_registry_init(void);

void king_tool_handler_registry_shutdown(void);

/* Registers a tool handler from a PHP array. */
int king_tool_handler_register_from_php(const char *tool_name, zval *config_php_array);

/* Returns a registry-owned tool handler config, or NULL if not found. */
const king_tool_handler_config_t* king_tool_handler_get(const char *tool_name);

#endif /* KING_TOOL_HANDLER_REGISTRY_H */
