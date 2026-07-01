void king_awaitable_register_classes(void)
{
    king_ce_cancel_token = king_register_class_with_flags(
        "King\\CancelToken",
        NULL,
        king_cancel_token_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_awaitable = king_register_class_with_flags(
        "King\\Awaitable",
        NULL,
        king_awaitable_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
}

void king_awaitable_init_object_handlers(void)
{
    king_cancel_token_object_handlers_init();
    king_awaitable_object_handlers_init();
}
