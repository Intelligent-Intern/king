/*
 * =========================================================================
 * FILENAME:   include/config/native_cdn/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the Native CDN
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_NATIVE_CDN_INDEX_H
#define KING_CONFIG_NATIVE_CDN_INDEX_H

/**
 * @brief Initializes the Native CDN configuration module.
 */
void kg_config_native_cdn_init(void);

/**
 * @brief Shuts down the Native CDN configuration module.
 */
void kg_config_native_cdn_shutdown(void);

#endif /* KING_CONFIG_NATIVE_CDN_INDEX_H */
