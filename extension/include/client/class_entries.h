/*
 * include/client/class_entries.h - Client and WebSocket class-entry externs
 */

#ifndef KING_CLIENT_CLASS_ENTRIES_H
#define KING_CLIENT_CLASS_ENTRIES_H

#include <php.h>

extern zend_class_entry *king_ce_session;
extern zend_class_entry *king_ce_stream;
extern zend_class_entry *king_ce_response;
extern zend_class_entry *king_ce_client_http;
extern zend_class_entry *king_ce_client_http1;
extern zend_class_entry *king_ce_client_http2;
extern zend_class_entry *king_ce_client_http3;
extern zend_class_entry *king_ce_ws_connection;
extern zend_class_entry *king_ce_ws_exception;
extern zend_class_entry *king_ce_ws_connection_error;
extern zend_class_entry *king_ce_ws_protocol_error;
extern zend_class_entry *king_ce_ws_timeout;
extern zend_class_entry *king_ce_ws_closed;

#endif /* KING_CLIENT_CLASS_ENTRIES_H */
