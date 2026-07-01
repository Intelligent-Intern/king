/*
 * =========================================================================
 * FILENAME:   include/config/bare_metal_tuning/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for bare metal tuning.
 * =========================================================================
 */

#ifndef KING_CONFIG_BARE_METAL_DEFAULT_H
#define KING_CONFIG_BARE_METAL_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_bare_metal_tuning_defaults_load(void);

#endif /* KING_CONFIG_BARE_METAL_DEFAULT_H */
