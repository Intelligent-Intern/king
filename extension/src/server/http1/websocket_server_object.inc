ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_WebSocket_Server___construct, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, config, King\\Config, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_WebSocket_Server_accept, 0, 0, King\\WebSocket\\Connection, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Server_stop, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

static void king_websocket_server_throw_last_error(
    zend_class_entry *exception_ce,
    const char *fallback
)
{
    zend_throw_exception_ex(
        exception_ce,
        0,
        "%s",
        king_get_error()[0] != '\0' ? king_get_error() : fallback
    );
}

static zend_bool king_websocket_server_error_is_timeout(void)
{
    return strstr(king_get_error(), "timed out") != NULL;
}

static zend_result king_websocket_server_open_listener(
    king_ws_server_object *intern,
    const char *function_name
)
{
    int listener_fd = -1;

    if (intern->closed) {
        king_set_error("WebSocket\\Server::accept() cannot run on a stopped WebSocket server.");
        return FAILURE;
    }

    if (intern->listener_fd >= 0) {
        return SUCCESS;
    }

    if (intern->host == NULL || ZSTR_LEN(intern->host) == 0) {
        king_set_error("WebSocket\\Server is not initialized.");
        return FAILURE;
    }

    if (
        king_server_http1_open_listener_socket(
            ZSTR_VAL(intern->host),
            intern->port,
            &listener_fd,
            function_name
        ) != SUCCESS
    ) {
        return FAILURE;
    }

    intern->listener_fd = listener_fd;
    return SUCCESS;
}

static zend_result king_websocket_server_init_connection_object(
    zval *target,
    zval *resource
)
{
    king_ws_object *connection;

    if (object_init_ex(target, king_ce_ws_connection) != SUCCESS) {
        return FAILURE;
    }

    connection = php_king_ws_obj_from_zend(Z_OBJ_P(target));
    if (!Z_ISUNDEF(connection->resource)) {
        zval_ptr_dtor(&connection->resource);
    }
    ZVAL_COPY(&connection->resource, resource);
    return SUCCESS;
}

PHP_METHOD(King_WebSocket_Server, __construct)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config = NULL;
    king_ws_server_object *intern;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(config, king_ce_config)
    ZEND_PARSE_PARAMETERS_END();

    if (host_len == 0) {
        king_set_error("WebSocket\\Server::__construct() requires a non-empty host.");
        zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", king_get_error());
        RETURN_THROWS();
    }

    if (port <= 0 || port > 65535) {
        king_set_error("WebSocket\\Server::__construct() port must be between 1 and 65535.");
        zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", king_get_error());
        RETURN_THROWS();
    }

    intern = php_king_ws_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
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

    intern->host = zend_string_init(host, host_len, 0);
    intern->port = port;
    intern->closed = false;
    if (config != NULL) {
        ZVAL_COPY(&intern->config, config);
    }

    king_set_error("");
}

PHP_METHOD(King_WebSocket_Server, accept)
{
    king_ws_server_object *intern;
    king_client_session_t *session = NULL;
    zval request;
    zval websocket_resource;
    zval *request_headers = NULL;
    smart_str request_head = {0};
    zend_long content_length = 0;
    zend_long accept_deadline_ms;
    zend_long read_deadline_ms;
    int accepted_fd = -1;
    zend_bool success = 0;
    zend_bool request_owns_session = 0;

    ZEND_PARSE_PARAMETERS_NONE();

    ZVAL_UNDEF(&request);
    ZVAL_UNDEF(&websocket_resource);

    intern = php_king_ws_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (intern->closed) {
        king_set_error("WebSocket\\Server::accept() cannot run on a stopped WebSocket server.");
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s", king_get_error());
        RETURN_THROWS();
    }

    if (
        king_websocket_server_open_listener(
            intern,
            "WebSocket\\Server::accept"
        ) != SUCCESS
    ) {
        king_websocket_server_throw_last_error(
            king_ce_ws_connection_error,
            "WebSocket\\Server::accept() failed to open the active listener."
        );
        RETURN_THROWS();
    }

    session = king_server_local_open_session(
        "WebSocket\\Server::accept",
        ZSTR_VAL(intern->host),
        ZSTR_LEN(intern->host),
        intern->port,
        Z_ISUNDEF(intern->config) ? NULL : &intern->config,
        0,
        "http/1.1",
        "server_http1_socket"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        king_websocket_server_throw_last_error(
            king_ce_ws_connection_error,
            "WebSocket\\Server::accept() failed to open the active server session."
        );
        RETURN_THROWS();
    }

    accept_deadline_ms = king_server_http1_now_ms()
        + king_server_http1_resolve_timeout_ms(session);
    if (
        king_server_http1_accept_once(
            intern->listener_fd,
            accept_deadline_ms,
            &accepted_fd,
            "WebSocket\\Server::accept"
        ) != SUCCESS
    ) {
        goto fail_accept;
    }

    if (
        king_server_http1_apply_transport_snapshot_from_socket(
            session,
            accepted_fd,
            "WebSocket\\Server::accept"
        ) != SUCCESS
    ) {
        goto fail_accept;
    }
    accepted_fd = -1;

    read_deadline_ms = king_server_http1_now_ms()
        + king_server_http1_resolve_timeout_ms(session);
    if (
        king_server_http1_read_request_head(
            session->transport_socket_fd,
            &request_head,
            read_deadline_ms,
            "WebSocket\\Server::accept"
        ) != SUCCESS
    ) {
        goto fail_accept;
    }

    if (
        king_server_http1_parse_request_head(
            ZSTR_VAL(request_head.s),
            ZSTR_LEN(request_head.s),
            session,
            &request,
            &content_length,
            "WebSocket\\Server::accept"
        ) != SUCCESS
    ) {
        goto fail_accept;
    }

    if (
        king_server_http1_read_request_body(
            session->transport_socket_fd,
            &request,
            content_length,
            read_deadline_ms,
            "WebSocket\\Server::accept"
        ) != SUCCESS
    ) {
        goto fail_accept;
    }

    if (!session->server_pending_websocket_upgrade) {
        king_set_error(
            "WebSocket\\Server::accept() received an HTTP/1 request that did not request a websocket upgrade."
        );
        king_websocket_server_throw_last_error(
            king_ce_ws_protocol_error,
            "WebSocket\\Server::accept() requires a websocket upgrade request."
        );
        goto fail_cleanup;
    }

    request_headers = zend_hash_str_find(
        Z_ARRVAL(request),
        "headers",
        sizeof("headers") - 1
    );
    if (
        king_server_websocket_upgrade_session(
            session,
            0,
            request_headers,
            &websocket_resource,
            "WebSocket\\Server::accept"
        ) != SUCCESS
    ) {
        goto fail_accept;
    }

    if (
        king_websocket_server_init_connection_object(
            return_value,
            &websocket_resource
        ) != SUCCESS
    ) {
        king_set_error("WebSocket\\Server::accept() failed to initialize the accepted Connection object.");
        king_websocket_server_throw_last_error(
            king_ce_runtime_exception,
            "WebSocket\\Server::accept() failed to initialize the accepted Connection object."
        );
        goto fail_cleanup;
    }

    success = 1;
    goto fail_cleanup;

fail_accept:
    if (king_websocket_server_error_is_timeout()) {
        king_websocket_server_throw_last_error(
            king_ce_ws_timeout,
            "WebSocket\\Server::accept() timed out while waiting for a websocket peer."
        );
    } else {
        king_websocket_server_throw_last_error(
            king_ce_ws_connection_error,
            "WebSocket\\Server::accept() failed to accept the active websocket peer."
        );
    }

fail_cleanup:
    request_owns_session =
        !Z_ISUNDEF(request)
        && Z_TYPE(request) == IS_ARRAY
        && zend_hash_str_exists(
            Z_ARRVAL(request),
            "session",
            sizeof("session") - 1
        );

    if (accepted_fd >= 0) {
        close(accepted_fd);
    }
    if (!Z_ISUNDEF(websocket_resource)) {
        zval_ptr_dtor(&websocket_resource);
        ZVAL_UNDEF(&websocket_resource);
    }
    if (!Z_ISUNDEF(request)) {
        zval_ptr_dtor(&request);
        ZVAL_UNDEF(&request);
        if (request_owns_session) {
            session = NULL;
        }
    }
    if (request_head.s != NULL) {
        smart_str_free(&request_head);
    }
    if (session != NULL) {
        king_server_local_close_session(session);
        king_client_session_free(session);
    }

    if (success) {
        king_set_error("");
        return;
    }

    RETURN_THROWS();
}

PHP_METHOD(King_WebSocket_Server, stop)
{
    king_ws_server_object *intern;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_ws_server_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (intern->listener_fd >= 0) {
        close(intern->listener_fd);
        intern->listener_fd = -1;
    }
    intern->closed = true;
    king_set_error("");
}

const zend_function_entry king_ws_server_class_methods[] = {
    PHP_ME(King_WebSocket_Server, __construct, arginfo_class_King_WebSocket_Server___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_WebSocket_Server, accept, arginfo_class_King_WebSocket_Server_accept, ZEND_ACC_PUBLIC)
    PHP_ME(King_WebSocket_Server, stop, arginfo_class_King_WebSocket_Server_stop, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
