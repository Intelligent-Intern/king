/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_long_range.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for bounded integer values.
 * =========================================================================
 */

#ifndef KING_VALIDATION_LONG_RANGE_H
#define KING_VALIDATION_LONG_RANGE_H

#include "php.h"

/**
 * @brief Validates that a zval is a long within an inclusive range.
 *
 * @param value The zval to validate.
 * @param min_value Minimum allowed value (inclusive).
 * @param max_value Maximum allowed value (inclusive).
 * @param target Receives the validated integer on success.
 * @return `SUCCESS` if the value is valid and assigned, `FAILURE` otherwise.
 */
int kg_validate_long_range(zval *value, zend_long min_value, zend_long max_value, zend_long *target);

#endif /* KING_VALIDATION_LONG_RANGE_H */
