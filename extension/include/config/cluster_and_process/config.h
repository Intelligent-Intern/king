/*
 * =========================================================================
 * FILENAME:   include/config/cluster_and_process/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for applying runtime
 * configuration changes from PHP userland to the `cluster_and_process`
 * module.
 *
 * ARCHITECTURE:
 * The function declared here is the single entry point for all runtime
 * configuration modifications for this module, originating from either a
 * `King\Config` object or the Admin API. It encapsulates the process of
 * checking permissions, validating input, and applying settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_CLUSTER_CONFIG_H
#define KING_CONFIG_CLUSTER_CONFIG_H

#include "php.h"

/**
 * @brief Applies runtime configuration settings from a PHP array.
 * @details This is a high-level function called at runtime. It performs:
 * 1. Checks the global `is_userland_override_allowed` flag.
 * 2. Iterates through the provided `config_arr`.
 * 3. For each key, it retrieves the value and passes it to a dedicated,
 * robust validation function from the central validation directory.
 * 4. Only if validation succeeds is the new value applied.
 *
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise (an exception will have been thrown).
 */
int kg_config_cluster_and_process_apply_userland_config(zval *config_arr);

#endif /* KING_CONFIG_CLUSTER_CONFIG_H */
