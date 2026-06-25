/*
 * include/php_king/transport_cancel.h - Shared transport cancel-token helpers
 */

#ifndef KING_PHP_KING_TRANSPORT_CANCEL_H
#define KING_PHP_KING_TRANSPORT_CANCEL_H

#include <php.h>
#include <zend_exceptions.h>
#include <stdio.h>

#include "awaitable/cancel_token.h"
#include "class_entries.h"
#include "error_state.h"

#define KING_INTERNAL_OPTION_CANCEL_TOKEN "__king_cancel_token"
#define KING_INTERNAL_OPTION_CANCEL_TOKEN_LEN (sizeof(KING_INTERNAL_OPTION_CANCEL_TOKEN) - 1)
#define KING_INTERNAL_OPTION_CANCEL_FUNCTION_NAME "__king_cancel_function_name"
#define KING_INTERNAL_OPTION_CANCEL_FUNCTION_NAME_LEN (sizeof(KING_INTERNAL_OPTION_CANCEL_FUNCTION_NAME) - 1)
#define KING_INTERNAL_OPTION_CANCEL_STREAM_STOPPED "__king_cancel_stream_stopped"
#define KING_INTERNAL_OPTION_CANCEL_STREAM_STOPPED_LEN (sizeof(KING_INTERNAL_OPTION_CANCEL_STREAM_STOPPED) - 1)

static inline zval *king_transport_cancel_token_from_options(zval *options_array)
{
    zval *option_value;

    if (options_array == NULL || Z_TYPE_P(options_array) != IS_ARRAY) {
        return NULL;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        KING_INTERNAL_OPTION_CANCEL_TOKEN,
        KING_INTERNAL_OPTION_CANCEL_TOKEN_LEN
    );
    if (option_value == NULL
        || Z_TYPE_P(option_value) != IS_OBJECT
        || !instanceof_function(Z_OBJCE_P(option_value), king_ce_cancel_token)) {
        return NULL;
    }

    return option_value;
}

static inline const char *king_transport_cancel_function_name_from_options(zval *options_array)
{
    zval *option_value;

    if (options_array == NULL || Z_TYPE_P(options_array) != IS_ARRAY) {
        return NULL;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        KING_INTERNAL_OPTION_CANCEL_FUNCTION_NAME,
        KING_INTERNAL_OPTION_CANCEL_FUNCTION_NAME_LEN
    );
    if (option_value == NULL || Z_TYPE_P(option_value) != IS_STRING || Z_STRLEN_P(option_value) == 0) {
        return NULL;
    }

    return Z_STRVAL_P(option_value);
}

static inline zend_class_entry *king_transport_cancel_exception_ce_from_options(zval *options_array)
{
    zval *option_value;

    if (options_array == NULL || Z_TYPE_P(options_array) != IS_ARRAY) {
        return king_ce_runtime_exception;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        KING_INTERNAL_OPTION_CANCEL_STREAM_STOPPED,
        KING_INTERNAL_OPTION_CANCEL_STREAM_STOPPED_LEN
    );
    if (option_value != NULL && Z_TYPE_P(option_value) == IS_TRUE) {
        return king_ce_stream_stopped;
    }

    return king_ce_runtime_exception;
}

static inline zend_bool king_transport_cancel_token_is_cancelled(zval *cancel_token)
{
    if (cancel_token == NULL
        || Z_TYPE_P(cancel_token) != IS_OBJECT
        || !instanceof_function(Z_OBJCE_P(cancel_token), king_ce_cancel_token)) {
        return 0;
    }

    return php_king_cancel_token_obj_from_zend(Z_OBJ_P(cancel_token))->cancelled ? 1 : 0;
}

static inline zend_result king_transport_maybe_throw_cancel(
    zval *cancel_token,
    const char *function_name,
    const char *cancel_function_name,
    zend_class_entry *exception_ce,
    const char *transport_label)
{
    char message[KING_ERR_LEN];
    const char *label;

    if (!king_transport_cancel_token_is_cancelled(cancel_token)) {
        return SUCCESS;
    }

    label = cancel_function_name != NULL ? cancel_function_name : function_name;
    if (exception_ce == NULL) {
        exception_ce = king_ce_runtime_exception;
    }

    snprintf(
        message,
        sizeof(message),
        "%s() cancelled the active %s transport via CancelToken.",
        label,
        transport_label
    );
    king_set_error(message);
    zend_throw_exception_ex(exception_ce, 0, "%s", message);
    return FAILURE;
}

#endif /* KING_PHP_KING_TRANSPORT_CANCEL_H */
