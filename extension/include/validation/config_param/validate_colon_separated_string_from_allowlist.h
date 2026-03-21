/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_colon_separated_string_from_allowlist.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for colon-separated allowlist strings.
 * =========================================================================
 */
#ifndef KING_VALIDATION_COLON_SEPARATED_STRING_FROM_ALLOWLIST_H
#define KING_VALIDATION_COLON_SEPARATED_STRING_FROM_ALLOWLIST_H

#include "php.h"

/**
 * @brief Validates a colon-separated string against a fixed allowlist.
 * @details Matching is case-sensitive. On success the original string is
 * copied into persistent memory and written to `dest`.
 *
 * @param value   The zval containing the string to validate.
 * @param allowed NULL-terminated array of valid tokens.
 * @param dest If non-NULL, receives a persistent duplicate of the original
 * string. Callers own any previous allocation stored there.
 *
 * @return SUCCESS on success, FAILURE otherwise (and throws
 *         InvalidArgumentException on error).
 */
int kg_validate_colon_separated_string_from_allowlist(zval *value, const char *allowed[], char **dest);

#endif /* KING_VALIDATION_COLON_SEPARATED_STRING_FROM_ALLOWLIST_H */
