/*
 * =========================================================================
 * FILENAME:   src/config/mcp_and_orchestrator/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the MCP/orchestrator config
 * family. MCP timeouts, retry/cache policy, peer-host and transfer-state
 * paths, plus orchestrator backend, recursion, timeout, queue, remote-peer,
 * and state-path settings all land in `king_mcp_orchestrator_config`.
 * =========================================================================
 */

#include "include/config/mcp_and_orchestrator/base_layer.h"

kg_mcp_orchestrator_config_t king_mcp_orchestrator_config;
