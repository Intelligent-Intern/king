/*
 * King\CancelToken method surface. Exposes the minimal userland cancel API on
 * top of the internal cancel-token object state.
 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_CancelToken_cancel, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_CancelToken_isCancelled, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

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

const zend_function_entry king_cancel_token_class_methods[] = {
    PHP_ME(King_CancelToken, cancel, arginfo_class_King_CancelToken_cancel, ZEND_ACC_PUBLIC)
    PHP_ME(King_CancelToken, isCancelled, arginfo_class_King_CancelToken_isCancelled, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
