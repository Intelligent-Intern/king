/*
 * Module implementation bindings included by the single php_king extension
 * translation unit. Keep this order stable: runtime/object binding fragments
 * must be available before the module registration hooks below are compiled.
 */

#include "../db_ingest/php_binding.inc"
#include "../awaitable/php_binding.inc"
#include "../config/php_binding.inc"
#include "../mcp/php_binding.inc"
#include "../media/php_binding.inc"
#include "../xslt/php_binding.inc"
#include "../inference/php_binding.inc"
#include "../client/php_binding.inc"
#include "../server/php_binding.inc"
