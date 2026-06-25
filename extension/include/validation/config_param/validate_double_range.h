/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_double_range.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for bounded floating-point values.
 * =========================================================================
 */

#ifndef KING_VALIDATION_DOUBLE_RANGE_H
#define KING_VALIDATION_DOUBLE_RANGE_H

#include "php.h"

/**
 * @brief Validates that a zval is a double within an inclusive range.
 *
 * @param value The zval to validate.
 * @param min The minimum allowed value (inclusive).
 * @param max The maximum allowed value (inclusive).
 * @param target A pointer to a double where the validated value will be stored.
 * @return `SUCCESS` on successful validation, `FAILURE` otherwise.
 */
int kg_validate_double_range(zval *value, double min, double max, double *target);

#endif /* KING_VALIDATION_DOUBLE_RANGE_H */
