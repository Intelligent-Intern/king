/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_generic_string.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for generic string parameters.
 * =========================================================================
 */

#ifndef KING_VALIDATION_GENERIC_STRING_H
#define KING_VALIDATION_GENERIC_STRING_H

#include "php.h"

/**
 * @brief Validates that a zval is a string. Empty strings are allowed.
 *
 * @param value The zval to validate.
 * @param target Receives the persistent copy on success.
 * @return `SUCCESS` on successful validation, `FAILURE` otherwise.
 */
int kg_validate_generic_string(zval *value, char **target);

#endif /* KING_VALIDATION_GENERIC_STRING_H */
