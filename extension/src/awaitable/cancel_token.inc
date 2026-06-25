/*
 * King\CancelToken method surface. Exposes the minimal userland cancel API on
 * top of the internal cancel-token object state.
 */

#include "awaitable/cancel_token_arginfo.h"

PHP_METHOD(King_CancelToken, cancel)
{
    king_cancel_token_object *intern;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_cancel_token_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    intern->cancelled = true;
    king_set_error("");
}

PHP_METHOD(King_CancelToken, isCancelled)
{
    king_cancel_token_object *intern;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_cancel_token_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    RETURN_BOOL(intern->cancelled);
}
