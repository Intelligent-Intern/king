/*
 * =========================================================================
 * FILENAME:   include/config/native_cdn/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for the Native CDN.
 * =========================================================================
 */

#ifndef KING_CONFIG_NATIVE_CDN_DEFAULT_H
#define KING_CONFIG_NATIVE_CDN_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_native_cdn_defaults_load(void);

#endif /* KING_CONFIG_NATIVE_CDN_DEFAULT_H */
