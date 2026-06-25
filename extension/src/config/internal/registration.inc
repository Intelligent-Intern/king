#include "resource_destructors.inc"

void king_config_register_resource_types(int module_number)
{
    le_king_cfg = zend_register_list_destructors_ex(
        king_config_resource_dtor, NULL, "King\\Config", module_number);
}

void king_config_register_classes(void)
{
    king_ce_config = king_register_class_with_flags(
        "King\\Config",
        NULL,
        king_config_class_methods,
        ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
}

void king_config_init_object_handlers(void)
{
    king_config_object_handlers_init();
}
