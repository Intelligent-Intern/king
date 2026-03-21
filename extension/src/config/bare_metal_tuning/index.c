#include "include/config/bare_metal_tuning/index.h"
#include "include/config/bare_metal_tuning/default.h"
#include "include/config/bare_metal_tuning/ini.h"

void kg_config_bare_metal_tuning_init(void)
{
    kg_config_bare_metal_tuning_defaults_load();
    kg_config_bare_metal_tuning_ini_register();
}

void kg_config_bare_metal_tuning_shutdown(void)
{
    kg_config_bare_metal_tuning_ini_unregister();
}
