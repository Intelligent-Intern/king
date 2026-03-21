/*
 * =========================================================================
 * FILENAME:   src/validation/config_param/validate_scale_up_policy_string.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * WELCOME:    Only I will remain.
 *
 * PURPOSE:
 * This file implements the validation helper for scale-up policy strings.
 * =========================================================================
 */

#include "include/validation/config_param/validate_scale_up_policy_string.h"
#include "ext/standard/php_string.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>
#include <ctype.h>

static bool is_numeric_string_policy(const char *s) {
    if (s == NULL || *s == '\0' || isspace(*s)) return false;
    char *p;
    strtol(s, &p, 10);
    return *p == '\0';
}

int kg_validate_scale_up_policy_string(zval *value, char **target) {
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type for scale-up policy. A string is required.");
        return FAILURE;
    }

    char *input_str = Z_STRVAL_P(value);
    char *input_copy = estrdup(input_str);
    char *saveptr = NULL;
    char *action_part = php_strtok_r(input_copy, ":", &saveptr);
    char *value_part = php_strtok_r(NULL, ":", &saveptr);
    bool is_valid = false;

    if (action_part && value_part) {
        if ((strcmp(action_part, "add_nodes") == 0 || strcmp(action_part, "add_percent") == 0) && is_numeric_string_policy(value_part)) {
            is_valid = true;
        }
    }

    efree(input_copy);

    if (!is_valid) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid format for scale-up policy. Expected format like 'add_nodes:1' or 'add_percent:10'.");
        return FAILURE;
    }

    if (*target) { pefree(*target, 1); }
    *target = pestrdup(input_str, 1);
    return SUCCESS;
}
