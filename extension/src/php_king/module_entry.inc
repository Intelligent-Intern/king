zend_module_entry king_module_entry = {
    STANDARD_MODULE_HEADER,
    "king",
    king_functions,
    PHP_MINIT(king),
    PHP_MSHUTDOWN(king),
    PHP_RINIT(king),
    PHP_RSHUTDOWN(king),
    PHP_MINFO(king),
    PHP_KING_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_KING
ZEND_GET_MODULE(king)
#endif
