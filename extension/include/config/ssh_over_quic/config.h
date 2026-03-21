/*
 * =========================================================================
 * FILENAME:   include/config/ssh_over_quic/config.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for applying runtime
 * configuration changes from PHP userland to the SSH over QUIC module.
 * =========================================================================
 */

#ifndef KING_CONFIG_SSH_OVER_QUIC_CONFIG_H
#define KING_CONFIG_SSH_OVER_QUIC_CONFIG_H

#include "php.h"
#include "include/config/ssh_over_quic/base_layer.h"

/**
 * @brief Applies runtime configuration settings from a PHP array.
 * @param config_arr A zval pointer to a PHP array containing the key-value
 * pairs of the configuration to apply.
 * @return `SUCCESS` if all settings were successfully validated and applied,
 * `FAILURE` otherwise.
 */
int kg_config_ssh_over_quic_apply_userland_config(zval *config_arr);
int kg_config_ssh_over_quic_apply_userland_config_to(
    kg_ssh_over_quic_config_t *target,
    zval *config_arr);

#endif /* KING_CONFIG_SSH_OVER_QUIC_CONFIG_H */
