#include "include/config/mcp_and_orchestrator/default.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"

static char *king_persistent_strdup(const char *value)
{
    return pestrdup(value, 1);
}

void kg_config_mcp_and_orchestrator_defaults_load(void)
{
    king_mcp_orchestrator_config.mcp_default_request_timeout_ms = 30000;
    king_mcp_orchestrator_config.mcp_max_message_size_bytes = 4194304;
    king_mcp_orchestrator_config.mcp_default_retry_policy_enable = true;
    king_mcp_orchestrator_config.mcp_default_retry_max_attempts = 3;
    king_mcp_orchestrator_config.mcp_default_retry_backoff_ms_initial = 100;
    king_mcp_orchestrator_config.mcp_enable_request_caching = false;
    king_mcp_orchestrator_config.mcp_request_cache_ttl_sec = 60;
    king_mcp_orchestrator_config.orchestrator_default_pipeline_timeout_ms = 120000;
    king_mcp_orchestrator_config.orchestrator_max_recursion_depth = 10;
    king_mcp_orchestrator_config.orchestrator_loop_concurrency_default = 50;
    king_mcp_orchestrator_config.orchestrator_enable_distributed_tracing = true;
    king_mcp_orchestrator_config.orchestrator_execution_backend = king_persistent_strdup("local");
    king_mcp_orchestrator_config.orchestrator_worker_queue_path = king_persistent_strdup("");
    king_mcp_orchestrator_config.orchestrator_state_path = king_persistent_strdup("");
}
