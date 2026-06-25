const zend_function_entry king_config_class_methods[] = {
    PHP_ME(King_Config, __construct, arginfo_class_King_Config___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_Config, new, arginfo_class_King_Config_new, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(King_Config, get, arginfo_class_King_Config_get, ZEND_ACC_PUBLIC)
    PHP_ME(King_Config, set, arginfo_class_King_Config_set, ZEND_ACC_PUBLIC)
    PHP_ME(King_Config, toArray, arginfo_class_King_Config_toArray, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
