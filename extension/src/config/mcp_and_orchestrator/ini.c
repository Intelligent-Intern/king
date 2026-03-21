#include "include/config/mcp_and_orchestrator/ini.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>
#include <zend_ini.h>

static ZEND_INI_MH(OnUpdateMcpPositiveLong)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);
    if (val <= 0) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value for an MCP/Orchestrator directive. A positive integer is required."
        );
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.mcp_default_request_timeout_ms")) {
        king_mcp_orchestrator_config.mcp_default_request_timeout_ms = val;
    } else if (zend_string_equals_literal(entry->name, "king.mcp_max_message_size_bytes")) {
        king_mcp_orchestrator_config.mcp_max_message_size_bytes = val;
    } else if (zend_string_equals_literal(entry->name, "king.mcp_default_retry_max_attempts")) {
        king_mcp_orchestrator_config.mcp_default_retry_max_attempts = val;
    } else if (zend_string_equals_literal(entry->name, "king.mcp_default_retry_backoff_ms_initial")) {
        king_mcp_orchestrator_config.mcp_default_retry_backoff_ms_initial = val;
    } else if (zend_string_equals_literal(entry->name, "king.mcp_request_cache_ttl_sec")) {
        king_mcp_orchestrator_config.mcp_request_cache_ttl_sec = val;
    } else if (zend_string_equals_literal(entry->name, "king.orchestrator_default_pipeline_timeout_ms")) {
        king_mcp_orchestrator_config.orchestrator_default_pipeline_timeout_ms = val;
    } else if (zend_string_equals_literal(entry->name, "king.orchestrator_max_recursion_depth")) {
        king_mcp_orchestrator_config.orchestrator_max_recursion_depth = val;
    } else if (zend_string_equals_literal(entry->name, "king.orchestrator_loop_concurrency_default")) {
        king_mcp_orchestrator_config.orchestrator_loop_concurrency_default = val;
    }

    return SUCCESS;
}

PHP_INI_BEGIN()
    ZEND_INI_ENTRY("king.mcp_default_request_timeout_ms", "30000", PHP_INI_SYSTEM, OnUpdateMcpPositiveLong)
    ZEND_INI_ENTRY("king.mcp_max_message_size_bytes", "4194304", PHP_INI_SYSTEM, OnUpdateMcpPositiveLong)
    STD_PHP_INI_ENTRY("king.mcp_default_retry_policy_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, mcp_default_retry_policy_enable, kg_mcp_orchestrator_config_t, king_mcp_orchestrator_config)
    ZEND_INI_ENTRY("king.mcp_default_retry_max_attempts", "3", PHP_INI_SYSTEM, OnUpdateMcpPositiveLong)
    ZEND_INI_ENTRY("king.mcp_default_retry_backoff_ms_initial", "100", PHP_INI_SYSTEM, OnUpdateMcpPositiveLong)
    STD_PHP_INI_ENTRY("king.mcp_enable_request_caching", "0", PHP_INI_SYSTEM, OnUpdateBool, mcp_enable_request_caching, kg_mcp_orchestrator_config_t, king_mcp_orchestrator_config)
    ZEND_INI_ENTRY("king.mcp_request_cache_ttl_sec", "60", PHP_INI_SYSTEM, OnUpdateMcpPositiveLong)
    ZEND_INI_ENTRY("king.orchestrator_default_pipeline_timeout_ms", "120000", PHP_INI_SYSTEM, OnUpdateMcpPositiveLong)
    ZEND_INI_ENTRY("king.orchestrator_max_recursion_depth", "10", PHP_INI_SYSTEM, OnUpdateMcpPositiveLong)
    ZEND_INI_ENTRY("king.orchestrator_loop_concurrency_default", "50", PHP_INI_SYSTEM, OnUpdateMcpPositiveLong)
    STD_PHP_INI_ENTRY("king.orchestrator_enable_distributed_tracing", "1", PHP_INI_SYSTEM, OnUpdateBool, orchestrator_enable_distributed_tracing, kg_mcp_orchestrator_config_t, king_mcp_orchestrator_config)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_mcp_and_orchestrator_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_mcp_and_orchestrator_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
