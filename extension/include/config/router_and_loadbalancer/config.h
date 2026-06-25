/*
 * =========================================================================
 * FILENAME:   include/config/router_and_loadbalancer/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the module-local helper for applying router/load-balancer
 * overrides to the live router/load-balancer config state.
 *
 * In the current build, the main `King\Config` snapshot pipeline does not
 * route through a dedicated router/load-balancer `*_apply_userland_config_to()`
 * helper, so this header describes the direct module helper rather than a
 * generic config-snapshot entry point.
 * =========================================================================
 */

#ifndef KING_CONFIG_ROUTER_LOADBALANCER_CONFIG_H
#define KING_CONFIG_ROUTER_LOADBALANCER_CONFIG_H

#include "php.h"

/**
 * @brief Applies router/load-balancer settings from a PHP array.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_router_and_loadbalancer_apply_userland_config(zval *config_arr);

#endif /* KING_CONFIG_ROUTER_LOADBALANCER_CONFIG_H */
