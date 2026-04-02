/*
 * =========================================================================
 * FILENAME:   src/config/state_management/ini.c
 * PROJECT:    king
 *
 * PURPOSE:
 * php.ini registration and update callbacks for the state-management config
 * family. This file exposes the system-level default backend and URI
 * directives and keeps `king_state_management_config` aligned with
 * validated updates.
 * =========================================================================
 */

#include "include/config/state_management/ini.h"
#include "include/config/state_management/base_layer.h"

#include "php.h"
#include <ext/spl/spl_exceptions.h>
#include <string.h>
#include <zend_ini.h>
#include <zend_exceptions.h>

static ZEND_INI_MH(OnUpdateStateMgmtBackend)
{
    const char *allowed[] = {"memory", "sqlite", "redis", "postgres", NULL};
    int i;

    for (i = 0; allowed[i]; ++i) {
        if (strcmp(ZSTR_VAL(new_value), allowed[i]) == 0) {
            if (king_state_management_config.default_backend) {
                pefree(king_state_management_config.default_backend, 1);
            }
            king_state_management_config.default_backend = pestrdup(ZSTR_VAL(new_value), 1);
            return SUCCESS;
        }
    }

    zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
        "Invalid state_manager_default_backend. Allowed values: memory, sqlite, redis, postgres");
    return FAILURE;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.state_manager_default_backend", "memory",
        PHP_INI_SYSTEM, OnUpdateStateMgmtBackend,
        default_backend, kg_state_management_config_t, king_state_management_config)
    STD_PHP_INI_ENTRY("king.state_manager_default_uri", "",
        PHP_INI_SYSTEM, OnUpdateString,
        default_uri, kg_state_management_config_t, king_state_management_config)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_state_management_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_state_management_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
