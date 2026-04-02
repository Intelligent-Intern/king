/*
 * =========================================================================
 * FILENAME:   src/config/smart_contracts/base_layer.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the shared module-global state for the smart-contracts config
 * family. Module enablement, registry and DLT provider endpoints, chain and
 * gas defaults, wallet / HSM material, ABI location, and event-listener
 * toggles all land in the single `king_smart_contracts_config` snapshot.
 * =========================================================================
 */

#include "include/config/smart_contracts/base_layer.h"

kg_smart_contracts_config_t king_smart_contracts_config;
