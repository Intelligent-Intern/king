/*
 * XSLT registration aggregation. The XSLT translation unit includes this
 * module boundary instead of leaving registration in the core bootstrap.
 */

void king_xslt_register_classes(void)
{
    king_ce_xslt_processor = king_register_class_with_flags(
        "King\\XSLT\\Processor",
        NULL,
        king_xslt_processor_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
}

void king_xslt_init_object_handlers(void)
{
    king_xslt_processor_object_handlers_init();
}
