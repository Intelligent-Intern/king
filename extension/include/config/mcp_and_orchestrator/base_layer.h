/*
 * =========================================================================
 * FILENAME:   include/config/mcp_and_orchestrator/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for MCP and the pipeline orchestrator.
 *
 * ARCHITECTURE:
 * This struct stores the MCP transport settings and orchestrator defaults.
 * =========================================================================
 */
#ifndef KING_CONFIG_MCP_ORCHESTRATOR_BASE_H
#define KING_CONFIG_MCP_ORCHESTRATOR_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_mcp_orchestrator_config_t {
    /* --- MCP (Model Context Protocol) Settings --- */
    zend_long mcp_default_request_timeout_ms;
    zend_long mcp_max_message_size_bytes;
    bool mcp_default_retry_policy_enable;
    zend_long mcp_default_retry_max_attempts;
    zend_long mcp_default_retry_backoff_ms_initial;
    bool mcp_enable_request_caching;
    zend_long mcp_request_cache_ttl_sec;

    /* --- Pipeline Orchestrator Settings --- */
    zend_long orchestrator_default_pipeline_timeout_ms;
    zend_long orchestrator_max_recursion_depth;
    zend_long orchestrator_loop_concurrency_default;
    bool orchestrator_enable_distributed_tracing;
    char *orchestrator_state_path;

} kg_mcp_orchestrator_config_t;

/* Module-global configuration instance. */
extern kg_mcp_orchestrator_config_t king_mcp_orchestrator_config;

#endif /* KING_CONFIG_MCP_ORCHESTRATOR_BASE_H */
