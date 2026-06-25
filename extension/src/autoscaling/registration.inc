void king_autoscaling_register_classes(void)
{
    king_ce_autoscaling = king_register_class_with_flags(
        "King\\Autoscaling",
        NULL,
        king_autoscaling_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
}
