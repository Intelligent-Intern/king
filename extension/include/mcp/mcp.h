/*
 * =========================================================================
 * FILENAME:   include/mcp/mcp.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Native MCP runtime primitives shared by the PHP resource/object wrapper
 * and the subsystem-local transport helpers. The runtime keeps one normalized
 * remote peer target, one reusable socket stream, and the local persisted
 * transfer-state fallback used by upload/download acknowledgements.
 * =========================================================================
 */
#ifndef KING_MCP_H
#define KING_MCP_H

#include "php.h"
#include <stdbool.h>

typedef enum _king_mcp_error_kind {
    KING_MCP_ERROR_NONE = 0,
    KING_MCP_ERROR_TRANSPORT,
    KING_MCP_ERROR_PROTOCOL,
    KING_MCP_ERROR_BACKEND
} king_mcp_error_kind_t;

typedef struct _king_mcp_state {
    zend_string *host;
    zend_long port;
    zval config; /* Optional King\Config snapshot copied at connect time. */
    php_stream *transport_stream;
    bool closed;
    bool operation_active;
    king_mcp_error_kind_t last_error_kind;
} king_mcp_state;

typedef struct _king_mcp_runtime_control {
    zend_long timeout_ms;  /* Relative timeout budget in milliseconds. */
    uint64_t deadline_ms;  /* Absolute monotonic deadline in milliseconds. */
    uint64_t started_at_ms;
    zval *cancel_token;    /* Optional King\CancelToken zval. */
} king_mcp_runtime_control_t;

/* Connection State Lifecycle */
king_mcp_state *king_mcp_state_create(const char *host, size_t host_len, zend_long port, zval *config);
void king_mcp_state_close(king_mcp_state *state);
void king_mcp_state_free(king_mcp_state *state);

/* Remote Transfer Operations with local persisted fallback state */
int king_mcp_transfer_store(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len,
    zend_string *payload,
    king_mcp_runtime_control_t *control);
zend_string *king_mcp_transfer_find(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len,
    king_mcp_runtime_control_t *control);
int king_mcp_transfer_acknowledge(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len);

/* Unary line-framed request exchange */
int king_mcp_request(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    zend_string *payload,
    zend_string **response_out,
    king_mcp_runtime_control_t *control);

#endif /* KING_MCP_H */
