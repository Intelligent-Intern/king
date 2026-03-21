#include "include/config/state_management/index.h"
#include "include/config/state_management/default.h"
#include "include/config/state_management/ini.h"

void kg_config_state_management_init(void)
{
    kg_config_state_management_defaults_load();
    kg_config_state_management_ini_register();
}

void kg_config_state_management_shutdown(void)
{
    kg_config_state_management_ini_unregister();
}
