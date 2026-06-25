/*
 * =========================================================================
 * FILENAME:   php_king.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Central public header for the extension. It exposes the shared constants,
 * core object wrappers, and helper prototypes used across the active C
 * sources. Bootstrap-owned extern declarations live under include/php_king.
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
#include <stdbool.h>

/* -----------------------------------------------------------------------------
 * Extension Version and Global Constants
 */
#ifndef PHP_KING_VERSION
#  define PHP_KING_VERSION      "1.0.9"
#endif

#ifndef KING_MAX_TICKET_SIZE
#  define KING_MAX_TICKET_SIZE  4096
#endif

#ifndef KING_TRANSPORT_INTERRUPT_SLICE_MS
#  define KING_TRANSPORT_INTERRUPT_SLICE_MS 25L
#endif

/* Include core headers required in every build. */
#include "king_globals.h"
#include "king_init.h"
#include "cancel_token.h"
#include "client/session.h"
#include "client/objects.h"
#include "client/websocket.h"
#include "config/object.h"
#include "server/websocket.h"

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

#include "awaitable/index.h"
#include "iibin/index.h"
#include "inference/index.h"
#include "mcp/index.h"
#include "php_king/class_entries.h"
#include "php_king/method_tables.h"
#include "php_king/registration.h"
#include "php_king/resource_ids.h"
#include "php_king/resources.h"
#include "php_king/runtime_helpers.h"
#include "xslt/index.h"

void king_http1_pool_request_shutdown(void);
void king_http1_pool_module_shutdown(void);
void king_http2_pool_request_shutdown(void);
void king_http2_pool_module_shutdown(void);
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
zend_result king_http1_request_context_append_early_hint(
    king_http1_request_context *context,
    zval *hint,
    const char *function_name
);
zend_bool king_telemetry_build_trace_context_snapshot(zval *destination);
zend_result king_http1_request_context_get_pending_early_hints(
    king_http1_request_context *context,
    zval *return_value
);
bool king_http1_request_context_is_end_of_body(
    king_http1_request_context *context,
    zend_long read_offset
);
zend_result king_server_cancel_invoke_if_registered(
    king_client_session_t *session,
    zend_long stream_id
);

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
PHP_FUNCTION(king_db_ingest);

#endif /* PHP_KING_H */
