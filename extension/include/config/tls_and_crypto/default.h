/*
 * =========================================================================
 * FILENAME:   include/config/tls_and_crypto/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for the TLS & Crypto settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_TLS_CRYPTO_DEFAULT_H
#define KING_CONFIG_TLS_CRYPTO_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_tls_and_crypto_defaults_load(void);

#endif /* KING_CONFIG_TLS_CRYPTO_DEFAULT_H */
