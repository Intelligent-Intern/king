/*
 * =========================================================================
 * FILENAME:   src/config/dynamic_admin_api/config.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Userland override application for the dynamic-admin-api config family.
 * This file validates the narrow `King\\Config` subset that can tune the
 * local admin bind host, port, auth mode, and readable mTLS material paths
 * on the live `king_dynamic_admin_api_config` snapshot.
 * =========================================================================
 */

#include "include/config/dynamic_admin_api/config.h"
#include "include/config/dynamic_admin_api/base_layer.h"
#include "include/king_globals.h"

#include "php.h"
#include "main/php_streams.h"
#include <ctype.h>
#include <ext/spl/spl_exceptions.h>
#include <Zend/zend_exceptions.h>
#include <strings.h>

/* These strings live in persistent module storage. */
static void dynamic_admin_replace_string(char **target, const char *value)
{
    if (*target) {
        pefree(*target, 1);
    }

    *target = pestrdup(value, 1);
}

static int dynamic_admin_validate_host_string(zval *value, char **target)
{
    const char *host;

    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided for host. A string is required.");
        return FAILURE;
    }

    host = Z_STRVAL_P(value);
    if (Z_STRLEN_P(value) == 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid value provided for host. A non-empty string is required.");
        return FAILURE;
    }

    for (size_t i = 0; i < Z_STRLEN_P(value); i++) {
        if (!isalnum((unsigned char) host[i]) && host[i] != '.' && host[i] != '-' && host[i] != ':') {
            zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid character detected in host string.");
            return FAILURE;
        }
    }

    dynamic_admin_replace_string(target, host);
    return SUCCESS;
}

static int dynamic_admin_validate_long_range(zval *value, zend_long min_value, zend_long max_value, zend_long *target)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Configuration parameter must be an integer.");
        return FAILURE;
    }

    if (Z_LVAL_P(value) < min_value || Z_LVAL_P(value) > max_value) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Configuration parameter must be between %ld and %ld.",
            min_value,
            max_value
        );
        return FAILURE;
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static int dynamic_admin_validate_string_from_allowlist(zval *value, const char *const allowed_values[], char **target)
{
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided. A string is required.");
        return FAILURE;
    }

    for (int i = 0; allowed_values[i] != NULL; i++) {
        if (strcasecmp(Z_STRVAL_P(value), allowed_values[i]) == 0) {
            dynamic_admin_replace_string(target, Z_STRVAL_P(value));
            return SUCCESS;
        }
    }

    zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid value provided. The value is not one of the allowed options.");
    return FAILURE;
}

static int dynamic_admin_validate_readable_file_path(zval *value, char **target)
{
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided for file path. A string is required.");
        return FAILURE;
    }

    if (Z_STRLEN_P(value) > 0 && VCWD_ACCESS(Z_STRVAL_P(value), R_OK) != 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Provided file path is not accessible or does not exist.");
        return FAILURE;
    }

    dynamic_admin_replace_string(target, Z_STRVAL_P(value));
    return SUCCESS;
}

int kg_config_dynamic_admin_api_apply_userland_config(zval *config_arr)
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

        if (zend_string_equals_literal(key, "bind_host")) {
            if (dynamic_admin_validate_host_string(value, &king_dynamic_admin_api_config.bind_host) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "port")) {
            if (dynamic_admin_validate_long_range(value, 1024, 65535, &king_dynamic_admin_api_config.port) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "auth_mode")) {
            const char *const allowed[] = {"mtls", NULL};
            if (dynamic_admin_validate_string_from_allowlist(value, allowed, &king_dynamic_admin_api_config.auth_mode) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "ca_file")) {
            if (dynamic_admin_validate_readable_file_path(value, &king_dynamic_admin_api_config.ca_file) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "cert_file")) {
            if (dynamic_admin_validate_readable_file_path(value, &king_dynamic_admin_api_config.cert_file) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "key_file")) {
            if (dynamic_admin_validate_readable_file_path(value, &king_dynamic_admin_api_config.key_file) != SUCCESS) {
                return FAILURE;
            }
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}
