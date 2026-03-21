#include "include/config/open_telemetry/index.h"
#include "include/config/open_telemetry/default.h"
#include "include/config/open_telemetry/ini.h"

void kg_config_open_telemetry_init(void)
{
    kg_config_open_telemetry_defaults_load();
    kg_config_open_telemetry_ini_register();
}

void kg_config_open_telemetry_shutdown(void)
{
    kg_config_open_telemetry_ini_unregister();
}
