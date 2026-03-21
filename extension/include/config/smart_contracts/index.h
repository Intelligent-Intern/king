/*
 * =========================================================================
 * FILENAME:   include/config/smart_contracts/index.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * This header file declares the public C-API for the Smart Contracts
 * configuration module.
 *
 * ARCHITECTURE:
 * It provides the function prototypes for the module's lifecycle, which
 * are called by the master dispatcher to orchestrate loading of settings.
 * =========================================================================
 */

#ifndef KING_CONFIG_SMART_CONTRACTS_INDEX_H
#define KING_CONFIG_SMART_CONTRACTS_INDEX_H

/**
 * @brief Initializes the Smart Contracts configuration module.
 */
void kg_config_smart_contracts_init(void);

/**
 * @brief Shuts down the Smart Contracts configuration module.
 */
void kg_config_smart_contracts_shutdown(void);

#endif /* KING_CONFIG_SMART_CONTRACTS_INDEX_H */
