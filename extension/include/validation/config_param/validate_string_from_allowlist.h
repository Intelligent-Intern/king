/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_string_from_allowlist.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for string values constrained by an allowlist.
 * =========================================================================
 */

#ifndef KING_VALIDATION_STRING_FROM_ALLOWLIST_H
#define KING_VALIDATION_STRING_FROM_ALLOWLIST_H

#include "php.h"

/**
 * @brief Validates that a string matches one of the allowed values.
 * @details Matching is case-insensitive. On success the original string is
 * copied into persistent memory and written to `target`.
 *
 * @param value The zval to validate.
 * @param allowed_values NULL-terminated array of valid options.
 * @param target Receives the persistent copy on success.
 * @return `SUCCESS` on successful validation, `FAILURE` otherwise.
 */
int kg_validate_string_from_allowlist(zval *value, const char *allowed_values[], char **target);

#endif /* KING_VALIDATION_STRING_FROM_ALLOWLIST_H */
