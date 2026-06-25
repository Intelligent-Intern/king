#include "resource_destructors.inc"

void king_mcp_register_resource_types(int module_number)
{
    le_king_mcp = zend_register_list_destructors_ex(
        king_mcp_resource_dtor, NULL, "King\\MCP", module_number);
}

void king_mcp_register_exception_classes(void)
{
    king_ce_mcp_exception = king_register_exception(
        "King\\MCPException", king_ce_exception);
    king_ce_mcp_connection_error = king_register_exception(
        "King\\MCPConnectionException", king_ce_mcp_exception);
    king_ce_mcp_protocol_error = king_register_exception(
        "King\\MCPProtocolException", king_ce_mcp_exception);
    king_ce_mcp_timeout = king_register_exception(
        "King\\MCPTimeoutException", king_ce_mcp_exception);
    king_ce_mcp_data_error = king_register_exception(
        "King\\MCPDataException", king_ce_mcp_exception);
}

void king_mcp_register_classes(void)
{
    king_ce_mcp = king_register_class_with_flags(
        "King\\MCP",
        NULL,
        king_mcp_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_mcp_server = king_register_class_with_flags(
        "King\\MCPServer",
        NULL,
        king_mcp_server_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
}

void king_mcp_init_object_handlers(void)
{
    king_mcp_object_handlers_init();
    king_mcp_server_object_handlers_init();
}
