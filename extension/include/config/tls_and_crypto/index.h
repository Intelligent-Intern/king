/*
 * =========================================================================
 * FILENAME:   include/config/tls_and_crypto/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the TLS & Crypto
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_TLS_CRYPTO_INDEX_H
#define KING_CONFIG_TLS_CRYPTO_INDEX_H

/**
 * @brief Initializes the TLS & Crypto configuration module.
 */
void kg_config_tls_and_crypto_init(void);

/**
 * @brief Shuts down the TLS & Crypto configuration module.
 */
void kg_config_tls_and_crypto_shutdown(void);

#endif /* KING_CONFIG_TLS_CRYPTO_INDEX_H */
