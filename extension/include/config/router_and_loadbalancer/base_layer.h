/*
 * =========================================================================
 * FILENAME:   include/config/router_and_loadbalancer/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for the router and load balancer module.
 *
 * ARCHITECTURE:
 * This struct stores routing, discovery, and forwarding settings.
 * =========================================================================
 */
#ifndef KING_CONFIG_ROUTER_LOADBALANCER_BASE_H
#define KING_CONFIG_ROUTER_LOADBALANCER_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_router_loadbalancer_config_t {
    /* --- Router Mode Activation --- */
    bool router_mode_enable;

    /* --- Backend Routing & Discovery --- */
    char *hashing_algorithm;
    char *connection_id_entropy_salt;
    char *backend_discovery_mode;
    char *backend_static_list;
    char *backend_mcp_endpoint;
    zend_long backend_mcp_poll_interval_sec;

    /* --- Performance & Security --- */
    zend_long max_forwarding_pps;

} kg_router_loadbalancer_config_t;

/* Module-global configuration instance. */
extern kg_router_loadbalancer_config_t king_router_loadbalancer_config;

#endif /* KING_CONFIG_ROUTER_LOADBALANCER_BASE_H */
