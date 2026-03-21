/*
 * =========================================================================
 * FILENAME:   include/config/ssh_over_quic/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the SSH over QUIC
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_SSH_OVER_QUIC_INDEX_H
#define KING_CONFIG_SSH_OVER_QUIC_INDEX_H

/**
 * @brief Initializes the SSH over QUIC configuration module.
 */
void kg_config_ssh_over_quic_init(void);

/**
 * @brief Shuts down the SSH over QUIC configuration module.
 */
void kg_config_ssh_over_quic_shutdown(void);

#endif /* KING_CONFIG_SSH_OVER_QUIC_INDEX_H */
