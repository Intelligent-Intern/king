/*
 * King WebSocket client connection PHP object handlers.
 */

static zend_object_handlers king_ws_object_handlers;

static void king_ws_object_free(zend_object *object)
{
    king_ws_object *intern = php_king_ws_obj_from_zend(object);

    if (!Z_ISUNDEF(intern->resource)) {
        zval_ptr_dtor(&intern->resource);
        ZVAL_UNDEF(&intern->resource);
    }

    zend_object_std_dtor(&intern->std);
}

static zend_object *king_ws_object_create(zend_class_entry *ce)
{
    king_ws_object *intern = zend_object_alloc(sizeof(*intern), ce);

    ZVAL_UNDEF(&intern->resource);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_ws_object_handlers;

    return &intern->std;
}

static void king_ws_object_handlers_init(void)
{
    memcpy(
        &king_ws_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_ws_object_handlers.offset = XtOffsetOf(king_ws_object, std);
    king_ws_object_handlers.free_obj = king_ws_object_free;
    king_ws_object_handlers.clone_obj = NULL;
    king_ce_ws_connection->create_object = king_ws_object_create;
}
