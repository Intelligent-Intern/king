/*
 * =========================================================================
 * FILENAME:   include/config/ssh_over_quic/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for the SSH over QUIC gateway.
 * =========================================================================
 */

#ifndef KING_CONFIG_SSH_OVER_QUIC_DEFAULT_H
#define KING_CONFIG_SSH_OVER_QUIC_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_ssh_over_quic_defaults_load(void);

#endif /* KING_CONFIG_SSH_OVER_QUIC_DEFAULT_H */
