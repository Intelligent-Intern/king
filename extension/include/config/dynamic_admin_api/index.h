/*
 * =========================================================================
 * FILENAME:   include/config/dynamic_admin_api/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the dynamic admin API
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_DYNAMIC_ADMIN_API_INDEX_H
#define KING_CONFIG_DYNAMIC_ADMIN_API_INDEX_H

/**
 * @brief Initializes the Dynamic Admin API configuration module.
 */
void kg_config_dynamic_admin_api_init(void);

/**
 * @brief Shuts down the Dynamic Admin API configuration module.
 */
void kg_config_dynamic_admin_api_shutdown(void);

#endif /* KING_CONFIG_DYNAMIC_ADMIN_API_INDEX_H */
