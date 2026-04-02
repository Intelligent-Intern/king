/*
 * include/pipeline_orchestrator/tool_handler_registry.h - Tool handler registry API
 * =================================================================================
 *
 * Legacy declarative tool-configuration shapes for the orchestrator.
 * The active runtime persists raw PHP tool-config arrays through the core
 * orchestrator registry instead of instantiating a separate C registry layer
 * from this header.
 */

#ifndef KING_PIPELINE_TOOL_HANDLER_REGISTRY_H
#define KING_PIPELINE_TOOL_HANDLER_REGISTRY_H

#include <php.h>
#include "mcp/mcp.h"

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

#endif /* KING_PIPELINE_TOOL_HANDLER_REGISTRY_H */
