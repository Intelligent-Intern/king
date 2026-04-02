/*
 * =========================================================================
 * FILENAME:   include/config/dynamic_admin_api/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the module-local helper for applying dynamic admin-listener
 * overrides to the live admin-API config state.
 *
 * In the current build, the main `King\Config` snapshot pipeline does not
 * route through a dedicated dynamic-admin `*_apply_userland_config_to()`
 * helper, so this header describes the direct module helper rather than a
 * generic config-snapshot entry point.
 * =========================================================================
 */

#ifndef KING_CONFIG_DYNAMIC_ADMIN_API_CONFIG_H
#define KING_CONFIG_DYNAMIC_ADMIN_API_CONFIG_H

#include "php.h"

/**
 * @brief Applies dynamic admin-listener settings from a PHP array.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_dynamic_admin_api_apply_userland_config(zval *config_arr);

#endif /* KING_CONFIG_DYNAMIC_ADMIN_API_CONFIG_H */
