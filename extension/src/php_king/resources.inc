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

static void king_register_resource_types(int module_number)
{
    le_king_session = zend_register_list_destructors_ex(
        king_session_dtor, NULL, "King\\Session", module_number);
    le_king_cfg = zend_register_list_destructors_ex(
        king_cfg_dtor, NULL, "King\\Config", module_number);
    le_king_mcp = zend_register_list_destructors_ex(
        king_mcp_dtor, NULL, "King\\MCP", module_number);
    le_king_ws = zend_register_list_destructors_ex(
        king_ws_dtor, NULL, "King\\WebSocket", module_number);
    le_king_request_context = zend_register_list_destructors_ex(
        king_http1_request_context_dtor, NULL, "King\\HttpRequestContext", module_number);
}
