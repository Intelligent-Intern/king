/*
 * =========================================================================
 * FILENAME:   src/validation/config_param/validate_scheduler_policy.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * WELCOME:    If you'd like to hear it I can sing it for you.
 *
 * PURPOSE:
 * This file implements the centralized, reusable validation helper function
 * for Linux scheduler policy strings.
 *
 * ARCHITECTURE:
 * The function validates against a fixed allow-list of known, valid
 * scheduler policies, preventing arbitrary strings from being passed
 * to OS-level scheduling functions.
 * =========================================================================
 */

#include "include/validation/config_param/validate_scheduler_policy.h"
#include <ext/spl/spl_exceptions.h> /* For spl_ce_InvalidArgumentException */

/**
 * @brief Validates if a zval is a valid scheduler policy string.
 */
int kg_validate_scheduler_policy(zval *value, char **target)
{
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid type provided for scheduler policy. A string is required."
        );
        return FAILURE;
    }

    const char *policy = Z_STRVAL_P(value);

    if (strcmp(policy, "other") != 0 &&
        strcmp(policy, "fifo") != 0 &&
        strcmp(policy, "rr") != 0)
    {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value for scheduler policy. Must be one of 'other', 'fifo', or 'rr'."
        );
        return FAILURE;
    }

    /* Free the old string if it exists. */
    if (*target) {
        pefree(*target, 1);
    }
    *target = pestrdup(policy, 1);

    return SUCCESS;
}
