static zend_class_entry *king_register_exception(
    const char *name,
    zend_class_entry *parent)
{
    zend_class_entry ce;

    INIT_CLASS_ENTRY_EX(ce, name, strlen(name), NULL);
    return zend_register_internal_class_ex(&ce, parent);
}
