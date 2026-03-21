/*
 * =========================================================================
 * FILENAME:   src/validation/config_param/validate_readable_file_path.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * WELCOME:    Just when I thought I was out, they pull me back in.
 *
 * PURPOSE:
 * This file implements the validation helper for readable file paths.
 *
 * ARCHITECTURE:
 * This function uses the `VCWD_ACCESS` macro, which is PHP's internal,
 * cross-platform way to check file permissions (`access()` syscall on Unix).
 * This ensures that configuration parameters pointing to files (like
 * certificates or keys) are validated at load time, preventing runtime
 * errors due to missing files or incorrect permissions.
 * =========================================================================
 */

#include "include/validation/config_param/validate_readable_file_path.h"
#include "main/php_streams.h"
#include <ext/spl/spl_exceptions.h>

int kg_validate_readable_file_path(zval *value, char **target)
{
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided for file path. A string is required.");
        return FAILURE;
    }

    char *path = Z_STRVAL_P(value);
    if (strlen(path) == 0) {
        /* An empty path can be a valid "not set" value. */
        if (*target) { pefree(*target, 1); }
        *target = pestrdup(path, 1);
        return SUCCESS;
    }

    /* Use PHP's cross-platform file access check. R_OK checks for read permission. */
    if (VCWD_ACCESS(path, R_OK) != 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Provided file path is not accessible or does not exist.");
        return FAILURE;
    }

    /* Free the old string if it exists. */
    if (*target) {
        pefree(*target, 1);
    }
    *target = pestrdup(path, 1);

    return SUCCESS;
}
