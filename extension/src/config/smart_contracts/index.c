#include "include/config/smart_contracts/index.h"
#include "include/config/smart_contracts/default.h"
#include "include/config/smart_contracts/ini.h"

void kg_config_smart_contracts_init(void)
{
    kg_config_smart_contracts_defaults_load();
    kg_config_smart_contracts_ini_register();
}

void kg_config_smart_contracts_shutdown(void)
{
    kg_config_smart_contracts_ini_unregister();
}
