/*
 * =========================================================================
 * FILENAME:   src/config/security_and_traffic/config.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Userland override application for the security and traffic config
 * family. This file enforces the global policy gate around `King\\Config`
 * overrides and validates the narrow runtime-adjustable subset for rate
 * limiting and CORS origin policy in the live module-global state.
 * =========================================================================
 */

#include "include/config/security_and_traffic/config.h"
#include "include/config/security_and_traffic/base_layer.h"
#include "include/king_globals.h"

#include "php.h"
#include <ext/spl/spl_exceptions.h>
#include <Zend/zend_exceptions.h>
#include <ext/standard/php_string.h>
#include <ext/standard/url.h>
#include <string.h>
#include <strings.h>

static int security_validate_bool(zval *value)
{
    return (Z_TYPE_P(value) == IS_TRUE || Z_TYPE_P(value) == IS_FALSE) ? SUCCESS : FAILURE;
}

static int security_apply_bool(zval *value, zend_bool *target)
{
    if (security_validate_bool(value) != SUCCESS) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid type provided. A boolean is required."
        );
        return FAILURE;
    }

    *target = zend_is_true(value);
    return SUCCESS;
}

static int security_validate_non_negative_long(zval *value, zend_long *target)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid type provided. An integer is required."
        );
        return FAILURE;
    }

    if (Z_LVAL_P(value) < 0) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value provided. A non-negative integer is required."
        );
        return FAILURE;
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static int security_validate_and_copy_cors_origins(zval *value, char **target)
{
    char *input_str;
    char *input_copy;
    char *token;
    char *saveptr = NULL;
    bool is_valid = true;

    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid type provided for CORS origin policy. A string is required."
        );
        return FAILURE;
    }

    input_str = Z_STRVAL_P(value);

    if (strcmp(input_str, "*") != 0) {
        input_copy = estrdup(input_str);
        for (token = strtok_r(input_copy, ",", &saveptr);
             token != NULL;
             token = strtok_r(NULL, ",", &saveptr))
        {
            zend_string *trimmed;
            php_url *parsed_url;

            trimmed = php_trim(zend_string_init(token, strlen(token), 0), " \t\n\r\v\f", 7, 3);
            if (ZSTR_LEN(trimmed) == 0) {
                zend_string_release(trimmed);
                continue;
            }

            parsed_url = php_url_parse_ex(ZSTR_VAL(trimmed), ZSTR_LEN(trimmed));
            if (parsed_url == NULL || parsed_url->scheme == NULL || parsed_url->host == NULL ||
                (strcasecmp(ZSTR_VAL(parsed_url->scheme), "http") != 0 && strcasecmp(ZSTR_VAL(parsed_url->scheme), "https") != 0)) {
                is_valid = false;
            }

            if (parsed_url) {
                php_url_free(parsed_url);
            }
            zend_string_release(trimmed);

            if (!is_valid) {
                break;
            }
        }
        efree(input_copy);
    }

    if (!is_valid) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value provided for CORS origin policy. Value must be '*' or a comma-separated list of valid origins."
        );
        return FAILURE;
    }

    if (*target) {
        pefree(*target, 1);
    }
    *target = pestrdup(input_str, 1);

    return SUCCESS;
}

int kg_config_security_apply_userland_config(zval *config_arr)
{
    zval *value;
    zend_string *key;

    /* The global security policy decides whether userland may override config at all. */
    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Configuration override from userland is disabled by system administrator in php.ini (king.security_allow_config_override)."
        );
        return FAILURE;
    }

    if (Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Configuration must be provided as an array."
        );
        return FAILURE;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, value) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "security_rate_limiter_enable")) {
            if (security_apply_bool(value, &king_security_config.rate_limiter_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "security_rate_limiter_requests_per_sec")) {
            if (security_validate_non_negative_long(value, &king_security_config.rate_limiter_requests_per_sec) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "security_rate_limiter_burst")) {
            if (security_validate_non_negative_long(value, &king_security_config.rate_limiter_burst) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "security_cors_allowed_origins")) {
            if (security_validate_and_copy_cors_origins(value, &king_security_config.cors_allowed_origins) != SUCCESS) {
                return FAILURE;
            }
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}
