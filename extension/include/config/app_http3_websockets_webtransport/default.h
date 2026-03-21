/*
 * =========================================================================
 * FILENAME:   include/config/app_http3_websockets_webtransport/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for application protocols.
 * =========================================================================
 */

#ifndef KING_CONFIG_APP_PROTOCOLS_DEFAULT_H
#define KING_CONFIG_APP_PROTOCOLS_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_app_http3_websockets_webtransport_defaults_load(void);

#endif /* KING_CONFIG_APP_PROTOCOLS_DEFAULT_H */
