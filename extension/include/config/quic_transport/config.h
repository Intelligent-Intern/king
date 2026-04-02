/*
 * =========================================================================
 * FILENAME:   include/config/quic_transport/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the QUIC transport config apply helpers for both the live
 * module-global runtime state and `King\Config` snapshot targets.
 *
 * ARCHITECTURE:
 * QUIC transport participates directly in the central config-snapshot
 * override pipeline through
 * `kg_config_quic_transport_apply_userland_config_to()`. The plain
 * `kg_config_quic_transport_apply_userland_config()` variant remains the
 * policy-gated helper for writing into the live module-global state.
 * =========================================================================
 */

#ifndef KING_CONFIG_QUIC_TRANSPORT_CONFIG_H
#define KING_CONFIG_QUIC_TRANSPORT_CONFIG_H

#include "php.h"
#include "include/config/quic_transport/base_layer.h"

/**
 * @brief Applies QUIC transport settings from a PHP array to the live runtime state.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_quic_transport_apply_userland_config(zval *config_arr);

/**
 * @brief Applies QUIC transport settings from a PHP array to a target config struct.
 * @param target The target QUIC transport config snapshot to mutate.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_quic_transport_apply_userland_config_to(
    kg_quic_transport_config_t *target,
    zval *config_arr
);

#endif /* KING_CONFIG_QUIC_TRANSPORT_CONFIG_H */
