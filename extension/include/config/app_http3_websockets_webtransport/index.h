/*
 * =========================================================================
 * FILENAME:   include/config/app_http3_websockets_webtransport/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the application protocols
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_APP_PROTOCOLS_INDEX_H
#define KING_CONFIG_APP_PROTOCOLS_INDEX_H

/**
 * @brief Initializes the Application Protocols configuration module.
 */
void kg_config_app_http3_websockets_webtransport_init(void);

/**
 * @brief Shuts down the Application Protocols configuration module.
 */
void kg_config_app_http3_websockets_webtransport_shutdown(void);

#endif /* KING_CONFIG_APP_PROTOCOLS_INDEX_H */
