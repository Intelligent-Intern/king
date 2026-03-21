#include "include/config/security_and_traffic/ini.h"
#include "include/config/security_and_traffic/base_layer.h"
#include "include/king_globals.h"

#include "php.h"
#include <ext/spl/spl_exceptions.h>
#include <Zend/zend_exceptions.h>
#include <ext/standard/php_string.h>
#include <ext/standard/url.h>
#include <string.h>
#include <strings.h>
#include <zend_ini.h>

static ZEND_INI_MH(OnUpdateRateLimiterValue)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);

    if (val < 0) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value provided for a rate-limiter directive. A non-negative integer is required."
        );
        return FAILURE;
    }

    if (zend_string_equals_literal(entry->name, "king.security_rate_limiter_requests_per_sec")) {
        king_security_config.rate_limiter_requests_per_sec = val;
    } else if (zend_string_equals_literal(entry->name, "king.security_rate_limiter_burst")) {
        king_security_config.rate_limiter_burst = val;
    }

    return SUCCESS;
}

static int security_validate_and_copy_cors_origins(zend_string *value, char **target)
{
    const char *input_str;
    char *input_copy;
    char *token;
    char *saveptr;
    bool is_valid = true;

    input_str = ZSTR_VAL(value);

    if (strcmp(input_str, "*") != 0) {
        input_copy = estrdup(input_str);

        for (token = strtok_r(input_copy, ",", &saveptr);
             token != NULL;
             token = strtok_r(NULL, ",", &saveptr))
        {
            zend_string *trimmed;
            php_url *parsed_url;

            trimmed = php_trim(
                zend_string_init(token, strlen(token), 0),
                " \t\n\r\v\f",
                7,
                3
            );

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

static ZEND_INI_MH(OnUpdateCorsOrigins)
{
    return security_validate_and_copy_cors_origins(new_value, &king_security_config.cors_allowed_origins);
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.security_allow_config_override", "0", PHP_INI_SYSTEM, OnUpdateBool, allow_config_override, kg_security_config_t, king_security_config)
    STD_PHP_INI_ENTRY("king.admin_api_enable", "0", PHP_INI_SYSTEM, OnUpdateBool, admin_api_enable, kg_security_config_t, king_security_config)
    STD_PHP_INI_ENTRY("king.security_rate_limiter_enable", "1", PHP_INI_SYSTEM, OnUpdateBool, rate_limiter_enable, kg_security_config_t, king_security_config)
    ZEND_INI_ENTRY_EX("king.security_rate_limiter_requests_per_sec", "100", PHP_INI_SYSTEM, OnUpdateRateLimiterValue, NULL)
    ZEND_INI_ENTRY_EX("king.security_rate_limiter_burst", "50", PHP_INI_SYSTEM, OnUpdateRateLimiterValue, NULL)
    ZEND_INI_ENTRY_EX("king.security_cors_allowed_origins", "*", PHP_INI_SYSTEM, OnUpdateCorsOrigins, NULL)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_security_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
    /* The security block owns the global policy gate for userland overrides. */
    king_globals.is_userland_override_allowed = king_security_config.allow_config_override;
}

void kg_config_security_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
