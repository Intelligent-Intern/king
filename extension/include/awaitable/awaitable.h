/*
 * include/awaitable/awaitable.h - Native Awaitable object contract
 */

#ifndef KING_AWAITABLE_H
#define KING_AWAITABLE_H

#include <php.h>
#include <zend_object_handlers.h>
#include <stdbool.h>
#include <stdint.h>

typedef enum _king_awaitable_status {
    KING_AWAITABLE_PENDING = 0,
    KING_AWAITABLE_RESOLVED = 1,
    KING_AWAITABLE_REJECTED = 2,
    KING_AWAITABLE_CANCELLED = 3
} king_awaitable_status_t;

typedef struct _king_awaitable_object king_awaitable_object;
typedef zend_result (*king_awaitable_runner)(king_awaitable_object *intern, zval *result);

struct _king_awaitable_object {
    king_awaitable_status_t status;
    zend_string *operation;
    zval payload;
    zval result;
    zval error;
    zval cancel_token;
    king_awaitable_runner runner;
    bool started;
    bool cancel_requested;
    zend_object std;
};

static inline king_awaitable_object *
php_king_awaitable_obj_from_zend(zend_object *obj)
{
    return (king_awaitable_object *)
        ((char*)obj - XtOffsetOf(king_awaitable_object, std));
}

zend_result king_awaitable_create(
    zval *return_value,
    const char *operation,
    size_t operation_len,
    king_awaitable_runner runner,
    zval *payload,
    zval *cancel_token
);
zend_result king_awaitable_create_function_call(
    zval *return_value,
    const char *operation,
    size_t operation_len,
    const char *function_name,
    size_t function_name_len,
    zval *params,
    uint32_t param_count,
    zval *cancel_token
);
zend_result king_awaitable_create_method_call(
    zval *return_value,
    const char *operation,
    size_t operation_len,
    zval *object,
    const char *method_name,
    size_t method_name_len,
    zval *params,
    uint32_t param_count,
    zval *cancel_token
);

#endif /* KING_AWAITABLE_H */
