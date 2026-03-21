/*
 * =========================================================================
 * FILENAME:   src/validation/config_param/validate_string_from_allowlist.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * WELCOME:    ...you stay in Wonderland, and I show you how deep the
 * rabbit hole goes.
 *
 * PURPOSE:
 * This file implements the validation helper for string values that must
 * match one of an allowed set of options.
 * =========================================================================
 */

#include "include/validation/config_param/validate_string_from_allowlist.h"
#include <ext/spl/spl_exceptions.h>

/**
 * @brief Validates if a zval is a string that exists in a predefined allow-list.
 */
int kg_validate_string_from_allowlist(zval *value, const char *allowed_values[], char **target)
{
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid type provided. A string is required."
        );
        return FAILURE;
    }

    const char *input_str = Z_STRVAL_P(value);
    bool is_allowed = false;
    for (int i = 0; allowed_values[i] != NULL; i++) {
        if (strcasecmp(input_str, allowed_values[i]) == 0) {
            is_allowed = true;
            break;
        }
    }

    if (!is_allowed) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value provided. The value is not one of the allowed options."
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
