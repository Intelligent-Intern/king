/*
 * =========================================================================
 * FILENAME:   include/config/tls_and_crypto/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the TLS/crypto config apply helpers for both the live
 * module-global runtime state and `King\Config` snapshot targets.
 *
 * ARCHITECTURE:
 * TLS/crypto participates directly in the central config-snapshot override
 * pipeline through `kg_config_tls_and_crypto_apply_userland_config_to()`. The
 * plain `kg_config_tls_and_crypto_apply_userland_config()` variant remains the
 * policy-gated helper for writing into the live module-global state.
 * =========================================================================
 */

#ifndef KING_CONFIG_TLS_CRYPTO_CONFIG_H
#define KING_CONFIG_TLS_CRYPTO_CONFIG_H

#include "php.h"
#include "include/config/tls_and_crypto/base_layer.h"

/**
 * @brief Applies TLS/crypto settings from a PHP array to the live runtime state.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_tls_and_crypto_apply_userland_config(zval *config_arr);

/**
 * @brief Applies TLS/crypto settings from a PHP array to a target config struct.
 * @param target The target TLS/crypto config snapshot to mutate.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_tls_and_crypto_apply_userland_config_to(
    kg_tls_and_crypto_config_t *target,
    zval *config_arr
);

#endif /* KING_CONFIG_TLS_CRYPTO_CONFIG_H */
