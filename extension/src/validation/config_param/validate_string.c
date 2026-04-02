/*
 * Core string validator for config parsing. Enforces string input and returns
 * a duplicated value suitable for persistent module-config storage.
 */

#include "include/validation/config_param/validate_string.h"
#include <zend_exceptions.h>
#include <ext/spl/spl_exceptions.h> /* spl_ce_InvalidArgumentException */

/**
 * @see validate_string.h for complete contract.
 */
int kg_validate_string(zval *value, char **dest)
{
    /* 1. Strict type check - no auto‑casting allowed */
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException, 0,
            "Invalid value provided. Expected a plain PHP string.");
        return FAILURE;
    }

    /* 2. If the caller wants the raw C‑string, provide a persistent duplicate */
    if (dest) {
        /* Free previously allocated memory (if any) */
        if (*dest) {
            pefree(*dest, 1);
        }
        *dest = pestrdup(Z_STRVAL_P(value), 1); /* persistent = 1 */
    }

    return SUCCESS;
}
