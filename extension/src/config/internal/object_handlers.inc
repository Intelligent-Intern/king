/*
 * King config PHP object handlers.
 */

static void king_config_object_free(zend_object *object)
{
    king_config_object *intern = php_king_config_obj_from_zend(object);

    if (!Z_ISUNDEF(intern->resource)) {
        zval_ptr_dtor(&intern->resource);
        ZVAL_UNDEF(&intern->resource);
    }
    if (!Z_ISUNDEF(intern->overrides)) {
        zval_ptr_dtor(&intern->overrides);
        ZVAL_UNDEF(&intern->overrides);
    }

    zend_object_std_dtor(&intern->std);
}

static zend_object *king_config_object_create(zend_class_entry *ce)
{
    king_config_object *intern = zend_object_alloc(sizeof(*intern), ce);

    ZVAL_UNDEF(&intern->resource);
    ZVAL_UNDEF(&intern->overrides);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_config_object_handlers;

    return &intern->std;
}

static void king_config_object_handlers_init(void)
{
    memcpy(
        &king_config_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_config_object_handlers.offset = XtOffsetOf(king_config_object, std);
    king_config_object_handlers.free_obj = king_config_object_free;
    king_config_object_handlers.clone_obj = NULL;
    king_ce_config->create_object = king_config_object_create;
}
