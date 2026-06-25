/*
 * Core Zend resource id registration. Destructor implementations stay with
 * the subsystem that owns the native state being freed.
 */

#include "../client/resource_destructors.inc"
#include "../config/internal/resource_destructors.inc"
#include "../mcp/php_binding/resource_destructors.inc"

void king_register_resource_types(int module_number)
{
    le_king_session = zend_register_list_destructors_ex(
        king_client_session_resource_dtor, NULL, "King\\Session", module_number);
    le_king_cfg = zend_register_list_destructors_ex(
        king_config_resource_dtor, NULL, "King\\Config", module_number);
    le_king_mcp = zend_register_list_destructors_ex(
        king_mcp_resource_dtor, NULL, "King\\MCP", module_number);
    le_king_ws = zend_register_list_destructors_ex(
        king_client_websocket_resource_dtor, NULL, "King\\WebSocket", module_number);
    le_king_request_context = zend_register_list_destructors_ex(
        king_client_http1_request_context_resource_dtor,
        NULL,
        "King\\HttpRequestContext",
        module_number);
}
