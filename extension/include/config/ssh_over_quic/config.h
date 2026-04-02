/*
 * =========================================================================
 * FILENAME:   include/config/ssh_over_quic/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the SSH-over-QUIC config apply helpers for both the live
 * module-global runtime state and `King\Config` snapshot targets.
 *
 * ARCHITECTURE:
 * SSH-over-QUIC participates directly in the central config-snapshot
 * override pipeline through
 * `kg_config_ssh_over_quic_apply_userland_config_to()`. The plain
 * `kg_config_ssh_over_quic_apply_userland_config()` variant remains the
 * policy-gated helper for writing into the live module-global state.
 * =========================================================================
 */

#ifndef KING_CONFIG_SSH_OVER_QUIC_CONFIG_H
#define KING_CONFIG_SSH_OVER_QUIC_CONFIG_H

#include "php.h"
#include "include/config/ssh_over_quic/base_layer.h"

/**
 * @brief Applies SSH-over-QUIC settings from a PHP array to the live runtime state.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_ssh_over_quic_apply_userland_config(zval *config_arr);

/**
 * @brief Applies SSH-over-QUIC settings from a PHP array to a target config struct.
 * @param target The target SSH-over-QUIC config snapshot to mutate.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_ssh_over_quic_apply_userland_config_to(
    kg_ssh_over_quic_config_t *target,
    zval *config_arr);

#endif /* KING_CONFIG_SSH_OVER_QUIC_CONFIG_H */
