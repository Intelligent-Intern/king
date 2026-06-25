/*
 * King client session and stream PHP object handlers.
 */

static void king_stream_object_free(zend_object *object)
{
    king_stream_object *intern = php_king_stream_obj_from_zend(object);

    if (!Z_ISUNDEF(intern->session)) {
        zval_ptr_dtor(&intern->session);
        ZVAL_UNDEF(&intern->session);
    }
    if (!Z_ISUNDEF(intern->cancel_token)) {
        zval_ptr_dtor(&intern->cancel_token);
        ZVAL_UNDEF(&intern->cancel_token);
    }
    if (!Z_ISUNDEF(intern->connection_config)) {
        zval_ptr_dtor(&intern->connection_config);
        ZVAL_UNDEF(&intern->connection_config);
    }
    if (!Z_ISUNDEF(intern->request_headers)) {
        zval_ptr_dtor(&intern->request_headers);
        ZVAL_UNDEF(&intern->request_headers);
    }
    if (intern->request_method != NULL) {
        zend_string_release(intern->request_method);
        intern->request_method = NULL;
    }
    if (intern->request_path != NULL) {
        zend_string_release(intern->request_path);
        intern->request_path = NULL;
    }
    if (intern->request_body != NULL) {
        zend_string_release(intern->request_body);
        intern->request_body = NULL;
    }

    zend_object_std_dtor(&intern->std);
}

static zend_object *king_stream_object_create(zend_class_entry *ce)
{
    king_stream_object *intern = zend_object_alloc(sizeof(*intern), ce);

    ZVAL_UNDEF(&intern->session);
    ZVAL_UNDEF(&intern->cancel_token);
    ZVAL_UNDEF(&intern->connection_config);
    ZVAL_UNDEF(&intern->request_headers);
    intern->request_method = NULL;
    intern->request_path = NULL;
    intern->request_body = zend_string_init("", 0, 0);
    intern->stream_id = -1;
    intern->buffered_bytes = 0;
    intern->request_body_was_supplied = false;
    intern->finished = false;
    intern->closed = false;
    intern->response_started = false;
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_stream_object_handlers;

    return &intern->std;
}

static void king_session_object_free(zend_object *object)
{
    king_session_object *intern = php_king_obj_from_zend(object);

    if (!Z_ISUNDEF(intern->resource)) {
        zval_ptr_dtor(&intern->resource);
        ZVAL_UNDEF(&intern->resource);
    }
    if (!Z_ISUNDEF(intern->config)) {
        zval_ptr_dtor(&intern->config);
        ZVAL_UNDEF(&intern->config);
    }

    zend_object_std_dtor(&intern->std);
}

static zend_object *king_session_object_create(zend_class_entry *ce)
{
    king_session_object *intern = zend_object_alloc(sizeof(*intern), ce);

    ZVAL_UNDEF(&intern->resource);
    ZVAL_UNDEF(&intern->config);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_session_object_handlers;

    return &intern->std;
}

static void king_stream_object_handlers_init(void)
{
    memcpy(
        &king_stream_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_stream_object_handlers.offset = XtOffsetOf(king_stream_object, std);
    king_stream_object_handlers.free_obj = king_stream_object_free;
    king_stream_object_handlers.clone_obj = NULL;
    king_ce_stream->create_object = king_stream_object_create;
}

static void king_session_object_handlers_init(void)
{
    memcpy(
        &king_session_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_session_object_handlers.offset = XtOffsetOf(king_session_object, std);
    king_session_object_handlers.free_obj = king_session_object_free;
    king_session_object_handlers.clone_obj = NULL;
    king_ce_session->create_object = king_session_object_create;
}
