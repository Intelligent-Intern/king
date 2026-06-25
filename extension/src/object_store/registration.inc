#include "class_methods.inc"

void king_object_store_register_classes(void)
{
    king_ce_object_store = king_register_class_with_flags(
        "King\\ObjectStore",
        NULL,
        king_object_store_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
}
