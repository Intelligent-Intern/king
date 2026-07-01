/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_comma_separated_string_from_allowlist.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for comma-separated allowlist strings.
 * =========================================================================
 */

#ifndef KING_VALIDATION_COMMA_SEPARATED_STRING_FROM_ALLOWLIST_H
#define KING_VALIDATION_COMMA_SEPARATED_STRING_FROM_ALLOWLIST_H

#include "php.h"

/**
 * @brief Validates a comma-separated string against an allowlist.
 * @details Matching is case-insensitive. Empty tokens are accepted after
 * trimming. On success the original string is copied into persistent memory
 * and written to `target`.
 *
 * @param value The zval to validate.
 * @param allowed_values NULL-terminated array of valid options.
 * @param target Receives the persistent copy on success.
 * @return `SUCCESS` on successful validation, `FAILURE` otherwise.
 */
int kg_validate_comma_separated_string_from_allowlist(zval *value, const char *allowed_values[], char **target);

#endif /* KING_VALIDATION_COMMA_SEPARATED_STRING_FROM_ALLOWLIST_H */
