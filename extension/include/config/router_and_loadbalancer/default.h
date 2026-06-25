/*
 * =========================================================================
 * FILENAME:   include/config/router_and_loadbalancer/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for the Router & Load Balancer mode.
 * =========================================================================
 */

#ifndef KING_CONFIG_ROUTER_LOADBALANCER_DEFAULT_H
#define KING_CONFIG_ROUTER_LOADBALANCER_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_router_and_loadbalancer_defaults_load(void);

#endif /* KING_CONFIG_ROUTER_LOADBALANCER_DEFAULT_H */
