/*
 * =========================================================================
 * FILENAME:   include/config/quic_transport/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for the QUIC Transport layer.
 * =========================================================================
 */

#ifndef KING_CONFIG_QUIC_TRANSPORT_DEFAULT_H
#define KING_CONFIG_QUIC_TRANSPORT_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_quic_transport_defaults_load(void);

#endif /* KING_CONFIG_QUIC_TRANSPORT_DEFAULT_H */
