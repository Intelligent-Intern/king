/*
 * =========================================================================
 * FILENAME:   src/mcp/mcp.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Implementation of the native MCP runtime. Manages connections, transfers,
 * and protocol consistency outside the local lifecycle-only slice.
 * =========================================================================
 */
#include "include/mcp/mcp.h"
#include <string.h>

king_mcp_state *king_mcp_state_create(
    const char *host,
    size_t host_len,
    zend_long port,
    zval *config)
{
    king_mcp_state *state = ecalloc(1, sizeof(*state));
    state->host = zend_string_init(host, host_len, 0);
    state->port = port;
    ZVAL_UNDEF(&state->config);
    if (config && Z_TYPE_P(config) != IS_NULL) {
        ZVAL_COPY(&state->config, config);
    }
    zend_hash_init(&state->transfers, 8, NULL, ZVAL_PTR_DTOR, 0);
    state->closed = false;
    return state;
}

void king_mcp_state_free(king_mcp_state *state)
{
    if (!state) return;
    if (state->host) zend_string_release(state->host);
    zval_ptr_dtor(&state->config);
    zend_hash_destroy(&state->transfers);
    efree(state);
}

static zend_string *king_mcp_transfer_key_create(
    const char *service,
    const char *method,
    const char *id)
{
    size_t s_len = strlen(service);
    size_t m_len = strlen(method);
    size_t i_len = strlen(id);
    size_t total = s_len + 1 + m_len + 1 + i_len;

    zend_string *key = zend_string_alloc(total, 0);
    char *p = ZSTR_VAL(key);
    
    memcpy(p, service, s_len); p += s_len; *p++ = ':';
    memcpy(p, method, m_len); p += m_len; *p++ = ':';
    memcpy(p, id, i_len);
    ZSTR_VAL(key)[total] = '\0';
    return key;
}

int king_mcp_transfer_store(
    king_mcp_state *state,
    const char *service,
    const char *method,
    const char *id,
    zend_string *payload)
{
    if (!state || state->closed) return FAILURE;

    zend_string *key = king_mcp_transfer_key_create(service, method, id);
    zval val;
    ZVAL_STR_COPY(&val, payload);
    
    zend_hash_update(&state->transfers, key, &val);
    zend_string_release(key);
    return SUCCESS;
}

zend_string *king_mcp_transfer_find(
    king_mcp_state *state,
    const char *service,
    const char *method,
    const char *id)
{
    if (!state || state->closed) return NULL;

    zend_string *key = king_mcp_transfer_key_create(service, method, id);
    zval *val = zend_hash_find(&state->transfers, key);
    zend_string_release(key);

    if (val && Z_TYPE_P(val) == IS_STRING) {
        return Z_STR_P(val);
    }
    return NULL;
}
