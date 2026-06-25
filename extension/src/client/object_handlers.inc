/*
 * King response and HTTP client PHP object handlers.
 */

static void king_response_object_free(zend_object *object)
{
    king_response_object *intern = php_king_response_obj_from_zend(object);

    if (!Z_ISUNDEF(intern->payload)) {
        zval_ptr_dtor(&intern->payload);
        ZVAL_UNDEF(&intern->payload);
    }
    if (!Z_ISUNDEF(intern->request_context)) {
        zval_ptr_dtor(&intern->request_context);
        ZVAL_UNDEF(&intern->request_context);
    }

    zend_object_std_dtor(&intern->std);
}

static zend_object *king_response_object_create(zend_class_entry *ce)
{
    king_response_object *intern = zend_object_alloc(sizeof(*intern), ce);

    ZVAL_UNDEF(&intern->payload);
    ZVAL_UNDEF(&intern->request_context);
    intern->read_offset = 0;
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_response_object_handlers;

    return &intern->std;
}

static void king_http_client_object_free(zend_object *object)
{
    king_http_client_object *intern = php_king_http_client_obj_from_zend(object);

    if (!Z_ISUNDEF(intern->config)) {
        zval_ptr_dtor(&intern->config);
        ZVAL_UNDEF(&intern->config);
    }

    zend_object_std_dtor(&intern->std);
}

static zend_object *king_http_client_object_create(zend_class_entry *ce)
{
    king_http_client_object *intern = zend_object_alloc(sizeof(*intern), ce);

    ZVAL_UNDEF(&intern->config);
    intern->preferred_protocol = KING_CLIENT_PROTOCOL_AUTO;
    intern->closed = false;
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_http_client_object_handlers;

    return &intern->std;
}

static void king_response_object_handlers_init(void)
{
    memcpy(
        &king_response_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_response_object_handlers.offset = XtOffsetOf(king_response_object, std);
    king_response_object_handlers.free_obj = king_response_object_free;
    king_response_object_handlers.clone_obj = NULL;
    king_ce_response->create_object = king_response_object_create;
}

static void king_http_client_object_handlers_init(void)
{
    memcpy(
        &king_http_client_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_http_client_object_handlers.offset = XtOffsetOf(king_http_client_object, std);
    king_http_client_object_handlers.free_obj = king_http_client_object_free;
    king_http_client_object_handlers.clone_obj = NULL;
    king_ce_client_http->create_object = king_http_client_object_create;
    king_ce_client_http1->create_object = king_http_client_object_create;
    king_ce_client_http2->create_object = king_http_client_object_create;
    king_ce_client_http3->create_object = king_http_client_object_create;
}
