/*
 * Validation helper for Linux niceness values. Enforces integer input and the
 * bounded priority range accepted by the cluster/process config surface.
 */

#include "include/validation/config_param/validate_niceness_value.h"
#include <zend_exceptions.h>
#include <ext/spl/spl_exceptions.h> /* For spl_ce_InvalidArgumentException */

/**
 * @brief Validates if a zval is a valid niceness value (typically -20 to 19).
 */
int kg_validate_niceness_value(zval *value, zend_long *target)
{
    /* Rule 1: Enforce strict integer type. */
    if (Z_TYPE_P(value) != IS_LONG) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid type provided for niceness value. An integer is required."
        );
        return FAILURE;
    }

    /* Rule 2: Enforce valid range. */
    if (Z_LVAL_P(value) < -20 || Z_LVAL_P(value) > 19) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value provided for niceness. Value must be between -20 and 19."
        );
        return FAILURE;
    }

    /* Validation passed, store the value in the target pointer. */
    *target = Z_LVAL_P(value);
    return SUCCESS;
}
