/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_cors_origin_string.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for CORS origin policy strings.
 * =========================================================================
 */

#ifndef KING_VALIDATION_CORS_ORIGIN_STRING_H
#define KING_VALIDATION_CORS_ORIGIN_STRING_H

#include "php.h"

/**
 * @brief Validates a CORS origin policy string.
 * @details Accepts either `"*"` or a comma-separated list of origins.
 * Each origin is parsed with PHP's URL parser and must use `http` or
 * `https`. On success the original string is copied into persistent
 * memory and written to `target`.
 *
 * @param value The zval to validate, which must be a string.
 * @param param_name Reserved for call-site context. The current
 * implementation does not include it in exception text.
 * @param target Receives the persistent copy on success. Callers own any
 * previous allocation stored there.
 * @return `SUCCESS` on successful validation, `FAILURE` otherwise.
 */
int kg_validate_cors_origin_string(zval *value, const char *param_name, char **target);

#endif /* KING_VALIDATION_CORS_ORIGIN_STRING_H */
