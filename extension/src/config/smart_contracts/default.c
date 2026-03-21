#include "include/config/smart_contracts/default.h"
#include "include/config/smart_contracts/base_layer.h"

void kg_config_smart_contracts_defaults_load(void)
{
    king_smart_contracts_config.enable = false;
    king_smart_contracts_config.registry_uri = pestrdup("https://contracts.king.internal", 1);

    king_smart_contracts_config.dlt_provider = pestrdup("ethereum", 1);
    king_smart_contracts_config.dlt_rpc_endpoint = pestrdup("", 1);
    king_smart_contracts_config.chain_id = 1;
    king_smart_contracts_config.default_gas_limit = 300000;
    king_smart_contracts_config.default_gas_price_gwei = 20;

    king_smart_contracts_config.default_wallet_path = pestrdup("", 1);
    king_smart_contracts_config.default_wallet_password_env_var = pestrdup("KING_WALLET_PASSWORD", 1);
    king_smart_contracts_config.use_hardware_wallet = false;
    king_smart_contracts_config.hsm_pkcs11_library_path = pestrdup("/usr/lib/x86_64-linux-gnu/softhsm/libsofthsm2.so", 1);

    king_smart_contracts_config.abi_directory = pestrdup("/var/www/abis", 1);
    king_smart_contracts_config.event_listener_enable = false;
}
