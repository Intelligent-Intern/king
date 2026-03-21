/*
 * =========================================================================
 * FILENAME:   include/validation/config_param/validate_cpu_affinity_map_string.h
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Declares the validator for CPU affinity map strings.
 * =========================================================================
 */

#ifndef KING_VALIDATION_CPU_AFFINITY_MAP_STRING_H
#define KING_VALIDATION_CPU_AFFINITY_MAP_STRING_H

#include "php.h"

/**
 * @brief Validates a CPU affinity map string.
 * @details Accepts strings like `0:0-1,1:2-3`. Empty strings are valid and
 * represent no affinity binding. On success the original string is copied
 * into persistent memory and written to `target`.
 *
 * @param value The zval to validate.
 * @param target Receives the persistent copy on success.
 * @return `SUCCESS` on successful validation, `FAILURE` otherwise.
 */
int kg_validate_cpu_affinity_map_string(zval *value, char **target);

#endif /* KING_VALIDATION_CPU_AFFINITY_MAP_STRING_H */
