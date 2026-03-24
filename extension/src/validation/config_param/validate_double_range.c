/*
 * =========================================================================
 * FILENAME:   src/validation/config_param/validate_double_range.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * WELCOME:    (He nods, and takes the pill.)
 *
 * PURPOSE:
 * This file implements the validation helper for floating-point values
 * that must be within a specific range.
 * =========================================================================
 */

#include "include/validation/config_param/validate_double_range.h"
#include <zend_exceptions.h>
#include <ext/spl/spl_exceptions.h>

/**
 * @brief Validates if a zval is a double within a specified [min, max] range.
 */
int kg_validate_double_range(zval *value, double min, double max, double *target)
{
    if (Z_TYPE_P(value) != IS_DOUBLE) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid type provided. A float (double) is required."
        );
        return FAILURE;
    }

    double val = Z_DVAL_P(value);
    if (val < min || val > max) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value provided. The value is outside the allowed range."
        );
        return FAILURE;
    }

    *target = val;
    return SUCCESS;
}
