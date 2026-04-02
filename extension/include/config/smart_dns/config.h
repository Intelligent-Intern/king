/*
 * =========================================================================
 * FILENAME:   include/config/smart_dns/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the Smart-DNS config apply helpers for both the live
 * module-global runtime state and `King\Config` snapshot targets.
 *
 * ARCHITECTURE:
 * Smart-DNS participates directly in the central config-snapshot override
 * pipeline through `kg_config_smart_dns_apply_userland_config_to()`. The
 * plain `kg_config_smart_dns_apply_userland_config()` variant remains the
 * policy-gated helper for writing into the live module-global state.
 *
 * In the current Smart-DNS v1 surface, this helper also enforces the exposed
 * subset explicitly: unsupported options are rejected and some settings stay
 * system-only.
 * =========================================================================
 */

#ifndef KING_CONFIG_SMART_DNS_CONFIG_H
#define KING_CONFIG_SMART_DNS_CONFIG_H

#include "php.h"
#include "include/config/smart_dns/base_layer.h"

/**
 * @brief Applies Smart-DNS settings from a PHP array to the live runtime state.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_smart_dns_apply_userland_config(zval *config_arr);

/**
 * @brief Applies Smart-DNS settings from a PHP array to a target config struct.
 * @param target The target Smart-DNS config snapshot to mutate.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_smart_dns_apply_userland_config_to(
    kg_smart_dns_config_t *target,
    zval *config_arr
);

#endif /* KING_CONFIG_SMART_DNS_CONFIG_H */
