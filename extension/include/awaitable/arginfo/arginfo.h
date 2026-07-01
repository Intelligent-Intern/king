ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_await, 0, 1, IS_MIXED, 0)
    ZEND_ARG_OBJ_INFO(0, awaitable, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_awaitable_poll, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_OBJ_INFO(0, awaitable, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_awaitable_cancel, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_OBJ_INFO(0, awaitable, King\\Awaitable, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_king_awaitable_status, 0, 1, IS_STRING, 0)
    ZEND_ARG_OBJ_INFO(0, awaitable, King\\Awaitable, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_awaitable_any, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, awaitables, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_king_awaitable_all, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, awaitables, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_Awaitable___construct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_await, 0, 0, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_poll, 0, 0, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_cancel, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_isPending, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_isDone, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_isCancelled, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_getStatus, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Awaitable_getOperation, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_Awaitable_any, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, awaitables, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_Awaitable_all, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, awaitables, IS_ARRAY, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()
