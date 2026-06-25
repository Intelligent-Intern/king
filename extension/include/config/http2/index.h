/*
 * =========================================================================
 * FILENAME:   include/config/http2/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the HTTP/2
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_HTTP2_INDEX_H
#define KING_CONFIG_HTTP2_INDEX_H

/**
 * @brief Initializes the HTTP/2 configuration module.
 */
void kg_config_http2_init(void);

/**
 * @brief Shuts down the HTTP/2 configuration module.
 */
void kg_config_http2_shutdown(void);

#endif /* KING_CONFIG_HTTP2_INDEX_H */
