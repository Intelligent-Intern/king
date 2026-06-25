/*
 * include/cancel_token.h - PHP-visible CancelToken object contract
 */

#ifndef KING_CANCEL_TOKEN_H
#define KING_CANCEL_TOKEN_H

#include <php.h>
#include <zend_object_handlers.h>
#include <stdbool.h>

typedef struct _king_cancel_token_object {
    bool cancelled;
    zend_object std;
} king_cancel_token_object;

static inline king_cancel_token_object *
php_king_cancel_token_obj_from_zend(zend_object *obj)
{
    return (king_cancel_token_object *)
        ((char*)obj - XtOffsetOf(king_cancel_token_object, std));
}

#endif /* KING_CANCEL_TOKEN_H */
