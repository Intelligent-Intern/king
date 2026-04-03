static zend_result king_server_http1_send_response(
    king_client_session_t *session,
    zval *retval,
    zend_long deadline_ms,
    const char *function_name
)
{
    zval *status_zv;
    zval *headers_zv;
    zval *body_zv;
    zend_long status = 200;
    zend_string *body = NULL;
    smart_str response = {0};
    zend_result rc;

    if (session->transport_socket_fd < 0) {
        return SUCCESS;
    }

    status_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "status", sizeof("status") - 1);
    headers_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "headers", sizeof("headers") - 1);
    body_zv = zend_hash_str_find(Z_ARRVAL_P(retval), "body", sizeof("body") - 1);

    if (status_zv != NULL && Z_TYPE_P(status_zv) != IS_NULL) {
        status = zval_get_long(status_zv);
    }

    if (body_zv != NULL && Z_TYPE_P(body_zv) != IS_NULL) {
        body = zval_get_string(body_zv);
    } else {
        body = zend_string_init("", 0, 0);
    }

    smart_str_appends(&response, "HTTP/1.1 ");
    smart_str_append_long(&response, status);
    smart_str_appendc(&response, ' ');
    smart_str_appends(&response, king_server_http1_status_text(status));
    smart_str_appends(&response, "\r\n");

    if (king_server_http1_append_response_headers(&response, headers_zv) != SUCCESS) {
        zend_string_release(body);
        smart_str_free(&response);
        return FAILURE;
    }

    smart_str_appends(&response, "Content-Length: ");
    smart_str_append_unsigned(&response, (zend_ulong) ZSTR_LEN(body));
    smart_str_appends(&response, "\r\nConnection: close\r\n\r\n");
    if (ZSTR_LEN(body) > 0) {
        smart_str_appendl(&response, ZSTR_VAL(body), ZSTR_LEN(body));
    }
    smart_str_0(&response);

    rc = king_server_http1_write_all_fd(
        session->transport_socket_fd,
        ZSTR_VAL(response.s),
        ZSTR_LEN(response.s),
        deadline_ms,
        function_name
    );

    smart_str_free(&response);
    zend_string_release(body);
    return rc;
}

PHP_FUNCTION(king_http1_server_listen)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config;
    zval *handler;
    king_client_session_t *session;
    zval request;
    zval retval;

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_ZVAL(config)
        Z_PARAM_ZVAL(handler)
    ZEND_PARSE_PARAMETERS_END();

    session = king_server_local_open_session(
        "king_http1_server_listen",
        host,
        host_len,
        port,
        config,
        3,
        "http/1.1",
        "server_http1_local"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    ZVAL_UNDEF(&request);
    ZVAL_UNDEF(&retval);

    king_server_http1_build_request(&request, session, host, host_len, port);

    if (king_server_local_invoke_handler(
            handler,
            &request,
            &retval,
            "king_http1_server_listen"
        ) != SUCCESS) {
        king_server_local_close_session(session);
        zval_ptr_dtor(&request);

        if (!Z_ISUNDEF(retval)) {
            zval_ptr_dtor(&retval);
        }

        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (king_server_local_validate_response(
            &retval,
            session,
            "http/1.1",
            "king_http1_server_listen"
        ) != SUCCESS) {
        king_server_local_close_session(session);
        zval_ptr_dtor(&request);
        zval_ptr_dtor(&retval);
        RETURN_FALSE;
    }

    king_server_local_close_session(session);
    zval_ptr_dtor(&request);
    zval_ptr_dtor(&retval);

    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_http1_server_listen_once)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config;
    zval *handler;
    king_client_session_t *session = NULL;
    zval request;
    zval retval;
    smart_str request_head = {0};
    zend_long content_length = 0;
    zend_long accept_deadline_ms;
    zend_long read_deadline_ms;
    zend_long write_deadline_ms;
    zend_long stream_id = 0;
    int listener_fd = -1;
    int accepted_fd = -1;
    zend_bool rc = 0;

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_ZVAL(config)
        Z_PARAM_ZVAL(handler)
    ZEND_PARSE_PARAMETERS_END();

    ZVAL_UNDEF(&request);
    ZVAL_UNDEF(&retval);

    session = king_server_local_open_session(
        "king_http1_server_listen_once",
        host,
        host_len,
        port,
        config,
        3,
        "http/1.1",
        "server_http1_socket"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (
        king_server_http1_open_listener_socket(
            host,
            port,
            &listener_fd,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    accept_deadline_ms = king_server_http1_now_ms()
        + king_server_http1_resolve_timeout_ms(session);
    if (
        king_server_http1_accept_once(
            listener_fd,
            accept_deadline_ms,
            &accepted_fd,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    close(listener_fd);
    listener_fd = -1;

    if (
        king_server_http1_apply_transport_snapshot_from_socket(
            session,
            accepted_fd,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    read_deadline_ms = king_server_http1_now_ms()
        + king_server_http1_resolve_timeout_ms(session);
    if (
        king_server_http1_read_request_head(
            accepted_fd,
            &request_head,
            read_deadline_ms,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_http1_parse_request_head(
            ZSTR_VAL(request_head.s),
            ZSTR_LEN(request_head.s),
            session,
            &request,
            &content_length,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_http1_read_request_body(
            accepted_fd,
            &request,
            content_length,
            read_deadline_ms,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (
        king_server_local_invoke_handler(
            handler,
            &request,
            &retval,
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        if (EG(exception) != NULL) {
            goto cleanup;
        }

        goto cleanup;
    }

    if (
        king_server_local_validate_response(
            &retval,
            session,
            "http/1.1",
            "king_http1_server_listen_once"
        ) != SUCCESS
    ) {
        goto cleanup;
    }

    if (!zend_hash_num_elements(&session->server_upgraded_streams) && !session->is_closed) {
        zval *stream_id_zv = zend_hash_str_find(
            Z_ARRVAL(request),
            "stream_id",
            sizeof("stream_id") - 1
        );

        if (stream_id_zv != NULL) {
            stream_id = zval_get_long(stream_id_zv);
        }

        write_deadline_ms = king_server_http1_now_ms()
            + king_server_http1_resolve_timeout_ms(session);
        if (
            king_server_http1_send_response(
                session,
                &retval,
                write_deadline_ms,
                "king_http1_server_listen_once"
            ) != SUCCESS
        ) {
            if (king_server_local_errno_is_peer_disconnect(errno)) {
                king_server_local_mark_stream_cancelled_if_registered(
                    session,
                    stream_id,
                    "king_http1_server_listen_once"
                );
            }
            goto cleanup;
        }
    }

    rc = 1;

cleanup:
    if (listener_fd >= 0) {
        close(listener_fd);
    }

    if (session != NULL) {
        king_server_local_close_session(session);
    }

    if (request_head.s != NULL) {
        smart_str_free(&request_head);
    }

    if (!Z_ISUNDEF(request)) {
        zval_ptr_dtor(&request);
    }

    if (!Z_ISUNDEF(retval)) {
        zval_ptr_dtor(&retval);
    }

    if (!rc) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}
