/*
 * =========================================================================
 * FILENAME:   include/config/smart_contracts/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the smart-contract config apply helpers for both the live
 * module-global runtime state and `King\Config` snapshot targets.
 *
 * ARCHITECTURE:
 * Smart contracts participates directly in the central config-snapshot
 * override pipeline through
 * `kg_config_smart_contracts_apply_userland_config_to()`. The plain
 * `kg_config_smart_contracts_apply_userland_config()` variant remains the
 * policy-gated helper for writing into the live module-global state.
 * =========================================================================
 */

#ifndef KING_CONFIG_SMART_CONTRACTS_CONFIG_H
#define KING_CONFIG_SMART_CONTRACTS_CONFIG_H

#include "php.h"
#include "include/config/smart_contracts/base_layer.h"

/**
 * @brief Applies smart-contract settings from a PHP array to the live runtime state.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_smart_contracts_apply_userland_config(zval *config_arr);

/**
 * @brief Applies smart-contract settings from a PHP array to a target config struct.
 * @param target The target smart-contract config snapshot to mutate.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_smart_contracts_apply_userland_config_to(
    kg_smart_contracts_config_t *target,
    zval *config_arr);

#endif /* KING_CONFIG_SMART_CONTRACTS_CONFIG_H */
