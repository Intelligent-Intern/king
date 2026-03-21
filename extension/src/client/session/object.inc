ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_Session___construct, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, config, King\\Config, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, connect_options, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Session_isConnected, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_Session_sendRequest, 0, 2, King\\Stream, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 0, "[]")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, body, IS_STRING, 0, "\"\"")
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Session_poll, 0, 0, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 0, "0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_Session_close, 0, 0, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, code, IS_LONG, 0, "0")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, reason, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Session_stats, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Session_alpn, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Session_enableEarlyHints, 0, 0, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, enable, _IS_BOOL, 0, "true")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_King_Stream_receiveResponse, 0, 0, King\\Response, 1)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 1, "null")
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Stream_send, 0, 1, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, cancel, King\\CancelToken, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Stream_finish, 0, 0, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, finalData, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Stream_isClosed, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_Stream_close, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

static zend_result king_stream_object_require_initialized(
    king_stream_object *intern,
    const char *function_name)
{
    if (!Z_ISUNDEF(intern->session) && Z_TYPE(intern->session) == IS_OBJECT && intern->stream_id >= 0) {
        return SUCCESS;
    }

    king_set_error("King\\Stream object is not initialized.");
    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "%s() requires an initialized King\\Stream object.",
        function_name
    );
    return FAILURE;
}

static king_client_session_t *king_stream_object_fetch_session(
    king_stream_object *intern,
    const char *function_name)
{
    if (king_stream_object_require_initialized(intern, function_name) != SUCCESS) {
        return NULL;
    }

    return king_client_session_fetch_resource(&intern->session, 0);
}

static zval *king_stream_object_resolve_cancel_token(
    king_stream_object *intern,
    zval *cancel,
    bool prefer_bound)
{
    if (cancel != NULL && Z_TYPE_P(cancel) == IS_OBJECT) {
        return cancel;
    }

    if (
        prefer_bound
        && !Z_ISUNDEF(intern->cancel_token)
        && Z_TYPE(intern->cancel_token) == IS_OBJECT
    ) {
        return &intern->cancel_token;
    }

    return NULL;
}

static zend_bool king_stream_object_is_locally_cancelled(
    king_client_session_t *session,
    king_stream_object *intern)
{
    return zend_hash_index_exists(
        &session->cancelled_streams,
        (zend_ulong) intern->stream_id
    );
}

static zend_result king_stream_object_validate_initial_cancel_token(
    zval *cancel,
    const char *function_name)
{
    king_cancel_token_object *cancel_intern;

    if (cancel == NULL || Z_TYPE_P(cancel) == IS_NULL) {
        return SUCCESS;
    }

    cancel_intern = php_king_cancel_token_obj_from_zend(Z_OBJ_P(cancel));
    if (cancel_intern->cancelled) {
        king_set_error("The provided CancelToken is already cancelled.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s() received a CancelToken that is already cancelled.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_stream_object_mark_cancelled(
    king_stream_object *intern,
    king_client_session_t *session,
    const char *function_name)
{
    if (king_stream_object_is_locally_cancelled(session, intern)) {
        intern->closed = true;
        return SUCCESS;
    }

    if (!session->is_closed) {
        if (king_client_session_mark_cancelled(
                session,
                intern->stream_id,
                "both",
                sizeof("both") - 1,
                function_name
            ) != SUCCESS) {
            zend_throw_exception_ex(
                king_ce_system_exception,
                0,
                "%s",
                king_get_error()[0] != '\0'
                    ? king_get_error()
                    : "Failed to record local stream cancellation state."
            );
            return FAILURE;
        }
    }

    intern->closed = true;
    return SUCCESS;
}

static zend_result king_stream_object_throw_if_cancelled(
    king_stream_object *intern,
    king_client_session_t *session,
    zval *cancel,
    const char *function_name)
{
    zval *active_cancel;
    king_cancel_token_object *cancel_intern;

    active_cancel = king_stream_object_resolve_cancel_token(intern, cancel, true);
    if (active_cancel != NULL) {
        cancel_intern = php_king_cancel_token_obj_from_zend(Z_OBJ_P(active_cancel));
        if (cancel_intern->cancelled) {
            if (king_stream_object_mark_cancelled(intern, session, function_name) != SUCCESS) {
                return FAILURE;
            }

            king_set_error("The active stream was cancelled via CancelToken.");
            zend_throw_exception_ex(
                king_ce_stream_stopped,
                0,
                "%s() cannot continue because the stream was cancelled via CancelToken.",
                function_name
            );
            return FAILURE;
        }
    }

    if (king_stream_object_is_locally_cancelled(session, intern)) {
        intern->closed = true;
        king_set_error("The active stream is locally cancelled.");
        zend_throw_exception_ex(
            king_ce_stream_stopped,
            0,
            "%s() cannot continue because the stream is locally cancelled.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static void king_stream_object_append_body(
    king_stream_object *intern,
    zend_string *chunk)
{
    zend_string *current_body;
    zend_string *appended_body;
    size_t current_len;
    size_t chunk_len;

    if (chunk == NULL) {
        return;
    }

    current_body = intern->request_body != NULL
        ? intern->request_body
        : zend_string_init("", 0, 0);
    current_len = ZSTR_LEN(current_body);
    chunk_len = ZSTR_LEN(chunk);
    appended_body = zend_string_safe_alloc(1, current_len, chunk_len, 0);

    if (current_len > 0) {
        memcpy(
            ZSTR_VAL(appended_body),
            ZSTR_VAL(current_body),
            current_len
        );
    }
    if (chunk_len > 0) {
        memcpy(
            ZSTR_VAL(appended_body) + current_len,
            ZSTR_VAL(chunk),
            chunk_len
        );
    }

    ZSTR_VAL(appended_body)[current_len + chunk_len] = '\0';

    if (intern->request_body != NULL) {
        zend_string_release(intern->request_body);
    } else {
        zend_string_release(current_body);
    }

    intern->request_body = appended_body;
    intern->request_body_was_supplied = true;
}

static zend_result king_stream_object_build_http1_url(
    king_client_session_t *session,
    king_stream_object *intern,
    zend_string **url_out)
{
    if (session == NULL || session->host == NULL || intern->request_path == NULL) {
        king_set_error("Stream::receiveResponse() lost the request snapshot required for dispatch.");
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "Stream::receiveResponse() lost the request snapshot required for dispatch."
        );
        return FAILURE;
    }

    if (strchr(ZSTR_VAL(session->host), ':') != NULL) {
        *url_out = strpprintf(
            0,
            "http://[%s]:%ld%s",
            ZSTR_VAL(session->host),
            session->port,
            ZSTR_VAL(intern->request_path)
        );
    } else {
        *url_out = strpprintf(
            0,
            "http://%s:%ld%s",
            ZSTR_VAL(session->host),
            session->port,
            ZSTR_VAL(intern->request_path)
        );
    }

    return SUCCESS;
}

static zend_result king_stream_object_receive_http1_response(
    zval *return_value,
    king_stream_object *intern,
    king_client_session_t *session,
    zend_long timeout_ms,
    bool timeout_is_null,
    zval *cancel)
{
    king_http1_request_context *context;
    zend_string *request_url = NULL;
    zval options;
    zval body_zval;
    zval request_context;
    zval response_payload;
    zval *headers = NULL;
    zval *body = NULL;
    zval *active_cancel;

    if (!timeout_is_null && timeout_ms < 0) {
        king_set_error("Stream::receiveResponse() timeout_ms must be >= 0 when provided.");
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Stream::receiveResponse() timeout_ms must be >= 0 when provided."
        );
        return FAILURE;
    }

    if (intern->response_started) {
        king_set_error("Stream::receiveResponse() can only be called once per local stream.");
        zend_throw_exception_ex(
            king_ce_invalid_state,
            0,
            "Stream::receiveResponse() can only be called once per local stream."
        );
        return FAILURE;
    }

    if (king_stream_object_build_http1_url(session, intern, &request_url) != SUCCESS) {
        return FAILURE;
    }

    active_cancel = king_stream_object_resolve_cancel_token(intern, cancel, true);
    array_init(&options);
    add_assoc_bool(&options, "response_stream", true);
    if (session->transport_connect_timeout_ms > 0) {
        add_assoc_long(
            &options,
            "connect_timeout_ms",
            session->transport_connect_timeout_ms
        );
        if (timeout_is_null || timeout_ms == 0) {
            add_assoc_long(
                &options,
                "timeout_ms",
                session->transport_connect_timeout_ms
            );
        }
    }
    if (!timeout_is_null && timeout_ms > 0) {
        add_assoc_long(&options, "timeout_ms", timeout_ms);
    }
    if (
        !Z_ISUNDEF(intern->connection_config)
        && Z_TYPE(intern->connection_config) == IS_OBJECT
    ) {
        zval config_copy;

        ZVAL_COPY(&config_copy, &intern->connection_config);
        add_assoc_zval(&options, "connection_config", &config_copy);
    }
    if (active_cancel != NULL) {
        zval cancel_copy;

        ZVAL_COPY(&cancel_copy, active_cancel);
        add_assoc_zval(
            &options,
            KING_INTERNAL_OPTION_CANCEL_TOKEN,
            &cancel_copy
        );
        add_assoc_string(
            &options,
            KING_INTERNAL_OPTION_CANCEL_FUNCTION_NAME,
            "Stream::receiveResponse"
        );
        add_assoc_bool(
            &options,
            KING_INTERNAL_OPTION_CANCEL_STREAM_STOPPED,
            true
        );
    }

    ZVAL_UNDEF(&body_zval);
    ZVAL_UNDEF(&request_context);
    ZVAL_UNDEF(&response_payload);

    if (
        !Z_ISUNDEF(intern->request_headers)
        && Z_TYPE(intern->request_headers) == IS_ARRAY
    ) {
        headers = &intern->request_headers;
    }

    if (intern->request_body_was_supplied && intern->request_body != NULL) {
        ZVAL_STR_COPY(&body_zval, intern->request_body);
        body = &body_zval;
    }

    intern->response_started = true;
    intern->finished = true;
    intern->closed = true;

    if (king_http1_request_dispatch(
            &request_context,
            ZSTR_VAL(request_url),
            ZSTR_LEN(request_url),
            ZSTR_VAL(intern->request_method),
            ZSTR_LEN(intern->request_method),
            headers,
            body,
            &options,
            "Stream::receiveResponse"
        ) != SUCCESS) {
        if (king_transport_cancel_token_is_cancelled(active_cancel)) {
            if (king_stream_object_mark_cancelled(intern, session, "Stream::receiveResponse") != SUCCESS) {
                if (!Z_ISUNDEF(body_zval)) {
                    zval_ptr_dtor(&body_zval);
                }
                zval_ptr_dtor(&options);
                zend_string_release(request_url);
                return FAILURE;
            }
        }
        if (!Z_ISUNDEF(body_zval)) {
            zval_ptr_dtor(&body_zval);
        }
        zval_ptr_dtor(&options);
        zend_string_release(request_url);
        return FAILURE;
    }

    if (!Z_ISUNDEF(body_zval)) {
        zval_ptr_dtor(&body_zval);
    }
    zval_ptr_dtor(&options);
    zend_string_release(request_url);

    context = (king_http1_request_context *) zend_fetch_resource(
        Z_RES(request_context),
        "King\\HttpRequestContext",
        le_king_request_context
    );
    if (context == NULL) {
        zval_ptr_dtor(&request_context);
        king_set_error("Stream::receiveResponse() lost the active HTTP/1 request context.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Stream::receiveResponse() lost the active HTTP/1 request context."
        );
        return FAILURE;
    }

    if (king_http1_request_context_build_payload(
            context,
            &response_payload,
            "Stream::receiveResponse"
        ) != SUCCESS) {
        zval_ptr_dtor(&request_context);
        return FAILURE;
    }

    if (king_response_object_init_from_context(
            return_value,
            &response_payload,
            &request_context
        ) != SUCCESS) {
        zval_ptr_dtor(&response_payload);
        zval_ptr_dtor(&request_context);
        king_set_error("Stream::receiveResponse() failed to initialize the streaming response object.");
        zend_throw_exception_ex(
            king_ce_system_exception,
            0,
            "Stream::receiveResponse() failed to initialize the streaming response object."
        );
        return FAILURE;
    }

    zval_ptr_dtor(&response_payload);
    zval_ptr_dtor(&request_context);
    session->last_activity_at = time(NULL);
    king_set_error("");
    return SUCCESS;
}

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

PHP_METHOD(King_Stream, receiveResponse)
{
    zend_long timeout_ms = 0;
    bool timeout_is_null = true;
    zval *cancel = NULL;
    king_stream_object *intern;
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_START(0, 2)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG_OR_NULL(timeout_ms, timeout_is_null)
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(cancel, king_ce_cancel_token)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_stream_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    session = king_stream_object_fetch_session(intern, "Stream::receiveResponse");
    if (session == NULL) {
        RETURN_THROWS();
    }

    if (king_stream_object_throw_if_cancelled(
            intern,
            session,
            cancel,
            "Stream::receiveResponse"
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_stream_object_receive_http1_response(
            return_value,
            intern,
            session,
            timeout_ms,
            timeout_is_null,
            cancel
        ) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_METHOD(King_Stream, send)
{
    zend_string *data;
    zval *cancel = NULL;
    king_stream_object *intern;
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(data)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS_OR_NULL(cancel, king_ce_cancel_token)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_stream_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    session = king_stream_object_fetch_session(intern, "Stream::send");
    if (session == NULL) {
        RETURN_THROWS();
    }

    if (king_stream_object_throw_if_cancelled(
            intern,
            session,
            cancel,
            "Stream::send"
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_stream_object_is_locally_cancelled(session, intern)) {
        king_set_error("Stream::send() cannot write after the stream was locally cancelled.");
        zend_throw_exception_ex(
            king_ce_stream_stopped,
            0,
            "Stream::send() cannot write after the stream was locally cancelled."
        );
        RETURN_THROWS();
    }

    if (session->is_closed || intern->closed || intern->finished) {
        king_set_error("Stream::send() cannot write to a closed stream.");
        zend_throw_exception_ex(
            king_ce_invalid_state,
            0,
            "Stream::send() cannot write to a closed stream."
        );
        RETURN_THROWS();
    }

    king_stream_object_append_body(intern, data);
    intern->buffered_bytes += ZSTR_LEN(data);
    session->last_activity_at = time(NULL);
    RETURN_LONG(ZSTR_LEN(data));
}

PHP_METHOD(King_Stream, finish)
{
    zend_string *final_data = NULL;
    king_stream_object *intern;
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_STR_OR_NULL(final_data)
    ZEND_PARSE_PARAMETERS_END();

    intern = php_king_stream_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    session = king_stream_object_fetch_session(intern, "Stream::finish");
    if (session == NULL) {
        RETURN_THROWS();
    }

    if (king_stream_object_throw_if_cancelled(
            intern,
            session,
            NULL,
            "Stream::finish"
        ) != SUCCESS) {
        RETURN_THROWS();
    }

    if (king_stream_object_is_locally_cancelled(session, intern)) {
        king_set_error("Stream::finish() cannot run after the stream was locally cancelled.");
        zend_throw_exception_ex(
            king_ce_stream_stopped,
            0,
            "Stream::finish() cannot run after the stream was locally cancelled."
        );
        RETURN_THROWS();
    }

    if (session->is_closed || intern->closed) {
        king_set_error("Stream::finish() cannot run on a closed stream.");
        zend_throw_exception_ex(
            king_ce_invalid_state,
            0,
            "Stream::finish() cannot run on a closed stream."
        );
        RETURN_THROWS();
    }

    if (final_data != NULL) {
        king_stream_object_append_body(intern, final_data);
        intern->buffered_bytes += ZSTR_LEN(final_data);
    }

    intern->finished = true;
    intern->closed = true;
    session->last_activity_at = time(NULL);
    king_set_error("");
}

PHP_METHOD(King_Stream, isClosed)
{
    king_stream_object *intern;
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_stream_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    session = king_stream_object_fetch_session(intern, "Stream::isClosed");
    if (session == NULL) {
        RETURN_THROWS();
    }

    if (king_stream_object_resolve_cancel_token(intern, NULL, true) != NULL) {
        king_cancel_token_object *cancel_intern = php_king_cancel_token_obj_from_zend(
            Z_OBJ_P(king_stream_object_resolve_cancel_token(intern, NULL, true))
        );

        if (cancel_intern->cancelled) {
            RETURN_TRUE;
        }
    }

    RETURN_BOOL(
        session->is_closed
        || intern->closed
        || king_stream_object_is_locally_cancelled(session, intern)
    );
}

PHP_METHOD(King_Stream, close)
{
    king_stream_object *intern;
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_NONE();

    intern = php_king_stream_obj_from_zend(Z_OBJ_P(ZEND_THIS));
    session = king_stream_object_fetch_session(intern, "Stream::close");
    if (session == NULL) {
        RETURN_THROWS();
    }

    if (!intern->closed && !session->is_closed) {
        if (king_client_session_mark_cancelled(
                session,
                intern->stream_id,
                "both",
                sizeof("both") - 1,
                "King\\Stream::close"
            ) != SUCCESS) {
            RETURN_THROWS();
        }
    }

    intern->closed = true;
    session->last_activity_at = time(NULL);
    king_set_error("");
}

const zend_function_entry king_session_class_methods[] = {
    PHP_ME(King_Session, __construct, arginfo_class_King_Session___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_Session, isConnected, arginfo_class_King_Session_isConnected, ZEND_ACC_PUBLIC)
    PHP_ME(King_Session, sendRequest, arginfo_class_King_Session_sendRequest, ZEND_ACC_PUBLIC)
    PHP_ME(King_Session, poll, arginfo_class_King_Session_poll, ZEND_ACC_PUBLIC)
    PHP_ME(King_Session, close, arginfo_class_King_Session_close, ZEND_ACC_PUBLIC)
    PHP_ME(King_Session, stats, arginfo_class_King_Session_stats, ZEND_ACC_PUBLIC)
    PHP_ME(King_Session, alpn, arginfo_class_King_Session_alpn, ZEND_ACC_PUBLIC)
    PHP_ME(King_Session, enableEarlyHints, arginfo_class_King_Session_enableEarlyHints, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

const zend_function_entry king_stream_class_methods[] = {
    PHP_ME(King_Stream, receiveResponse, arginfo_class_King_Stream_receiveResponse, ZEND_ACC_PUBLIC)
    PHP_ME(King_Stream, send, arginfo_class_King_Stream_send, ZEND_ACC_PUBLIC)
    PHP_ME(King_Stream, finish, arginfo_class_King_Stream_finish, ZEND_ACC_PUBLIC)
    PHP_ME(King_Stream, isClosed, arginfo_class_King_Stream_isClosed, ZEND_ACC_PUBLIC)
    PHP_ME(King_Stream, close, arginfo_class_King_Stream_close, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
