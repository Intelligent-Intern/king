/*
 * =========================================================================
 * FILENAME:   src/validation/config_param/validate_bool.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * WELCOME:    I can see it in your eyes. You have the look of a man who accepts what he sees because he is expecting to wake up.
 *
 * PURPOSE:
 * This file implements centralized, reusable validation helper function
 * for boolean values passed from PHP userland.
 * =========================================================================
 */

#include "include/validation/config_param/validate_bool.h"
#include "php.h"
#include <ext/spl/spl_exceptions.h>

/**
 * @brief Validates if a zval is a strict boolean (true or false).
 * @details Checks if the provided zval is of type IS_TRUE or IS_FALSE.
 * It does not perform type juggling. If the type is incorrect, it throws
 * a detailed InvalidArgumentException. This function is a cornerstone
 * for enforcing strict type checking for all boolean configuration
 * parameters passed from userland.
 *
 * @param value The zval to validate.
 * @param param_name The name of the configuration parameter being validated,
 * used for generating a clear and helpful exception message.
 * @return `SUCCESS` if the value is a valid boolean, `FAILURE` otherwise.
 */
int kg_validate_bool(zval *value, const char *param_name)
{
    if (Z_TYPE_P(value) != IS_TRUE && Z_TYPE_P(value) != IS_FALSE) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, 
            "Configuration parameter '%s' must be a boolean (true or false), %s given.", 
            param_name, zend_get_type_by_const(Z_TYPE_P(value)));
        return FAILURE;
    }
    
    return SUCCESS;
}