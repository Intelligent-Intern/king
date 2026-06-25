/*
 * include/php_king/method_tables.h - Userland class method-table externs
 */

#ifndef KING_PHP_KING_METHOD_TABLES_H
#define KING_PHP_KING_METHOD_TABLES_H

#include <php.h>

extern const zend_function_entry king_cancel_token_class_methods[];
extern const zend_function_entry king_awaitable_class_methods[];
extern const zend_function_entry king_config_class_methods[];
extern const zend_function_entry king_session_class_methods[];
extern const zend_function_entry king_stream_class_methods[];
extern const zend_function_entry king_response_class_methods[];
extern const zend_function_entry king_mcp_class_methods[];
extern const zend_function_entry king_pipeline_orchestrator_class_methods[];
extern const zend_function_entry king_object_store_class_methods[];
extern const zend_function_entry king_autoscaling_class_methods[];
extern const zend_function_entry king_http_client_class_methods[];
extern const zend_function_entry king_ws_server_class_methods[];
extern const zend_function_entry king_ws_connection_class_methods[];

#endif /* KING_PHP_KING_METHOD_TABLES_H */
