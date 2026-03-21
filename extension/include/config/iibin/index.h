/*
 * =========================================================================
 * FILENAME:   include/config/iibin/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the IIBIN serialization
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_IIBIN_INDEX_H
#define KING_CONFIG_IIBIN_INDEX_H

/**
 * @brief Initializes the IIBIN Serialization configuration module.
 */
void kg_config_iibin_init(void);

/**
 * @brief Shuts down the IIBIN Serialization configuration module.
 */
void kg_config_iibin_shutdown(void);

#endif /* KING_CONFIG_IIBIN_INDEX_H */
