/*
 * Validation helper for CORS origin strings. Enforces string input and the
 * bounded origin-shape checks used by the current server config surface.
 */

#include "include/validation/config_param/validate_cors_origin_string.h"

#include "ext/standard/php_string.h" /* For php_trim */
#include "ext/standard/url.h"        /* For php_url_parse_ex */
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h> /* For spl_ce_InvalidArgumentException */

/**
 * @brief Validates if a zval contains a valid CORS origin string.
 * @details This function provides robust validation for the CORS policy string.
 * It accepts either a single wildcard `"*"` or a comma-separated list of
 * valid origins. Each origin in the list is individually parsed using
 * PHP's internal `php_url_parse_ex` function to ensure it has a valid
 * structure (`scheme://host:port`) and an appropriate scheme (`http` or
 * `https`).
 *
 * @param value The zval to validate, which must be a string.
 * @param param_name The name of the configuration parameter being validated.
 * @param target A pointer to a char* where a newly allocated, validated
 * string will be stored. The caller is responsible for managing the
 * memory of any pre-existing string in the target.
 * @return `SUCCESS` on successful validation, `FAILURE` otherwise.
 */
int kg_validate_cors_origin_string(zval *value, const char *param_name, char **target)
{
    char *input_str;
    char *input_copy;
    char *token;
    char *saveptr;
    bool is_valid = true;

    /* Rule 1: Enforce strict string type. */
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid type provided for CORS origin policy. A string is required."
        );
        return FAILURE;
    }

    input_str = Z_STRVAL_P(value);

    /* Rule 2: A single wildcard is a valid policy. */
    if (strcmp(input_str, "*") == 0) {
        is_valid = true;
    } else {
        /*
         * Rule 3: For all other cases, it must be a valid, comma-separated
         * list of origins. We tokenize the string and validate each part.
         */
        input_copy = estrdup(input_str);

        for (token = php_strtok_r(input_copy, ",", &saveptr);
             token != NULL;
             token = php_strtok_r(NULL, ",", &saveptr))
        {
            zend_string *trimmed_token_str;
            php_url *parsed_url;

            trimmed_token_str = php_trim(zend_string_init(token, strlen(token), 0), " \t\n\r\v\f", 7, 3);

            /* Allow trailing commas or empty entries by skipping them. */
            if (ZSTR_LEN(trimmed_token_str) == 0) {
                zend_string_release(trimmed_token_str);
                continue;
            }

            /* Use PHP's internal parser for robust validation. */
            parsed_url = php_url_parse_ex(ZSTR_VAL(trimmed_token_str), ZSTR_LEN(trimmed_token_str));

            /*
             * A valid origin MUST:
             * 1. Be parseable by `php_url_parse_ex`.
             * 2. Have a `scheme` component.
             * 3. Have a `host` component.
             * 4. Have a `scheme` of either `http` or `https`.
             */
            if (parsed_url == NULL || parsed_url->scheme == NULL || parsed_url->host == NULL ||
                (strcasecmp(ZSTR_VAL(parsed_url->scheme), "http") != 0 &&
                 strcasecmp(ZSTR_VAL(parsed_url->scheme), "https") != 0)) {
                is_valid = false;
            }

            /* Clean up resources for this token. */
            if (parsed_url) {
                php_url_free(parsed_url);
            }
            zend_string_release(trimmed_token_str);

            /* If an invalid origin was found, stop processing the list. */
            if (!is_valid) {
                break;
            }
        }
        efree(input_copy);
    }

    /* If any part of the validation failed, throw a single, clear exception. */
    if (!is_valid) {
        zend_throw_exception_ex(
            spl_ce_InvalidArgumentException,
            0,
            "Invalid value provided for CORS origin policy. Value must be '*' or a comma-separated list of valid origins (e.g., 'https://example.com:8443')."
        );
        return FAILURE;
    }

    /*
     * Validation passed. Allocate a new persistent string for the target
     * and assign it. The caller is responsible for freeing the old value.
     */
    *target = pestrdup(Z_STRVAL_P(value), 1);

    return SUCCESS;
}
