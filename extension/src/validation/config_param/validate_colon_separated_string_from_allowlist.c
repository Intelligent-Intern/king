/*
 * =========================================================================
 * FILENAME:   src/validation/config_param/validate_colon_separated_string_from_allowlist.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * WELCOME:    "A chain is only as strong as its weakest link; a config only
 *              as strong as its weakest token."
 *
 * PURPOSE:
 *   Implements kg_validate_colon_separated_string_from_allowlist().  The
 *   function performs strict type checking (must be a PHP string) and then
 *   iterates over each colon‑separated token, comparing it case‑sensitively
 *   against the provided allow‑list.  Any unknown token causes validation to
 *   fail immediately.  On success, an *exact* copy of the original string is
 *   duplicated into persistent memory for storage in a module config struct.
 * =========================================================================
 */
#include "include/validation/config_param/validate_colon_separated_string_from_allowlist.h"
#include <ext/spl/spl_exceptions.h> /* spl_ce_InvalidArgumentException */

int kg_validate_colon_separated_string_from_allowlist(zval *value, const char *allowed[], char **dest)
{
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException, 0,
            "Invalid value provided. A string is required.");
        return FAILURE;
    }

    /* Work on a temporary, non‑persistent copy. */
    char *input      = estrndup(Z_STRVAL_P(value), Z_STRLEN_P(value));
    char *token      = NULL;
    char *saveptr    = NULL;

    for (token = strtok_r(input, ":", &saveptr);
         token != NULL;
         token = strtok_r(NULL, ":", &saveptr)) {

        int allowed_match = 0;
        for (int i = 0; allowed[i] != NULL; ++i) {
            if (strcmp(token, allowed[i]) == 0) {
                allowed_match = 1;
                break;
            }
        }

        if (!allowed_match) {
            efree(input);
            zend_throw_exception_ex(
                spl_ce_InvalidArgumentException, 0,
                "Unknown token '%s' encountered. Allowed values are restricted by the module.",
                token);
            return FAILURE;
        }
    }

    /* Validation succeeded; persist the original string if requested. */
    if (dest != NULL) {
        if (*dest == NULL) {
            *dest = pestrdup(Z_STRVAL_P(value), 1);
        } else {
            /* Caller is responsible for freeing previous allocation to avoid leaks. */
            *dest = pestrdup(Z_STRVAL_P(value), 1);
        }
    }

    efree(input);
    return SUCCESS;
}
