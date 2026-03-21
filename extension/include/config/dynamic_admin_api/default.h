/*
 * =========================================================================
 * FILENAME:   include/config/dynamic_admin_api/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for the dynamic admin API.
 * =========================================================================
 */

#ifndef KING_CONFIG_DYNAMIC_ADMIN_API_DEFAULT_H
#define KING_CONFIG_DYNAMIC_ADMIN_API_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_dynamic_admin_api_defaults_load(void);

#endif /* KING_CONFIG_DYNAMIC_ADMIN_API_DEFAULT_H */
