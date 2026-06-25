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
