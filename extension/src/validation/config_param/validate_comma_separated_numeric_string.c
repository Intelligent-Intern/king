/*
 * Validation helper for comma-separated numeric strings. Enforces string input
 * and keeps the bounded numeric-token validation used by config parsing.
 */

#include "include/validation/config_param/validate_comma_separated_numeric_string.h"
#include "ext/standard/php_string.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>
#include <ctype.h>

static bool is_numeric_token(const char *s) {
    if (s == NULL || *s == '\0') return false;
    char *p;
    strtod(s, &p);
    return *p == '\0';
}

int kg_validate_comma_separated_numeric_string(zval *value, char **target) {
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided. A string is required.");
        return FAILURE;
    }

    char *input_str = Z_STRVAL_P(value);
    char *input_copy = estrdup(input_str);
    char *token;
    char *saveptr;
    bool is_valid = true;

    for (token = php_strtok_r(input_copy, ",", &saveptr);
         token != NULL;
         token = php_strtok_r(NULL, ",", &saveptr))
    {
        zend_string *trimmed_token_str = php_trim(zend_string_init(token, strlen(token), 0), " \t\n\r\v\f", 7, 3);
        if (ZSTR_LEN(trimmed_token_str) > 0 && !is_numeric_token(ZSTR_VAL(trimmed_token_str))) {
            is_valid = false;
        }
        zend_string_release(trimmed_token_str);
        if (!is_valid) break;
    }
    efree(input_copy);

    if (!is_valid) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid value provided. Expected a comma-separated string of numbers.");
        return FAILURE;
    }

    if (*target) { pefree(*target, 1); }
    *target = pestrdup(input_str, 1);
    return SUCCESS;
}
