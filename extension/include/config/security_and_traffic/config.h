/*
 * =========================================================================
 * FILENAME:   include/config/security_and_traffic/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the module-local helper for applying security/traffic overrides
 * to the live security policy state.
 *
 * ARCHITECTURE:
 * In the current build, the namespaced `King\Config` snapshot override
 * pipeline does not route through a dedicated security
 * `*_apply_userland_config_to()` helper, and this function is not a generic
 * Admin-API entry point. It is the direct policy-gated helper for mutating
 * the live module-global security config.
 * =========================================================================
 */

#ifndef KING_CONFIG_SECURITY_CONFIG_H
#define KING_CONFIG_SECURITY_CONFIG_H

#include "php.h"

/**
 * @brief Applies security/traffic settings from a PHP array.
 * @details This helper checks the global override policy, validates the
 * supported security keys, and writes successful updates into the live
 * `king_security_config` module state.
 *
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise (an exception will have been thrown).
 */
int kg_config_security_apply_userland_config(zval *config_arr);

#endif /* KING_CONFIG_SECURITY_CONFIG_H */
