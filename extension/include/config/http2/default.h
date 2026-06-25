/*
 * =========================================================================
 * FILENAME:   include/config/http2/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for the HTTP/2 protocol engine.
 * =========================================================================
 */

#ifndef KING_CONFIG_HTTP2_DEFAULT_H
#define KING_CONFIG_HTTP2_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_http2_defaults_load(void);

#endif /* KING_CONFIG_HTTP2_DEFAULT_H */
