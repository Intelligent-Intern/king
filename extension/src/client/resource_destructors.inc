/*
 * Client-owned Zend resource destructors.
 */

#include "client/session.h"
#include "client/websocket.h"
#include "php_king/runtime_contracts.h"

static void king_client_session_resource_dtor(zend_resource *rsrc)
{
    if (rsrc->ptr) {
        king_client_session_free(rsrc->ptr);
    }
}

static void king_client_websocket_resource_dtor(zend_resource *rsrc)
{
    if (rsrc->ptr) {
        king_ws_state_free((king_ws_state *) rsrc->ptr);
    }
}

static void king_client_http1_request_context_resource_dtor(zend_resource *rsrc)
{
    if (rsrc->ptr) {
        king_http1_request_context_free((king_http1_request_context *) rsrc->ptr);
    }
}
