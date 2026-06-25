/*
 * =========================================================================
 * FILENAME:   include/config/iibin/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for the IIBIN serialization engine.
 * =========================================================================
 */

#ifndef KING_CONFIG_IIBIN_DEFAULT_H
#define KING_CONFIG_IIBIN_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_iibin_defaults_load(void);

#endif /* KING_CONFIG_IIBIN_DEFAULT_H */
