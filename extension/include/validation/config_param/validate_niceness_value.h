/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_niceness_value.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for Linux niceness values.
 * =========================================================================
 */

#ifndef KING_VALIDATION_NICENESS_VALUE_H
#define KING_VALIDATION_NICENESS_VALUE_H

#include "php.h"

/**
 * @brief Validates a niceness value in the standard Linux range.
 *
 * @param value The zval to validate.
 * @param target A pointer to a zend_long where the validated value will be
 * stored upon success.
 * @return `SUCCESS` on successful validation, `FAILURE` otherwise.
 */
int kg_validate_niceness_value(zval *value, zend_long *target);

#endif /* KING_VALIDATION_NICENESS_VALUE_H */
