/*
 * =========================================================================
 * FILENAME:   src/validation/config_param/validate_host_string.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * WELCOME:    This is the business we've chosen.
 *
 * PURPOSE:
 * This file implements the validation helper for host strings.
 *
 * ARCHITECTURE:
 * The function performs a character-level validation to ensure that the
 * provided string conforms to the allowed character set for hostnames
 * and IP addresses, preventing invalid input from reaching network layers.
 * =========================================================================
 */

#include "include/validation/config_param/validate_host_string.h"
#include <ext/spl/spl_exceptions.h>
#include <ctype.h>

int kg_validate_host_string(zval *value, char **target)
{
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided for host. A string is required.");
        return FAILURE;
    }

    const char *host = Z_STRVAL_P(value);
    size_t len = Z_STRLEN_P(value);

    if (len == 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid value provided for host. A non-empty string is required.");
        return FAILURE;
    }

    for (size_t i = 0; i < len; i++) {
        /* Basic sanity check: allow alphanumeric, dots, dashes, and colons (for IPv6). */
        if (!isalnum(host[i]) && host[i] != '.' && host[i] != '-' && host[i] != ':') {
             zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid character detected in host string.");
             return FAILURE;
        }
    }

    /* Free the old string if it exists. */
    if (*target) {
        pefree(*target, 1);
    }
    *target = pestrdup(host, 1);

    return SUCCESS;
}
