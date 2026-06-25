const zend_function_entry king_cancel_token_class_methods[] = {
    PHP_ME(King_CancelToken, cancel, arginfo_class_King_CancelToken_cancel, ZEND_ACC_PUBLIC)
    PHP_ME(King_CancelToken, isCancelled, arginfo_class_King_CancelToken_isCancelled, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

const zend_function_entry king_awaitable_class_methods[] = {
    PHP_ME(King_Awaitable, __construct, arginfo_class_King_Awaitable___construct, ZEND_ACC_PRIVATE | ZEND_ACC_CTOR)
    PHP_ME(King_Awaitable, await, arginfo_class_King_Awaitable_await, ZEND_ACC_PUBLIC)
    PHP_ME(King_Awaitable, poll, arginfo_class_King_Awaitable_poll, ZEND_ACC_PUBLIC)
    PHP_ME(King_Awaitable, cancel, arginfo_class_King_Awaitable_cancel, ZEND_ACC_PUBLIC)
    PHP_ME(King_Awaitable, isPending, arginfo_class_King_Awaitable_isPending, ZEND_ACC_PUBLIC)
    PHP_ME(King_Awaitable, isDone, arginfo_class_King_Awaitable_isDone, ZEND_ACC_PUBLIC)
    PHP_ME(King_Awaitable, isCancelled, arginfo_class_King_Awaitable_isCancelled, ZEND_ACC_PUBLIC)
    PHP_ME(King_Awaitable, getStatus, arginfo_class_King_Awaitable_getStatus, ZEND_ACC_PUBLIC)
    PHP_ME(King_Awaitable, getOperation, arginfo_class_King_Awaitable_getOperation, ZEND_ACC_PUBLIC)
    PHP_ME(King_Awaitable, any, arginfo_class_King_Awaitable_any, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(King_Awaitable, all, arginfo_class_King_Awaitable_all, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_FE_END
};
