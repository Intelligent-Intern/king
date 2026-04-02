/*
 * Generic string validator for config parsing. Enforces string input and
 * returns a duplicated value suitable for longer-lived config state.
 */

#include "include/validation/config_param/validate_generic_string.h"
#include <zend_exceptions.h>
#include <ext/spl/spl_exceptions.h> /* For spl_ce_InvalidArgumentException */

/**
 * @brief Validates if a zval is a string (can be empty).
 */
int kg_validate_generic_string(zval *value, char **target)
{
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid type provided. A string is required."
        );
        return FAILURE;
    }

    /* Free the old string if it exists. */
    if (*target) {
        pefree(*target, 1);
    }
    *target = pestrdup(Z_STRVAL_P(value), 1);

    return SUCCESS;
}
