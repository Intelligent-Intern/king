/*
 * =========================================================================
 * FILENAME:   src/config/smart_contracts/default.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Default-value loader for the smart-contracts config family. This slice
 * seeds the baseline module-disabled state, chain and gas defaults, wallet
 * and HSM placeholders, ABI location placeholder, and event-listener flag
 * before INI and any allowed userland overrides refine the live snapshot.
 * =========================================================================
 */

#include "include/config/smart_contracts/default.h"
#include "include/config/smart_contracts/base_layer.h"

void kg_config_smart_contracts_defaults_load(void)
{
    king_smart_contracts_config.enable = false;
    king_smart_contracts_config.registry_uri = NULL;

    king_smart_contracts_config.dlt_provider = NULL;
    king_smart_contracts_config.dlt_rpc_endpoint = NULL;
    king_smart_contracts_config.chain_id = 1;
    king_smart_contracts_config.default_gas_limit = 300000;
    king_smart_contracts_config.default_gas_price_gwei = 20;

    king_smart_contracts_config.default_wallet_path = NULL;
    king_smart_contracts_config.default_wallet_password_env_var = NULL;
    king_smart_contracts_config.use_hardware_wallet = false;
    king_smart_contracts_config.hsm_pkcs11_library_path = NULL;

    king_smart_contracts_config.abi_directory = NULL;
    king_smart_contracts_config.event_listener_enable = false;
}
