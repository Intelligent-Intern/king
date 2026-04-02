/*
 * Zend resource destructors for php_king. Releases session, config, MCP and
 * related native resource handles when their userland wrappers are freed.
 */

#include "include/mcp/mcp.h"

static void king_session_dtor(zend_resource *rsrc)
{
    if (rsrc->ptr) {
        king_client_session_free(rsrc->ptr);
    }
}

static void king_cfg_dtor(zend_resource *rsrc)
{
    if (rsrc->ptr) {
        king_config_free((king_cfg_t *)rsrc->ptr);
    }
}

static void king_mcp_dtor(zend_resource *rsrc)
{
    if (rsrc->ptr) {
        king_mcp_state_free((king_mcp_state *) rsrc->ptr);
    }
}

static void king_ws_dtor(zend_resource *rsrc)
{
    if (rsrc->ptr) {
        king_ws_state_free((king_ws_state *) rsrc->ptr);
    }
}

static void king_http1_request_context_dtor(zend_resource *rsrc)
{
    if (rsrc->ptr) {
        king_http1_request_context_free((king_http1_request_context *) rsrc->ptr);
    }
}
