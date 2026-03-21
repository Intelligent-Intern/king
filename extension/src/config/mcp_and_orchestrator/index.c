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
