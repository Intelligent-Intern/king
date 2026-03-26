/*
 * =========================================================================
 * FILENAME:   php_king.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Central public header for the extension. It exposes the shared constants,
 * resource identifiers, exception class entries, object wrappers, and core
 * helper prototypes used across the active C sources.
 * =========================================================================
 */

#ifndef PHP_KING_H
#define PHP_KING_H

#ifdef HAVE_CONFIG_H
#  include "config.h"
#endif

#include <php.h>
#include <zend_object_handlers.h>
#include <Zend/zend_execute.h>
#include <zend_exceptions.h>
#include <stdint.h>
#include <string.h>
#include <stdatomic.h>

/* -----------------------------------------------------------------------------
 * Extension Version and Global Constants
 */
#ifndef PHP_KING_VERSION
#  define PHP_KING_VERSION      "1.0.0"
#endif

#ifndef KING_MAX_TICKET_SIZE
#  define KING_MAX_TICKET_SIZE  512
#endif

#ifndef KING_TRANSPORT_INTERRUPT_SLICE_MS
#  define KING_TRANSPORT_INTERRUPT_SLICE_MS 25L
#endif

#define KING_INTERNAL_OPTION_CANCEL_TOKEN "__king_cancel_token"
#define KING_INTERNAL_OPTION_CANCEL_TOKEN_LEN (sizeof(KING_INTERNAL_OPTION_CANCEL_TOKEN) - 1)
#define KING_INTERNAL_OPTION_CANCEL_FUNCTION_NAME "__king_cancel_function_name"
#define KING_INTERNAL_OPTION_CANCEL_FUNCTION_NAME_LEN (sizeof(KING_INTERNAL_OPTION_CANCEL_FUNCTION_NAME) - 1)
#define KING_INTERNAL_OPTION_CANCEL_STREAM_STOPPED "__king_cancel_stream_stopped"
#define KING_INTERNAL_OPTION_CANCEL_STREAM_STOPPED_LEN (sizeof(KING_INTERNAL_OPTION_CANCEL_STREAM_STOPPED) - 1)

/* Include core headers required in every build. */
#include "king_globals.h"
#include "king_init.h"
#include "client/session.h"

/*
 * Keep this header lightweight for the current v1 runtime surface so the
 * extension can compile without pulling in the full native dependency graph.
 */
#ifndef KING_RUNTIME_BUILD
#  include "client/cancel.h"
#  include "client/tls.h"
#  include "config/config.h"
#  include "connect/connect.h"
#  include "client/http3.h"
#  include "poll/poll.h"
#  include "websocket/websocket.h"
#endif /* KING_RUNTIME_BUILD */

#include "mcp/mcp.h"


/* -----------------------------------------------------------------------------
 * Exception Class Entry Declarations
 */
extern zend_class_entry
    *king_ce_exception,
    *king_ce_stream_exception,
    *king_ce_invalid_state,
    *king_ce_unknown_stream,
    *king_ce_stream_blocked,
    *king_ce_stream_limit,
    *king_ce_final_size,
    *king_ce_stream_stopped,
    *king_ce_fin_expected,
    *king_ce_invalid_fin_state,
    *king_ce_done,
    *king_ce_quic_exception,
    *king_ce_congestion_control,
    *king_ce_too_many_streams,
    *king_ce_runtime_exception,
    *king_ce_system_exception,
    *king_ce_validation_exception,
    *king_ce_timeout_exception,
    *king_ce_network_exception,
    *king_ce_tls_exception,
    *king_ce_protocol_exception,
    *king_ce_mcp_exception,
    *king_ce_mcp_connection_error,
    *king_ce_mcp_protocol_error,
    *king_ce_mcp_timeout,
    *king_ce_mcp_data_error,
    *king_ce_ws_exception,
    *king_ce_ws_connection_error,
    *king_ce_ws_protocol_error,
    *king_ce_ws_timeout,
    *king_ce_ws_closed;

extern zend_class_entry
    *king_ce_cancel_token,
    *king_ce_config,
    *king_ce_session,
    *king_ce_stream,
    *king_ce_response,
    *king_ce_mcp,
    *king_ce_client_http,
    *king_ce_client_http1,
    *king_ce_client_http2,
    *king_ce_client_http3,
    *king_ce_ws_connection;

/* -----------------------------------------------------------------------------
 * Resource Type Identifiers
 */
extern int le_king_session;
extern int le_king_cfg;
extern int le_king_perf;
extern int le_king_mcp;
extern int le_king_ws;
extern int le_king_request_context;

typedef struct _king_http1_request_context king_http1_request_context;
typedef enum _king_ws_connection_state {
    KING_WS_STATE_CONNECTING = 0,
    KING_WS_STATE_OPEN = 1,
    KING_WS_STATE_CLOSING = 2,
    KING_WS_STATE_CLOSED = 3
} king_ws_connection_state_t;

typedef struct _king_ws_message {
    zend_string *payload;
    bool is_binary;
    struct _king_ws_message *next;
} king_ws_message;

typedef struct _king_ws_state {
    zend_string *url;
    zend_string *scheme;
    zend_string *host;
    zend_string *request_target;
    zval config;
    zval headers;
    zend_long port;
    zend_long max_payload_size;
    zend_long ping_interval_ms;
    zend_long handshake_timeout_ms;
    zend_long last_close_status_code;
    king_ws_connection_state_t state;
    king_ws_message *incoming_head;
    king_ws_message *incoming_tail;
    zend_string *last_close_reason;
    zend_string *last_ping_payload;
    bool secure;
    bool handshake_complete;
    bool closed;
} king_ws_state;

typedef enum _king_client_protocol_preference {
    KING_CLIENT_PROTOCOL_AUTO = 0,
    KING_CLIENT_PROTOCOL_HTTP1,
    KING_CLIENT_PROTOCOL_HTTP2,
    KING_CLIENT_PROTOCOL_HTTP3
} king_client_protocol_preference_t;

void king_http1_pool_request_shutdown(void);
void king_http1_pool_module_shutdown(void);
void king_http2_pool_request_shutdown(void);
void king_http2_pool_module_shutdown(void);
zend_result king_response_object_init_from_array(zval *target, zval *payload);
zend_result king_response_object_init_from_context(
    zval *target,
    zval *payload,
    zval *request_context
);
void king_http1_request_context_free(king_http1_request_context *context);
zend_result king_http1_request_context_build_payload(
    king_http1_request_context *context,
    zval *payload,
    const char *function_name
);
zend_result king_http1_request_context_read(
    king_http1_request_context *context,
    zend_long read_offset,
    size_t length,
    zend_string **chunk_out,
    const char *function_name
);
zend_result king_http1_request_context_get_body(
    king_http1_request_context *context,
    zend_string **body_out,
    const char *function_name
);
void king_http1_request_context_append_early_hint(
    king_http1_request_context *context,
    zval *hint
);
zend_result king_http1_request_context_get_pending_early_hints(
    king_http1_request_context *context,
    zval *return_value
);
bool king_http1_request_context_is_end_of_body(
    king_http1_request_context *context,
    zend_long read_offset
);
void king_ws_state_free(king_ws_state *state);
zend_result king_server_cancel_invoke_if_registered(
    king_client_session_t *session,
    zend_long stream_id
);

/* -----------------------------------------------------------------------------
 * Zend Object Wrappers for PHP Classes
 */
typedef struct _king_config_object {
    zval resource;
    zval overrides;
    zend_object std;
} king_config_object;

typedef struct _king_cancel_token_object {
    bool cancelled;
    zend_object std;
} king_cancel_token_object;

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
    zend_object        std;
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

/* king_mcp_state is now in include/mcp/mcp.h */

typedef struct _king_mcp_object {
    zval resource;
    zend_object   std;
} king_mcp_object;

typedef struct _king_ws_object {
    zval resource;
    zend_object   std;
} king_ws_object;

/* -----------------------------------------------------------------------------
 * Shared Error Buffer
 */
#ifndef KING_ERR_LEN
#  define KING_ERR_LEN 256
#endif

#if defined(ZTS) && (PHP_VERSION_ID < 80200)
#  include <TSRM.h>
   extern ZEND_TLS char king_last_error[KING_ERR_LEN];
#else
   extern char king_last_error[KING_ERR_LEN];
#endif

void king_set_error(const char *msg);
const char *king_get_error(void);
void king_add_runtime_surface(zval *target);
const char *king_get_active_runtime_summary(void);
const char *king_get_stubbed_api_summary(void);

/* -----------------------------------------------------------------------------
 * Inline Helpers for Accessing Native Resources
 */
static inline void king_secure_zero(void *v, size_t n)
{
    volatile unsigned char *p = (volatile unsigned char *) v;
    while (n--) *p++ = 0;
}
static inline king_config_object *
php_king_config_obj_from_zend(zend_object *obj)
{
    return (king_config_object *)
        ((char*)obj - XtOffsetOf(king_config_object, std));
}

static inline king_cancel_token_object *
php_king_cancel_token_obj_from_zend(zend_object *obj)
{
    return (king_cancel_token_object *)
        ((char*)obj - XtOffsetOf(king_cancel_token_object, std));
}

#if PHP_VERSION_ID < 80200
static inline bool king_zend_string_equals_cstr_compat(
    const zend_string *value,
    const char *literal,
    size_t literal_len)
{
    return value != NULL
        && ZSTR_LEN(value) == literal_len
        && memcmp(ZSTR_VAL(value), literal, literal_len) == 0;
}

#define zend_string_equals_cstr(value, literal, literal_len) \
    king_zend_string_equals_cstr_compat((value), (literal), (literal_len))
#endif

static inline bool king_zend_string_starts_with_cstr(
    const zend_string *value,
    const char *literal)
{
    if (value == NULL || literal == NULL) {
        return 0;
    }

    size_t literal_len = strlen(literal);

    return literal_len > 0
        && ZSTR_LEN(value) >= literal_len
        && memcmp(ZSTR_VAL(value), literal, literal_len) == 0;
}

static inline bool king_vm_interrupt_pending(void)
{
#if PHP_VERSION_ID >= 80200
    return zend_atomic_bool_load_ex(&EG(vm_interrupt));
#else
    return EG(vm_interrupt);
#endif
}

static inline void king_process_pending_interrupts(void)
{
    if (UNEXPECTED(king_vm_interrupt_pending())) {
        if (zend_interrupt_function != NULL) {
            zend_interrupt_function(EG(current_execute_data));
        }
    }
}

static inline zval *king_transport_cancel_token_from_options(zval *options_array)
{
    zval *option_value;

    if (options_array == NULL || Z_TYPE_P(options_array) != IS_ARRAY) {
        return NULL;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        KING_INTERNAL_OPTION_CANCEL_TOKEN,
        KING_INTERNAL_OPTION_CANCEL_TOKEN_LEN
    );
    if (option_value == NULL
        || Z_TYPE_P(option_value) != IS_OBJECT
        || !instanceof_function(Z_OBJCE_P(option_value), king_ce_cancel_token)) {
        return NULL;
    }

    return option_value;
}

static inline const char *king_transport_cancel_function_name_from_options(zval *options_array)
{
    zval *option_value;

    if (options_array == NULL || Z_TYPE_P(options_array) != IS_ARRAY) {
        return NULL;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        KING_INTERNAL_OPTION_CANCEL_FUNCTION_NAME,
        KING_INTERNAL_OPTION_CANCEL_FUNCTION_NAME_LEN
    );
    if (option_value == NULL || Z_TYPE_P(option_value) != IS_STRING || Z_STRLEN_P(option_value) == 0) {
        return NULL;
    }

    return Z_STRVAL_P(option_value);
}

static inline zend_class_entry *king_transport_cancel_exception_ce_from_options(zval *options_array)
{
    zval *option_value;

    if (options_array == NULL || Z_TYPE_P(options_array) != IS_ARRAY) {
        return king_ce_runtime_exception;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options_array),
        KING_INTERNAL_OPTION_CANCEL_STREAM_STOPPED,
        KING_INTERNAL_OPTION_CANCEL_STREAM_STOPPED_LEN
    );
    if (option_value != NULL && Z_TYPE_P(option_value) == IS_TRUE) {
        return king_ce_stream_stopped;
    }

    return king_ce_runtime_exception;
}

static inline zend_bool king_transport_cancel_token_is_cancelled(zval *cancel_token)
{
    if (cancel_token == NULL
        || Z_TYPE_P(cancel_token) != IS_OBJECT
        || !instanceof_function(Z_OBJCE_P(cancel_token), king_ce_cancel_token)) {
        return 0;
    }

    return php_king_cancel_token_obj_from_zend(Z_OBJ_P(cancel_token))->cancelled ? 1 : 0;
}

static inline zend_result king_transport_maybe_throw_cancel(
    zval *cancel_token,
    const char *function_name,
    const char *cancel_function_name,
    zend_class_entry *exception_ce,
    const char *transport_label)
{
    char message[KING_ERR_LEN];
    const char *label;

    if (!king_transport_cancel_token_is_cancelled(cancel_token)) {
        return SUCCESS;
    }

    label = cancel_function_name != NULL ? cancel_function_name : function_name;
    if (exception_ce == NULL) {
        exception_ce = king_ce_runtime_exception;
    }

    snprintf(
        message,
        sizeof(message),
        "%s() cancelled the active %s transport via CancelToken.",
        label,
        transport_label
    );
    king_set_error(message);
    zend_throw_exception_ex(exception_ce, 0, "%s", message);
    return FAILURE;
}

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

static inline void *king_obj_fetch(zval *zobj)
{
    if (Z_TYPE_P(zobj) != IS_OBJECT) return NULL;
    king_session_object *intern = php_king_obj_from_zend(Z_OBJ_P(zobj));
    if (Z_ISUNDEF(intern->resource) || Z_TYPE(intern->resource) != IS_RESOURCE) {
        return NULL;
    }

    return Z_RES(intern->resource)->ptr;
}

static inline king_mcp_object *
php_king_mcp_obj_from_zend(zend_object *obj)
{
    return (king_mcp_object *)
        ((char*)obj - XtOffsetOf(king_mcp_object, std));
}

static inline king_ws_object *
php_king_ws_obj_from_zend(zend_object *obj)
{
    return (king_ws_object *)
        ((char*)obj - XtOffsetOf(king_ws_object, std));
}

extern void *king_fetch_config(zval *zcfg);
extern void king_ticket_ring_put(const uint8_t *ticket, size_t len);
extern int king_ticket_ring_get(uint8_t *out, size_t *out_len);
extern void king_client_session_free(void *session_ptr);
extern const zend_function_entry king_cancel_token_class_methods[];
extern const zend_function_entry king_config_class_methods[];
extern const zend_function_entry king_session_class_methods[];
extern const zend_function_entry king_stream_class_methods[];
extern const zend_function_entry king_response_class_methods[];
extern const zend_function_entry king_mcp_class_methods[];
extern const zend_function_entry king_http_client_class_methods[];
extern const zend_function_entry king_ws_connection_class_methods[];

/* -----------------------------------------------------------------------------
 * PHP_FUNCTION Prototypes: active public entry points
 */
PHP_FUNCTION(king_connect);
PHP_FUNCTION(king_close);
PHP_FUNCTION(king_send_request);
PHP_FUNCTION(king_receive_response);
PHP_FUNCTION(king_poll);
PHP_FUNCTION(king_cancel_stream);
PHP_FUNCTION(king_export_session_ticket);
PHP_FUNCTION(king_import_session_ticket);
PHP_FUNCTION(king_set_ca_file);
PHP_FUNCTION(king_set_client_cert);
PHP_FUNCTION(king_get_last_error);
PHP_FUNCTION(king_get_stats);
PHP_FUNCTION(king_version);
PHP_FUNCTION(king_health);

#endif /* PHP_KING_H */
