void king_server_register_websocket_classes(void)
{
    king_ce_ws_server = king_register_class_with_flags(
        "King\\WebSocket\\Server",
        NULL,
        king_ws_server_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
}

void king_server_init_object_handlers(void)
{
    king_ws_server_object_handlers_init();
}
