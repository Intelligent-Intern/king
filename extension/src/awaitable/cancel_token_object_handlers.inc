/*
 * King cancel token PHP object handlers.
 */

static void king_cancel_token_object_free(zend_object *object)
{
    king_cancel_token_object *intern = php_king_cancel_token_obj_from_zend(object);

    intern->cancelled = false;
    zend_object_std_dtor(&intern->std);
}

static zend_object *king_cancel_token_object_create(zend_class_entry *ce)
{
    king_cancel_token_object *intern = zend_object_alloc(sizeof(*intern), ce);

    intern->cancelled = false;
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_cancel_token_object_handlers;

    return &intern->std;
}

static void king_cancel_token_object_handlers_init(void)
{
    memcpy(
        &king_cancel_token_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_cancel_token_object_handlers.offset = XtOffsetOf(king_cancel_token_object, std);
    king_cancel_token_object_handlers.free_obj = king_cancel_token_object_free;
    king_cancel_token_object_handlers.clone_obj = NULL;
    king_ce_cancel_token->create_object = king_cancel_token_object_create;
}
