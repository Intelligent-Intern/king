/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_string.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the strict string validator used by config parsing.
 * =========================================================================
 */

#ifndef KING_VALIDATION_STRING_H
#define KING_VALIDATION_STRING_H

#include "php.h"

/**
 * @brief Validates that a zval is a strict PHP string.
 * @details No type juggling is performed. If `dest` is non-NULL, it receives
 * a persistent duplicate of the original string and callers own any previous
 * allocation stored there.
 */
int kg_validate_string(zval *value, char **dest);

#endif /* KING_VALIDATION_STRING_H */
