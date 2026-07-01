/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_bool.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the strict boolean validator used by config parsing.
 * =========================================================================
 */

#ifndef KING_VALIDATION_BOOL_H
#define KING_VALIDATION_BOOL_H

#include "php.h"

/**
 * @brief Validates that a zval is a strict boolean value.
 * @details No type juggling is performed. The value must already be
 * IS_TRUE or IS_FALSE, otherwise InvalidArgumentException is thrown.
 *
 * @param value The zval to validate.
 * @param param_name The name of the configuration parameter being validated,
 * used for generating a clear and helpful exception message.
 * @return `SUCCESS` if the value is a valid boolean, `FAILURE` otherwise.
 */
int kg_validate_bool(zval *value, const char *param_name);

#endif /* KING_VALIDATION_BOOL_H */
