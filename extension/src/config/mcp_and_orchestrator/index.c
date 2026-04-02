/*
 * =========================================================================
 * FILENAME:   src/config/mcp_and_orchestrator/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the MCP/orchestrator config family. This file
 * wires together default loading and INI registration during module init and
 * unregisters the INI surface again during shutdown.
 * =========================================================================
 */

#include "include/config/mcp_and_orchestrator/index.h"
#include "include/config/mcp_and_orchestrator/default.h"
#include "include/config/mcp_and_orchestrator/ini.h"

void kg_config_mcp_and_orchestrator_init(void)
{
    kg_config_mcp_and_orchestrator_defaults_load();
    kg_config_mcp_and_orchestrator_ini_register();
}

void kg_config_mcp_and_orchestrator_shutdown(void)
{
    kg_config_mcp_and_orchestrator_ini_unregister();
}
