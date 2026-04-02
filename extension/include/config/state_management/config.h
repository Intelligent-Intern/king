/*
 * =========================================================================
 * FILENAME:   include/config/state_management/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the module-local helper for applying state-management overrides to
 * the live state-management config state.
 *
 * In the current build, the main `King\Config` snapshot pipeline does not
 * route through a dedicated state-management `*_apply_userland_config_to()`
 * helper, so this header describes the direct module helper rather than a
 * generic config-snapshot entry point.
 * =========================================================================
 */

#ifndef KING_CONFIG_STATE_MANAGEMENT_CONFIG_H
#define KING_CONFIG_STATE_MANAGEMENT_CONFIG_H

#include "php.h"

/**
 * @brief Applies state-management settings from a PHP array.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_state_management_apply_userland_config(zval *config_arr);

#endif /* KING_CONFIG_STATE_MANAGEMENT_CONFIG_H */
