/*
 * Zend object handlers for php_king classes. Owns the create/free logic for
 * the internal CancelToken and other object-backed surfaces.
 */

#include <unistd.h>

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

static void king_mcp_object_free(zend_object *object)
{
    king_mcp_object *intern = php_king_mcp_obj_from_zend(object);

    if (!Z_ISUNDEF(intern->resource)) {
        zval_ptr_dtor(&intern->resource);
        ZVAL_UNDEF(&intern->resource);
    }

    zend_object_std_dtor(&intern->std);
}

static zend_object *king_mcp_object_create(zend_class_entry *ce)
{
    king_mcp_object *intern = zend_object_alloc(sizeof(*intern), ce);

    ZVAL_UNDEF(&intern->resource);
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_mcp_object_handlers;

    return &intern->std;
}

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

static void king_ws_server_object_free(zend_object *object)
{
    king_ws_server_object *intern = php_king_ws_server_obj_from_zend(object);

    if (!Z_ISUNDEF(intern->config)) {
        zval_ptr_dtor(&intern->config);
        ZVAL_UNDEF(&intern->config);
    }
    if (intern->host != NULL) {
        zend_string_release(intern->host);
        intern->host = NULL;
    }
    if (intern->listener_fd >= 0) {
        close(intern->listener_fd);
        intern->listener_fd = -1;
    }

    zend_object_std_dtor(&intern->std);
}

static zend_object *king_ws_server_object_create(zend_class_entry *ce)
{
    king_ws_server_object *intern = zend_object_alloc(sizeof(*intern), ce);

    ZVAL_UNDEF(&intern->config);
    intern->host = NULL;
    intern->port = 0;
    intern->listener_fd = -1;
    intern->closed = false;
    zend_object_std_init(&intern->std, ce);
    object_properties_init(&intern->std, ce);
    intern->std.handlers = &king_ws_server_object_handlers;

    return &intern->std;
}

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

static void king_mcp_object_handlers_init(void)
{
    memcpy(
        &king_mcp_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_mcp_object_handlers.offset = XtOffsetOf(king_mcp_object, std);
    king_mcp_object_handlers.free_obj = king_mcp_object_free;
    king_mcp_object_handlers.clone_obj = NULL;
    king_ce_mcp->create_object = king_mcp_object_create;
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

static void king_ws_server_object_handlers_init(void)
{
    memcpy(
        &king_ws_server_object_handlers,
        &std_object_handlers,
        sizeof(zend_object_handlers)
    );
    king_ws_server_object_handlers.offset = XtOffsetOf(king_ws_server_object, std);
    king_ws_server_object_handlers.free_obj = king_ws_server_object_free;
    king_ws_server_object_handlers.clone_obj = NULL;
    king_ce_ws_server->create_object = king_ws_server_object_create;
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
