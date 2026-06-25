/*
 * include/php_king/runtime_contracts.h - Cross-module runtime hook contracts
 */

#ifndef KING_PHP_KING_RUNTIME_CONTRACTS_H
#define KING_PHP_KING_RUNTIME_CONTRACTS_H

#include <php.h>
#include <stdbool.h>

#include "../client/objects.h"
#include "../client/session.h"

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

#endif /* KING_PHP_KING_RUNTIME_CONTRACTS_H */
