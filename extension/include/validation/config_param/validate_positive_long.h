/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_positive_long.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for positive integers.
 * =========================================================================
 */

#ifndef KING_VALIDATION_POSITIVE_LONG_H
#define KING_VALIDATION_POSITIVE_LONG_H

#include "php.h"

/**
 * @brief Validates that a zval is a long greater than zero.
 *
 * @param value The zval to validate.
 * @param target Receives the validated integer on success.
 * @return `SUCCESS` if the value is valid and assigned, `FAILURE` otherwise.
 */
int kg_validate_positive_long(zval *value, zend_long *target);

#endif /* KING_VALIDATION_POSITIVE_LONG_H */
