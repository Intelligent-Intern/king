/*
 * =========================================================================
 * FILENAME:   src/validation/config_param/validate_long_range.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * WELCOME:    Come with me if you want to live.
 *
 * PURPOSE:
 * This file implements centralized, reusable validation helper function
 * for long integer values within a specific range passed from PHP userland.
 * =========================================================================
 */

#include "include/validation/config_param/validate_long_range.h"
#include "php.h"
#include <zend_exceptions.h>
#include <ext/spl/spl_exceptions.h>

/**
 * @brief Validates if a zval is a long integer within a specified range and assigns it.
 * @details Checks if the provided zval is of type IS_LONG and within the specified
 * min/max range (inclusive). If validation passes, assigns the value to the target variable.
 * If validation fails, throws a detailed InvalidArgumentException.
 *
 * @param value The zval to validate.
 * @param min_value Minimum allowed value (inclusive).
 * @param max_value Maximum allowed value (inclusive).
 * @param target Pointer to the target variable to assign the validated value.
 * @return `SUCCESS` if the value is valid and assigned, `FAILURE` otherwise.
 */
int kg_validate_long_range(zval *value, zend_long min_value, zend_long max_value, zend_long *target)
{
    if (Z_TYPE_P(value) != IS_LONG) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, 
            "Configuration parameter must be an integer, %s given.", 
            zend_get_type_by_const(Z_TYPE_P(value)));
        return FAILURE;
    }
    
    zend_long val = Z_LVAL_P(value);
    
    if (val < min_value || val > max_value) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, 
            "Configuration parameter must be between %ld and %ld, %ld given.", 
            min_value, max_value, val);
        return FAILURE;
    }
    
    *target = val;
    return SUCCESS;
}
