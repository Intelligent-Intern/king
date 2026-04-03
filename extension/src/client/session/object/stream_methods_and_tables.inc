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
