#include "include/config/router_and_loadbalancer/config.h"
#include "include/config/router_and_loadbalancer/base_layer.h"
#include "include/king_globals.h"

#include "php.h"
#include <ext/spl/spl_exceptions.h>
#include <Zend/zend_exceptions.h>
#include <strings.h>

static int router_validate_bool(zval *value)
{
    return (Z_TYPE_P(value) == IS_TRUE || Z_TYPE_P(value) == IS_FALSE) ? SUCCESS : FAILURE;
}

/* These values live in persistent module storage, so replace them manually. */
static void router_replace_string(char **target, const char *value)
{
    if (*target) {
        pefree(*target, 1);
    }

    *target = pestrdup(value, 1);
}

static int router_validate_positive_long(zval *value, zend_long *target)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided. An integer is required.");
        return FAILURE;
    }

    if (Z_LVAL_P(value) <= 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid value provided. A positive integer is required.");
        return FAILURE;
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static int router_validate_string_from_allowlist(zval *value, const char *const allowed_values[], char **target)
{
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided. A string is required.");
        return FAILURE;
    }

    for (int i = 0; allowed_values[i] != NULL; i++) {
        if (strcasecmp(Z_STRVAL_P(value), allowed_values[i]) == 0) {
            router_replace_string(target, Z_STRVAL_P(value));
            return SUCCESS;
        }
    }

    zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid value provided. The value is not one of the allowed options.");
    return FAILURE;
}

static int router_validate_generic_string(zval *value, char **target)
{
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided. A string is required.");
        return FAILURE;
    }

    router_replace_string(target, Z_STRVAL_P(value));
    return SUCCESS;
}

int kg_config_router_and_loadbalancer_apply_userland_config(zval *config_arr)
{
    zval *value;
    zend_string *key;

    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Configuration override from userland is disabled by system administrator.");
        return FAILURE;
    }

    if (Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Configuration must be provided as an array.");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, value) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "router_mode_enable")) {
            if (router_validate_bool(value) != SUCCESS) {
                zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided. A boolean is required.");
                return FAILURE;
            }
            king_router_loadbalancer_config.router_mode_enable = zend_is_true(value);
        } else if (zend_string_equals_literal(key, "hashing_algorithm")) {
            const char *const allowed[] = {"consistent_hash", "round_robin", NULL};
            if (router_validate_string_from_allowlist(value, allowed, &king_router_loadbalancer_config.hashing_algorithm) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "connection_id_entropy_salt")) {
            if (router_validate_generic_string(value, &king_router_loadbalancer_config.connection_id_entropy_salt) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "backend_discovery_mode")) {
            const char *const allowed[] = {"static", "mcp", NULL};
            if (router_validate_string_from_allowlist(value, allowed, &king_router_loadbalancer_config.backend_discovery_mode) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "backend_static_list")) {
            if (router_validate_generic_string(value, &king_router_loadbalancer_config.backend_static_list) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "backend_mcp_endpoint")) {
            if (router_validate_generic_string(value, &king_router_loadbalancer_config.backend_mcp_endpoint) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "backend_mcp_poll_interval_sec")) {
            if (router_validate_positive_long(value, &king_router_loadbalancer_config.backend_mcp_poll_interval_sec) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "max_forwarding_pps")) {
            if (router_validate_positive_long(value, &king_router_loadbalancer_config.max_forwarding_pps) != SUCCESS) {
                return FAILURE;
            }
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}
