static zend_class_entry *king_register_class_with_flags(
    const char *name,
    zend_class_entry *parent,
    const zend_function_entry *functions,
    uint32_t flags)
{
    zend_class_entry ce;
    zend_class_entry *registered;

    INIT_CLASS_ENTRY_EX(ce, name, strlen(name), functions);
    registered = zend_register_internal_class_ex(&ce, parent);
    if (registered != NULL) {
        registered->ce_flags |= flags;
    }

    return registered;
}
