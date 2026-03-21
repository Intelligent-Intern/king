/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_comma_separated_numeric_string.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for comma-separated numeric strings.
 * =========================================================================
 */

#ifndef KING_VALIDATION_COMMA_SEPARATED_NUMERIC_STRING_H
#define KING_VALIDATION_COMMA_SEPARATED_NUMERIC_STRING_H

#include "php.h"

/**
 * @brief Validates a comma-separated string of numeric values.
 * @details On success the original string is copied into persistent memory
 * and written to `target`.
 *
 * @param value The zval to validate.
 * @param target Receives the persistent copy on success.
 * @return `SUCCESS` on successful validation, `FAILURE` otherwise.
 */
int kg_validate_comma_separated_numeric_string(zval *value, char **target);

#endif /* KING_VALIDATION_COMMA_SEPARATED_NUMERIC_STRING_H */
