/*
 * include/php_king/string_helpers.h - Shared zend_string helper functions
 */

#ifndef KING_PHP_KING_STRING_HELPERS_H
#define KING_PHP_KING_STRING_HELPERS_H

#include <php.h>
#include <stdbool.h>
#include <string.h>

#if PHP_VERSION_ID < 80200
static inline bool king_zend_string_equals_cstr_compat(
    const zend_string *value,
    const char *literal,
    size_t literal_len)
{
    return value != NULL
        && ZSTR_LEN(value) == literal_len
        && memcmp(ZSTR_VAL(value), literal, literal_len) == 0;
}

#define zend_string_equals_cstr(value, literal, literal_len) \
    king_zend_string_equals_cstr_compat((value), (literal), (literal_len))
#endif

static inline bool king_zend_string_starts_with_cstr(
    const zend_string *value,
    const char *literal)
{
    if (value == NULL || literal == NULL) {
        return 0;
    }

    size_t literal_len = strlen(literal);

    return literal_len > 0
        && ZSTR_LEN(value) >= literal_len
        && memcmp(ZSTR_VAL(value), literal, literal_len) == 0;
}

#endif /* KING_PHP_KING_STRING_HELPERS_H */
