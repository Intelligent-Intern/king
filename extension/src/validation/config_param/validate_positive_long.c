/*
 * Validation helper for strictly positive integers. Enforces long input and
 * the `> 0` contract used by multiple config families.
 */

#include "include/validation/config_param/validate_positive_long.h"
#include "php.h"
#include <zend_exceptions.h>
#include <ext/spl/spl_exceptions.h>

/**
 * @brief Validates if a zval is a positive long integer and assigns it.
 * @details Checks if the provided zval is of type IS_LONG and greater than 0.
 * If validation passes, assigns the value to the target variable.
 * If validation fails, throws a detailed InvalidArgumentException.
 *
 * @param value The zval to validate.
 * @param target Pointer to the target variable to assign the validated value.
 * @return `SUCCESS` if the value is valid and assigned, `FAILURE` otherwise.
 */
int kg_validate_positive_long(zval *value, zend_long *target)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, 
            "Configuration parameter must be an integer, %s given.", 
            zend_get_type_by_const(Z_TYPE_P(value)));
        return FAILURE;
    }
    
    if (Z_LVAL_P(value) <= 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, 
            "Configuration parameter must be a positive integer, %ld given.", 
            Z_LVAL_P(value));
        return FAILURE;
    }
    
    *target = Z_LVAL_P(value);
    return SUCCESS;
}
