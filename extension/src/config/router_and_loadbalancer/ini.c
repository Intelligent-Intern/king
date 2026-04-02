/*
 * =========================================================================
 * FILENAME:   src/config/router_and_loadbalancer/ini.c
 * PROJECT:    king
 *
 * PURPOSE:
 * php.ini registration and update callbacks for the router and load-
 * balancer config family. This file exposes the system-level router mode,
 * hashing, backend discovery/static inventory, MCP poll interval, and
 * forwarding-cap directives and keeps
 * `king_router_loadbalancer_config` aligned with validated updates.
 * =========================================================================
 */

#include "include/config/router_and_loadbalancer/ini.h"
#include "include/config/router_and_loadbalancer/base_layer.h"

#include "php.h"
#include <ext/spl/spl_exceptions.h>
#include <Zend/zend_exceptions.h>
#include <strings.h>
#include <zend_ini.h>

extern int king_ini_module_number;

/* INI strings live in persistent module storage, so replace them manually. */
static void router_replace_string(char **target, zend_string *value)
{
    if (*target) {
        pefree(*target, 1);
    }
    *target = pestrdup(ZSTR_VAL(value), 1);
}

static ZEND_INI_MH(OnUpdateRouterPositiveLong)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);

    if (val <= 0) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value for a router directive. A positive integer is required."
        );
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.router_backend_mcp_poll_interval_sec")) {
        king_router_loadbalancer_config.backend_mcp_poll_interval_sec = val;
    } else if (zend_string_equals_literal(entry->name, "king.router_max_forwarding_pps")) {
        king_router_loadbalancer_config.max_forwarding_pps = val;
    }

    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateRouterAllowlist)
{
    const char *const hashing_allowed[] = {"consistent_hash", "round_robin", NULL};
    const char *const discovery_allowed[] = {"static", "mcp", NULL};
    const char *const *current_list = NULL;
    bool is_allowed = false;

    if (zend_string_equals_literal(entry->name, "king.router_hashing_algorithm")) {
        current_list = hashing_allowed;
    } else if (zend_string_equals_literal(entry->name, "king.router_backend_discovery_mode")) {
        current_list = discovery_allowed;
    }

    if (current_list != NULL) {
        for (int i = 0; current_list[i] != NULL; i++) {
            if (strcasecmp(ZSTR_VAL(new_value), current_list[i]) == 0) {
                is_allowed = true;
                break;
            }
        }
    }

    if (!is_allowed) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value provided for a router directive. The value is not one of the allowed options."
        );
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.router_hashing_algorithm")) {
        router_replace_string(&king_router_loadbalancer_config.hashing_algorithm, new_value);
    } else if (zend_string_equals_literal(entry->name, "king.router_backend_discovery_mode")) {
        router_replace_string(&king_router_loadbalancer_config.backend_discovery_mode, new_value);
    }
    return SUCCESS;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.router_mode_enable", "0", PHP_INI_SYSTEM, OnUpdateBool, router_mode_enable, kg_router_loadbalancer_config_t, king_router_loadbalancer_config)
    ZEND_INI_ENTRY_EX("king.router_hashing_algorithm", "consistent_hash", PHP_INI_SYSTEM, OnUpdateRouterAllowlist, NULL)
    STD_PHP_INI_ENTRY("king.router_connection_id_entropy_salt", "change-this-to-a-long-random-string-in-production", PHP_INI_SYSTEM, OnUpdateString, connection_id_entropy_salt, kg_router_loadbalancer_config_t, king_router_loadbalancer_config)
    ZEND_INI_ENTRY_EX("king.router_backend_discovery_mode", "static", PHP_INI_SYSTEM, OnUpdateRouterAllowlist, NULL)
    STD_PHP_INI_ENTRY("king.router_backend_static_list", "127.0.0.1:8443", PHP_INI_SYSTEM, OnUpdateString, backend_static_list, kg_router_loadbalancer_config_t, king_router_loadbalancer_config)
    STD_PHP_INI_ENTRY("king.router_backend_mcp_endpoint", "127.0.0.1:9998", PHP_INI_SYSTEM, OnUpdateString, backend_mcp_endpoint, kg_router_loadbalancer_config_t, king_router_loadbalancer_config)
    ZEND_INI_ENTRY_EX("king.router_backend_mcp_poll_interval_sec", "10", PHP_INI_SYSTEM, OnUpdateRouterPositiveLong, NULL)
    ZEND_INI_ENTRY_EX("king.router_max_forwarding_pps", "1000000", PHP_INI_SYSTEM, OnUpdateRouterPositiveLong, NULL)
PHP_INI_END()

void kg_config_router_and_loadbalancer_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_router_and_loadbalancer_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
