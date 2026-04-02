/*
 * =========================================================================
 * FILENAME:   src/config/mcp_and_orchestrator/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the MCP/orchestrator config family. This slice
 * seeds the baseline MCP timeout/retry/cache values plus orchestrator
 * timeout, recursion, concurrency, backend, queue, and remote-peer defaults
 * before INI and any allowed userland overrides refine the live snapshot.
 * =========================================================================
 */

#include "include/config/mcp_and_orchestrator/default.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"

void kg_config_mcp_and_orchestrator_defaults_load(void)
{
    king_mcp_orchestrator_config.mcp_default_request_timeout_ms = 30000;
    king_mcp_orchestrator_config.mcp_max_message_size_bytes = 4194304;
    king_mcp_orchestrator_config.mcp_default_retry_policy_enable = true;
    king_mcp_orchestrator_config.mcp_default_retry_max_attempts = 3;
    king_mcp_orchestrator_config.mcp_default_retry_backoff_ms_initial = 100;
    king_mcp_orchestrator_config.mcp_enable_request_caching = false;
    king_mcp_orchestrator_config.mcp_request_cache_ttl_sec = 60;
    king_mcp_orchestrator_config.mcp_allowed_peer_hosts = NULL;
    king_mcp_orchestrator_config.mcp_transfer_state_path = NULL;
    king_mcp_orchestrator_config.orchestrator_default_pipeline_timeout_ms = 120000;
    king_mcp_orchestrator_config.orchestrator_max_recursion_depth = 10;
    king_mcp_orchestrator_config.orchestrator_loop_concurrency_default = 50;
    king_mcp_orchestrator_config.orchestrator_enable_distributed_tracing = true;
    king_mcp_orchestrator_config.orchestrator_execution_backend = NULL;
    king_mcp_orchestrator_config.orchestrator_worker_queue_path = NULL;
    king_mcp_orchestrator_config.orchestrator_remote_host = NULL;
    king_mcp_orchestrator_config.orchestrator_remote_port = 9444;
    king_mcp_orchestrator_config.orchestrator_state_path = NULL;
}
