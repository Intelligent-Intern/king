/*
 * =========================================================================
 * FILENAME:   include/config/bare_metal_tuning/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the module-local helper for applying bare-metal tuning overrides to
 * the live bare-metal config state.
 *
 * In the current build, the main `King\Config` snapshot pipeline does not
 * route through a dedicated bare-metal `*_apply_userland_config_to()` helper,
 * so this header describes the direct module helper rather than a generic
 * config-snapshot entry point.
 * =========================================================================
 */

#ifndef KING_CONFIG_BARE_METAL_CONFIG_H
#define KING_CONFIG_BARE_METAL_CONFIG_H

#include "php.h"

/**
 * @brief Applies bare-metal tuning settings from a PHP array.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_bare_metal_tuning_apply_userland_config(zval *config_arr);

#endif /* KING_CONFIG_BARE_METAL_CONFIG_H */
