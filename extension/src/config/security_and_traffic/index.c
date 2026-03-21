#include "include/config/security_and_traffic/index.h"
#include "include/config/security_and_traffic/default.h"
#include "include/config/security_and_traffic/ini.h"

void kg_config_security_and_traffic_init(void)
{
    kg_config_security_defaults_load();
    kg_config_security_ini_register();
}

void kg_config_security_and_traffic_shutdown(void)
{
    kg_config_security_ini_unregister();
}
