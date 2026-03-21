#include "include/config/router_and_loadbalancer/index.h"
#include "include/config/router_and_loadbalancer/default.h"
#include "include/config/router_and_loadbalancer/ini.h"

void kg_config_router_and_loadbalancer_init(void)
{
    kg_config_router_and_loadbalancer_defaults_load();
    kg_config_router_and_loadbalancer_ini_register();
}

void kg_config_router_and_loadbalancer_shutdown(void)
{
    kg_config_router_and_loadbalancer_ini_unregister();
}
