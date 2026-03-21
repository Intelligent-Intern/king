/*
 * =========================================================================
 * FILENAME:   include/config/smart_contracts/default.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header declares the function for loading the module's hardcoded
 * default values for the Smart Contracts integration.
 * =========================================================================
 */

#ifndef KING_CONFIG_SMART_CONTRACTS_DEFAULT_H
#define KING_CONFIG_SMART_CONTRACTS_DEFAULT_H

/**
 * @brief Loads the hardcoded, default values into the module's config struct.
 */
void kg_config_smart_contracts_defaults_load(void);

#endif /* KING_CONFIG_SMART_CONTRACTS_DEFAULT_H */
