/*
 * include/client/objects.h - PHP-visible client/session object contracts
 */

#ifndef KING_CLIENT_OBJECTS_H
#define KING_CLIENT_OBJECTS_H

#include <php.h>
#include <zend_object_handlers.h>
#include <stdbool.h>

typedef struct _king_http1_request_context king_http1_request_context;

typedef enum _king_client_protocol_preference {
    KING_CLIENT_PROTOCOL_AUTO = 0,
    KING_CLIENT_PROTOCOL_HTTP1,
    KING_CLIENT_PROTOCOL_HTTP2,
    KING_CLIENT_PROTOCOL_HTTP3
} king_client_protocol_preference_t;

typedef struct _king_response_object {
    zval payload;
    zval request_context;
    zend_long read_offset;
    zend_object std;
} king_response_object;

typedef struct _king_http_client_object {
    zval config;
    king_client_protocol_preference_t preferred_protocol;
    bool closed;
    zend_object std;
} king_http_client_object;

typedef struct _king_session_object {
    zval resource;
    zval config;
    zend_object std;
} king_session_object;

typedef struct _king_stream_object {
    zval session;
    zval cancel_token;
    zval connection_config;
    zval request_headers;
    zend_string *request_method;
    zend_string *request_path;
    zend_string *request_body;
    zend_long stream_id;
    zend_long buffered_bytes;
    bool request_body_was_supplied;
    bool finished;
    bool closed;
    bool response_started;
    zend_object std;
} king_stream_object;

static inline king_session_object *
php_king_obj_from_zend(zend_object *obj)
{
    return (king_session_object *)
        ((char*)obj - XtOffsetOf(king_session_object, std));
}

static inline king_stream_object *
php_king_stream_obj_from_zend(zend_object *obj)
{
    return (king_stream_object *)
        ((char*)obj - XtOffsetOf(king_stream_object, std));
}

static inline king_response_object *
php_king_response_obj_from_zend(zend_object *obj)
{
    return (king_response_object *)
        ((char*)obj - XtOffsetOf(king_response_object, std));
}

static inline king_http_client_object *
php_king_http_client_obj_from_zend(zend_object *obj)
{
    return (king_http_client_object *)
        ((char*)obj - XtOffsetOf(king_http_client_object, std));
}

zend_result king_response_object_init_from_array(zval *target, zval *payload);
zend_result king_response_object_init_from_context(
    zval *target,
    zval *payload,
    zval *request_context
);

#endif /* KING_CLIENT_OBJECTS_H */
