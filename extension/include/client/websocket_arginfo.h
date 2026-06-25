/*
 * include/client/websocket_arginfo.h - King\WebSocket\Connection OO arginfo
 */

#ifndef KING_CLIENT_WEBSOCKET_ARGINFO_H
#define KING_CLIENT_WEBSOCKET_ARGINFO_H

#include <php.h>

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_WebSocket_Connection___construct, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, url, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_send, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_WebSocket_Connection_sendAsync, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_sendBinary, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_WebSocket_Connection_sendBinaryAsync, 0, 1, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_receive, 0, 0, IS_STRING, 1)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_WebSocket_Connection_receiveAsync, 0, 0, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_ping, 0, 0, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, data, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_close, 0, 0, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, code, IS_LONG, 0, "1000")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, reason, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_getInfo, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

#endif /* KING_CLIENT_WEBSOCKET_ARGINFO_H */
