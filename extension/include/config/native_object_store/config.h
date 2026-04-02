/*
 * =========================================================================
 * FILENAME:   include/config/native_object_store/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the native object-store config apply helpers for both the live
 * module-global runtime state and `King\Config` snapshot targets.
 *
 * ARCHITECTURE:
 * Native object store participates directly in the central config-snapshot
 * override pipeline through
 * `kg_config_native_object_store_apply_userland_config_to()`. The plain
 * `kg_config_native_object_store_apply_userland_config()` variant remains the
 * policy-gated helper for writing into the live module-global state.
 * =========================================================================
 */

#ifndef KING_CONFIG_NATIVE_OBJECT_STORE_CONFIG_H
#define KING_CONFIG_NATIVE_OBJECT_STORE_CONFIG_H

#include "php.h"
#include "include/config/native_object_store/base_layer.h"

/**
 * @brief Applies native object-store settings from a PHP array to live runtime state.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_native_object_store_apply_userland_config(zval *config_arr);

/**
 * @brief Applies native object-store settings from a PHP array to a target config struct.
 * @param target The target native object-store config snapshot to mutate.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_native_object_store_apply_userland_config_to(
    kg_native_object_store_config_t *target,
    zval *config_arr
);

#endif /* KING_CONFIG_NATIVE_OBJECT_STORE_CONFIG_H */
