/*
 * =========================================================================
 * FILENAME:   include/config/quic_transport/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the QUIC Transport
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_QUIC_TRANSPORT_INDEX_H
#define KING_CONFIG_QUIC_TRANSPORT_INDEX_H

/**
 * @brief Initializes the QUIC Transport configuration module.
 */
void kg_config_quic_transport_init(void);

/**
 * @brief Shuts down the QUIC Transport configuration module.
 */
void kg_config_quic_transport_shutdown(void);

#endif /* KING_CONFIG_QUIC_TRANSPORT_INDEX_H */
