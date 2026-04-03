static zend_result king_stream_object_init_from_session(
    zval *target,
    zval *session_object,
    zend_long stream_id,
    zval *cancel,
    const char *method,
    size_t method_len,
    const char *path,
    size_t path_len,
    zval *headers,
    zend_string *body,
    zval *connection_config)
{
    king_stream_object *intern;

    object_init_ex(target, king_ce_stream);
    intern = php_king_stream_obj_from_zend(Z_OBJ_P(target));
    ZVAL_COPY(&intern->session, session_object);
    if (cancel != NULL && Z_TYPE_P(cancel) == IS_OBJECT) {
        ZVAL_COPY(&intern->cancel_token, cancel);
    }
    if (connection_config != NULL && Z_TYPE_P(connection_config) == IS_OBJECT) {
        ZVAL_COPY(&intern->connection_config, connection_config);
    }
    if (headers != NULL && Z_TYPE_P(headers) == IS_ARRAY) {
        ZVAL_COPY(&intern->request_headers, headers);
    } else {
        array_init(&intern->request_headers);
    }
    intern->request_method = zend_string_init(method, method_len, 0);
    intern->request_path = zend_string_init(path, path_len, 0);
    if (intern->request_body != NULL) {
        zend_string_release(intern->request_body);
    }
    if (body != NULL) {
        intern->request_body = zend_string_copy(body);
        intern->request_body_was_supplied = true;
    } else {
        intern->request_body = zend_string_init("", 0, 0);
        intern->request_body_was_supplied = false;
    }
    intern->stream_id = stream_id;
    intern->buffered_bytes = 0;
    intern->finished = false;
    intern->closed = false;
    intern->response_started = false;

    return SUCCESS;
}

PHP_METHOD(King_Session, __construct)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config = NULL;
    zval *connect_options = NULL;
    king_client_session_t *session;
    king_session_object *intern;

    ZEND_PARSE_PARAMETERS_START(2, 4)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(config, king_ce_config)
        Z_PARAM_ARRAY(connect_options)
    ZEND_PARSE_PARAMETERS_END();

    (void) connect_options;

    session = king_client_session_open(host, host_len, port, config, 3);
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "%s",
            king_get_error()[0] != '\0'
                ? king_get_error()
                : "King\\Session construction failed."
        );
        RETURN_THROWS();
    }

    intern = php_king_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    if (!Z_ISUNDEF(intern->resource)) {
        zval_ptr_dtor(&intern->resource);
    }
    if (!Z_ISUNDEF(intern->config)) {
        zval_ptr_dtor(&intern->config);
        ZVAL_UNDEF(&intern->config);
    }
    if (config != NULL && Z_TYPE_P(config) == IS_OBJECT) {
        ZVAL_COPY(&intern->config, config);
    }
    ZVAL_RES(&intern->resource, zend_register_resource(session, le_king_session));
    king_set_error("");
}

PHP_METHOD(King_Session, isConnected)
{
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_NONE();

    session = king_client_session_fetch_resource(ZEND_THIS, 0);
    if (session == NULL) {
        RETURN_THROWS();
    }

    RETURN_BOOL(!session->is_closed);
}

PHP_METHOD(King_Session, sendRequest)
{
    char *method = NULL;
    size_t method_len = 0;
    char *path = NULL;
    size_t path_len = 0;
    zval *headers = NULL;
    zend_string *body = NULL;
    zval *cancel = NULL;
    king_client_session_t *session;
    king_session_object *session_intern;
    zend_long stream_id;

    ZEND_PARSE_PARAMETERS_START(2, 5)
        Z_PARAM_STRING(method, method_len)
        Z_PARAM_STRING(path, path_len)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY(headers)
        Z_PARAM_STR(body)
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(cancel, king_ce_cancel_token)
    ZEND_PARSE_PARAMETERS_END();

    session = king_client_session_fetch_resource(ZEND_THIS, 0);
    if (session == NULL) {
        RETURN_THROWS();
    }
    session_intern = php_king_obj_from_zend(Z_OBJ_P(ZEND_THIS));

    if (session->is_closed) {
        king_set_error("Session::sendRequest() cannot run on a closed session.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Session::sendRequest() cannot run on a closed session."
        );
        RETURN_THROWS();
    }

    if (method_len == 0) {
        king_set_error("Session::sendRequest() requires a non-empty HTTP method.");
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Session::sendRequest() requires a non-empty HTTP method."
        );
        RETURN_THROWS();
    }

    if (path_len == 0 || path[0] != '/') {
        king_set_error("Session::sendRequest() requires an absolute request path beginning with '/'.");
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Session::sendRequest() requires an absolute request path beginning with '/'."
        );
        RETURN_THROWS();
    }

    if (king_stream_object_validate_initial_cancel_token(cancel, "Session::sendRequest") != SUCCESS) {
        RETURN_THROWS();
    }

    stream_id = session->next_client_bidi_stream_id;
    session->next_client_bidi_stream_id += 4;
    session->last_activity_at = time(NULL);

    if (king_stream_object_init_from_session(
            return_value,
            ZEND_THIS,
            stream_id,
            cancel,
            method,
            method_len,
            path,
            path_len,
            headers,
            body,
            !Z_ISUNDEF(session_intern->config) ? &session_intern->config : NULL
        ) != SUCCESS) {
        king_set_error("Session::sendRequest() failed to initialize the stream object.");
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "Session::sendRequest() failed to initialize the stream object."
        );
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Session, poll)
{
    zend_long timeout_ms = 0;
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(timeout_ms)
    ZEND_PARSE_PARAMETERS_END();

    session = king_client_session_fetch_resource(ZEND_THIS, 0);
    if (session == NULL) {
        RETURN_THROWS();
    }

    if (king_client_session_poll_internal(session, timeout_ms) != SUCCESS) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    RETURN_TRUE;
}

PHP_METHOD(King_Session, close)
{
    zend_long code = 0;
    zend_string *reason = NULL;
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_START(0, 2)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(code)
        Z_PARAM_STR_OR_NULL(reason)
    ZEND_PARSE_PARAMETERS_END();

    (void) code;
    (void) reason;

    session = king_client_session_fetch_resource(ZEND_THIS, 0);
    if (session == NULL) {
        RETURN_THROWS();
    }

    if (!session->is_closed) {
        king_client_session_close_socket(session);
        session->is_closed = true;
        session->last_activity_at = time(NULL);
    }

    king_set_error("");
}

PHP_METHOD(King_Session, stats)
{
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_NONE();

    session = king_client_session_fetch_resource(ZEND_THIS, 0);
    if (session == NULL) {
        RETURN_THROWS();
    }

    king_client_session_populate_stats(return_value, session);
}

PHP_METHOD(King_Session, alpn)
{
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_NONE();

    session = king_client_session_fetch_resource(ZEND_THIS, 0);
    if (session == NULL) {
        RETURN_THROWS();
    }

    RETURN_STR_COPY(session->negotiated_alpn);
}

PHP_METHOD(King_Session, enableEarlyHints)
{
    bool enable = true;
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(enable)
    ZEND_PARSE_PARAMETERS_END();

    session = king_client_session_fetch_resource(ZEND_THIS, 0);
    if (session == NULL) {
        RETURN_THROWS();
    }

    session->http_enable_early_hints = enable;
    king_set_error("");
}

