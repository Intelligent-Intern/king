const zend_function_entry king_xslt_processor_class_methods[] = {
    PHP_ME(King_XSLT_Processor, __construct, arginfo_class_King_XSLT_Processor___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_XSLT_Processor, getOptions, arginfo_class_King_XSLT_Processor_getOptions, ZEND_ACC_PUBLIC)
    PHP_ME(King_XSLT_Processor, engineStatus, arginfo_class_King_XSLT_Processor_engineStatus, ZEND_ACC_PUBLIC)
    PHP_ME(King_XSLT_Processor, transformFile, arginfo_class_King_XSLT_Processor_transformFile, ZEND_ACC_PUBLIC)
    PHP_ME(King_XSLT_Processor, transformToFile, arginfo_class_King_XSLT_Processor_transformToFile, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
