/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_readable_file_path.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for readable file paths.
 * =========================================================================
 */

#ifndef KING_VALIDATION_READABLE_FILE_PATH_H
#define KING_VALIDATION_READABLE_FILE_PATH_H

#include "php.h"

/**
 * @brief Validates a readable file path string.
 * @details Empty strings are accepted as an unset value. Non-empty paths
 * must point to a readable file. On success the original string is copied
 * into persistent memory and written to `target`.
 *
 * @param value The zval to validate.
 * @param target Receives the persistent copy on success.
 * @return `SUCCESS` on successful validation, `FAILURE` otherwise.
 */
int kg_validate_readable_file_path(zval *value, char **target);

#endif /* KING_VALIDATION_READABLE_FILE_PATH_H */
