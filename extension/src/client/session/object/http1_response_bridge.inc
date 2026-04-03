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

