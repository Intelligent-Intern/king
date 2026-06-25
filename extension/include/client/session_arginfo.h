/*
 * include/client/session_arginfo.h - King\Session and King\Stream OO arginfo
 */

#ifndef KING_CLIENT_SESSION_ARGINFO_H
#define KING_CLIENT_SESSION_ARGINFO_H

#include <php.h>

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_Session___construct, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, config, King\\Config, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, connect_options, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Session_isConnected, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_Session_sendRequest, 0, 2, King\\Stream, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 0, "[]")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, body, IS_STRING, 0, "\"\"")
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Session_poll, 0, 0, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_Session_close, 0, 0, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, code, IS_LONG, 0, "0")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, reason, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Session_stats, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Session_alpn, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Session_enableEarlyHints, 0, 0, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, enable, _IS_BOOL, 0, "true")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_Stream_receiveResponse, 0, 0, King\\Response, 1)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 1, "null")
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Stream_send, 0, 1, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Stream_finish, 0, 0, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, finalData, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Stream_isClosed, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Stream_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

#endif /* KING_CLIENT_SESSION_ARGINFO_H */
