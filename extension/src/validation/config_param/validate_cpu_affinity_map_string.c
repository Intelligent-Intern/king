/*
 * =========================================================================
 * FILENAME:   src/validation/config_param/validate_cpu_affinity_map_string.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * WELCOME:    A new life awaits you in the Off-world colonies! A chance
 * to begin again in a golden land of opportunity and adventure!
 *
 * PURPOSE:
 * This file implements the validation helper for CPU affinity map strings.
 *
 * ARCHITECTURE:
 * This function performs multi-level tokenization and validation to
 * strictly enforce the `worker:core-range` format. It uses `strtok_r`
 * to parse the comma-separated list and then further dissects each
 * entry to validate its components, ensuring that only a perfectly
 * formatted string can be accepted into the configuration.
 * =========================================================================
 */

#include "include/validation/config_param/validate_cpu_affinity_map_string.h"
#include "ext/standard/php_string.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>
#include <ctype.h>

static bool kg_is_numeric_affinity_string(const char *s) {
    if (s == NULL || *s == '\0' || isspace(*s)) return false;
    char *p;
    strtol(s, &p, 10);
    return *p == '\0';
}

int kg_validate_cpu_affinity_map_string(zval *value, char **target) {
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type for CPU affinity map. A string is required.");
        return FAILURE;
    }

    char *input_str = Z_STRVAL_P(value);
    if (strlen(input_str) == 0) { /* Empty string is valid (no affinity) */
        if (*target) { pefree(*target, 1); }
        *target = pestrdup(input_str, 1);
        return SUCCESS;
    }

    char *input_copy = estrdup(input_str);
    char *entry_token, *range_token;
    char *entry_saveptr, *range_saveptr, *core_saveptr;
    bool is_valid = true;

    for (entry_token = php_strtok_r(input_copy, ",", &entry_saveptr);
         entry_token != NULL;
         entry_token = php_strtok_r(NULL, ",", &entry_saveptr))
    {
        char *worker_part = php_strtok_r(entry_token, ":", &range_saveptr);
        char *core_part = php_strtok_r(NULL, ":", &range_saveptr);

        if (worker_part == NULL || core_part == NULL || !kg_is_numeric_affinity_string(worker_part)) {
            is_valid = false;
            break;
        }

        char *core_start = php_strtok_r(core_part, "-", &core_saveptr);
        char *core_end = php_strtok_r(NULL, "-", &core_saveptr);

        if (core_start == NULL || !kg_is_numeric_affinity_string(core_start)) {
            is_valid = false;
            break;
        }
        if (core_end != NULL && !kg_is_numeric_affinity_string(core_end)) {
            is_valid = false;
            break;
        }
    }
    efree(input_copy);

    if (!is_valid) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid format for CPU affinity map. Expected format like '0:0-1,1:2-3'.");
        return FAILURE;
    }

    if (*target) { pefree(*target, 1); }
    *target = pestrdup(input_str, 1);
    return SUCCESS;
}
