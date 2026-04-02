/*
 * =========================================================================
 * FILENAME:   src/config/router_and_loadbalancer/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the router and load-balancer config family.
 * This slice seeds the baseline router-disabled state, hashing mode,
 * backend discovery placeholders, MCP poll cadence, and forwarding cap
 * before INI and any allowed userland overrides refine the live snapshot.
 * =========================================================================
 */

#include "include/config/router_and_loadbalancer/default.h"
#include "include/config/router_and_loadbalancer/base_layer.h"

void kg_config_router_and_loadbalancer_defaults_load(void)
{
    king_router_loadbalancer_config.router_mode_enable = false;
    king_router_loadbalancer_config.hashing_algorithm = NULL;
    king_router_loadbalancer_config.connection_id_entropy_salt = NULL;
    king_router_loadbalancer_config.backend_discovery_mode = NULL;
    king_router_loadbalancer_config.backend_static_list = NULL;
    king_router_loadbalancer_config.backend_mcp_endpoint = NULL;
    king_router_loadbalancer_config.backend_mcp_poll_interval_sec = 10;
    king_router_loadbalancer_config.max_forwarding_pps = 1000000;
}
