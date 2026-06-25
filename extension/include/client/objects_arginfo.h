/*
 * include/client/objects_arginfo.h - King\Response and King\Client\HttpClient OO arginfo
 */

#ifndef KING_CLIENT_OBJECTS_ARGINFO_H
#define KING_CLIENT_OBJECTS_ARGINFO_H

#include <php.h>

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Response_getStatusCode_ret, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Response_getHeaders, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Response_getBody, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Response_read, 0, 0, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, length, IS_LONG, 0, "8192")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Response_isEndOfBody, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_Client_HttpClient___construct, 0, 0, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, config, King\\Config, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_Client_HttpClient_request, 0, 2, King\\Response, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, url, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 0, "[]")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, body, IS_STRING, 0, "\"\"")
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_Client_HttpClient_requestAsync, 0, 2, King\\Awaitable, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, url, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 0, "[]")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, body, IS_STRING, 0, "\"\"")
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Client_HttpClient_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

#endif /* KING_CLIENT_OBJECTS_ARGINFO_H */
