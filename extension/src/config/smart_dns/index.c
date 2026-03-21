#include "include/config/smart_dns/index.h"
#include "include/config/smart_dns/default.h"
#include "include/config/smart_dns/ini.h"

void kg_config_smart_dns_init(void)
{
    kg_config_smart_dns_defaults_load();
    kg_config_smart_dns_ini_register();
}

void kg_config_smart_dns_shutdown(void)
{
    kg_config_smart_dns_ini_unregister();
}
