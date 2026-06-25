/*
 * King\MCP userland surface. Carries the MCP class arginfo, object methods and
 * helper glue that bridge the native MCP runtime into userland.
 */

#include "main/php_streams.h"
#include "Zend/zend_smart_str.h"
#include "ext/standard/base64.h"
#include "ext/json/php_json.h"
#include "include/mcp/mcp.h"
#include "include/iibin/iibin_internal.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"
#include "include/config/mcp_and_orchestrator/default.h"
#include "include/king_hrtime.h"
#include <strings.h>
#include <time.h>

#include "mcp/arginfo_and_runtime_controls.inc"
#include "mcp/transfer_helpers.inc"
#include "mcp/iibin_helpers.inc"
#include "mcp/public_server_helpers.inc"
#include "mcp/public_server_http_helpers.inc"
#include "mcp/procedural_api.inc"
#include "mcp/object_api.inc"
#include "mcp/public_server_api.inc"
#include "mcp/public_server_object_api.inc"
