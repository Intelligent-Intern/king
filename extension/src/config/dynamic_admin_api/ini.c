/*
 * =========================================================================
 * FILENAME:   src/config/dynamic_admin_api/ini.c
 * PROJECT:    king
 *
 * PURPOSE:
 * php.ini registration and update callbacks for the dynamic-admin-api
 * config family. This file exposes the system-level bind host, port, auth
 * mode, and mTLS material directives and keeps the shared
 * `king_dynamic_admin_api_config` snapshot aligned with validated updates.
 * =========================================================================
 */

#include "include/config/dynamic_admin_api/ini.h"
#include "include/config/dynamic_admin_api/base_layer.h"

#include "php.h"
#include "main/php_streams.h"
#include <ext/spl/spl_exceptions.h>
#include <Zend/zend_exceptions.h>
#include <strings.h>
#include <zend_ini.h>

extern int king_ini_module_number;

static void dynamic_admin_replace_string(char **target, zend_string *value)
{
    if (*target) {
        pefree(*target, 1);
    }
    *target = pestrdup(ZSTR_VAL(value), 1);
}

static ZEND_INI_MH(OnUpdateAdminApiPort)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);

    if (val < 1024 || val > 65535) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid port for Admin API. Must be between 1024 and 65535.");
        return FAILURE;
    }

    king_dynamic_admin_api_config.port = val;
    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateAdminAuthMode)
{
    if (strcasecmp(ZSTR_VAL(new_value), "mtls") != 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid auth mode for Admin API. Only 'mtls' is supported.");
        return FAILURE;
    }

    dynamic_admin_replace_string(&king_dynamic_admin_api_config.auth_mode, new_value);
    return SUCCESS;
}

static ZEND_INI_MH(OnUpdateAdminApiFilePath)
{
    const char *path = ZSTR_VAL(new_value);
    char **target = NULL;

    if (ZSTR_LEN(new_value) > 0 && VCWD_ACCESS(path, R_OK) != 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Provided file path for Admin API is not accessible or does not exist.");
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.admin_api_ca_file")) {
        target = &king_dynamic_admin_api_config.ca_file;
    } else if (zend_string_equals_literal(entry->name, "king.admin_api_cert_file")) {
        target = &king_dynamic_admin_api_config.cert_file;
    } else if (zend_string_equals_literal(entry->name, "king.admin_api_key_file")) {
        target = &king_dynamic_admin_api_config.key_file;
    }

    if (target == NULL) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Unknown Admin API file directive.");
        return FAILURE;
    }

    dynamic_admin_replace_string(target, new_value);
    return SUCCESS;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.admin_api_bind_host", "127.0.0.1", PHP_INI_SYSTEM, OnUpdateString, bind_host, kg_dynamic_admin_api_config_t, king_dynamic_admin_api_config)
    ZEND_INI_ENTRY_EX("king.admin_api_port", "2019", PHP_INI_SYSTEM, OnUpdateAdminApiPort, NULL)
    ZEND_INI_ENTRY_EX("king.admin_api_auth_mode", "mtls", PHP_INI_SYSTEM, OnUpdateAdminAuthMode, NULL)
    ZEND_INI_ENTRY_EX("king.admin_api_ca_file", "", PHP_INI_SYSTEM, OnUpdateAdminApiFilePath, NULL)
    ZEND_INI_ENTRY_EX("king.admin_api_cert_file", "", PHP_INI_SYSTEM, OnUpdateAdminApiFilePath, NULL)
    ZEND_INI_ENTRY_EX("king.admin_api_key_file", "", PHP_INI_SYSTEM, OnUpdateAdminApiFilePath, NULL)
PHP_INI_END()

void kg_config_dynamic_admin_api_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_dynamic_admin_api_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
