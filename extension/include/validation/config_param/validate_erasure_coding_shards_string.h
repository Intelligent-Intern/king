/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_erasure_coding_shards_string.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for erasure-coding shard strings.
 * =========================================================================
 */

#ifndef KING_VALIDATION_ERASURE_CODING_SHARDS_STRING_H
#define KING_VALIDATION_ERASURE_CODING_SHARDS_STRING_H

#include "php.h"

/**
 * @brief Validates an erasure-coding shard string.
 * @details Accepts values like `8d4p`. On success the original string is
 * copied into persistent memory and written to `target`.
 *
 * @param value The zval to validate.
 * @param target Receives the persistent copy on success.
 * @return `SUCCESS` on successful validation, `FAILURE` otherwise.
 */
int kg_validate_erasure_coding_shards_string(zval *value, char **target);

#endif /* KING_VALIDATION_ERASURE_CODING_SHARDS_STRING_H */
