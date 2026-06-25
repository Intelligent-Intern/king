/*
 * include/client/class_methods.h - Client/session OO method table externs
 */

#ifndef KING_CLIENT_CLASS_METHODS_H
#define KING_CLIENT_CLASS_METHODS_H

#include <php.h>

extern const zend_function_entry king_session_class_methods[];
extern const zend_function_entry king_stream_class_methods[];
extern const zend_function_entry king_response_class_methods[];
extern const zend_function_entry king_http_client_class_methods[];
extern const zend_function_entry king_ws_connection_class_methods[];

#endif /* KING_CLIENT_CLASS_METHODS_H */
