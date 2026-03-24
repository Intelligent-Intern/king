/*
 * =========================================================================
 * FILENAME:   include/mcp/mcp.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Native Machine Control Protocol (MCP) runtime. This defines the stateful
 * connection handle and transfer registry for stream-upload/download parity.
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
    HashTable transfers; /* key: service\nmethod\nid, value: payload (zend_string) */
    bool closed;
} king_mcp_state;

/* Runtime Management */
king_mcp_state *king_mcp_state_create(const char *host, size_t host_len, zend_long port, zval *config);
void king_mcp_state_free(king_mcp_state *state);

/* Transfer Registry */
int king_mcp_transfer_store(king_mcp_state *state, const char *service, const char *method, const char *id, zend_string *payload);
zend_string *king_mcp_transfer_find(king_mcp_state *state, const char *service, const char *method, const char *id);

#endif /* KING_MCP_H */
