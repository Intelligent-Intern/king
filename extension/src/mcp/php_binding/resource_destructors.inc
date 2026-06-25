/*
 * MCP-owned Zend resource destructor.
 */

#include "mcp/mcp.h"

static void king_mcp_resource_dtor(zend_resource *rsrc)
{
    if (rsrc->ptr) {
        king_mcp_state_free((king_mcp_state *) rsrc->ptr);
    }
}
