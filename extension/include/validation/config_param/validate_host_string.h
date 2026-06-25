/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_host_string.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for host strings.
 * =========================================================================
 */

#ifndef KING_VALIDATION_HOST_STRING_H
#define KING_VALIDATION_HOST_STRING_H

#include "php.h"

/**
 * @brief Validates a hostname or IP literal string.
 * @details This is a basic character-level sanity check only. It does not
 * perform DNS resolution.
 *
 * @param value The zval to validate.
 * @param target Receives the persistent copy on success.
 * @return `SUCCESS` on successful validation, `FAILURE` otherwise.
 */
int kg_validate_host_string(zval *value, char **target);

#endif /* KING_VALIDATION_HOST_STRING_H */
