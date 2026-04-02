/*
 * =========================================================================
 * FILENAME:   src/config/router_and_loadbalancer/index.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Lifecycle entry points for the router and load-balancer config family.
 * This file wires together default loading and INI registration during
 * module init and unregisters the INI surface again during shutdown.
 * =========================================================================
 */

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
