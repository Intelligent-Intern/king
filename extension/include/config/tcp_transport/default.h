/*
 * =========================================================================
 * FILENAME:   include/config/tcp_transport/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for the TCP Transport layer.
 * =========================================================================
 */

#ifndef KING_CONFIG_TCP_TRANSPORT_DEFAULT_H
#define KING_CONFIG_TCP_TRANSPORT_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_tcp_transport_defaults_load(void);

#endif /* KING_CONFIG_TCP_TRANSPORT_DEFAULT_H */
