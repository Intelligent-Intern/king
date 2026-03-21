#include "include/config/dynamic_admin_api/index.h"
#include "include/config/dynamic_admin_api/default.h"
#include "include/config/dynamic_admin_api/ini.h"

void kg_config_dynamic_admin_api_init(void)
{
    kg_config_dynamic_admin_api_defaults_load();
    kg_config_dynamic_admin_api_ini_register();
}

void kg_config_dynamic_admin_api_shutdown(void)
{
    kg_config_dynamic_admin_api_ini_unregister();
}
