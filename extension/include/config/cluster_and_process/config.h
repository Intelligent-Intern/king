/*
 * =========================================================================
 * FILENAME:   include/config/cluster_and_process/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the module-local helper for applying cluster/process overrides to
 * the live cluster config state.
 *
 * ARCHITECTURE:
 * In the current build, the main `King\Config` snapshot pipeline does not
 * route through a dedicated `cluster_and_process` `*_apply_userland_config_to()`
 * helper, and this header is not a generic Admin-API entry point. It exposes
 * the direct module helper that validates and applies userland-shaped updates
 * onto the module-global runtime config.
 * =========================================================================
 */

#ifndef KING_CONFIG_CLUSTER_CONFIG_H
#define KING_CONFIG_CLUSTER_CONFIG_H

#include "php.h"

/**
 * @brief Applies cluster/process settings from a PHP array.
 * @details This helper checks userland-override policy, validates the supplied
 * values, and writes successful updates into the module-global
 * `king_cluster_config` state.
 *
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise (an exception will have been thrown).
 */
int kg_config_cluster_and_process_apply_userland_config(zval *config_arr);

#endif /* KING_CONFIG_CLUSTER_CONFIG_H */
