/*
 * include/server/websocket_server_arginfo.h - King\WebSocket\Server OO arginfo
 */

#ifndef KING_SERVER_WEBSOCKET_SERVER_ARGINFO_H
#define KING_SERVER_WEBSOCKET_SERVER_ARGINFO_H

#include <php.h>

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_WebSocket_Server___construct, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, config, King\\Config, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_WebSocket_Server_accept, 0, 0, King\\WebSocket\\Connection, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Server_getConnections, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Server_send, 0, 2, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, connectionId, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Server_sendBinary, 0, 2, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, connectionId, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Server_broadcast, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Server_broadcastBinary, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Server_stop, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

#endif /* KING_SERVER_WEBSOCKET_SERVER_ARGINFO_H */
