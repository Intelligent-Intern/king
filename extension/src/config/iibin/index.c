#include "include/config/iibin/index.h"
#include "include/config/iibin/default.h"
#include "include/config/iibin/ini.h"

void kg_config_iibin_init(void)
{
    kg_config_iibin_defaults_load();
    kg_config_iibin_ini_register();
}

void kg_config_iibin_shutdown(void)
{
    kg_config_iibin_ini_unregister();
}
