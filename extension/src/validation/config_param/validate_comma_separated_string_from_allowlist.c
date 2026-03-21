/*
 * =========================================================================
 * FILENAME:   src/validation/config_param/validate_comma_separated_string_from_allowlist.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * WELCOME:    And many of them are so inert, so hopelessly dependent on the
 * system, that they will fight to protect it.
 *
 * PURPOSE:
 * This file implements the validation helper for comma-separated string
 * values that must match an allowed set of options.
 * =========================================================================
 */

#include "include/validation/config_param/validate_comma_separated_string_from_allowlist.h"
#include "ext/standard/php_string.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>

/**
 * @brief Validates if a zval is a comma-separated string where each token
 * exists in a predefined allow-list.
 */
int kg_validate_comma_separated_string_from_allowlist(zval *value, const char *allowed_values[], char **target)
{
    char *input_str;
    char *input_copy;
    char *token;
    char *saveptr;
    bool is_valid = true;

    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided. A string is required.");
        return FAILURE;
    }

    input_str = Z_STRVAL_P(value);
    input_copy = estrdup(input_str);

    for (token = php_strtok_r(input_copy, ",", &saveptr);
         token != NULL;
         token = php_strtok_r(NULL, ",", &saveptr))
    {
        zend_string *trimmed_token_str = php_trim(zend_string_init(token, strlen(token), 0), " \t\n\r\v\f", 7, 3);
        bool token_is_valid = false;

        if (ZSTR_LEN(trimmed_token_str) > 0) {
            for (int i = 0; allowed_values[i] != NULL; i++) {
                if (strcasecmp(ZSTR_VAL(trimmed_token_str), allowed_values[i]) == 0) {
                    token_is_valid = true;
                    break;
                }
            }
        } else {
            token_is_valid = true; /* Allow empty entries */
        }
        zend_string_release(trimmed_token_str);

        if (!token_is_valid) {
            is_valid = false;
            break;
        }
    }
    efree(input_copy);

    if (!is_valid) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value provided. The value contains an unsupported algorithm or format."
        );
        return FAILURE;
    }

    /* Free the old string if it exists. */
    if (*target) {
        pefree(*target, 1);
    }
    *target = pestrdup(input_str, 1);

    return SUCCESS;
}
