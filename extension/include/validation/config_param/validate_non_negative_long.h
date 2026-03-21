/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_non_negative_long.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for non-negative integers.
 * =========================================================================
 */

#ifndef KING_VALIDATION_NON_NEGATIVE_LONG_H
#define KING_VALIDATION_NON_NEGATIVE_LONG_H

#include "php.h"

/**
 * @brief Validates that a zval is a long greater than or equal to zero.
 * @param value The zval to validate.
 * @param param_name Parameter name used in exception messages.
 * @param target Receives the validated integer on success.
 */
int kg_validate_non_negative_long(zval *value, const char *param_name, zend_long *target);

#endif /* KING_VALIDATION_NON_NEGATIVE_LONG_H */
