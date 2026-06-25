/*
 * Module implementation bindings included by the single php_king extension
 * translation unit. Keep this order stable: runtime/object binding fragments
 * must be available before the module registration hooks below are compiled.
 */

#include "../db_ingest/api.inc"
#include "../awaitable/cancel_token.inc"
#include "../awaitable/cancel_token_object_handlers.inc"
#include "../config/internal/object_handlers.inc"
#include "../mcp/php_binding.inc"
#include "../media/php_binding.inc"
#include "../xslt/php_binding.inc"
#include "../inference/api.inc"
#include "../client/session/object_handlers.inc"
#include "../client/object_handlers.inc"
#include "../client/websocket/object_handlers.inc"
#include "../server/http1/websocket_server_object_handlers.inc"
#include "../awaitable/awaitable.inc"
