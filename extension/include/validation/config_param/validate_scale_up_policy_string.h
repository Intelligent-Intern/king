/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_scale_up_policy_string.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for scale-up policy strings.
 * =========================================================================
 */

#ifndef KING_VALIDATION_SCALE_UP_POLICY_STRING_H
#define KING_VALIDATION_SCALE_UP_POLICY_STRING_H

#include "php.h"

/**
 * @brief Validates a scale-up policy string.
 * @details Accepts values like `add_nodes:1` and `add_percent:10`. On
 * success the original string is copied into persistent memory and written
 * to `target`.
 *
 * @param value The zval to validate.
 * @param target Receives the persistent copy on success.
 * @return `SUCCESS` on successful validation, `FAILURE` otherwise.
 */
int kg_validate_scale_up_policy_string(zval *value, char **target);

#endif /* KING_VALIDATION_SCALE_UP_POLICY_STRING_H */
