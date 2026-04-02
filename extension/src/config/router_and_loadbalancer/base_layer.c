/*
 * =========================================================================
 * FILENAME:   src/config/router_and_loadbalancer/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the router and load-balancer
 * config family. Router enablement, hashing mode, connection-ID salt,
 * backend discovery/static inventory, MCP polling cadence, and forwarding
 * caps all land in the single `king_router_loadbalancer_config` snapshot.
 * =========================================================================
 */

#include "include/config/router_and_loadbalancer/base_layer.h"

kg_router_loadbalancer_config_t king_router_loadbalancer_config;
