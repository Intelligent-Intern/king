void king_inference_register_classes(void)
{
    king_ce_inference = king_register_class_with_flags(
        "King\\Inference",
        NULL,
        king_inference_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_inference_model = king_register_class_with_flags(
        "King\\Inference\\Model",
        NULL,
        king_inference_model_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
    king_ce_inference_stream = king_register_class_with_flags(
        "King\\Inference\\Stream",
        NULL,
        king_inference_stream_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
}

void king_inference_init_object_handlers(void)
{
    king_inference_model_object_handlers_init();
    king_inference_stream_object_handlers_init();
}
