/*
 * =========================================================================
 * FILENAME:   include/config/smart_contracts/base_layer.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 * PURPOSE:
 * Defines the configuration struct for smart contract integration.
 *
 * ARCHITECTURE:
 * This struct stores registry, blockchain, wallet, and event settings.
 * =========================================================================
 */
#ifndef KING_CONFIG_SMART_CONTRACTS_BASE_H
#define KING_CONFIG_SMART_CONTRACTS_BASE_H

#include "php.h"
#include <stdbool.h>

typedef struct _kg_smart_contracts_config_t {
    /* --- General & Registry --- */
    bool enable;
    char *registry_uri;

    /* --- Blockchain/DLT Connectivity --- */
    char *dlt_provider;
    char *dlt_rpc_endpoint;
    zend_long chain_id;
    zend_long default_gas_limit;
    zend_long default_gas_price_gwei;

    /* --- Wallet & Key Management --- */
    char *default_wallet_path;
    char *default_wallet_password_env_var;
    bool use_hardware_wallet;
    char *hsm_pkcs11_library_path;

    /* --- Application & Event Handling --- */
    char *abi_directory;
    bool event_listener_enable;

} kg_smart_contracts_config_t;

/* Module-global configuration instance. */
extern kg_smart_contracts_config_t king_smart_contracts_config;

#endif /* KING_CONFIG_SMART_CONTRACTS_BASE_H */
