/*
 * =========================================================================
 * FILENAME:   include/config/bare_metal_tuning/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for applying runtime
 * configuration changes from PHP userland to the bare metal tuning module.
 * =========================================================================
 */

#ifndef KING_CONFIG_BARE_METAL_CONFIG_H
#define KING_CONFIG_BARE_METAL_CONFIG_H

#include "php.h"

/**
 * @brief Applies runtime configuration settings from a PHP array.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_bare_metal_tuning_apply_userland_config(zval *config_arr);

#endif /* KING_CONFIG_BARE_METAL_CONFIG_H */
