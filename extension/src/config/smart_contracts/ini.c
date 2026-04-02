/*
 * =========================================================================
 * FILENAME:   src/config/smart_contracts/ini.c
 * PROJECT:    king
 *
 * PURPOSE:
 * php.ini registration and update callbacks for the smart-contracts config
 * family. This file exposes the system-level enablement, registry, DLT
 * provider, gas defaults, wallet / HSM material, ABI directory, and
 * event-listener directives and keeps `king_smart_contracts_config`
 * aligned with validated updates.
 * =========================================================================
 */

#include "include/config/smart_contracts/ini.h"
#include "include/config/smart_contracts/base_layer.h"

#include "php.h"
#include <Zend/zend_exceptions.h>
#include <ext/spl/spl_exceptions.h>
#include <zend_ini.h>

static void smart_contracts_replace_string(char **target, zend_string *value)
{
    if (*target) {
        pefree(*target, 1);
    }

    *target = pestrdup(ZSTR_VAL(value), 1);
}

static ZEND_INI_MH(OnUpdateSmartContractPositiveLong)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);

    if (val <= 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value provided for smart-contract directive. A positive integer is required.");
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.smartcontract_chain_id")) {
        king_smart_contracts_config.chain_id = val;
    } else if (zend_string_equals_literal(entry->name, "king.smartcontract_default_gas_limit")) {
        king_smart_contracts_config.default_gas_limit = val;
    } else if (zend_string_equals_literal(entry->name, "king.smartcontract_default_gas_price_gwei")) {
        king_smart_contracts_config.default_gas_price_gwei = val;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateDltProviderString)
{
    const char *allowed[] = {"ethereum", "solana", "fabric", NULL};

    for (int i = 0; allowed[i] != NULL; ++i) {
        if (strcasecmp(ZSTR_VAL(new_value), allowed[i]) == 0) {
            smart_contracts_replace_string(&king_smart_contracts_config.dlt_provider, new_value);
            return SUCCESS;
        }
    }

    zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
        "Invalid DLT provider specified for smart-contract module.");
    return FAILURE;
}

static ZEND_INI_MH(OnUpdateStringDuplicate)
{
    /* ZEND_INI_ENTRY1() passes the persistent char** field directly in mh_arg1. */
    smart_contracts_replace_string((char **) mh_arg1, new_value);
    return SUCCESS;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.smartcontract_enable", "0", PHP_INI_SYSTEM, OnUpdateBool, enable, kg_smart_contracts_config_t, king_smart_contracts_config)

    ZEND_INI_ENTRY1("king.smartcontract_registry_uri", "https://contracts.king.internal",
        PHP_INI_SYSTEM, OnUpdateStringDuplicate, &king_smart_contracts_config.registry_uri)

    ZEND_INI_ENTRY("king.smartcontract_dlt_provider", "ethereum",
        PHP_INI_SYSTEM, OnUpdateDltProviderString)

    ZEND_INI_ENTRY1("king.smartcontract_dlt_rpc_endpoint", "",
        PHP_INI_SYSTEM, OnUpdateStringDuplicate, &king_smart_contracts_config.dlt_rpc_endpoint)

    ZEND_INI_ENTRY_EX("king.smartcontract_chain_id", "1",
        PHP_INI_SYSTEM, OnUpdateSmartContractPositiveLong, NULL)

    ZEND_INI_ENTRY_EX("king.smartcontract_default_gas_limit", "300000",
        PHP_INI_SYSTEM, OnUpdateSmartContractPositiveLong, NULL)

    ZEND_INI_ENTRY_EX("king.smartcontract_default_gas_price_gwei", "20",
        PHP_INI_SYSTEM, OnUpdateSmartContractPositiveLong, NULL)

    ZEND_INI_ENTRY1("king.smartcontract_default_wallet_path", "",
        PHP_INI_SYSTEM, OnUpdateStringDuplicate, &king_smart_contracts_config.default_wallet_path)

    ZEND_INI_ENTRY1("king.smartcontract_default_wallet_password_env_var", "KING_WALLET_PASSWORD",
        PHP_INI_SYSTEM, OnUpdateStringDuplicate, &king_smart_contracts_config.default_wallet_password_env_var)

    STD_PHP_INI_ENTRY("king.smartcontract_use_hardware_wallet", "0", PHP_INI_SYSTEM,
        OnUpdateBool, use_hardware_wallet, kg_smart_contracts_config_t, king_smart_contracts_config)

    ZEND_INI_ENTRY1("king.smartcontract_hsm_pkcs11_library_path", "/usr/lib/x86_64-linux-gnu/softhsm/libsofthsm2.so",
        PHP_INI_SYSTEM, OnUpdateStringDuplicate, &king_smart_contracts_config.hsm_pkcs11_library_path)

    ZEND_INI_ENTRY1("king.smartcontract_abi_directory", "/var/www/abis",
        PHP_INI_SYSTEM, OnUpdateStringDuplicate, &king_smart_contracts_config.abi_directory)

    STD_PHP_INI_ENTRY("king.smartcontract_event_listener_enable", "0", PHP_INI_SYSTEM,
        OnUpdateBool, event_listener_enable, kg_smart_contracts_config_t, king_smart_contracts_config)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_smart_contracts_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_smart_contracts_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
