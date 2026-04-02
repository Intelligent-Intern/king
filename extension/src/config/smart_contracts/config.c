/*
 * =========================================================================
 * FILENAME:   src/config/smart_contracts/config.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Userland override application for the smart-contracts config family.
 * This file validates the `King\\Config` subset that can target either a
 * temporary config snapshot or the live module-global state and applies
 * bounded registry, DLT-provider, gas, wallet, ABI, and event-listener
 * overrides.
 * =========================================================================
 */

#include "include/config/smart_contracts/config.h"
#include "include/config/smart_contracts/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_bool.h"
#include "include/validation/config_param/validate_generic_string.h"
#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_string_from_allowlist.h"

#include "php.h"
#include <Zend/zend_exceptions.h>
#include <ext/spl/spl_exceptions.h>

static int kg_smart_contracts_apply_bool_field(zval *value, const char *name, bool *target)
{
    if (kg_validate_bool(value, name) != SUCCESS) {
        return FAILURE;
    }

    *target = zend_is_true(value);
    return SUCCESS;
}

static const char *k_smart_contracts_dlt_provider_allowed[] = {"ethereum", "solana", "fabric", NULL};

int kg_config_smart_contracts_apply_userland_config_to(
    kg_smart_contracts_config_t *target,
    zval *config_arr)
{
    zval *value;
    zend_string *key;

    if (target == NULL || Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration must be provided as an array.");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, value) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "enable")) {
            if (kg_smart_contracts_apply_bool_field(value, "enable", &target->enable) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "registry_uri")) {
            if (kg_validate_generic_string(value, &target->registry_uri) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "dlt_provider")) {
            if (kg_validate_string_from_allowlist(value, k_smart_contracts_dlt_provider_allowed, &target->dlt_provider) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "dlt_rpc_endpoint")) {
            if (kg_validate_generic_string(value, &target->dlt_rpc_endpoint) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "chain_id")) {
            if (kg_validate_positive_long(value, &target->chain_id) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "default_gas_limit")) {
            if (kg_validate_positive_long(value, &target->default_gas_limit) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "default_gas_price_gwei")) {
            if (kg_validate_positive_long(value, &target->default_gas_price_gwei) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "default_wallet_path")) {
            if (kg_validate_generic_string(value, &target->default_wallet_path) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "default_wallet_password_env_var")) {
            if (kg_validate_generic_string(value, &target->default_wallet_password_env_var) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "use_hardware_wallet")) {
            if (kg_smart_contracts_apply_bool_field(value, "use_hardware_wallet", &target->use_hardware_wallet) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "hsm_pkcs11_library_path")) {
            if (kg_validate_generic_string(value, &target->hsm_pkcs11_library_path) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "abi_directory")) {
            if (kg_validate_generic_string(value, &target->abi_directory) != SUCCESS) return FAILURE;
        } else if (zend_string_equals_literal(key, "event_listener_enable")) {
            if (kg_smart_contracts_apply_bool_field(value, "event_listener_enable", &target->event_listener_enable) != SUCCESS) return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

int kg_config_smart_contracts_apply_userland_config(zval *config_arr)
{
    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration override from userland is disabled by system administrator.");
        return FAILURE;
    }

    return kg_config_smart_contracts_apply_userland_config_to(
        &king_smart_contracts_config,
        config_arr
    );
}
