/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_scheduler_policy.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for Linux scheduler policy strings.
 * =========================================================================
 */

#ifndef KING_VALIDATION_SCHEDULER_POLICY_H
#define KING_VALIDATION_SCHEDULER_POLICY_H

#include "php.h"

/**
 * @brief Validates a scheduler policy string.
 * @details Allowed values are `other`, `fifo`, and `rr`. On success the
 * original string is copied into persistent memory and written to `target`.
 *
 * @param value The zval to validate.
 * @param target Receives the persistent copy on success.
 * @return `SUCCESS` on successful validation, `FAILURE` otherwise.
 */
int kg_validate_scheduler_policy(zval *value, char **target);

#endif /* KING_VALIDATION_SCHEDULER_POLICY_H */
