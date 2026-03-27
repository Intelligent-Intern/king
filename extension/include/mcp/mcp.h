/*
 * =========================================================================
 * FILENAME:   include/mcp/mcp.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Native Machine Control Protocol (MCP) runtime. This defines the stateful
 * remote peer connection handle used by request and transfer helpers.
 * =========================================================================
 */
#ifndef KING_MCP_H
#define KING_MCP_H

#include "php.h"
#include <stdbool.h>

typedef struct _king_mcp_state {
    zend_string *host;
    zend_long port;
    zval config; /* King\Config object */
    php_stream *transport_stream;
    bool closed;
    bool operation_active;
} king_mcp_state;

typedef struct _king_mcp_runtime_control {
    zend_long timeout_ms;
    uint64_t deadline_ms;
    uint64_t started_at_ms;
    zval *cancel_token;
} king_mcp_runtime_control_t;

/* Runtime Management */
king_mcp_state *king_mcp_state_create(const char *host, size_t host_len, zend_long port, zval *config);
void king_mcp_state_close(king_mcp_state *state);
void king_mcp_state_free(king_mcp_state *state);

/* Remote Transfer Operations */
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

/* Request Transport */
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
